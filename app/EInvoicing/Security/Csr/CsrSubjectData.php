<?php

namespace App\EInvoicing\Security\Csr;

/**
 * Placeholder CSR subject DN inputs. ZATCA Table 1 RDNs are not encoded here until verified in-repo.
 */
final readonly class CsrSubjectData
{
    public function __construct(
        public string $commonName,
        public ?string $organizationName = null,
        public ?string $organizationalUnitName = null,
        public ?string $countryName = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function distinguishedName(): array
    {
        $dn = ['CN' => $this->commonName];
        if ($this->organizationName !== null && $this->organizationName !== '') {
            $dn['O'] = $this->organizationName;
        }
        if ($this->organizationalUnitName !== null && $this->organizationalUnitName !== '') {
            $dn['OU'] = $this->organizationalUnitName;
        }
        if ($this->countryName !== null && $this->countryName !== '') {
            $dn['C'] = $this->countryName;
        }

        return $dn;
    }
}
