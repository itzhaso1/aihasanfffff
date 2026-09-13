<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\Security\EInvoiceSecurityArtifact;
use App\EInvoicing\Security\Exceptions\IcvAllocationException;
use App\EInvoicing\Security\Exceptions\InvoiceHashException;
use App\EInvoicing\Security\Exceptions\SecurityChainException;
use App\EInvoicing\Security\Pih;
use App\EInvoicing\Xml\GeneratedEInvoiceXml;
use App\Enums\EInvoicing\ComplianceStatus;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Models\EInvoicing\EgsUnit;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Finance\IssuedDocumentSnapshot;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\EInvoicing\EInvoiceFactory;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use App\Services\EInvoicing\Security\EInvoiceSecurityService;
use App\Services\EInvoicing\Security\SecurityChainDiagnostic;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase7EInvoiceSecurityChainTest extends TestCase
{
    use RefreshDatabase;

    private int $sourceSeq = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(FoundationSeeder::class);
    }

    public function test_first_second_and_third_documents_form_an_official_hash_chain(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $first = $this->secure($workspace, 'INV-1');
        $second = $this->secure($workspace, 'INV-2');
        $third = $this->secure($workspace, 'INV-3');

        $this->assertSame(1, $first['artifact']->icv->value());
        $this->assertSame(2, $second['artifact']->icv->value());
        $this->assertSame(3, $third['artifact']->icv->value());
        $this->assertTrue($first['artifact']->pih->equals(Pih::firstDocument()));
        $this->assertSame($first['artifact']->invoiceHash->value(), $second['artifact']->pih->value());
        $this->assertSame($second['artifact']->invoiceHash->value(), $third['artifact']->pih->value());
        $this->assertNotSame($first['artifact']->invoiceHash->value(), $second['artifact']->invoiceHash->value());
        $this->assertSame(Pih::FIRST_DOCUMENT, $this->xpathValue($first['artifact']->enrichedXml, '//cac:AdditionalDocumentReference[cbc:ID="PIH"]//cbc:EmbeddedDocumentBinaryObject'));
        $this->assertSame('1', $this->xpathValue($first['artifact']->enrichedXml, '//cac:AdditionalDocumentReference[cbc:ID="ICV"]/cbc:UUID'));
        $this->assertStringNotContainsString('QR', $first['artifact']->enrichedXml);
        $this->assertSame(ComplianceStatus::Generated, $first['record']->fresh()->compliance_status);
    }

    public function test_same_document_retry_reuses_icv_pih_and_hash(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-RETRY');
        $again = app(EInvoiceSecurityService::class)->generate($prepared['document'], $prepared['xml']);

        $this->assertTrue($again->reused);
        $this->assertTrue($again->icv->equals($prepared['artifact']->icv));
        $this->assertTrue($again->pih->equals($prepared['artifact']->pih));
        $this->assertTrue($again->invoiceHash->equals($prepared['artifact']->invoiceHash));
        $this->assertSame(1, EInvoiceSecurityRecord::withoutGlobalScopes()->count());
        $this->assertSame(2, (int) EgsUnit::withoutGlobalScopes()->firstOrFail()->next_icv);

        $next = $this->secure($workspace, 'INV-AFTER-RETRY');
        $this->assertSame(2, $next['artifact']->icv->value());
        $this->assertSame($prepared['artifact']->invoiceHash->value(), $next['artifact']->pih->value());
        $this->assertSame(2, EInvoiceSecurityRecord::withoutGlobalScopes()->count());
    }

    public function test_new_document_increments_icv_and_chains_previous_hash(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $first = $this->secure($workspace, 'INV-NEW-1');
        $second = $this->secure($workspace, 'INV-NEW-2');

        $this->assertSame(1, $first['artifact']->icv->value());
        $this->assertSame(2, $second['artifact']->icv->value());
        $this->assertSame($first['artifact']->invoiceHash->value(), $second['artifact']->pih->value());
        $this->assertNotSame($first['artifact']->invoiceHash->value(), $second['artifact']->invoiceHash->value());
        $this->assertNotSame($first['record']->id, $second['record']->id);
    }

    public function test_document_cannot_use_another_workspace_egs_unit(): void
    {
        [$workspaceA] = $this->createWorkspaceOwner('Workspace A isolation');
        [$workspaceB] = $this->createWorkspaceOwner('Workspace B isolation');
        $this->secure($workspaceB, 'B-EGS-SEED');
        $foreignEgsId = (int) EgsUnit::withoutGlobalScopes()
            ->where('workspace_id', $workspaceB->id)
            ->value('id');

        $prepared = $this->persistDocument($workspaceA, 'A-CROSS');
        $xml = app(EInvoiceXmlGenerator::class)->generate($prepared['document']);

        $this->expectException(IcvAllocationException::class);
        $this->expectExceptionMessage('EGS unit does not belong to the document workspace');
        app(EInvoiceSecurityService::class)->generate($prepared['document'], $xml, $foreignEgsId);
    }

    public function test_security_record_is_one_to_one_and_immutable(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-IMM-REC');
        $record = EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('e_invoice_document_id', $prepared['record']->id)
            ->firstOrFail();

        $this->assertSame(1, EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('e_invoice_document_id', $prepared['record']->id)
            ->count());
        $this->assertSame('SHA-256', $record->hash_algorithm);
        $this->assertSame('http://www.w3.org/2006/12/xml-c14n11', $record->canonicalization_method);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Electronic invoice security records are immutable');
        $record->icv = 99;
        $record->pih = 'changed';
        $record->invoice_hash = 'changed';
        $record->hash_algorithm = 'SHA-1';
        $record->canonicalization_method = 'none';
        $record->save();
    }

    public function test_mutated_xml_after_finalization_is_rejected(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-IMMUTABLE');
        $mutatedXml = new GeneratedEInvoiceXml(
            xml: str_replace('INV-IMMUTABLE', 'INV-CHANGED', $prepared['xml']->xml),
            documentKind: $prepared['xml']->documentKind,
            rootLocalName: $prepared['xml']->rootLocalName,
            rootNamespace: $prepared['xml']->rootNamespace,
            workspaceId: $prepared['xml']->workspaceId,
            sourceSnapshotId: $prepared['xml']->sourceSnapshotId,
            sourceType: $prepared['xml']->sourceType,
            sourceId: $prepared['xml']->sourceId,
            documentNumber: $prepared['xml']->documentNumber,
            documentUuid: $prepared['xml']->documentUuid,
            schemaValid: true,
        );

        $this->expectException(SecurityChainException::class);
        $this->expectExceptionMessage('XML changed after security artifacts were finalized');
        app(EInvoiceSecurityService::class)->generate($prepared['document'], $mutatedXml);
    }

    public function test_workspaces_and_egs_units_have_independent_sequences(): void
    {
        [$workspaceA] = $this->createWorkspaceOwner('Workspace A');
        [$workspaceB] = $this->createWorkspaceOwner('Workspace B');

        $a1 = $this->secure($workspaceA, 'A-1');
        $a2 = $this->secure($workspaceA, 'A-2');
        $b1 = $this->secure($workspaceB, 'B-1');

        $this->assertSame(1, $a1['artifact']->icv->value());
        $this->assertSame(2, $a2['artifact']->icv->value());
        $this->assertSame(1, $b1['artifact']->icv->value());
        $this->assertTrue($b1['artifact']->pih->equals(Pih::firstDocument()));
        $this->assertSame($a1['artifact']->invoiceHash->value(), $a2['artifact']->pih->value());
        $this->assertNotSame($a1['artifact']->egsUnitId, $b1['artifact']->egsUnitId);
        $this->assertNotSame($a1['artifact']->workspaceId, $b1['artifact']->workspaceId);

        app(WorkspaceContext::class)->set($workspaceA);
        $secondEgs = EgsUnit::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'uuid' => '11111111-2222-4333-8444-555555555555',
            'name' => 'Second EGS',
            'next_icv' => 1,
            'last_invoice_hash' => null,
            'status' => EgsUnit::STATUS_ACTIVE,
        ]);
        $aOther = $this->secure($workspaceA, 'A-OTHER', egsUnitId: (int) $secondEgs->id);
        $this->assertSame(1, $aOther['artifact']->icv->value());
        $this->assertTrue($aOther['artifact']->pih->equals(Pih::firstDocument()));
        $this->assertSame((int) $secondEgs->id, $aOther['artifact']->egsUnitId);
        $this->assertSame(3, (int) EgsUnit::withoutGlobalScopes()->find($a1['artifact']->egsUnitId)?->next_icv);
    }

    public function test_credit_and_debit_notes_continue_the_same_egs_sequence(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $invoice = $this->secure($workspace, 'INV-NOTE-BASE');
        $credit = $this->secure(
            $workspace,
            'CN-1',
            sourceType: IssuedDocumentSnapshot::SOURCE_FINANCE_CREDIT_NOTE,
            kind: ElectronicDocumentKind::CreditNote,
            originalNumber: 'INV-NOTE-BASE',
        );
        $debit = $this->secure(
            $workspace,
            'DN-1',
            sourceType: IssuedDocumentSnapshot::SOURCE_FINANCE_DEBIT_NOTE,
            kind: ElectronicDocumentKind::DebitNote,
            originalNumber: 'INV-NOTE-BASE',
        );

        $this->assertSame($invoice['artifact']->egsUnitId, $credit['artifact']->egsUnitId);
        $this->assertSame($invoice['artifact']->egsUnitId, $debit['artifact']->egsUnitId);
        $this->assertSame(2, $credit['artifact']->icv->value());
        $this->assertSame(3, $debit['artifact']->icv->value());
        $this->assertSame($invoice['artifact']->invoiceHash->value(), $credit['artifact']->pih->value());
        $this->assertSame($credit['artifact']->invoiceHash->value(), $debit['artifact']->pih->value());
        $this->assertSame('CreditNote', $credit['xml']->rootLocalName);
        $this->assertSame('Invoice', $debit['xml']->rootLocalName);
    }

    public function test_security_generation_does_not_query_live_business_tables(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->persistDocument($workspace, 'INV-ISO');
        $xml = app(EInvoiceXmlGenerator::class)->generate($prepared['document']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(EInvoiceSecurityService::class)->generate($prepared['document'], $xml);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $sql = strtolower((string) ($query['query'] ?? ''));
            foreach ([
                'finance_invoices',
                'finance_invoice_items',
                'customers',
                'products',
                'from "workspaces"',
                'from `workspaces`',
                'finance_settings',
                'from "orders"',
                'from `orders`',
                'order_items',
                'payments',
            ] as $table) {
                $this->assertStringNotContainsString($table, $sql);
            }
        }
    }

    public function test_hash_failure_does_not_consume_icv(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->persistDocument($workspace, 'INV-ROLLBACK');
        $xml = app(EInvoiceXmlGenerator::class)->generate($prepared['document']);
        $refused = new GeneratedEInvoiceXml(
            xml: preg_replace('/<Invoice\b/', '<Invoice xml:lang="ar"', $xml->xml, 1) ?? $xml->xml,
            documentKind: $xml->documentKind,
            rootLocalName: $xml->rootLocalName,
            rootNamespace: $xml->rootNamespace,
            workspaceId: $xml->workspaceId,
            sourceSnapshotId: $xml->sourceSnapshotId,
            sourceType: $xml->sourceType,
            sourceId: $xml->sourceId,
            documentNumber: $xml->documentNumber,
            documentUuid: $xml->documentUuid,
            schemaValid: $xml->schemaValid,
        );

        try {
            app(EInvoiceSecurityService::class)->generate($prepared['document'], $refused);
            $this->fail('Hash failure was swallowed.');
        } catch (InvoiceHashException) {
        }

        $this->assertSame(0, EInvoiceSecurityRecord::withoutGlobalScopes()->count());
        $this->assertSame(1, (int) EgsUnit::withoutGlobalScopes()->value('next_icv'));
    }

    public function test_icv_uniqueness_and_sequential_allocation(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $icvs = [];
        for ($i = 1; $i <= 12; $i++) {
            $icvs[] = $this->secure($workspace, 'INV-SEQ-'.$i)['artifact']->icv->value();
        }

        $this->assertSame(range(1, 12), $icvs);
        $this->assertCount(12, array_unique($icvs));

        $first = EInvoiceSecurityRecord::withoutGlobalScopes()->orderBy('icv')->firstOrFail();
        $this->expectException(UniqueConstraintViolationException::class);
        EInvoiceSecurityRecord::withoutGlobalScopes()->create([
            'workspace_id' => $first->workspace_id,
            'egs_unit_id' => $first->egs_unit_id,
            'e_invoice_document_id' => $this->persistDocument($workspace, 'INV-DUP')['record']->id,
            'icv' => $first->icv,
            'pih' => Pih::FIRST_DOCUMENT,
            'invoice_hash' => base64_encode(str_repeat("\x02", 32)),
            'hash_algorithm' => 'SHA-256',
            'canonicalization_method' => 'http://www.w3.org/2006/12/xml-c14n11',
            'source_xml_digest' => str_repeat('B', 44),
            'security_status' => 'generated',
            'finalized_at' => now(),
        ]);
    }

    public function test_concurrent_workers_do_not_duplicate_icv(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');
        $canFork = function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && $database !== ':memory:'
            && in_array($connection, ['mysql', 'pgsql'], true);

        if (! $canFork) {
            $this->markTestSkipped(
                'Genuine multi-writer ICV concurrency requires mysql or pgsql, not sqlite :memory:. Sequential uniqueness is covered by test_icv_uniqueness_and_sequential_allocation. Use TEST_DB_CONNECTION=mysql|pgsql with Phase7EgsConcurrencyIntegrationTest.'
            );
        }

        [$workspace] = $this->createWorkspaceOwner();
        $prepared = [];
        for ($i = 0; $i < 6; $i++) {
            $prepared[] = $this->persistDocument($workspace, 'INV-FORK-'.$i);
        }

        $pids = [];
        for ($i = 0; $i < 6; $i++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge();
                DB::reconnect();
                app(WorkspaceContext::class)->set($workspace);
                $xml = app(EInvoiceXmlGenerator::class)->generate($prepared[$i]['document']);
                app(EInvoiceSecurityService::class)->generate($prepared[$i]['document'], $xml);
                exit(0);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $icvs = EInvoiceSecurityRecord::withoutGlobalScopes()
            ->orderBy('icv')
            ->pluck('icv')
            ->map(fn ($v) => (int) $v)
            ->all();
        $this->assertSame(range(1, 6), $icvs);
    }

    public function test_read_only_diagnostic_does_not_rewrite_history(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $first = $this->secure($workspace, 'INV-DIAG-1');
        $this->secure($workspace, 'INV-DIAG-2');
        $described = app(SecurityChainDiagnostic::class)->describe($first['artifact']->egsUnitId);

        $this->assertSame(3, $described['next_icv']);
        $this->assertCount(2, $described['documents']);
        $this->assertSame(1, $described['documents'][0]['icv']);
        $this->assertSame($first['artifact']->invoiceHash->value(), $described['documents'][1]['pih']);
        $this->assertSame(2, EInvoiceSecurityRecord::withoutGlobalScopes()->count());
    }

    public function test_audit_records_generation_reuse_and_failure_without_secrets(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $prepared = $this->secure($workspace, 'INV-AUDIT');
        app(EInvoiceSecurityService::class)->generate($prepared['document'], $prepared['xml']);

        try {
            $mutated = new GeneratedEInvoiceXml(
                xml: $prepared['xml']->xml.' ',
                documentKind: $prepared['xml']->documentKind,
                rootLocalName: $prepared['xml']->rootLocalName,
                rootNamespace: $prepared['xml']->rootNamespace,
                workspaceId: $prepared['xml']->workspaceId,
                sourceSnapshotId: $prepared['xml']->sourceSnapshotId,
                sourceType: $prepared['xml']->sourceType,
                sourceId: $prepared['xml']->sourceId,
                documentNumber: $prepared['xml']->documentNumber,
                documentUuid: $prepared['xml']->documentUuid,
                schemaValid: true,
            );
            app(EInvoiceSecurityService::class)->generate($prepared['document'], $mutated);
            $this->fail('Mutated XML was accepted.');
        } catch (SecurityChainException) {
        }

        $actions = DB::table('audit_logs')->pluck('action')->all();
        $this->assertContains('e_invoice.security.generated', $actions);
        $this->assertContains('e_invoice.security.reused', $actions);
        $this->assertContains('e_invoice.security.failed', $actions);

        foreach (DB::table('audit_logs')->get() as $row) {
            $payload = json_encode($row);
            $this->assertStringNotContainsString('BEGIN PRIVATE KEY', (string) $payload);
            $this->assertStringNotContainsString('password', strtolower((string) $payload));
        }
    }

    public function test_icv_is_not_database_id_or_invoice_number(): void
    {
        [$workspace] = $this->createWorkspaceOwner();
        $this->persistDocument($workspace, 'INV-PLACEHOLDER');
        $prepared = $this->secure($workspace, 'INV-9999');
        $this->assertSame(1, $prepared['artifact']->icv->value());
        $this->assertNotSame(9999, $prepared['artifact']->icv->value());
        $this->assertNotSame((int) $prepared['document']->sourceId, $prepared['artifact']->icv->value());
        $this->assertGreaterThan(1, (int) $prepared['record']->id);
        $this->assertNotSame((int) $prepared['record']->id, $prepared['artifact']->icv->value());
        $this->assertNotSame($prepared['xml']->documentUuid, (string) $prepared['artifact']->icv);
        $this->assertNotSame($prepared['xml']->documentUuid, $prepared['artifact']->invoiceHash->value());
    }

    /**
     * @return array{document: EInvoiceDocument, xml: GeneratedEInvoiceXml, artifact: EInvoiceSecurityArtifact, record: EInvoiceDocumentRecord}
     */
    private function secure(
        Workspace $workspace,
        string $number,
        string $sourceType = IssuedDocumentSnapshot::SOURCE_FINANCE_INVOICE,
        ElectronicDocumentKind $kind = ElectronicDocumentKind::TaxInvoice,
        ?string $originalNumber = null,
        ?int $egsUnitId = null,
    ): array {
        $prepared = $this->persistDocument($workspace, $number, $sourceType, $kind, $originalNumber);
        $xml = app(EInvoiceXmlGenerator::class)->generate($prepared['document']);
        $artifact = app(EInvoiceSecurityService::class)->generate($prepared['document'], $xml, $egsUnitId);

        return [
            'document' => $prepared['document'],
            'xml' => $xml,
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
        ?string $originalNumber = null,
    ): array {
        app(WorkspaceContext::class)->set($workspace);
        $sourceId = $this->sourceSeq++;
        $subtype = 'standard';
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
                    'tax_document_subtype' => $subtype,
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
                'reference' => $originalNumber === null ? null : [
                    'invoice_id' => 1,
                    'invoice_number' => $originalNumber,
                    'invoice_issue_date' => '2026-09-01',
                    'invoice_type' => 'sales',
                ],
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
    private function createWorkspaceOwner(string $name = 'Phase7 Workspace'): array
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

    private function xpathValue(string $xml, string $query): string
    {
        $document = new \DOMDocument;
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return trim((string) $xpath->evaluate('string('.$query.')'));
    }
}
