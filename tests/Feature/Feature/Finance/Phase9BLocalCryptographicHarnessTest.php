<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\QR\Harness\QrTag9Provider;
use App\EInvoicing\QR\Harness\TestOnlyExternalCaArtifactProvider;
use App\EInvoicing\QR\Harness\TestQrCryptographicAssembler;
use App\EInvoicing\QR\Harness\TestQrTag6Representation;
use App\EInvoicing\QR\Harness\TestQrTag8Representation;
use App\EInvoicing\QR\QrField;
use App\EInvoicing\QR\QrPayloadProfile;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\Security\CryptographicStampSigner;
use App\EInvoicing\Security\EInvoiceSecurityArtifact;
use App\EInvoicing\Security\Exceptions\CertificateException;
use App\EInvoicing\Security\Exceptions\CryptographicStampException;
use App\EInvoicing\Security\Exceptions\ProductionCryptographicProfileException;
use App\EInvoicing\Security\Harness\CryptographicProfile;
use App\EInvoicing\Security\Harness\CryptographicProfileGuard;
use App\EInvoicing\Security\Harness\TestCryptographicProfile;
use App\EInvoicing\Security\Harness\TestProfileEcdsaSigner;
use App\EInvoicing\Security\Harness\UnresolvedProductionZatcaCryptographicProfile;
use App\EInvoicing\Security\StampStatus;
use App\EInvoicing\Security\XadesEnvelopedSignature;
use App\Models\EInvoicing\EInvoiceCertificate;
use App\Models\EInvoicing\EInvoiceCryptographicStamp;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Audit\AuditLogService;
use App\Services\EInvoicing\EInvoiceFactory;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\EInvoicing\QR\EInvoiceQrService;
use App\Services\EInvoicing\Security\CertificateRegistry;
use App\Services\EInvoicing\Security\DeferredCryptographicStampSigner;
use App\Services\EInvoicing\Security\EInvoiceCryptographicStampService;
use App\Services\EInvoicing\Security\EInvoiceSecurityService;
use App\Services\EInvoicing\Security\TestCryptographicStampSigner;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Phase9BLocalCryptographicHarnessTest extends TestCase
{
    use RefreshDatabase;

    private int $sourceSeq = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    #[Test]
    public function production_container_binds_deferred_signer_and_unresolved_profiles(): void
    {
        $this->assertInstanceOf(DeferredCryptographicStampSigner::class, app(CryptographicStampSigner::class));
        $this->assertInstanceOf(UnresolvedProductionZatcaCryptographicProfile::class, app(CryptographicProfile::class));
        $this->assertTrue(app(CryptographicProfile::class)->isProductionZatca());
        $this->assertFalse(app(CryptographicStampSigner::class)->isTestOnly());

        $this->expectException(ProductionCryptographicProfileException::class);
        app(QrTag9Provider::class)->artifact();
    }

    #[Test]
    public function retry_reuses_security_identity_and_test_stamp_without_mutating_hash(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-9B-RETRY');
        $security = $this->security($prepared);
        $icv = (int) $security->icv;
        $pih = (string) $security->pih;
        $hash = (string) $security->invoice_hash;

        $cert = $this->import($workspace, (int) $prepared['artifact']->egsUnitId, 'test-only-egs-a.crt.pem');
        $service = $this->stampService(
            'test-only-egs-a.key.pem',
            (string) $cert->fingerprint_sha256,
            TestCryptographicProfile::signedInfoDer(),
        );
        $first = $service->stamp($prepared['document'], $security, (int) $cert->id);
        $retry = $service->stamp($prepared['document'], $security, (int) $cert->id);

        $this->assertSame($first->signatureDerBase64, $retry->signatureDerBase64);
        $this->assertSame('test.signed_info', $first->signedInputIdentifier);
        $this->assertSame($hash, $security->fresh()->invoice_hash);
        $this->assertSame($icv, (int) $security->fresh()->icv);
        $this->assertSame($pih, $security->fresh()->pih);
        $this->assertSame(1, EInvoiceSecurityRecord::withoutGlobalScopes()->where('e_invoice_document_id', $prepared['record']->id)->count());
        $this->assertSame(1, EInvoiceCryptographicStamp::withoutGlobalScopes()->where('e_invoice_document_id', $prepared['record']->id)->count());
        $this->assertSame(StampStatus::TestSigned, $first->status);
        $this->assertFalse($first->isProductionIdentity());
        $this->assertStringStartsWith('TEST_ONLY:', $first->signatureAlgorithm);

        $this->expectException(\RuntimeException::class);
        $security->invoice_hash = 'changed';
        $security->save();
    }

    #[Test]
    public function qr_consumes_phase7_hash_and_test_artifacts_without_mutating_security(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-9B-QR');
        $security = $this->security($prepared);
        $hashBefore = (string) $security->invoice_hash;
        $icvBefore = (int) $security->icv;
        $pihBefore = (string) $security->pih;
        $securityCount = EInvoiceSecurityRecord::withoutGlobalScopes()->count();

        $cert = $this->import($workspace, (int) $prepared['artifact']->egsUnitId, 'test-only-egs-a.crt.pem');
        $parsed = app(CertificateRegistry::class)->parsed($cert);
        $signer = new TestProfileEcdsaSigner(
            $this->pem('test-only-egs-a.key.pem'),
            TestCryptographicProfile::invoiceHashP1363(),
            new CryptographicProfileGuard(treatAsProduction: false),
        );
        $artifact = $signer->signCertificate($prepared['artifact']->invoiceHash, $parsed);
        $this->assertSame($hashBefore, $artifact->signature->invoiceHash->value());

        $phase8 = app(EInvoiceQrService::class)->generateFromSecurityRecord($prepared['document'], $security);
        $this->assertSame(QrPayloadProfile::Phase8Unsigned, $phase8->profile);
        $this->assertSame($hashBefore, $phase8->valueForTag(QrTag::INVOICE_HASH));
        $this->assertSame([1, 2, 3, 4, 5, 6], $phase8->tagOrder());

        $tag9 = TestOnlyExternalCaArtifactProvider::fromHex(
            '3046022100ee61d3eb283ce63b50196a7733bb4f4fb264dbececbd51c6b376d4e59ed813af022100fad1e6d06a662362f75e6e716335fc785f8768a7b2ec101142352b0b63420569',
        )->artifact();

        $harness = (new TestQrCryptographicAssembler)->assemble(
            [
                QrField::of(QrTag::SELLER_NAME, (string) $phase8->valueForTag(QrTag::SELLER_NAME)),
                QrField::of(QrTag::SELLER_VAT, (string) $phase8->valueForTag(QrTag::SELLER_VAT)),
                QrField::of(QrTag::TIMESTAMP, (string) $phase8->valueForTag(QrTag::TIMESTAMP)),
                QrField::of(QrTag::TOTAL_WITH_VAT, (string) $phase8->valueForTag(QrTag::TOTAL_WITH_VAT)),
                QrField::of(QrTag::VAT_TOTAL, (string) $phase8->valueForTag(QrTag::VAT_TOTAL)),
            ],
            $prepared['artifact']->invoiceHash,
            TestQrTag6Representation::RawSha256,
            $artifact,
            TestQrTag8Representation::SpkiDer,
            $tag9,
        );

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $harness->tagOrder());
        $this->assertSame($prepared['artifact']->invoiceHash->binary(), $harness->valueForTag(QrTag::INVOICE_HASH));
        $this->assertSame(64, strlen((string) $harness->valueForTag(QrTag::ECDSA_SIGNATURE)));
        $this->assertSame($parsed->publicKey->spkiDer, $harness->valueForTag(QrTag::ECDSA_PUBLIC_KEY));
        $this->assertSame($tag9->bytes, $harness->valueForTag(QrTag::ZATCA_CA_SIGNATURE));
        $this->assertSame(TestOnlyExternalCaArtifactProvider::MARKER, $tag9->marker);

        $this->assertSame($hashBefore, $security->fresh()->invoice_hash);
        $this->assertSame($icvBefore, (int) $security->fresh()->icv);
        $this->assertSame($pihBefore, $security->fresh()->pih);
        $this->assertSame($securityCount, EInvoiceSecurityRecord::withoutGlobalScopes()->count());
        $this->assertSame($phase8->valueForTag(QrTag::INVOICE_HASH), $hashBefore);
        $this->assertNotSame($phase8->valueForTag(QrTag::INVOICE_HASH), $harness->valueForTag(QrTag::INVOICE_HASH));
    }

    #[Test]
    public function certificate_registry_stores_metadata_not_private_keys_or_csid(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-9B-CERT');
        $cert = $this->import($workspace, (int) $prepared['artifact']->egsUnitId, 'test-only-egs-a.crt.pem');
        $retry = $this->import($workspace, (int) $prepared['artifact']->egsUnitId, 'test-only-egs-a.crt.pem');

        $this->assertSame($cert->id, $retry->id);
        $this->assertTrue((bool) $cert->test_fixture);
        $this->assertNotSame('', $cert->fingerprint_sha256);
        $this->assertNotSame('', $cert->serial_number);
        $this->assertNotSame('', $cert->subject);
        $this->assertNotSame('', $cert->issuer);
        $this->assertNotNull($cert->not_before);
        $this->assertNotNull($cert->not_after);
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) $cert->public_certificate);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $cert->public_certificate);
        $this->assertArrayNotHasKey('private_key', $cert->getAttributes());
        $this->assertFalse(Schema::hasColumn('e_invoice_certificates', 'private_key'));
        $this->assertFalse(Schema::hasColumn('e_invoice_cryptographic_stamps', 'private_key_pem'));
        $this->assertStringNotContainsString('CSID', (string) $cert->status);

        $this->expectException(CertificateException::class);
        $this->import($workspace, (int) $prepared['artifact']->egsUnitId, 'test-only-egs-a.key.pem');
    }

    #[Test]
    public function finalized_stamp_and_security_records_are_immutable(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-9B-IMM');
        $security = $this->security($prepared);
        $cert = $this->import($workspace, (int) $prepared['artifact']->egsUnitId, 'test-only-egs-a.crt.pem');
        $this->stampService('test-only-egs-a.key.pem', (string) $cert->fingerprint_sha256)
            ->stamp($prepared['document'], $security, (int) $cert->id);
        $stamp = EInvoiceCryptographicStamp::withoutGlobalScopes()
            ->where('e_invoice_document_id', $prepared['record']->id)
            ->firstOrFail();

        $profile = (string) $stamp->signed_input_identifier;
        $signature = (string) $stamp->signature_value;

        try {
            $stamp->signature_value = 'mutated';
            $stamp->save();
            $this->fail('Stamp mutation must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $fresh = $stamp->fresh();
        $this->assertSame($signature, $fresh->signature_value);
        $this->assertSame($profile, $fresh->signed_input_identifier);
        $this->assertSame($security->invoice_hash, $fresh->invoice_hash);

        $this->expectException(\RuntimeException::class);
        $security->icv = 999;
        $security->save();
    }

    #[Test]
    public function xades_and_default_stamping_remain_blocked(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-9B-BLOCK');
        $security = $this->security($prepared);
        $cert = $this->import($workspace, (int) $prepared['artifact']->egsUnitId, 'test-only-egs-a.crt.pem');

        try {
            app(EInvoiceCryptographicStampService::class)->stamp($prepared['document'], $security, (int) $cert->id);
            $this->fail('Default container must not stamp.');
        } catch (CryptographicStampException $exception) {
            $this->assertStringContainsString('deferred', $exception->getMessage());
        }

        $this->expectException(CryptographicStampException::class);
        (new XadesEnvelopedSignature)->materialize();
    }

    #[Test]
    public function harness_source_has_no_fatoora_http_or_production_credentials(): void
    {
        $files = array_merge(
            glob(app_path('EInvoicing/Security/Harness/*.php')) ?: [],
            glob(app_path('EInvoicing/QR/Harness/*.php')) ?: [],
        );
        $this->assertNotEmpty($files);
        foreach ($files as $path) {
            $source = strtolower((string) file_get_contents($path));
            $this->assertStringNotContainsString('http::', $source, $path);
            $this->assertStringNotContainsString('guzzle', $source, $path);
            $this->assertStringNotContainsString('curl_', $source, $path);
            $this->assertStringNotContainsString('fatoora.gov', $source, $path);
            $this->assertStringNotContainsString('sandbox.zatca', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/-----begin .*private key-----/', $source);
        }

        foreach (glob(base_path('tests/Fixtures/EInvoicing/*.key.pem')) ?: [] as $key) {
            $this->assertStringContainsString('test-only', basename($key));
        }
    }

    private function stampService(string $keyFile, string $fingerprint, ?TestCryptographicProfile $profile = null): EInvoiceCryptographicStampService
    {
        $signer = $profile === null
            ? new TestCryptographicStampSigner($this->pem($keyFile), $fingerprint)
            : new TestProfileEcdsaSigner(
                $this->pem($keyFile),
                $profile,
                new CryptographicProfileGuard(treatAsProduction: false),
            );

        return new EInvoiceCryptographicStampService(
            $signer,
            app(CertificateRegistry::class),
            app(AuditLogService::class),
        );
    }

    private function import(Workspace $workspace, int $egsUnitId, string $file): EInvoiceCertificate
    {
        return app(CertificateRegistry::class)->importPublicCertificate(
            (int) $workspace->id,
            $egsUnitId,
            $this->pem($file),
            testFixture: str_contains($file, 'crt'),
        );
    }

    private function security(array $prepared): EInvoiceSecurityRecord
    {
        return EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('e_invoice_document_id', $prepared['record']->id)
            ->firstOrFail();
    }

    private function pem(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/EInvoicing/'.$name));
    }

    /**
     * @return array{document: EInvoiceDocument, artifact: EInvoiceSecurityArtifact, record: EInvoiceDocumentRecord}
     */
    private function secure(Workspace $workspace, string $number): array
    {
        app(WorkspaceContext::class)->set($workspace);
        $sourceId = $this->sourceSeq++;
        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'source_type' => IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
            'source_id' => $sourceId,
            'document_number' => $number,
            'issue_date' => '2026-09-01',
            'issued_at' => '2026-09-01 10:15:30',
            'currency' => 'SAR',
            'payload' => [
                'document' => [
                    'type' => 'sales',
                    'number' => $number,
                    'issue_date' => '2026-09-01',
                    'issued_at' => '2026-09-01T10:15:30+03:00',
                    'currency' => 'SAR',
                    'status' => 'issued',
                    'tax_document_subtype' => 'standard',
                ],
                'seller' => [
                    'kind' => 'company',
                    'name' => 'Issued Co',
                    'vat_number' => '310000000000003',
                    'commercial_registration' => '1010000000',
                    'address' => [
                        'building_number' => '1234',
                        'street' => 'King Fahd Road',
                        'district' => 'Al Olaya',
                        'city' => 'Riyadh',
                        'postal_code' => '12345',
                        'country_code' => 'SA',
                    ],
                ],
                'buyer' => [
                    'kind' => 'customer',
                    'name' => 'E-Invoice Buyer',
                    'vat_number' => '300111111111113',
                    'address' => [
                        'street' => 'Buyer Street',
                        'district' => 'Al Balad',
                        'city' => 'Jeddah',
                        'postal_code' => '22222',
                        'country_code' => 'SA',
                    ],
                ],
                'lines' => [[
                    'description' => 'خدمة فوترة',
                    'product_name' => 'خدمة فوترة',
                    'quantity' => '1.000',
                    'unit_price' => '100.00',
                    'discount' => '0.00',
                    'taxable_amount' => '100.00',
                    'tax_profile_type' => 'standard',
                    'tax_rate' => '15.00',
                    'tax_amount' => '15.00',
                    'total' => '115.00',
                ]],
                'tax' => [
                    'profile_type' => 'standard',
                    'rate' => '15.00',
                    'amount' => '15.00',
                    'price_mode' => 'exclusive',
                ],
                'totals' => [
                    'subtotal' => '100.00',
                    'discount' => '0.00',
                    'taxable_amount' => '100.00',
                    'tax_amount' => '15.00',
                    'total' => '115.00',
                    'amount_paid' => '0.00',
                    'amount_due' => '115.00',
                ],
                'payment' => [],
                'metadata' => ['schema_version' => 2],
            ],
        ]);
        $record = app(EInvoiceFactory::class)->persist($snapshot);
        $document = app(EInvoiceFactory::class)->make($snapshot);
        $xml = app(EInvoiceXmlGenerator::class)->generate($document);
        $artifact = app(EInvoiceSecurityService::class)->generate($document, $xml);

        return ['document' => $document, 'artifact' => $artifact, 'record' => $record];
    }

    /**
     * @return array{0: Workspace, 1: User}
     */
    private function createWorkspaceOwner(string $name = 'Phase9B Workspace'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(WorkspaceContext::class)->set($workspace);
        foreach (['finance', 'products', 'customers'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }
        $plan = Plan::query()->where('workspace_type', 'company')->where('is_active', true)->orderByDesc('price')->first();
        if ($plan) {
            Subscription::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        }

        return [$workspace->fresh(), $user];
    }
}
