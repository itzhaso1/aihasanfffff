<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\QR\QrPayloadProfile;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\Security\EInvoiceSecurityArtifact;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\EInvoicing\EInvoiceFactory;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\EInvoicing\QR\EInvoiceQrService;
use App\Services\EInvoicing\Security\EInvoiceSecurityService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Phase8ZatcaQrFoundationTest extends TestCase
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
    public function qr_tags_come_from_snapshot_and_persisted_phase7_hash(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-QR-1');
        $security = EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('e_invoice_document_id', $prepared['record']->id)
            ->firstOrFail();

        $payload = app(EInvoiceQrService::class)->generateFromSecurityRecord(
            $prepared['document'],
            $security,
        );

        $this->assertSame('Issued Co', $payload->valueForTag(QrTag::SELLER_NAME));
        $this->assertSame('310000000000003', $payload->valueForTag(QrTag::SELLER_VAT));
        $this->assertSame('2026-09-01T10:15:30', $payload->valueForTag(QrTag::TIMESTAMP));
        $this->assertSame('115.00', $payload->valueForTag(QrTag::TOTAL_WITH_VAT));
        $this->assertSame('15.00', $payload->valueForTag(QrTag::VAT_TOTAL));
        $this->assertSame($security->invoice_hash, $payload->valueForTag(QrTag::INVOICE_HASH));
        $this->assertSame($prepared['artifact']->invoiceHash->value(), $payload->valueForTag(QrTag::INVOICE_HASH));
        $this->assertSame(QrPayloadProfile::Phase8Unsigned, $payload->profile);
        $this->assertSame([1, 2, 3, 4, 5, 6], $payload->tagOrder());
        $this->assertFalse($payload->includesCryptographicTags());
    }

    #[Test]
    public function qr_generation_is_idempotent_and_does_not_persist_a_mutable_qr_row(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-QR-IDEM');
        $security = EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('e_invoice_document_id', $prepared['record']->id)
            ->firstOrFail();

        $first = app(EInvoiceQrService::class)->generateFromSecurityRecord($prepared['document'], $security);
        $second = app(EInvoiceQrService::class)->generateFromSecurityRecord($prepared['document'], $security);

        $this->assertSame($first->tlvBytes, $second->tlvBytes);
        $this->assertSame($first->base64, $second->base64);
        $this->assertSame($first->valueForTag(QrTag::INVOICE_HASH), $second->valueForTag(QrTag::INVOICE_HASH));
        $this->assertSame($security->invoice_hash, $first->valueForTag(QrTag::INVOICE_HASH));
        $this->assertSame(1, EInvoiceSecurityRecord::withoutGlobalScopes()->count());
        $this->assertFalse(Schema::hasTable('e_invoice_qr_records'));
        $this->assertFalse(Schema::hasColumn('e_invoice_security_records', 'qr_payload'));
        $this->assertFalse(Schema::hasColumn('e_invoice_security_records', 'qr_tlv'));
        $this->assertSame($security->invoice_hash, $security->fresh()->invoice_hash);
        $this->assertSame($security->icv, $security->fresh()->icv);
        $this->assertSame($security->pih, $security->fresh()->pih);
    }

    #[Test]
    public function historical_qr_uses_snapshot_seller_after_live_company_data_changes(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-QR-HIST');
        $snapshotId = $prepared['document']->sourceSnapshotId;

        $workspace->update(['name' => 'Mutated Live Company']);
        FinanceSetting::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'workspace_id' => $workspace->id,
                'company_name' => 'Mutated Live Company',
                'vat_number' => '399999999999993',
            ],
        );

        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()->findOrFail($snapshotId);
        $historical = app(EInvoiceFactory::class)->make($snapshot);
        $security = EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('e_invoice_document_id', $prepared['record']->id)
            ->firstOrFail();

        $payload = app(EInvoiceQrService::class)->generateFromSecurityRecord($historical, $security);

        $this->assertSame('Issued Co', $historical->seller->name);
        $this->assertSame('310000000000003', $historical->seller->vatNumber);
        $this->assertSame('Issued Co', $payload->valueForTag(QrTag::SELLER_NAME));
        $this->assertSame('310000000000003', $payload->valueForTag(QrTag::SELLER_VAT));
        $this->assertNotSame('Mutated Live Company', $payload->valueForTag(QrTag::SELLER_NAME));
        $this->assertNotSame('399999999999993', $payload->valueForTag(QrTag::SELLER_VAT));
        $this->assertSame('Mutated Live Company', $workspace->fresh()->name);
    }

    #[Test]
    public function qr_layer_source_has_no_tax_engine_http_or_signing_integration(): void
    {
        $files = array_merge(
            glob(app_path('EInvoicing/QR/*.php')) ?: [],
            glob(app_path('Services/EInvoicing/QR/*.php')) ?: [],
        );
        $this->assertNotEmpty($files);

        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringNotContainsString('TaxCalculationService', $source, $path);
            $this->assertStringNotContainsString('PosTaxCalculator', $source, $path);
            $this->assertStringNotContainsString('InvoiceHashService', $source, $path);
            $this->assertStringNotContainsString('Http::', $source, $path);
            $this->assertStringNotContainsString('Guzzle', $source, $path);
            $this->assertStringNotContainsString('curl_', $source, $path);
            $this->assertStringNotContainsString('FATOORA', $source, $path);
            $this->assertStringNotContainsString('clearance', strtolower($source), $path);
            $this->assertStringNotContainsString('openssl_sign', $source, $path);
            $this->assertStringNotContainsString('XAdES', $source, $path);
        }
    }

    /**
     * @return array{document: EInvoiceDocument, artifact: EInvoiceSecurityArtifact, record: EInvoiceDocumentRecord}
     */
    private function secure(
        Workspace $workspace,
        string $number,
        string $sourceType = IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
        ElectronicDocumentKind $kind = ElectronicDocumentKind::TaxInvoice,
    ): array {
        $prepared = $this->persistDocument($workspace, $number, $sourceType, $kind);
        $xml = app(EInvoiceXmlGenerator::class)->generate($prepared['document']);
        $artifact = app(EInvoiceSecurityService::class)->generate($prepared['document'], $xml);

        return [
            'document' => $prepared['document'],
            'artifact' => $artifact,
            'record' => $prepared['record'],
        ];
    }

    /**
     * @return array{document: EInvoiceDocument, record: EInvoiceDocumentRecord}
     */
    private function persistDocument(
        Workspace $workspace,
        string $number,
        string $sourceType = IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
        ElectronicDocumentKind $kind = ElectronicDocumentKind::TaxInvoice,
    ): array {
        app(WorkspaceContext::class)->set($workspace);
        $sourceId = $this->sourceSeq++;
        $snapshot = IssuedDocumentSnapshot::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'source_type' => $sourceType,
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
                    'reason' => $kind->isCredit() || $kind->isDebit() ? 'تعديل' : null,
                ],
                'seller' => [
                    'kind' => 'company',
                    'name' => 'Issued Co',
                    'vat_number' => '310000000000003',
                    'commercial_registration' => '1010000000',
                    'phone' => '0111111111',
                    'email' => 'seller@example.com',
                    'address' => [
                        'building_number' => '1234',
                        'street' => 'King Fahd Road',
                        'district' => 'Al Olaya',
                        'city' => 'Riyadh',
                        'postal_code' => '12345',
                        'country_code' => 'SA',
                        'additional_number' => '5678',
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
                'reference' => null,
                'metadata' => ['schema_version' => 2],
            ],
        ]);

        $record = app(EInvoiceFactory::class)->persist($snapshot);
        $document = app(EInvoiceFactory::class)->make($snapshot);

        return ['document' => $document, 'record' => $record];
    }

    /**
     * @return array{0: Workspace, 1: User}
     */
    private function createWorkspaceOwner(string $name = 'Phase8 Workspace'): array
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
