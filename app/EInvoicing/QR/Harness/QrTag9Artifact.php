<?php

namespace App\EInvoicing\QR\Harness;

use App\EInvoicing\Security\Exceptions\CryptographicStampException;

/**
 * External Tag 9 bytes. Never a locally generated ZATCA Technical CA signature.
 */
final readonly class QrTag9Artifact
{
    public function __construct(
        public string $bytes,
        public string $marker,
        public bool $syntheticTestFixture,
    ) {
        if ($this->bytes === '') {
            throw new CryptographicStampException(
                'Tag 9 artifact must not be empty.',
                operation: 'tag_9',
                reason: 'empty_tag_9',
            );
        }
        if (! $this->syntheticTestFixture) {
            throw new CryptographicStampException(
                'Harness Tag 9 artifacts must be explicit synthetic test fixtures, not ZATCA CA signatures.',
                operation: 'tag_9',
                reason: 'non_test_tag_9',
            );
        }
        if ($this->marker !== TestOnlyExternalCaArtifactProvider::MARKER) {
            throw new CryptographicStampException(
                'Tag 9 test artifacts must carry TEST_ONLY_EXTERNAL_CA_ARTIFACT.',
                operation: 'tag_9',
                reason: 'missing_test_marker',
            );
        }
    }
}
