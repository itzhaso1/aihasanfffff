<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\Security\EInvoiceSecurityArtifact;
use App\EInvoicing\Security\Exceptions\CertificateException;
use App\EInvoicing\Security\Exceptions\CryptographicStampException;
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

class Phase9CryptographicStampFoundationTest extends TestCase
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
    public function egs_isolation_and_certificate_rotation_keep_historical_stamps(): void
    {
        [$workspaceA] = $this->createWorkspaceOwner('Workspace A');
        [$workspaceB] = $this->createWorkspaceOwner('Workspace B');
        $preparedA = $this->secure($workspaceA, 'INV-STAMP-A1');
        $preparedB = $this->secure($workspaceB, 'INV-STAMP-B1');

        $certA = $this->import($workspaceA, (int) $preparedA['artifact']->egsUnitId, 'test-only-egs-a.crt.pem');
        app(WorkspaceContext::class)->set($workspaceB);
        $certB = $this->import($workspaceB, (int) $preparedB['artifact']->egsUnitId, 'test-only-egs-b.crt.pem');

        $this->expectException(CertificateException::class);
        app(CertificateRegistry::class)->requireForEgs(
            (int) $workspaceA->id,
            (int) $preparedA['artifact']->egsUnitId,
            (int) $certB->id,
        );
    }

    #[Test]
    public function rotation_and_retry_preserve_hash_chain_and_certificate_identity(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $first = $this->secure($workspace, 'INV-ROT-1');
        $second = $this->secure($workspace, 'INV-ROT-2');
        $securityFirst = $this->security($first);
        $securitySecond = $this->security($second);

        $certA = $this->import($workspace, (int) $first['artifact']->egsUnitId, 'test-only-egs-a.crt.pem');
        $service = $this->stampService('test-only-egs-a.key.pem', (string) $certA->fingerprint_sha256);
        $stampA = $service->stamp($first['document'], $securityFirst, (int) $certA->id);

        $certB = $this->import($workspace, (int) $first['artifact']->egsUnitId, 'test-only-egs-b.crt.pem');
        $serviceB = $this->stampService('test-only-egs-b.key.pem', (string) $certB->fingerprint_sha256);
        $stampB = $serviceB->stamp($second['document'], $securitySecond, (int) $certB->id);

        $retry = $service->stamp($first['document'], $securityFirst, (int) $certA->id);

        $this->assertSame($stampA->certificateId, (int) $certA->id);
        $this->assertSame($stampB->certificateId, (int) $certB->id);
        $this->assertSame($stampA->signatureDerBase64, $retry->signatureDerBase64);
        $this->assertSame($stampA->certificateFingerprint->value(), $retry->certificateFingerprint->value());
        $this->assertSame($first['artifact']->invoiceHash->value(), $stampA->invoiceHash->value());
        $this->assertSame($first['artifact']->invoiceHash->value(), $securityFirst->fresh()->invoice_hash);
        $this->assertSame($first['artifact']->icv->value(), (int) $securityFirst->fresh()->icv);
        $this->assertSame($first['artifact']->pih->value(), $securityFirst->fresh()->pih);
        $this->assertSame(1, EInvoiceCryptographicStamp::withoutGlobalScopes()->where('e_invoice_document_id', $first['record']->id)->count());
        $this->assertSame(2, EInvoiceCryptographicStamp::withoutGlobalScopes()->count());
        $this->assertNotSame($stampA->certificateId, $stampB->certificateId);

        $this->expectException(CryptographicStampException::class);
        $serviceB->stamp($first['document'], $securityFirst, (int) $certB->id);
    }

    #[Test]
    public function qr_keeps_tags_1_to_6_and_adds_7_8_without_fabricating_tag_9(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-QR-STAMP');
        $security = $this->security($prepared);
        $cert = $this->import($workspace, (int) $prepared['artifact']->egsUnitId, 'test-only-egs-a.crt.pem');
        $stamp = $this->stampService('test-only-egs-a.key.pem', (string) $cert->fingerprint_sha256)
            ->stamp($prepared['document'], $security, (int) $cert->id);

        $unsigned = app(EInvoiceQrService::class)->generateFromSecurityRecord($prepared['document'], $security);
        $signed = app(EInvoiceQrService::class)->generateWithStamp($prepared['document'], $security, $stamp);

        $this->assertSame($unsigned->valueForTag(QrTag::SELLER_NAME), $signed->valueForTag(QrTag::SELLER_NAME));
        $this->assertSame($unsigned->valueForTag(QrTag::SELLER_VAT), $signed->valueForTag(QrTag::SELLER_VAT));
        $this->assertSame($unsigned->valueForTag(QrTag::TIMESTAMP), $signed->valueForTag(QrTag::TIMESTAMP));
        $this->assertSame($unsigned->valueForTag(QrTag::TOTAL_WITH_VAT), $signed->valueForTag(QrTag::TOTAL_WITH_VAT));
        $this->assertSame($unsigned->valueForTag(QrTag::VAT_TOTAL), $signed->valueForTag(QrTag::VAT_TOTAL));
        $this->assertSame($security->invoice_hash, $signed->valueForTag(QrTag::INVOICE_HASH));
        $this->assertSame($stamp->signatureDerBase64, $signed->valueForTag(QrTag::ECDSA_SIGNATURE));
        $this->assertSame($stamp->publicKeySpkiDer, $signed->valueForTag(QrTag::ECDSA_PUBLIC_KEY));
        $this->assertFalse($signed->hasTag(QrTag::ZATCA_CA_SIGNATURE));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $signed->tagOrder());
        $this->assertSame(StampStatus::TestSigned, $stamp->status);
        $this->assertFalse($stamp->isProductionIdentity());
    }

    #[Test]
    public function default_container_signer_is_deferred_and_xades_is_blocked(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-DEFERRED');
        $security = $this->security($prepared);
        $cert = $this->import($workspace, (int) $prepared['artifact']->egsUnitId, 'test-only-egs-a.crt.pem');

        try {
            app(EInvoiceCryptographicStampService::class)->stamp($prepared['document'], $security, (int) $cert->id);
            $this->fail('Default container must not silently stamp.');
        } catch (CryptographicStampException $exception) {
            $this->assertStringContainsString('deferred', $exception->getMessage());
        }

        $this->expectException(CryptographicStampException::class);
        (new XadesEnvelopedSignature)->materialize();
    }

    #[Test]
    public function persistence_has_no_private_key_columns_and_rejects_private_key_import(): void
    {
        foreach (['e_invoice_certificates', 'e_invoice_cryptographic_stamps'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            foreach (['private_key', 'private_key_pem', 'private_key_password', 'secret_key'] as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), $table.'.'.$column);
            }
        }

        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-NO-SECRET');
        $this->expectException(CertificateException::class);
        $this->import($workspace, (int) $prepared['artifact']->egsUnitId, 'test-only-egs-a.key.pem');
    }

    #[Test]
    public function phase9_source_has_no_fatoora_http_or_csid_provisioning(): void
    {
        $files = array_merge(
            glob(app_path('EInvoicing/Security/*.php')) ?: [],
            glob(app_path('Services/EInvoicing/Security/*.php')) ?: [],
            glob(app_path('EInvoicing/QR/*.php')) ?: [],
        );
        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringNotContainsString('Http::', $source, $path);
            $this->assertStringNotContainsString('Guzzle', $source, $path);
            $this->assertStringNotContainsString('curl_', $source, $path);
            $this->assertStringNotContainsString('fatoora.gov', strtolower($source), $path);
            $this->assertDoesNotMatchRegularExpression('/APP_KEY/', $source);
        }
    }

    private function stampService(string $keyFile, string $fingerprint): EInvoiceCryptographicStampService
    {
        return new EInvoiceCryptographicStampService(
            new TestCryptographicStampSigner($this->pem($keyFile), $fingerprint),
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
    private function createWorkspaceOwner(string $name = 'Phase9 Workspace'): array
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
