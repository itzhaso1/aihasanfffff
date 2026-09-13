import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/features/shared/document_lines_editor.dart';

void main() {
  test('InvoiceRecord retains rich nested Finance payload', () {
    final invoice = InvoiceRecord.fromJson(_richInvoice);
    expect(invoice.invoiceNumber, 'PARITY INV 001');
    expect(invoice.discount, '10.00');
    expect(invoice.taxableAmount, '190.00');
    expect(invoice.taxAmount, '28.50');
    expect(invoice.total, '218.50');
    expect(invoice.amountPaid, '50.00');
    expect(invoice.amountDue, '168.50');
    expect(invoice.amountCredited, '0.00');
    expect(invoice.paymentTerms, '14 يوم');
    expect(invoice.notes, 'ملاحظة عربية');
    expect(invoice.hasZatcaQr, isTrue);
    expect(invoice.zatcaRequirement, 'simplified');
    expect(invoice.zatcaXmlAvailable, isTrue);
    expect(invoice.supplyDate, '2026-09-10');
    expect(invoice.issuedAt, isNotNull);
    expect(invoice.contractNumber, 'C-9');
    expect(invoice.projectName, 'مشروع');
    expect(invoice.taxBreakdown, isNotEmpty);
    expect(invoice.journalEntries, isNotEmpty);
    expect(invoice.lines.first.exemptionCode, 'VATEX-SA-32');
    expect(invoice.lines, hasLength(2));
    expect(invoice.lines.first.unit, 'ساعة');
    expect(invoice.lines.first.discount, '10.00');
    expect(invoice.lines.first.taxRate, '15.00');
    expect(invoice.lines.first.taxableAmount, '90.00');
    expect(invoice.payments.single.treasuryAccountName, 'الصندوق');
    expect(invoice.payments.single.notes, 'دفعة جزئية');
    expect(invoice.creditNotes.single.noteNumber, 'CN-1');
    expect(invoice.receipts.single.method, 'cash');
    expect(invoice.companySnapshot?['vat_number'], '300000000000003');
  });

  test('CustomerRecord retains Saudi address and WhatsApp', () {
    final customer = CustomerRecord.fromJson(_richCustomer);
    expect(customer.name, 'PARITY CUSTOMER 001');
    expect(customer.whatsapp, '0501112233');
    expect(customer.buildingNumber, '1234');
    expect(customer.district, 'العليا');
    expect(customer.postalCode, '12345');
    expect(customer.additionalNumber, '5678');
    expect(customer.paymentTerms, 'صافي 14');
    expect(customer.vatNumber, '300111111111113');
    expect(customer.outstandingBalance, '168.50');
  });

  test('QuoteRecord retains discount, terms, and rejection reason', () {
    final quote = QuoteRecord.fromJson({
      'id': 5,
      'quote_number': 'Q-5',
      'subtotal': '100.00',
      'discount': '5.00',
      'taxable_amount': '95.00',
      'tax_amount': '14.25',
      'total': '109.25',
      'terms': 'صالح 30 يوماً',
      'rejection_reason': null,
      'notes': 'عرض تجريبي',
      'attachments': [
        {'id': 2, 'file_name': 'quote-scan.pdf'},
      ],
      'document_status': 'issued',
      'outcome': 'pending',
      'lines': [
        {
          'product_name': 'استشارة',
          'unit': 'ساعة',
          'quantity': '2',
          'unit_price': '50.00',
          'discount': '5.00',
          'tax_rate': '15.00',
          'tax_amount': '14.25',
          'total': '109.25',
        },
      ],
    });
    expect(quote.discount, '5.00');
    expect(quote.taxableAmount, '95.00');
    expect(quote.terms, 'صالح 30 يوماً');
    expect(quote.attachments.single['file_name'], 'quote-scan.pdf');
    expect(quote.lines.single.unit, 'ساعة');
  });

  test('LineDraft payload omits client totals and includes product and tax fields', () {
    final line = LineDraft(
      description: 'استشارة',
      quantity: '2',
      unitPrice: '50',
      taxRate: '15',
      discount: '5',
      exemptionReason: 'صادرات',
      exemptionCode: 'VATEX-SA-32',
    )..productId = 41
     ..taxProfileType = 'standard';
    final payload = line.toPayload();
    expect(payload.containsKey('total'), isFalse);
    expect(payload.containsKey('tax_amount'), isFalse);
    expect(payload.containsKey('taxable_amount'), isFalse);
    expect(payload['product_id'], 41);
    expect(payload['tax_profile_type'], 'standard');
    expect(payload['exemption_reason'], 'صادرات');
    expect(payload['exemption_code'], 'VATEX-SA-32');
    line.dispose();
  });

  test('Product and project presenters parse Finance catalog payloads', () {
    final product = ProductRecord.fromJson({
      'id': 9,
      'name': 'Widget',
      'sku': 'W-1',
      'price': '50.00',
      'sold_total': '200.00',
      'stock': 12,
    });
    expect(product.sku, 'W-1');
    expect(product.soldTotal, '200.00');
    final project = ProjectRecord.fromJson({
      'id': 3,
      'name': 'Launch',
      'budget': '1000.00',
      'revenue': '400.00',
      'costs': '100.00',
      'profit': '300.00',
    });
    expect(project.profit, '300.00');
  });

  test('StatementRecord retains debit, credit, description, and invoice_id', () {
    final statement = StatementRecord.fromJson({
      'customer': {'id': 7, 'name': 'PARITY CUSTOMER 001'},
      'from': '2026-09-01',
      'to': '2026-09-30',
      'opening_balance': '0.00',
      'closing_balance': '65.00',
      'invoices_total': '115.00',
      'payments_total': '50.00',
      'credits_total': '0.00',
      'debits_total': '0.00',
      'lines': [
        {
          'date': '2026-09-10',
          'kind': 'invoice',
          'reference': 'PARITY INV 001',
          'description': 'فاتورة مبيعات',
          'debit': '115.00',
          'credit': '0.00',
          'balance': '115.00',
          'invoice_id': 11,
        },
      ],
    });
    expect(statement.invoicesTotal, '115.00');
    expect(statement.paymentsTotal, '50.00');
    expect(statement.lines.single['debit'], '115.00');
    expect(statement.lines.single['credit'], '0.00');
    expect(statement.lines.single['description'], 'فاتورة مبيعات');
    expect(statement.lines.single['invoice_id'], 11);
  });

  test('NoteRecord retains lines and subtotal', () {
    final note = NoteRecord.fromJson({
      'id': 4,
      'note_number': 'CN-1',
      'type': 'credit',
      'status': 'issued',
      'invoice_id': 11,
      'reason': 'خصم جودة',
      'subtotal': '10.00',
      'tax_amount': '1.50',
      'total': '11.50',
      'lines': [
        {
          'product_name': 'خصم',
          'quantity': '1',
          'unit_price': '10.00',
          'tax_rate': '15.00',
          'total': '11.50',
        },
      ],
    });
    expect(note.invoiceId, 11);
    expect(note.subtotal, '10.00');
    expect(note.lines, hasLength(1));
  });

  test('ContractRecord retains terms, items, and schedule amount', () {
    final contract = ContractRecord.fromJson({
      'id': 8,
      'title': 'عقد صيانة',
      'terms': 'شروط عربية',
      'notes': 'ملاحظات',
      'value': '1200.00',
      'currency': 'SAR',
      'items': [
        {'id': 1, 'title': 'صيانة شهرية', 'quantity': '1', 'unit_price': '100.00', 'total': '100.00'},
      ],
      'billing_schedules': [
        {
          'id': 2,
          'title': 'شهري',
          'frequency': 'monthly',
          'status': 'active',
          'next_run_on': '2026-10-01',
          'amount': '100.00',
          'auto_issue': true,
          'generated_count': 1,
        },
      ],
      'billing_summary': {'invoiced_total': '100.00', 'paid_total': '0.00', 'outstanding': '100.00'},
      'generated_invoices': [
        {'id': 11, 'invoice_number': 'INV-11', 'total': '100.00'},
      ],
      'attachments': [
        {'id': 4, 'file_name': 'scan.pdf', 'file_type': 'application/pdf', 'file_size': 1200},
      ],
    });
    expect(contract.terms, 'شروط عربية');
    expect(contract.items.single.title, 'صيانة شهرية');
    expect(contract.scheduleRecords.single.amount, '100.00');
    expect(contract.scheduleRecords.single.nextRunOn, '2026-10-01');
    expect(contract.billingSummary['outstanding'], '100.00');
    expect(contract.generatedInvoices.single.invoiceNumber, 'INV-11');
    expect(contract.attachments.single['file_name'], 'scan.pdf');
  });

  test('ExpenseRecord and PaymentRecord retain treasury and method fields', () {
    final expense = ExpenseRecord.fromJson({
      'id': 9,
      'description': 'إيجار',
      'supplier_name': 'مورد',
      'amount': '1000.00',
      'tax_rate': '15.00',
      'tax_amount': '150.00',
      'total': '1150.00',
      'payment_method': 'bank_transfer',
      'treasury_account_name': 'البنك',
      'is_recurring': true,
      'recurring_frequency': 'monthly',
      'next_due_date': '2026-10-01',
    });
    expect(expense.supplierName, 'مورد');
    expect(expense.paymentMethod, 'bank_transfer');
    expect(expense.treasuryAccountName, 'البنك');
    expect(expense.isRecurring, isTrue);
    expect(expense.recurringFrequency, 'monthly');

    final payment = PaymentRecord.fromJson({
      'id': 9,
      'amount': '50.00',
      'notes': 'جزئي',
      'treasury_account_name': 'الصندوق',
      'reversed_at': null,
      'customer_id': 7,
    });
    expect(payment.notes, 'جزئي');
    expect(payment.treasuryAccountName, 'الصندوق');
    expect(payment.customerId, 7);
  });

  test('DashboardData retains extra cards and recent expenses without recomputing', () {
    final data = DashboardData.fromJson({
      'cards': {
        'purchases': '80.00',
        'output_vat': '15.00',
        'cash_balance': '200.00',
        'net_profit': '35.00',
      },
      'recent_invoices': [],
      'recent_payments': [],
      'recent_expenses': [
        {'id': 9, 'description': 'إيجار', 'total': '1150.00'},
      ],
      'overdue_invoices': [
        {'id': 11, 'invoice_number': 'INV-11', 'amount_due': '115.00'},
      ],
    });
    expect(data.cards['net_profit'], '35.00');
    expect(data.recentExpenses.single.description, 'إيجار');
    expect(data.overdueInvoices.single.invoiceNumber, 'INV-11');
  });

  test('SupplierRecord retains CR, address, and opening balance', () {
    final supplier = SupplierRecord.fromJson({
      'id': 3,
      'name': 'مورد',
      'arabic_name': 'المورد',
      'commercial_registration': '1010000000',
      'address': 'الرياض',
      'payment_terms': '30 يوم',
      'opening_balance': '25.00',
    });
    expect(supplier.commercialRegistration, '1010000000');
    expect(supplier.openingBalance, '25.00');
    expect(supplier.arabicName, 'المورد');
  });
}

const _richCustomer = {
  'id': 7,
  'name': 'PARITY CUSTOMER 001',
  'party_type': 'company',
  'email': 'parity@example.com',
  'phone': '0500000000',
  'whatsapp': '0501112233',
  'vat_number': '300111111111113',
  'commercial_registration': '1010123456',
  'address': 'الرياض',
  'building_number': '1234',
  'street': 'طريق الملك',
  'district': 'العليا',
  'city': 'الرياض',
  'postal_code': '12345',
  'country_code': 'SA',
  'additional_number': '5678',
  'payment_terms': 'صافي 14',
  'notes': 'عميل تدقيق',
  'outstanding_balance': '168.50',
};

const _richInvoice = {
  'id': 11,
  'invoice_number': 'PARITY INV 001',
  'customer_id': 7,
  'customer_name': 'PARITY CUSTOMER 001',
  'issue_date': '2026-09-10',
  'due_date': '2026-09-24',
  'supply_date': '2026-09-10',
  'issued_at': '2026-09-10T10:00:00+03:00',
  'currency': 'SAR',
  'subtotal': '200.00',
  'discount': '10.00',
  'taxable_amount': '190.00',
  'tax_amount': '28.50',
  'total': '218.50',
  'amount_paid': '50.00',
  'amount_due': '168.50',
  'amount_credited': '0.00',
  'amount_debited': '0.00',
  'document_status': 'issued',
  'payment_status': 'partial',
  'notes': 'ملاحظة عربية',
  'payment_terms': '14 يوم',
  'contract_number': 'C-9',
  'project_name': 'مشروع',
  'tax_breakdown': [
    {'code': 'S', 'tax_amount': '28.50'},
  ],
  'journal_entries': [
    {
      'entry_number': 'JE-1',
      'type': 'invoice',
      'status': 'posted',
      'lines': [
        {'debit': '218.50', 'credit': '0.00'},
        {'debit': '0.00', 'credit': '218.50'},
      ],
    },
  ],
  'zatca': {'requirement': 'simplified', 'has_qr': true, 'xml_available': true, 'qr_available': true, 'clearance': false, 'integration': 'foundation'},
  'company_snapshot': {'name': 'شركة حاسم', 'vat_number': '300000000000003'},
  'lines': [
    {
      'id': 1,
      'product_name': 'استشارة',
      'description': 'ساعة استشارة',
      'unit': 'ساعة',
      'quantity': '1',
      'unit_price': '100.00',
      'discount': '10.00',
      'tax_rate': '15.00',
      'tax_amount': '13.50',
      'taxable_amount': '90.00',
      'exemption_reason': 'صادرات',
      'exemption_code': 'VATEX-SA-32',
      'total': '103.50',
    },
    {
      'id': 2,
      'product_name': 'تنفيذ',
      'unit': 'بند',
      'quantity': '1',
      'unit_price': '100.00',
      'discount': '0.00',
      'tax_rate': '15.00',
      'taxable_amount': '100.00',
      'tax_amount': '15.00',
      'total': '115.00',
    },
  ],
  'payments': [
    {
      'id': 9,
      'amount': '50.00',
      'method': 'cash',
      'status': 'posted',
      'notes': 'دفعة جزئية',
      'treasury_account_name': 'الصندوق',
    },
  ],
  'receipts': [
    {'id': 3, 'receipt_number': 'R-3', 'amount': '50.00', 'method': 'cash', 'status': 'posted'},
  ],
  'credit_notes': [
    {'id': 4, 'note_number': 'CN-1', 'type': 'credit', 'status': 'issued', 'total': '0.00'},
  ],
};
