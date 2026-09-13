<?php

namespace App\EInvoicing\Security\Harness;

use App\EInvoicing\Security\Exceptions\CryptographicStampException;

/**
 * TEST ONLY codec between OpenSSL ECDSA DER and fixed-width P1363 r||s.
 * Does not establish a production ZATCA encoding.
 */
final class TestEcdsaSignatureCodec
{
    public const COORDINATE_BYTES = 32;

    public function derToP1363(string $der): string
    {
        [$r, $s] = $this->parseDerIntegers($der);

        return $this->i2osp($r, self::COORDINATE_BYTES).$this->i2osp($s, self::COORDINATE_BYTES);
    }

    public function p1363ToDer(string $p1363): string
    {
        if (strlen($p1363) !== self::COORDINATE_BYTES * 2) {
            throw new CryptographicStampException(
                'Test P1363 encoding for 256-bit keys must be 64 bytes.',
                operation: 'test_encoding',
                reason: 'invalid_p1363_length',
            );
        }

        $r = substr($p1363, 0, self::COORDINATE_BYTES);
        $s = substr($p1363, self::COORDINATE_BYTES);

        return $this->sequence($this->integer($r).$this->integer($s));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseDerIntegers(string $der): array
    {
        $offset = 0;
        $this->expectByte($der, $offset, 0x30);
        $this->readLength($der, $offset);
        $r = $this->readInteger($der, $offset);
        $s = $this->readInteger($der, $offset);
        if ($offset !== strlen($der)) {
            throw new CryptographicStampException(
                'Test DER ECDSA signature has trailing bytes.',
                operation: 'test_encoding',
                reason: 'invalid_der',
            );
        }

        return [$r, $s];
    }

    private function readInteger(string $der, int &$offset): string
    {
        $this->expectByte($der, $offset, 0x02);
        $length = $this->readLength($der, $offset);
        $value = substr($der, $offset, $length);
        $offset += $length;
        if (strlen($value) !== $length || $value === '') {
            throw new CryptographicStampException(
                'Test DER INTEGER is truncated.',
                operation: 'test_encoding',
                reason: 'invalid_der',
            );
        }

        return ltrim($value, "\x00") === '' ? "\x00" : ltrim($value, "\x00");
    }

    private function expectByte(string $der, int &$offset, int $byte): void
    {
        if (! isset($der[$offset]) || ord($der[$offset]) !== $byte) {
            throw new CryptographicStampException(
                'Test DER ECDSA signature is malformed.',
                operation: 'test_encoding',
                reason: 'invalid_der',
            );
        }
        $offset++;
    }

    private function readLength(string $der, int &$offset): int
    {
        if (! isset($der[$offset])) {
            throw new CryptographicStampException(
                'Test DER length is truncated.',
                operation: 'test_encoding',
                reason: 'invalid_der',
            );
        }
        $first = ord($der[$offset]);
        $offset++;
        if (($first & 0x80) === 0) {
            return $first;
        }
        $count = $first & 0x7F;
        if ($count < 1 || $count > 2 || $offset + $count > strlen($der)) {
            throw new CryptographicStampException(
                'Test DER length is unsupported.',
                operation: 'test_encoding',
                reason: 'invalid_der',
            );
        }
        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($der[$offset]);
            $offset++;
        }

        return $length;
    }

    private function i2osp(string $unsigned, int $length): string
    {
        $unsigned = ltrim($unsigned, "\x00");
        if (strlen($unsigned) > $length) {
            throw new CryptographicStampException(
                'Test P1363 coordinate exceeds the 256-bit field.',
                operation: 'test_encoding',
                reason: 'invalid_p1363_length',
            );
        }

        return str_pad($unsigned, $length, "\x00", STR_PAD_LEFT);
    }

    private function integer(string $unsigned): string
    {
        $unsigned = ltrim($unsigned, "\x00");
        if ($unsigned === '') {
            $unsigned = "\x00";
        }
        if ((ord($unsigned[0]) & 0x80) !== 0) {
            $unsigned = "\x00".$unsigned;
        }

        return "\x02".$this->length(strlen($unsigned)).$unsigned;
    }

    private function sequence(string $body): string
    {
        return "\x30".$this->length(strlen($body)).$body;
    }

    private function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length <= 0xFF) {
            return "\x81".chr($length);
        }

        return "\x82".chr(($length >> 8) & 0xFF).chr($length & 0xFF);
    }
}
