import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/network/api_response.dart';
import 'package:hasim_finance/core/permissions/finance_permissions.dart';

void main() {
  test('InvoiceRecord keeps document, payment, and delivery statuses separate', () {
    final invoice = InvoiceRecord.fromJson({
      'id': 11,
      'invoice_number': 'INV-11',
      'customer_name': 'عميل',
      'subtotal': '100.00',
      'tax_amount': '15.00',
      'total': '115.00',
      'amount_paid': '0.00',
      'amount_due': '115.00',
      'document_status': 'issued',
      'payment_status': 'unpaid',
      'delivery_status': 'sent',
      'lines': [
        {'product_name': 'خدمة', 'quantity': '1', 'unit_price': '100.00', 'total': '115.00'},
      ],
    });

    expect(invoice.documentStatus, 'issued');
    expect(invoice.paymentStatus, 'unpaid');
    expect(invoice.deliveryStatus, 'sent');
    expect(invoice.total, '115.00');
    expect(invoice.lines, hasLength(1));
  });

  test('QuoteRecord keeps document status and commercial outcome separate', () {
    final quote = QuoteRecord.fromJson({
      'id': 4,
      'quote_number': 'Q-4',
      'document_status': 'issued',
      'outcome': 'accepted',
      'delivery_status': 'failed',
      'total': '115.00',
    });

    expect(quote.documentStatus, 'issued');
    expect(quote.outcome, 'accepted');
    expect(quote.deliveryStatus, 'failed');
  });

  test('CustomerRecord uses server outstanding_balance, not a local ledger', () {
    final customer = CustomerRecord.fromJson({
      'id': 2,
      'name': 'شركة',
      'outstanding_balance': '250.50',
      'balance': '999.00',
    });
    expect(customer.outstandingBalance, '250.50');
  });

  test('CheckoutInfo never implies paid from a URL', () {
    final info = CheckoutInfo.fromJson({
      'supported': true,
      'checkout_url': 'https://pay.example/checkout',
      'message': 'إنشاء الرابط لا يعني أن الفاتورة دُفعت.',
    });
    expect(info.hasUrl, isTrue);
    expect(info.supported, isTrue);
  });

  test('ApiResponse reads pagination meta from the Laravel envelope', () {
    final res = ApiResponse.fromJson({
      'success': true,
      'data': [
        {'id': 1},
      ],
      'meta': {'current_page': 2, 'last_page': 5, 'total': 99},
    }, null);
    expect(res.currentPage, 2);
    expect(res.lastPage, 5);
    expect(res.total, 99);
  });

  test('ApiException classifies HTTP statuses used by Finance', () {
    expect(ApiException('x', statusCode: 401).isUnauthorized, isTrue);
    expect(ApiException('x', statusCode: 403).isForbidden, isTrue);
    expect(ApiException('x', statusCode: 404).isNotFound, isTrue);
    expect(ApiException('x', statusCode: 409).isConflict, isTrue);
    expect(ApiException('x', statusCode: 422).isValidation, isTrue);
    expect(ApiException('x', statusCode: 429).isRateLimited, isTrue);
  });

  test('FinancePermissions gate screens from the server map', () {
    const allowed = FinancePermissions({
      'invoices.view': true,
      'payments.manage': true,
      'quotes.create': false,
    });
    expect(allowed.invoicesView, isTrue);
    expect(allowed.paymentsManage, isTrue);
    expect(allowed.quotesCreate, isFalse);

    const denied = FinancePermissions({});
    expect(denied.invoicesView, isFalse);
    expect(denied.customersView, isFalse);
  });

  test('DashboardData maps server cards without recomputing totals', () {
    final data = DashboardData.fromJson({
      'cards': {
        'outstanding_customer_balance': '115.00',
        'sales': '115.00',
      },
      'recent_invoices': [
        {'id': 1, 'invoice_number': 'INV-1', 'total': '115.00', 'document_status': 'issued', 'payment_status': 'unpaid'},
      ],
      'recent_payments': [],
    });
    expect(data.cards['outstanding_customer_balance'], '115.00');
    expect(data.recentInvoices.single.invoiceNumber, 'INV-1');
  });

  test('LineItem payload omits client-calculated totals', () {
    const line = LineItem(
      productName: 'خدمة',
      quantity: '2',
      unitPrice: '50.00',
      taxRate: '15',
      total: '115.00',
    );
    expect(line.toPayload().containsKey('total'), isFalse);
    expect(line.toPayload().containsKey('tax_amount'), isFalse);
    expect(line.toPayload()['unit_price'], '50.00');
  });

  test('SupplierRecord parses list JSON', () {
    final supplier = SupplierRecord.fromJson({
      'id': 3,
      'name': 'مورد',
      'vat_number': '300000000000003',
      'status': 'active',
    });
    expect(supplier.id, 3);
    expect(supplier.name, 'مورد');
  });
}
