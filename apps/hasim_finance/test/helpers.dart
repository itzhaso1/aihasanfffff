import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/api/finance_api.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/auth/google_auth.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_client.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:hasim_finance/core/permissions/finance_permissions.dart';
import 'package:hasim_finance/core/storage/prefs_store.dart';
import 'package:hasim_finance/core/storage/secure_store.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

class FakeGoogleSource implements GoogleAccessTokenSource {
  FakeGoogleSource({this.token = 'ya29.test', this.error, this.delay});

  String token;
  Object? error;
  Duration? delay;
  var called = false;

  @override
  Future<String> obtainAccessToken() async {
    called = true;
    final wait = delay;
    if (wait != null) {
      await Future<void>.delayed(wait);
    }
    final thrown = error;
    if (thrown != null) throw thrown;
    return token;
  }
}

class MemorySecureStore extends SecureStore {
  MemorySecureStore() : super();

  String? _token;

  @override
  Future<void> saveToken(String token) async => _token = token;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> clearToken() async => _token = null;
}

class SeededAuthController extends AuthController {
  SeededAuthController({this.allowAll = true, this.workspaces});

  final bool allowAll;
  final List<WorkspaceInfo>? workspaces;

  static const ownerPerms = {
    'finance.view': true,
    'customers.view': true,
    'customers.create': true,
    'customers.edit': true,
    'quotes.view': true,
    'quotes.create': true,
    'quotes.edit': true,
    'quotes.issue': true,
    'quotes.send': true,
    'quotes.accept': true,
    'quotes.reject': true,
    'quotes.convert': true,
    'quotes.cancel': true,
    'invoices.view': true,
    'invoices.create': true,
    'invoices.edit': true,
    'invoices.issue': true,
    'invoices.send': true,
    'invoices.remind': true,
    'invoices.cancel': true,
    'invoices.delete': true,
    'quotes.delete': true,
    'expenses.edit': true,
    'payments.view': true,
    'payments.manage': true,
    'receipts.view': true,
    'receipts.send': true,
    'statements.view': true,
    'notes.view': true,
    'contracts.view': true,
    'expenses.view': true,
    'purchases.view': true,
    'reports.view': true,
    'finance.settings': true,
    'finance.manage': true,
    'accounting.view': true,
    'accounting.manage': true,
    'finance.price_lists.view': true,
    'finance.price_lists.manage': true,
    'finance.fiscal_years.view': true,
    'finance.fiscal_years.manage': true,
    'payroll.view': true,
    'payroll.manage': true,
    'finance.adjustments.view': true,
    'finance.adjustments.manage': true,
    'finance.salary_advances.view': true,
    'finance.salary_advances.manage': true,
    'purchases.create': true,
    'purchases.manage': true,
    'purchases.edit': true,
    'expenses.create': true,
    'contracts.create': true,
    'contracts.manage': true,
    'invoices.credit': true,
    'notes.create': true,
  };

  @override
  AuthState build() {
    final list = workspaces ??
        const [WorkspaceInfo(id: 1, name: 'شركة الاختبار', financeEnabled: true)];
    return AuthState(
      bootstrapping: false,
      user: const AuthUser(id: 1, name: 'Owner', email: 'owner@example.com'),
      workspace: list.first,
      workspaces: list,
      permissions: FinancePermissions(allowAll ? ownerPerms : const {'finance.view': false}),
      financeEnabled: true,
    );
  }
}

class FakeFinanceApi extends FinanceApi {
  FakeFinanceApi(super.client);

  DashboardData dashboardData = DashboardData(
    cards: const {
      'outstanding_customer_balance': '115.00',
      'invoices_due': '1',
      'overdue_invoices': '0',
      'paid_this_period': '0.00',
      'sales': '115.00',
      'purchases': '0.00',
      'expenses': '0.00',
      'receivables': '115.00',
      'payables': '0.00',
      'net_profit': '115.00',
      'company_employees': '1',
      'payroll_paid_total': '0.00',
      'open_advances_total': '0.00',
      'deductions_total': '0.00',
    },
    recentInvoices: [
      InvoiceRecord(
        id: 11,
        invoiceNumber: 'INV-11',
        customerName: 'عميل الاختبار',
        total: '115.00',
        amountDue: '115.00',
        documentStatus: 'issued',
        paymentStatus: 'unpaid',
      ),
    ],
    recentPayments: const [],
    overdueInvoices: const [],
    analytics: const {
      'from': '2026-09-01',
      'to': '2026-09-30',
      'hero': [
        {'key': 'sales', 'label': 'كم بعنا؟', 'value': '115.00', 'hint': 'إيراد فواتير المبيعات الصادرة'},
      ],
      'attention': [
        {'title': 'تحصيل متأخر', 'reason': 'لا توجد فواتير متأخرة في بيانات الاختبار'},
      ],
    },
  );

  CustomerRecord customerRecord = CustomerRecord(
    id: 7,
    name: 'عميل الاختبار',
    outstandingBalance: '115.00',
    partyType: 'company',
    email: 'buyer@example.com',
    phone: '0500000000',
    vatNumber: '300111111111113',
    invoices: [
      InvoiceRecord(id: 11, invoiceNumber: 'INV-11', total: '115.00', documentStatus: 'issued', paymentStatus: 'unpaid'),
    ],
  );

  InvoiceRecord invoiceRecord = InvoiceRecord(
    id: 11,
    invoiceNumber: 'INV-11',
    customerId: 7,
    customerName: 'عميل الاختبار',
    subtotal: '100.00',
    taxAmount: '15.00',
    total: '115.00',
    amountPaid: '0.00',
    amountDue: '115.00',
    documentStatus: 'issued',
    paymentStatus: 'unpaid',
    deliveryStatus: 'pending',
    lines: const [
      LineItem(productName: 'خدمة فوترة', quantity: '1', unitPrice: '100.00', taxAmount: '15.00', total: '115.00'),
    ],
  );

  QuoteRecord quoteRecord = QuoteRecord(
    id: 5,
    quoteNumber: 'Q-5',
    customerName: 'عميل الاختبار',
    subtotal: '100.00',
    taxAmount: '15.00',
    total: '115.00',
    documentStatus: 'issued',
    outcome: 'pending',
    deliveryStatus: 'sent',
    lines: const [
      LineItem(productName: 'خدمة عرض', quantity: '1', unitPrice: '100.00', total: '115.00'),
    ],
  );

  ContractRecord contractRecord = const ContractRecord(
    id: 21,
    contractNumber: 'C-21',
    title: 'عقد الاختبار',
    status: 'draft',
    customerId: 7,
    customerName: 'عميل الاختبار',
    value: '500.00',
    attachments: [
      {'id': 9, 'file_name': 'contract-scan.pdf', 'file_type': 'application/pdf'},
    ],
  );

  ReceiptRecord receiptRecord = ReceiptRecord(
    id: 3,
    receiptNumber: 'R-3',
    invoiceNumber: 'INV-11',
    customerName: 'عميل الاختبار',
    amount: '115.00',
    status: 'posted',
  );

  PaymentRecord paymentRecord = const PaymentRecord(
    id: 9,
    invoiceId: 11,
    invoiceNumber: 'INV-11',
    customerName: 'عميل الاختبار',
    amount: '115.00',
    method: 'cash',
    status: 'posted',
    receiptNumber: 'R-3',
  );

  bool loggedIn = false;
  bool loggedOut = false;
  String? lastSocialToken;
  String? lastForgotEmail;
  String? lastResetEmail;
  ApiException? socialError;
  bool financeEnabledFlag = true;
  List<WorkspaceInfo> sessionWorkspaces = const [
    WorkspaceInfo(id: 1, name: 'شركة الاختبار', financeEnabled: true),
  ];

  SessionPayload sessionPayload() {
    return SessionPayload(
      token: 'sanctum-token',
      user: const AuthUser(id: 1, name: 'Owner', email: 'owner@example.com'),
      workspace: sessionWorkspaces.first,
      workspaces: sessionWorkspaces,
      permissions: SeededAuthController.ownerPerms,
      financeEnabled: financeEnabledFlag,
    );
  }

  @override
  Future<SessionPayload> login({required String emailOrPhone, required String password}) async {
    if (password != 'password') {
      throw ApiException('بيانات الدخول غير صحيحة.', statusCode: 401);
    }
    loggedIn = true;
    return sessionPayload();
  }

  @override
  Future<SessionPayload> socialLogin({required String accessToken, int? workspaceId}) async {
    lastSocialToken = accessToken;
    final error = socialError;
    if (error != null) throw error;
    loggedIn = true;
    return sessionPayload();
  }

  bool meThrowsUnauthorized = false;

  @override
  Future<SessionPayload> me() async {
    if (meThrowsUnauthorized) {
      throw ApiException('انتهت جلسة تسجيل الدخول.', statusCode: 401);
    }
    return sessionPayload();
  }

  @override
  Future<void> switchWorkspace(int workspaceId) async {}

  @override
  Future<void> logout() async {
    loggedOut = true;
  }

  @override
  Future<String> forgotPassword(String email) async {
    lastForgotEmail = email;
    return 'تم إرسال رابط إعادة تعيين كلمة المرور.';
  }

  @override
  Future<String> resetPassword({
    required String email,
    required String token,
    required String password,
    required String passwordConfirmation,
  }) async {
    lastResetEmail = email;
    return 'تم إعادة تعيين كلمة المرور بنجاح.';
  }

  @override
  Future<DashboardData> dashboard({
    String? from,
    String? to,
    int? customerId,
    int? productId,
    int? projectId,
    String? lifecycle,
    String? paymentMethod,
  }) async {
    lastDashboardQuery = {
      'from': from,
      'to': to,
      'customer_id': customerId,
      'product_id': productId,
      'project_id': projectId,
      'lifecycle': lifecycle,
      'payment_method': paymentMethod,
    };
    return dashboardData;
  }

  Map<String, dynamic>? lastDashboardQuery;

  List<CustomerRecord> catalogCustomers = [];

  @override
  Future<PagedResult<CustomerRecord>> customers({String? search, int page = 1, int perPage = 25}) async {
    var items = catalogCustomers.isEmpty ? [customerRecord] : List<CustomerRecord>.from(catalogCustomers);
    if (search != null && search.isNotEmpty) {
      items = items.where((row) => row.name.contains(search)).toList();
    }
    const perPage = 25;
    final lastPage = items.isEmpty ? 1 : ((items.length + perPage - 1) / perPage).floor();
    final start = (page - 1) * perPage;
    final slice = items.skip(start).take(perPage).toList();
    return PagedResult(items: slice, page: page, lastPage: lastPage, total: items.length);
  }

  @override
  Future<CustomerRecord> customer(int id) async => customerRecord;

  @override
  Future<PagedResult<InvoiceRecord>> invoices({
    String? search,
    String? paymentStatus,
    String? invoiceStatus,
    String? lifecycle,
    int? customerId,
    String? from,
    String? to,
    String? currency,
    int? projectId,
    int? contractId,
    String? paymentMethod,
    String? sort,
    String? direction,
    int page = 1,
  }) async {
    return PagedResult(items: [invoiceRecord], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<InvoiceRecord> invoice(int id) async => invoiceRecord;

  @override
  Future<Map<String, dynamic>> recordPayment(int invoiceId, Map<String, dynamic> body) async {
    invoiceRecord = InvoiceRecord(
      id: invoiceRecord.id,
      invoiceNumber: invoiceRecord.invoiceNumber,
      customerId: invoiceRecord.customerId,
      customerName: invoiceRecord.customerName,
      subtotal: invoiceRecord.subtotal,
      taxAmount: invoiceRecord.taxAmount,
      total: invoiceRecord.total,
      amountPaid: invoiceRecord.total,
      amountDue: '0.00',
      documentStatus: 'issued',
      paymentStatus: 'paid',
      lines: invoiceRecord.lines,
      payments: [paymentRecord],
      receipts: [receiptRecord],
    );
    return {
      'invoice': {'payment_status': 'paid'},
      'payment': {'status': 'posted', 'id': 9},
    };
  }

  @override
  Future<PagedResult<QuoteRecord>> quotes({String? search, String? status, String? outcome, int page = 1}) async {
    return PagedResult(items: [quoteRecord], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<QuoteRecord> quote(int id) async => quoteRecord;

  Map<String, dynamic>? lastQuotePayload;

  @override
  Future<QuoteRecord> saveQuote(Map<String, dynamic> body, {int? id, FormData? form}) async {
    lastQuotePayload = body;
    return QuoteRecord(
      id: id ?? 99,
      quoteNumber: 'Q-99',
      customerId: body['customer_id'] as int?,
      documentStatus: 'draft',
      outcome: 'pending',
    );
  }

  @override
  Future<QuoteRecord> quoteAction(int id, String action, {Map<String, dynamic>? body}) async {
    if (action == 'convert') {
      quoteRecord = QuoteRecord(
        id: quoteRecord.id,
        quoteNumber: quoteRecord.quoteNumber,
        customerName: quoteRecord.customerName,
        documentStatus: 'issued',
        outcome: 'converted',
        convertedInvoiceId: 11,
        convertedInvoiceNumber: 'INV-11',
        lines: quoteRecord.lines,
      );
    }
    return quoteRecord;
  }

  @override
  Future<PagedResult<ReceiptRecord>> receipts({
    String? search,
    String? status,
    int? customerId,
    int? invoiceId,
    String? from,
    String? to,
    String? method,
    int page = 1,
  }) async {
    return PagedResult(items: [receiptRecord], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<ReceiptRecord> receipt(int id) async => receiptRecord;

  @override
  Future<PagedResult<PaymentRecord>> payments({
    String? search,
    String? status,
    int? customerId,
    int? invoiceId,
    String? from,
    String? to,
    String? method,
    int? treasuryAccountId,
    String? reference,
    int page = 1,
  }) async {
    return PagedResult(items: [paymentRecord], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<PaymentRecord> payment(int id) async => paymentRecord;

  StatementRecord statementRecord = const StatementRecord(
    openingBalance: '0.00',
    closingBalance: '65.00',
    invoicesTotal: '115.00',
    paymentsTotal: '50.00',
    creditsTotal: '0.00',
    debitsTotal: '0.00',
    customerName: 'عميل الاختبار',
    customerId: 7,
    from: '2026-09-01',
    to: '2026-09-30',
    lines: [
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
      {
        'date': '2026-09-12',
        'kind': 'payment',
        'reference': 'PAY-9',
        'description': 'دفعة جزئية',
        'debit': '0.00',
        'credit': '50.00',
        'balance': '65.00',
        'invoice_id': 11,
      },
    ],
  );

  @override
  Future<StatementRecord> statement({required int customerId, required String from, required String to}) async {
    return statementRecord;
  }

  Map<String, dynamic> reportPayload = {
    'report': 'profit-loss',
    'from': '2026-09-01',
    'to': '2026-09-30',
    'profit_and_loss': {
      'revenue': '1000.00',
      'cogs': '200.00',
      'gross_profit': '800.00',
      'net_profit': '500.00',
      'rows': [
        {'code': '4000', 'name': 'إيرادات', 'type': 'revenue', 'balance': '1000.00'},
      ],
    },
  };

  @override
  Future<Map<String, dynamic>> report(String key, {String? from, String? to, int? accountId}) async {
    return reportPayload;
  }

  @override
  Future<PagedResult<SupplierRecord>> suppliers({String? search, int page = 1}) async {
    return const PagedResult(items: [SupplierRecord(id: 3, name: 'مورد الاختبار')], page: 1, lastPage: 1, total: 1);
  }

  Map<String, dynamic>? lastInvoicePayload;
  Map<String, dynamic>? lastPurchasePayload;

  @override
  Future<InvoiceRecord> saveInvoice(Map<String, dynamic> body, {int? id, FormData? form}) async {
    lastInvoicePayload = body;
    return InvoiceRecord(id: id ?? 99, invoiceNumber: 'INV-99', documentStatus: 'draft');
  }

  @override
  Future<Map<String, dynamic>> invoiceXml(int invoiceId) async {
    return {'xml': '<Invoice/>', 'root_local_name': 'Invoice', 'schema_valid': true};
  }

  @override
  Future<Map<String, dynamic>> invoiceQr(int invoiceId) async {
    return {'payload': 'A', 'tags': <Map<String, dynamic>>[]};
  }

  @override
  Future<QuoteRecord> uploadQuoteAttachments(int quoteId, FormData form) async => quoteRecord;

  @override
  Future<QuoteRecord> deleteQuoteAttachment(int quoteId, int attachmentId) async => quoteRecord;

  @override
  Future<Uint8List> downloadQuoteAttachment(int quoteId, int attachmentId) async => Uint8List.fromList(const [1, 2, 3]);

  @override
  Future<PagedResult<InvoiceRecord>> purchases({
    String? search,
    String? paymentStatus,
    String? invoiceStatus,
    String? lifecycle,
    int? supplierId,
    String? from,
    String? to,
    int page = 1,
  }) async {
    return PagedResult(items: [invoiceRecord], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<InvoiceRecord> savePurchase(Map<String, dynamic> body, {int? id, FormData? form}) async {
    lastPurchasePayload = body;
    return InvoiceRecord(id: id ?? 88, invoiceNumber: 'PINV-88', documentStatus: 'draft', supplierName: 'مورد الاختبار');
  }

  @override
  Future<Map<String, dynamic>> recordPurchasePayment(int invoiceId, Map<String, dynamic> body) async => {
        'payment': {'id': 1, 'amount': body['amount']},
      };

  @override
  Future<InvoiceRecord> uploadPurchaseAttachments(int invoiceId, FormData form) async => invoiceRecord;

  @override
  Future<Uint8List> downloadPurchaseAttachment(int invoiceId, int attachmentId) async => Uint8List.fromList(const [1, 2, 3]);

  @override
  Future<InvoiceRecord> deletePurchaseAttachment(int invoiceId, int attachmentId) async => invoiceRecord;

  @override
  Future<Map<String, dynamic>> bootstrap() async => {
        'settings': {
          'allow_manual_invoice_numbers': false,
          'default_vat_rate': '15.00',
        },
        'catalog': {
          'products': [
            {'id': 1, 'name': 'خدمة فوترة', 'price': '100.00'},
          ],
          'tax_rates': [
            {'id': 1, 'name': 'VAT 15', 'rate': '15.00'},
          ],
          'contracts': <Map<String, dynamic>>[],
          'projects': <Map<String, dynamic>>[],
          'treasury_accounts': <Map<String, dynamic>>[],
          'expense_categories': <Map<String, dynamic>>[],
          'suppliers': [
            {'id': 3, 'name': 'مورد الاختبار'},
          ],
        },
      };

  @override
  Future<PagedResult<ProductRecord>> products({String? search, int page = 1}) async {
    return const PagedResult(items: [ProductRecord(id: 1, name: 'خدمة فوترة', sku: 'SKU-1', price: '100.00')], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<ProductRecord> product(int id) async => const ProductRecord(id: 1, name: 'خدمة فوترة', sku: 'SKU-1', price: '100.00', stock: 5);

  @override
  Future<PagedResult<InventoryMovementRecord>> inventory({String? search, int page = 1}) async {
    return const PagedResult(items: [], page: 1, lastPage: 1, total: 0);
  }

  @override
  Future<PagedResult<ProjectRecord>> projects({String? search, int page = 1}) async {
    return const PagedResult(items: [ProjectRecord(id: 2, name: 'مشروع تجريبي')], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<ProjectRecord> project(int id) async => const ProjectRecord(id: 2, name: 'مشروع تجريبي', budget: '1000.00', revenue: '200.00', costs: '50.00', profit: '150.00');

  @override
  Future<PagedResult<PriceListRecord>> priceLists({String? search, int page = 1}) async {
    return const PagedResult(items: [PriceListRecord(id: 4, name: 'قائمة أساسية', status: 'draft')], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<PriceListRecord> priceList(int id) async => const PriceListRecord(id: 4, name: 'قائمة أساسية', status: 'draft');

  @override
  Future<PagedResult<PurchaseOrderRecord>> purchaseOrders({String? search, String? status, int page = 1}) async {
    return const PagedResult(items: [PurchaseOrderRecord(id: 6, poNumber: 'PO-6', status: 'draft', total: '50.00')], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<PurchaseOrderRecord> purchaseOrder(int id) async => const PurchaseOrderRecord(id: 6, poNumber: 'PO-6', status: 'draft', total: '50.00', subtotal: '50.00');

  @override
  Future<PagedResult<LeadRecord>> leads({String? search, String? status, int page = 1}) async {
    return const PagedResult(items: [LeadRecord(id: 8, name: 'فرصة تجريبية', status: 'new')], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<LeadRecord> lead(int id) async => const LeadRecord(id: 8, name: 'فرصة تجريبية', status: 'new', estimatedValue: '500.00');

  @override
  Future<Map<String, dynamic>> salesHub({String? from, String? to}) async => {
        'summary': {'total_sales': '115.00', 'total_paid': '0.00', 'total_due': '115.00', 'overdue_count': 0},
        'invoices': [
          {'id': 11, 'invoice_number': 'INV-11', 'customer_name': 'عميل الاختبار', 'payment_status': 'unpaid', 'total': '115.00'},
        ],
        'recent_payments': <Map<String, dynamic>>[],
      };

  @override
  Future<Map<String, dynamic>> billingHub({String? from, String? to}) async => {
        'total_invoices': 1,
        'total_revenue': '115.00',
        'outstanding_amount': '115.00',
        'overdue_amount': '0.00',
        'payments_received': '0.00',
        'credits_issued': '0.00',
      };

  @override
  Future<Map<String, dynamic>> vatHub() async => {'output': '15.00', 'input': '0.00', 'net': '15.00', 'rates': <Map<String, dynamic>>[]};

  @override
  Future<List<Map<String, dynamic>>> alerts() async => [
        {'key': 'overdue_invoices', 'severity': 'high', 'title': 'فواتير متأخرة', 'reason': 'none'},
      ];

  @override
  Future<Map<String, dynamic>> accountingHub() async => {
        'accounts': <Map<String, dynamic>>[
          {'id': 1, 'code': '1000', 'name': 'الصندوق', 'debit_total': '200.00', 'credit_total': '0.00'},
        ],
        'entries': <Map<String, dynamic>>[
          {'id': 1, 'entry_number': 'JE-1', 'entry_date': '2026-09-01', 'status': 'posted', 'description': 'قيد افتتاحي'},
        ],
        'trial_balance': <Map<String, dynamic>>[],
        'trial_totals': {'debit': '200.00', 'credit': '200.00'},
        'monthly_cash_flow': [
          {'month': '2026-09', 'inflow': '115.00'},
        ],
      };

  @override
  Future<PagedResult<TreasuryAccountRecord>> banks({int page = 1}) async {
    return const PagedResult(items: [TreasuryAccountRecord(id: 1, name: 'الصندوق', type: 'cash', currentBalance: '200.00')], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<Map<String, dynamic>> treasury() async => {
        'accounts': [
          {'id': 1, 'name': 'الصندوق', 'type': 'cash', 'current_balance': '200.00'},
        ],
        'transfers': <Map<String, dynamic>>[],
        'statements': [
          {
            'id': 4,
            'treasury_account_name': 'البنك',
            'statement_date': '2026-09-10',
            'status': 'open',
            'closing_balance': '100.00',
          },
        ],
      };

  Map<String, dynamic> bankStatementPayload = {
    'id': 4,
    'treasury_account_name': 'البنك',
    'statement_date': '2026-09-10',
    'status': 'open',
    'opening_balance': '0.00',
    'closing_balance': '100.00',
    'lines': [
      {
        'id': 8,
        'posted_date': '2026-09-10',
        'description': 'إيداع',
        'amount': '100.00',
        'status': 'suggested',
        'suggested_type': 'App\\Models\\Finance\\FinanceInvoicePayment',
        'suggested_id': 3,
        'suggestion_reason': 'Matching payment amount and date',
        'suggestion_confidence': 90,
      },
    ],
  };

  @override
  Future<Map<String, dynamic>> bankStatement(int id) async => bankStatementPayload;

  @override
  Future<Map<String, dynamic>> matchBankStatementLine(
    int statementId,
    int lineId, {
    required String matchedType,
    required int matchedId,
  }) async {
    bankStatementPayload = {
      ...bankStatementPayload,
      'lines': [
        {
          ...Map<String, dynamic>.from((bankStatementPayload['lines'] as List).first as Map),
          'status': 'matched',
          'matched_type': matchedType,
          'matched_id': matchedId,
        },
      ],
    };
    return bankStatementPayload;
  }

  @override
  Future<ContractRecord> contract(int id) async => contractRecord;

  @override
  Future<ContractRecord> uploadContractAttachments(int contractId, FormData form) async => contractRecord;

  @override
  Future<ContractRecord> deleteContractAttachment(int contractId, int attachmentId) async {
    contractRecord = ContractRecord(
      id: contractRecord.id,
      contractNumber: contractRecord.contractNumber,
      title: contractRecord.title,
      status: contractRecord.status,
      customerId: contractRecord.customerId,
      customerName: contractRecord.customerName,
      value: contractRecord.value,
    );
    return contractRecord;
  }

  Map<String, dynamic> settingsPayload = {
    'company_name': 'شركة الاختبار',
    'has_logo': false,
    'zatca_integration_mode': 'disabled',
    'currency': 'SAR',
    'allow_manual_invoice_numbers': false,
    'tax_rates': [
      {'id': 1, 'name': 'VAT 15', 'code': 'VAT_STD_15', 'type': 'standard', 'rate': '15.00', 'is_default': true, 'is_active': true},
    ],
    'treasury_accounts': [
      {'id': 2, 'name': 'صندوق المكتب', 'type': 'cash', 'currency': 'SAR', 'current_balance': '50.00'},
    ],
    'finance_accounts': [
      {'id': 9, 'code': '1100', 'name': 'الصندوق', 'type': 'asset'},
    ],
  };

  @override
  Future<Map<String, dynamic>> settings() async => settingsPayload;

  @override
  Future<Map<String, dynamic>> uploadCompanyLogo(FormData form) async {
    settingsPayload = {...settingsPayload, 'has_logo': true};
    return settingsPayload;
  }

  @override
  Future<Map<String, dynamic>> removeCompanyLogo() async {
    settingsPayload = {...settingsPayload, 'has_logo': false};
    return settingsPayload;
  }

  @override
  Future<Map<String, dynamic>> storeTaxRate(Map<String, dynamic> body) async {
    final rates = [
      ..._maps('tax_rates'),
      {
        'id': _maps('tax_rates').length + 10,
        'name': body['name'],
        'code': body['code'],
        'type': body['type'],
        'rate': body['rate'],
        'is_default': body['is_default'] == true,
        'is_active': body['is_active'] != false,
      },
    ];
    settingsPayload = {...settingsPayload, 'tax_rates': rates};
    return rates.last;
  }

  @override
  Future<Map<String, dynamic>> storeTreasuryAccount(Map<String, dynamic> body) async {
    final accounts = [
      ..._maps('treasury_accounts'),
      {
        'id': _maps('treasury_accounts').length + 20,
        'name': body['name'],
        'type': body['type'] ?? 'bank',
        'currency': body['currency'] ?? 'SAR',
        'current_balance': body['current_balance'] ?? body['opening_balance'] ?? '0.00',
        'opening_balance': body['opening_balance'] ?? '0.00',
      },
    ];
    settingsPayload = {...settingsPayload, 'treasury_accounts': accounts};
    return accounts.last;
  }

  List<Map<String, dynamic>> _maps(String key) {
    final raw = settingsPayload[key];
    if (raw is! List) return const [];
    return [
      for (final row in raw)
        if (row is Map) Map<String, dynamic>.from(row),
    ];
  }

  @override
  Future<Uint8List> downloadCompanyLogo() async => Uint8List.fromList(const [1, 2, 3]);

  @override
  Future<List<Map<String, dynamic>>> exportIndex() async => [
        {'dataset': 'invoices', 'label': 'الفواتير', 'hint': 'رقم وحالة المستند'},
      ];

  @override
  Future<PagedResult<FiscalYearRecord>> fiscalYears({int page = 1}) async {
    return const PagedResult(items: [FiscalYearRecord(id: 1, name: '2026', status: 'open')], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<FiscalYearRecord> fiscalYear(int id) async => const FiscalYearRecord(id: 1, name: '2026', status: 'open');

  @override
  Future<SupplierRecord> supplier(int id) async => const SupplierRecord(id: 3, name: 'مورد الاختبار', vatNumber: '300000000000003');

  @override
  Future<Map<String, dynamic>> askCopilot(String question) async => {'answer': 'لا توجد مبالغ مخترعة. المبيعات 115.00'};

  FinanceEmployeeRecord employeeRecord = const FinanceEmployeeRecord(
    id: 4,
    fullName: 'موظف الشركة',
    employeeCode: 'FEMP-00004',
    jobTitle: 'محاسب',
    basicSalary: '1500.00',
    status: 'active',
    summary: {
      'total_owed': '1500.00',
      'total_paid': '1000.00',
      'remaining': '500.00',
      'advance_issued': '800.00',
      'advance_settled': '300.00',
      'advance_remaining': '500.00',
      'bonuses_total': '200.00',
      'deductions_total': '0.00',
    },
    payrollRecords: [
      PayrollRecord(id: 1, employeeId: 4, employeeName: 'موظف الشركة', netAmount: '1500.00', remaining: '500.00', paymentStatus: 'partial', periodStart: '2026-09-01', periodEnd: '2026-09-30'),
    ],
    advances: [
      SalaryAdvanceRecord(id: 2, employeeId: 4, employeeName: 'موظف الشركة', amount: '800.00', remainingAmount: '500.00', settledAmount: '300.00', status: 'open'),
    ],
  );

  @override
  Future<PagedResult<FinanceEmployeeRecord>> employees({String? search, String? status, int page = 1, int perPage = 25}) async {
    return PagedResult(items: [employeeRecord], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<FinanceEmployeeRecord> employee(int id) async => employeeRecord;

  @override
  Future<FinanceEmployeeRecord> saveEmployee(Map<String, dynamic> body, {int? id}) async => employeeRecord;

  @override
  Future<PayrollRecord> savePayrollRecord(int employeeId, Map<String, dynamic> body) async {
    return employeeRecord.payrollRecords.first;
  }

  @override
  Future<PayrollOverview> payrollOverview() async => PayrollOverview(
        cards: const {
          'company_employees': '1',
          'payroll_paid_total': '1000.00',
          'open_advances_total': '500.00',
          'allowances_bonuses_total': '200.00',
          'deductions_total': '0.00',
        },
        latestRecords: employeeRecord.payrollRecords,
      );

  @override
  Future<PagedResult<SalaryAdvanceRecord>> salaryAdvances({String? search, String? status, String? type, int page = 1}) async {
    return PagedResult(items: employeeRecord.advances, page: 1, lastPage: 1, total: employeeRecord.advances.length);
  }

  @override
  Future<SalaryAdvanceRecord> issueSalaryAdvance(Map<String, dynamic> body) async => employeeRecord.advances.first;

  @override
  Future<SalaryAdvanceRecord> repaySalaryAdvance(int id, Map<String, dynamic> body) async => employeeRecord.advances.first;

  @override
  Future<PagedResult<PayrollAdjustmentRecord>> payrollAdjustments({String? search, String? type, String? status, int page = 1}) async {
    return const PagedResult(items: [
      PayrollAdjustmentRecord(id: 9, employeeId: 4, employeeName: 'موظف الشركة', type: 'bonus', title: 'مكافأة أداء', amount: '200.00', status: 'posted'),
    ], page: 1, lastPage: 1, total: 1);
  }

  @override
  Future<PayrollAdjustmentRecord> savePayrollAdjustment(Map<String, dynamic> body) async {
    return PayrollAdjustmentRecord(id: 10, type: body['type']?.toString(), title: body['title']?.toString(), amount: '${body['amount'] ?? '0.00'}', status: 'draft');
  }
}

Future<SharedPreferences> mockPrefs() async {
  SharedPreferences.setMockInitialValues({});
  return SharedPreferences.getInstance();
}

ApiClient testClient(SharedPreferences prefs) {
  return ApiClient(
    secureStore: MemorySecureStore(),
    prefsStore: PrefsStore(prefs),
  );
}

List<Override> financeOverrides({
  required SharedPreferences prefs,
  required FakeFinanceApi api,
  bool allowAll = true,
  GoogleAccessTokenSource? google,
  SecureStore? secureStore,
  List<WorkspaceInfo>? workspaces,
}) {
  return [
    sharedPrefsProvider.overrideWithValue(prefs),
    financeApiProvider.overrideWithValue(api),
    authControllerProvider.overrideWith(() => SeededAuthController(allowAll: allowAll, workspaces: workspaces)),
    if (google != null) googleAccessTokenSourceProvider.overrideWithValue(google),
    if (secureStore != null) secureStoreProvider.overrideWithValue(secureStore),
  ];
}

Widget financeHarness({
  required List<Override> overrides,
  required Widget child,
}) {
  return ProviderScope(
    overrides: overrides,
    child: MaterialApp(
      locale: const Locale('ar'),
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      home: child,
    ),
  );
}
