import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:hasim_finance/core/models/models.dart';
import 'package:hasim_finance/core/network/api_client.dart';
import 'package:hasim_finance/core/network/api_response.dart';

class FinanceApi {
  FinanceApi(this._client);

  final ApiClient _client;

  Future<SessionPayload> login({
    required String emailOrPhone,
    required String password,
  }) async {
    final res = await _client.post(
      'auth/login',
      body: {
        'email_or_phone': emailOrPhone,
        'password': password,
        'device_name': 'Hasim Finance',
        'device_type': 'finance',
      },
      mapData: (raw) => SessionPayload.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<SessionPayload> me() async {
    final res = await _client.get(
      'auth/me',
      mapData: (raw) => SessionPayload.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<void> logout() => _client.post('auth/logout');

  Future<SessionPayload> socialLogin({required String accessToken, int? workspaceId}) async {
    final res = await _client.post(
      'auth/google',
      body: {
        'access_token': accessToken,
        'device_name': 'Hasim Finance',
        'device_type': 'finance',
        'workspace_id': ?workspaceId,
      },
      mapData: (raw) => SessionPayload.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<GoogleStartResult> googleStart() async {
    final res = await _client.post(
      'auth/google/start',
      mapData: (raw) => GoogleStartResult.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<GoogleStatusResult> googleStatus(String ticket) async {
    final res = await _client.get(
      'auth/google/status',
      query: {'ticket': ticket},
      mapData: (raw) => GoogleStatusResult.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<String> forgotPassword(String email) async {
    final res = await _client.post('auth/forgot-password', body: {'email': email});
    return res.message ?? '';
  }

  Future<String> resetPassword({
    required String email,
    required String token,
    required String password,
    required String passwordConfirmation,
  }) async {
    final res = await _client.post(
      'auth/reset-password',
      body: {
        'email': email,
        'token': token,
        'password': password,
        'password_confirmation': passwordConfirmation,
      },
    );
    return res.message ?? '';
  }

  Future<void> switchWorkspace(int workspaceId) async {
    await _client.post(
      'workspaces/switch',
      body: {'workspace_id': workspaceId, 'device_type': 'finance'},
    );
  }

  Future<DashboardData> dashboard({
    String? from,
    String? to,
    int? customerId,
    int? productId,
    int? projectId,
    String? lifecycle,
    String? paymentMethod,
  }) async {
    final res = await _client.get(
      'dashboard',
      query: {
        'from': ?from,
        'to': ?to,
        'customer_id': ?customerId,
        'product_id': ?productId,
        'project_id': ?projectId,
        if (lifecycle != null && lifecycle.isNotEmpty) 'lifecycle': lifecycle,
        if (paymentMethod != null && paymentMethod.isNotEmpty) 'payment_method': paymentMethod,
      },
      mapData: (raw) => DashboardData.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<Map<String, dynamic>> bootstrap() async {
    final res = await _client.get('bootstrap');
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<PagedResult<CustomerRecord>> customers({String? search, int page = 1, int perPage = 25}) {
    return _paged('customers', (raw) => CustomerRecord.fromJson(raw), search: search, page: page, extra: {
      'per_page': perPage,
    });
  }

  Future<CustomerRecord> customer(int id) async {
    final res = await _client.get('customers/$id', mapData: (raw) => CustomerRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<CustomerRecord> saveCustomer(Map<String, dynamic> body, {int? id}) async {
    final res = id == null
        ? await _client.post('customers', body: body, mapData: (raw) => CustomerRecord.fromJson(Map<String, dynamic>.from(raw as Map)))
        : await _client.put('customers/$id', body: body, mapData: (raw) => CustomerRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<PagedResult<QuoteRecord>> quotes({String? search, String? status, String? outcome, int page = 1}) {
    return _paged('quotes', (raw) => QuoteRecord.fromJson(raw), search: search, page: page, extra: {
      if (status != null && status.isNotEmpty) 'status': status,
      if (outcome != null && outcome.isNotEmpty) 'outcome': outcome,
    });
  }

  Future<QuoteRecord> quote(int id) async {
    final res = await _client.get('quotes/$id', mapData: (raw) => QuoteRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<QuoteRecord> saveQuote(Map<String, dynamic> body, {int? id, FormData? form}) async {
    if (form != null && id == null) {
      final res = await _client.upload(
        'quotes',
        formData: form,
        mapData: (raw) => QuoteRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
      );
      return res.data!;
    }
    final res = id == null
        ? await _client.post('quotes', body: body, mapData: (raw) => QuoteRecord.fromJson(Map<String, dynamic>.from(raw as Map)))
        : await _client.put('quotes/$id', body: body, mapData: (raw) => QuoteRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<QuoteRecord> quoteAction(int id, String action, {Map<String, dynamic>? body}) async {
    final res = await _client.post(
      'quotes/$id/$action',
      body: body,
      mapData: (raw) {
        final map = Map<String, dynamic>.from(raw as Map);
        if (map.containsKey('quote')) {
          return QuoteRecord.fromJson(Map<String, dynamic>.from(map['quote'] as Map));
        }
        return QuoteRecord.fromJson(map);
      },
    );
    return res.data!;
  }

  Future<QuoteRecord> uploadQuoteAttachments(int quoteId, FormData form) async {
    final res = await _client.upload(
      'quotes/$quoteId/attachments',
      formData: form,
      mapData: (raw) => QuoteRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<Uint8List> downloadQuoteAttachment(int quoteId, int attachmentId) {
    return _client.downloadBytes('quotes/$quoteId/attachments/$attachmentId');
  }

  Future<QuoteRecord> deleteQuoteAttachment(int quoteId, int attachmentId) async {
    final res = await _client.delete(
      'quotes/$quoteId/attachments/$attachmentId',
      mapData: (raw) => QuoteRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

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
  }) {
    return _paged('sales-invoices', (raw) => InvoiceRecord.fromJson(raw), search: search, page: page, extra: {
      if (paymentStatus != null && paymentStatus.isNotEmpty) 'payment_status': paymentStatus,
      if (invoiceStatus != null && invoiceStatus.isNotEmpty) 'invoice_status': invoiceStatus,
      if (lifecycle != null && lifecycle.isNotEmpty) 'lifecycle': lifecycle,
      'customer_id': ?customerId,
      if (from != null && from.isNotEmpty) 'from': from,
      if (to != null && to.isNotEmpty) 'to': to,
      if (currency != null && currency.isNotEmpty) 'currency': currency,
      'project_id': ?projectId,
      'contract_id': ?contractId,
      if (paymentMethod != null && paymentMethod.isNotEmpty) 'payment_method': paymentMethod,
      if (sort != null && sort.isNotEmpty) 'sort': sort,
      if (direction != null && direction.isNotEmpty) 'direction': direction,
    });
  }

  Future<InvoiceRecord> invoice(int id) async {
    final res = await _client.get('sales-invoices/$id', mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<InvoiceRecord> saveInvoice(Map<String, dynamic> body, {int? id, FormData? form}) async {
    if (form != null && id == null) {
      final res = await _client.upload(
        'sales-invoices',
        formData: form,
        mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
      );
      return res.data!;
    }
    final res = id == null
        ? await _client.post('sales-invoices', body: body, mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)))
        : await _client.put('sales-invoices/$id', body: body, mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<void> deleteInvoice(int id) => _client.delete('sales-invoices/$id');

  Future<void> deleteQuote(int id) => _client.delete('quotes/$id');

  Future<void> deleteExpense(int id) => _client.delete('expenses/$id');

  Future<InvoiceRecord> uploadInvoiceAttachments(int invoiceId, FormData form) async {
    final res = await _client.upload(
      'sales-invoices/$invoiceId/attachments',
      formData: form,
      mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<Uint8List> downloadInvoiceAttachment(int invoiceId, int attachmentId) {
    return _client.downloadBytes('sales-invoices/$invoiceId/attachments/$attachmentId');
  }

  Future<InvoiceRecord> deleteInvoiceAttachment(int invoiceId, int attachmentId) async {
    final res = await _client.delete(
      'sales-invoices/$invoiceId/attachments/$attachmentId',
      mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<Map<String, dynamic>> invoiceXml(int invoiceId) async {
    final res = await _client.get('sales-invoices/$invoiceId/xml');
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<Map<String, dynamic>> invoiceQr(int invoiceId) async {
    final res = await _client.get('sales-invoices/$invoiceId/qr');
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<InvoiceRecord> invoiceAction(int id, String action, {Map<String, dynamic>? body}) async {
    final res = await _client.post(
      'sales-invoices/$id/$action',
      body: body,
      mapData: (raw) {
        final map = Map<String, dynamic>.from(raw as Map);
        if (map.containsKey('invoice')) {
          return InvoiceRecord.fromJson(Map<String, dynamic>.from(map['invoice'] as Map));
        }
        return InvoiceRecord.fromJson(map);
      },
    );
    return res.data!;
  }

  Future<Map<String, dynamic>> recordPayment(int invoiceId, Map<String, dynamic> body) async {
    final res = await _client.post('sales-invoices/$invoiceId/payments', body: body);
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<Map<String, dynamic>> reverseInvoicePayment(int invoiceId, int paymentId, {String? reason}) async {
    final res = await _client.post(
      'sales-invoices/$invoiceId/payments/$paymentId/reverse',
      body: {'reversal_reason': reason},
    );
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<CheckoutInfo> checkoutAvailability(int invoiceId) async {
    final res = await _client.get(
      'sales-invoices/$invoiceId/checkout',
      mapData: (raw) => CheckoutInfo.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<CheckoutInfo> checkout(int invoiceId) async {
    final res = await _client.post(
      'sales-invoices/$invoiceId/checkout',
      idempotent: true,
      mapData: (raw) {
        final map = Map<String, dynamic>.from(raw as Map);
        return CheckoutInfo.fromJson(Map<String, dynamic>.from(map['checkout'] as Map));
      },
    );
    return res.data!;
  }

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
  }) {
    return _paged('payments', (raw) => PaymentRecord.fromJson(raw), search: search, page: page, extra: {
      if (status != null && status.isNotEmpty) 'status': status,
      'customer_id': ?customerId,
      'invoice_id': ?invoiceId,
      if (from != null && from.isNotEmpty) 'from': from,
      if (to != null && to.isNotEmpty) 'to': to,
      if (method != null && method.isNotEmpty) 'method': method,
      'treasury_account_id': ?treasuryAccountId,
      if (reference != null && reference.isNotEmpty) 'reference': reference,
    });
  }

  Future<PaymentRecord> payment(int id) async {
    final res = await _client.get('payments/$id', mapData: (raw) => PaymentRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<PagedResult<ReceiptRecord>> receipts({
    String? search,
    String? status,
    int? customerId,
    int? invoiceId,
    String? from,
    String? to,
    String? method,
    int page = 1,
  }) {
    return _paged('receipts', (raw) => ReceiptRecord.fromJson(raw), search: search, page: page, extra: {
      if (status != null && status.isNotEmpty) 'status': status,
      'customer_id': ?customerId,
      'invoice_id': ?invoiceId,
      if (from != null && from.isNotEmpty) 'from': from,
      if (to != null && to.isNotEmpty) 'to': to,
      if (method != null && method.isNotEmpty) 'method': method,
    });
  }

  Future<ReceiptRecord> receipt(int id) async {
    final res = await _client.get('receipts/$id', mapData: (raw) => ReceiptRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<ReceiptRecord> sendReceipt(int id, Map<String, dynamic> body) async {
    final res = await _client.post(
      'receipts/$id/send',
      body: body,
      mapData: (raw) => ReceiptRecord.fromJson(Map<String, dynamic>.from((raw as Map)['receipt'] as Map)),
    );
    return res.data!;
  }

  Future<StatementRecord> statement({required int customerId, required String from, required String to}) async {
    final res = await _client.get(
      'statements',
      query: {'customer_id': customerId, 'from': from, 'to': to},
      mapData: (raw) => StatementRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<PagedResult<NoteRecord>> notes({String? search, int page = 1}) {
    return _paged('credit-notes', (raw) => NoteRecord.fromJson(raw), search: search, page: page);
  }

  Future<NoteRecord> note(int id) async {
    final res = await _client.get('credit-notes/$id', mapData: (raw) => NoteRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<NoteRecord> saveNote(Map<String, dynamic> body) async {
    final res = await _client.post('credit-notes', body: body, mapData: (raw) => NoteRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<NoteRecord> noteAction(int id, String action) async {
    final res = await _client.post('credit-notes/$id/$action', mapData: (raw) => NoteRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<PagedResult<ContractRecord>> contracts({String? search, String? status, int page = 1}) {
    return _paged('contracts', (raw) => ContractRecord.fromJson(raw), search: search, page: page, extra: {
      if (status != null && status.isNotEmpty) 'status': status,
    });
  }

  Future<ContractRecord> contract(int id) async {
    final res = await _client.get('contracts/$id', mapData: (raw) => ContractRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<ContractRecord> saveContract(Map<String, dynamic> body, {int? id}) async {
    final res = id == null
        ? await _client.post('contracts', body: body, mapData: (raw) => ContractRecord.fromJson(Map<String, dynamic>.from(raw as Map)))
        : await _client.put('contracts/$id', body: body, mapData: (raw) => ContractRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<ContractRecord> contractAction(int id, String action) async {
    final res = await _client.post('contracts/$id/$action', mapData: (raw) => ContractRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<ContractRecord> uploadContractAttachments(int contractId, FormData form) async {
    final res = await _client.upload(
      'contracts/$contractId/attachments',
      formData: form,
      mapData: (raw) => ContractRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<Uint8List> downloadContractAttachment(int contractId, int attachmentId) {
    return _client.downloadBytes('contracts/$contractId/attachments/$attachmentId');
  }

  Future<ContractRecord> deleteContractAttachment(int contractId, int attachmentId) async {
    final res = await _client.delete(
      'contracts/$contractId/attachments/$attachmentId',
      mapData: (raw) => ContractRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<InvoiceRecord?> generateScheduleInvoice(int contractId, int scheduleId) async {
    final res = await _client.post('contracts/$contractId/billing-schedules/$scheduleId/generate');
    final map = Map<String, dynamic>.from(res.data as Map? ?? {});
    final invoice = map['invoice'];
    if (invoice is Map) {
      return InvoiceRecord.fromJson(Map<String, dynamic>.from(invoice));
    }
    return null;
  }

  Future<Map<String, dynamic>> reversePayment(int paymentId, {String? reason}) async {
    final res = await _client.post('payments/$paymentId/reverse', body: {'reversal_reason': reason});
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> purchaseAging() async {
    final res = await _client.get('purchases/aging');
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<InvoiceRecord> savePurchase(Map<String, dynamic> body, {int? id, FormData? form}) async {
    if (form != null && id == null) {
      final res = await _client.upload(
        'purchases',
        formData: form,
        mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
      );
      return res.data!;
    }
    final res = id == null
        ? await _client.post('purchases', body: body, mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)))
        : await _client.put('purchases/$id', body: body, mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<Map<String, dynamic>> recordPurchasePayment(int invoiceId, Map<String, dynamic> body) async {
    final res = await _client.post('purchases/$invoiceId/payments', body: body);
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<InvoiceRecord> uploadPurchaseAttachments(int invoiceId, FormData form) async {
    final res = await _client.upload(
      'purchases/$invoiceId/attachments',
      formData: form,
      mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<Uint8List> downloadPurchaseAttachment(int invoiceId, int attachmentId) {
    return _client.downloadBytes('purchases/$invoiceId/attachments/$attachmentId');
  }

  Future<InvoiceRecord> deletePurchaseAttachment(int invoiceId, int attachmentId) async {
    final res = await _client.delete(
      'purchases/$invoiceId/attachments/$attachmentId',
      mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<Uint8List> expenseAttachment(int id) => _client.downloadBytes('expenses/$id/attachment');

  Future<Uint8List> exportDataset(String dataset, {Map<String, dynamic>? query}) =>
      _client.downloadBytes('exports/$dataset', query: query);

  Future<PagedResult<ExpenseRecord>> expenses({String? search, String? status, int page = 1}) {
    return _paged(
      'expenses',
      (raw) => ExpenseRecord.fromJson(raw),
      search: search,
      page: page,
      extra: {'status': ?status},
    );
  }

  Future<ExpenseRecord> expense(int id) async {
    final res = await _client.get('expenses/$id', mapData: (raw) => ExpenseRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<ExpenseRecord> saveExpense(Map<String, dynamic> body, {int? id, FormData? form}) async {
    if (form != null) {
      final res = await _client.upload('expenses', formData: form, mapData: (raw) => ExpenseRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
      return res.data!;
    }
    final res = id == null
        ? await _client.post('expenses', body: body, mapData: (raw) => ExpenseRecord.fromJson(Map<String, dynamic>.from(raw as Map)))
        : await _client.put('expenses/$id', body: body, mapData: (raw) => ExpenseRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<PagedResult<SupplierRecord>> suppliers({String? search, int page = 1}) {
    return _paged('suppliers', (raw) => SupplierRecord.fromJson(raw), search: search, page: page);
  }

  Future<PagedResult<InvoiceRecord>> purchases({
    String? search,
    String? paymentStatus,
    String? invoiceStatus,
    String? lifecycle,
    int? supplierId,
    String? from,
    String? to,
    int page = 1,
  }) {
    return _paged('purchases', (raw) => InvoiceRecord.fromJson(raw), search: search, page: page, extra: {
      if (paymentStatus != null && paymentStatus.isNotEmpty) 'payment_status': paymentStatus,
      if (invoiceStatus != null && invoiceStatus.isNotEmpty) 'invoice_status': invoiceStatus,
      if (lifecycle != null && lifecycle.isNotEmpty) 'lifecycle': lifecycle,
      'supplier_id': ?supplierId,
      if (from != null && from.isNotEmpty) 'from': from,
      if (to != null && to.isNotEmpty) 'to': to,
    });
  }

  Future<InvoiceRecord> purchase(int id) async {
    final res = await _client.get('purchases/$id', mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<Map<String, dynamic>> report(String key, {String? from, String? to, int? accountId}) async {
    final res = await _client.get('reports/$key', query: {
      'from': ?from,
      'to': ?to,
      'account_id': ?accountId,
    });
    return Map<String, dynamic>.from(res.data as Map);
  }

  Future<Map<String, dynamic>> settings() async {
    final res = await _client.get('settings');
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> updateSettings(Map<String, dynamic> body) async {
    final res = await _client.put('settings', body: body);
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> uploadCompanyLogo(FormData form) async {
    final res = await _client.upload('settings/logo', formData: form);
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Uint8List> downloadCompanyLogo() => _client.downloadBytes('settings/logo');

  Future<Map<String, dynamic>> removeCompanyLogo() async {
    final res = await _client.delete('settings/logo');
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> search(String q) async {
    final res = await _client.get('search', query: {'q': q});
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Uint8List> pdf(String path, {Map<String, dynamic>? query}) => _client.downloadBytes(path, query: query);

  Future<Map<String, dynamic>> salesHub({String? from, String? to}) async {
    final res = await _client.get('sales', query: {'from': ?from, 'to': ?to});
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> billingHub({String? from, String? to}) async {
    final res = await _client.get('billing', query: {'from': ?from, 'to': ?to});
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> vatHub() async {
    final res = await _client.get('vat');
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<List<Map<String, dynamic>>> alerts() async {
    final res = await _client.get('alerts');
    if (res.data is List) {
      return (res.data as List).whereType<Map>().map((row) => Map<String, dynamic>.from(row)).toList();
    }
    return const [];
  }

  Future<Map<String, dynamic>> accountingHub() async {
    final res = await _client.get('accounting');
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<PagedResult<TreasuryAccountRecord>> banks({int page = 1}) {
    return _paged('banks', TreasuryAccountRecord.fromJson, page: page);
  }

  Future<Map<String, dynamic>> treasury() async {
    final res = await _client.get('treasury');
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> treasuryTransfer(Map<String, dynamic> body) async {
    final res = await _client.post('treasury/transfers', body: body);
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> createBankStatement(Map<String, dynamic> body) async {
    final res = await _client.post('treasury/statements', body: body);
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> bankStatement(int id) async {
    final res = await _client.get('treasury/statements/$id');
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> addBankStatementLines(int id, List<Map<String, dynamic>> lines) async {
    final res = await _client.post('treasury/statements/$id/lines', body: {'lines': lines});
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> suggestBankStatementMatches(int id) async {
    final res = await _client.post('treasury/statements/$id/suggest');
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> matchBankStatementLine(int statementId, int lineId, {required String matchedType, required int matchedId}) async {
    final res = await _client.post(
      'treasury/statements/$statementId/lines/$lineId/match',
      body: {'matched_type': matchedType, 'matched_id': matchedId},
    );
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> ignoreBankStatementLine(int statementId, int lineId) async {
    final res = await _client.post('treasury/statements/$statementId/lines/$lineId/ignore');
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> completeBankStatement(int id) async {
    final res = await _client.post('treasury/statements/$id/complete');
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<List<Map<String, dynamic>>> exportIndex() async {
    final res = await _client.get('exports');
    if (res.data is List) {
      return (res.data as List).whereType<Map>().map((row) => Map<String, dynamic>.from(row)).toList();
    }
    return const [];
  }

  Future<PagedResult<ProductRecord>> products({String? search, int page = 1}) {
    return _paged('products', ProductRecord.fromJson, search: search, page: page);
  }

  Future<ProductRecord> product(int id) async {
    final res = await _client.get('products/$id', mapData: (raw) => ProductRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<PagedResult<InventoryMovementRecord>> inventory({String? search, int page = 1}) {
    return _paged('inventory', InventoryMovementRecord.fromJson, search: search, page: page);
  }

  Future<PagedResult<ProjectRecord>> projects({String? search, int page = 1}) {
    return _paged('projects', ProjectRecord.fromJson, search: search, page: page);
  }

  Future<ProjectRecord> project(int id) async {
    final res = await _client.get('projects/$id', mapData: (raw) => ProjectRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<ProjectRecord> saveProject(Map<String, dynamic> body) async {
    final res = await _client.post('projects', body: body, mapData: (raw) => ProjectRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<PagedResult<PriceListRecord>> priceLists({String? search, int page = 1}) {
    return _paged('price-lists', PriceListRecord.fromJson, search: search, page: page);
  }

  Future<PriceListRecord> priceList(int id) async {
    final res = await _client.get('price-lists/$id', mapData: (raw) => PriceListRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<PriceListRecord> savePriceList(Map<String, dynamic> body, {int? id}) async {
    final res = id == null
        ? await _client.post('price-lists', body: body, mapData: (raw) => PriceListRecord.fromJson(Map<String, dynamic>.from(raw as Map)))
        : await _client.put('price-lists/$id', body: body, mapData: (raw) => PriceListRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<PriceListRecord> priceListAction(int id, String action) async {
    final res = await _client.post('price-lists/$id/$action', mapData: (raw) => PriceListRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<void> addPriceListItem(int id, Map<String, dynamic> body) async {
    await _client.post('price-lists/$id/items', body: body);
  }

  Future<PagedResult<PurchaseOrderRecord>> purchaseOrders({String? search, String? status, int page = 1}) {
    return _paged('purchase-orders', PurchaseOrderRecord.fromJson, search: search, page: page, extra: {
      if (status != null && status.isNotEmpty) 'status': status,
    });
  }

  Future<PurchaseOrderRecord> purchaseOrder(int id) async {
    final res = await _client.get('purchase-orders/$id', mapData: (raw) => PurchaseOrderRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<PurchaseOrderRecord> savePurchaseOrder(Map<String, dynamic> body) async {
    final res = await _client.post(
      'purchase-orders',
      body: body,
      mapData: (raw) => PurchaseOrderRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<PurchaseOrderRecord> purchaseOrderAction(int id, String action) async {
    final res = await _client.post(
      'purchase-orders/$id/$action',
      mapData: (raw) => PurchaseOrderRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<PagedResult<LeadRecord>> leads({String? search, String? status, int page = 1}) {
    return _paged('leads', LeadRecord.fromJson, search: search, page: page, extra: {
      if (status != null && status.isNotEmpty) 'status': status,
    });
  }

  Future<LeadRecord> lead(int id) async {
    final res = await _client.get('leads/$id', mapData: (raw) => LeadRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<LeadRecord> saveLead(Map<String, dynamic> body) async {
    final res = await _client.post('leads', body: body, mapData: (raw) => LeadRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<Map<String, dynamic>> leadAction(int id, String action, {Map<String, dynamic>? body}) async {
    final res = await _client.post('leads/$id/$action', body: body);
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<PagedResult<FiscalYearRecord>> fiscalYears({int page = 1}) {
    return _paged('fiscal-years', FiscalYearRecord.fromJson, page: page);
  }

  Future<FiscalYearRecord> fiscalYear(int id) async {
    final res = await _client.get('fiscal-years/$id', mapData: (raw) => FiscalYearRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<FiscalYearRecord> saveFiscalYear(Map<String, dynamic> body) async {
    final res = await _client.post('fiscal-years', body: body, mapData: (raw) => FiscalYearRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<FiscalYearRecord> fiscalYearAction(int id, String action) async {
    final res = await _client.post('fiscal-years/$id/$action', mapData: (raw) => FiscalYearRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<AccountingPeriodRecord> setPeriodStatus(int periodId, String status) async {
    final res = await _client.post(
      'fiscal-years/periods/$periodId/status',
      body: {'status': status},
      mapData: (raw) => AccountingPeriodRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<Map<String, dynamic>> askCopilot(String question) async {
    final res = await _client.post('copilot/ask', body: {'question': question});
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<SupplierRecord> supplier(int id) async {
    final res = await _client.get('suppliers/$id', mapData: (raw) => SupplierRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<SupplierRecord> saveSupplier(Map<String, dynamic> body, {int? id}) async {
    final res = id == null
        ? await _client.post('suppliers', body: body, mapData: (raw) => SupplierRecord.fromJson(Map<String, dynamic>.from(raw as Map)))
        : await _client.put('suppliers/$id', body: body, mapData: (raw) => SupplierRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<InvoiceRecord> purchaseAction(int id, String action) async {
    final res = await _client.post(
      'purchases/$id/$action',
      mapData: (raw) => InvoiceRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<Map<String, dynamic>> storeTaxRate(Map<String, dynamic> body) async {
    final res = await _client.post('settings/tax-rates', body: body);
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<Map<String, dynamic>> storeTreasuryAccount(Map<String, dynamic> body) async {
    final res = await _client.post('settings/treasury-accounts', body: body);
    return Map<String, dynamic>.from(res.data as Map? ?? {});
  }

  Future<ContractRecord> saveSchedule(int contractId, Map<String, dynamic> body) async {
    final res = await _client.post(
      'contracts/$contractId/billing-schedules',
      body: body,
      mapData: (raw) => ContractRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<ContractRecord> scheduleAction(int contractId, int scheduleId, String action) async {
    final res = await _client.post(
      'contracts/$contractId/billing-schedules/$scheduleId/$action',
      mapData: (raw) => ContractRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<PagedResult<FinanceEmployeeRecord>> employees({String? search, String? status, int page = 1, int perPage = 25}) {
    return _paged('employees', FinanceEmployeeRecord.fromJson, search: search, page: page, extra: {
      'per_page': perPage,
      if (status != null && status.isNotEmpty) 'status': status,
    });
  }

  Future<FinanceEmployeeRecord> employee(int id) async {
    final res = await _client.get('employees/$id', mapData: (raw) => FinanceEmployeeRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<FinanceEmployeeRecord> saveEmployee(Map<String, dynamic> body, {int? id}) async {
    final res = id == null
        ? await _client.post('employees', body: body, mapData: (raw) => FinanceEmployeeRecord.fromJson(Map<String, dynamic>.from(raw as Map)))
        : await _client.put('employees/$id', body: body, mapData: (raw) => FinanceEmployeeRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<void> deleteEmployee(int id) => _client.delete('employees/$id');

  Future<PayrollRecord> savePayrollRecord(int employeeId, Map<String, dynamic> body) async {
    final res = await _client.post(
      'employees/$employeeId/payroll-records',
      body: body,
      mapData: (raw) => PayrollRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<PayrollOverview> payrollOverview() async {
    final res = await _client.get('payroll', mapData: (raw) => PayrollOverview.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<PagedResult<SalaryAdvanceRecord>> salaryAdvances({String? search, String? status, String? type, int page = 1}) {
    return _paged('salary-advances', SalaryAdvanceRecord.fromJson, search: search, page: page, extra: {
      if (status != null && status.isNotEmpty) 'status': status,
      if (type != null && type.isNotEmpty) 'type': type,
    });
  }

  Future<SalaryAdvanceRecord> salaryAdvance(int id) async {
    final res = await _client.get('salary-advances/$id', mapData: (raw) => SalaryAdvanceRecord.fromJson(Map<String, dynamic>.from(raw as Map)));
    return res.data!;
  }

  Future<SalaryAdvanceRecord> issueSalaryAdvance(Map<String, dynamic> body) async {
    final res = await _client.post(
      'salary-advances',
      body: body,
      mapData: (raw) => SalaryAdvanceRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<SalaryAdvanceRecord> repaySalaryAdvance(int id, Map<String, dynamic> body) async {
    final res = await _client.post(
      'salary-advances/$id/repay',
      body: body,
      mapData: (raw) => SalaryAdvanceRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<PagedResult<PayrollAdjustmentRecord>> payrollAdjustments({String? search, String? type, String? status, int page = 1}) {
    return _paged('payroll-adjustments', PayrollAdjustmentRecord.fromJson, search: search, page: page, extra: {
      if (type != null && type.isNotEmpty) 'type': type,
      if (status != null && status.isNotEmpty) 'status': status,
    });
  }

  Future<PayrollAdjustmentRecord> savePayrollAdjustment(Map<String, dynamic> body) async {
    final res = await _client.post(
      'payroll-adjustments',
      body: body,
      mapData: (raw) => PayrollAdjustmentRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<PayrollAdjustmentRecord> payrollAdjustmentAction(int id, String action, {Map<String, dynamic>? body}) async {
    final res = await _client.post(
      'payroll-adjustments/$id/$action',
      body: body,
      mapData: (raw) => PayrollAdjustmentRecord.fromJson(Map<String, dynamic>.from(raw as Map)),
    );
    return res.data!;
  }

  Future<PagedResult<T>> _paged<T>(
    String path,
    T Function(Map<String, dynamic> json) map, {
    String? search,
    int page = 1,
    Map<String, dynamic>? extra,
  }) async {
    final res = await _client.get(
      path,
      query: {
        'page': page,
        'per_page': 25,
        if (search != null && search.isNotEmpty) 'search': search,
        ...?extra,
      },
    );
    final items = (res.data as List? ?? [])
        .whereType<Map>()
        .map((row) => map(Map<String, dynamic>.from(row)))
        .toList();
    return PagedResult(
      items: items,
      page: res.currentPage,
      lastPage: res.lastPage,
      total: res.total,
      meta: res.meta,
    );
  }
}

extension FinanceApiResponseX on ApiResponse<dynamic> {}
