class WorkspaceInfo {
  const WorkspaceInfo({
    required this.id,
    required this.name,
    this.type,
    this.slug,
    this.financeEnabled = false,
  });

  final int id;
  final String name;
  final String? type;
  final String? slug;
  final bool financeEnabled;

  factory WorkspaceInfo.fromJson(Map<String, dynamic> json) {
    return WorkspaceInfo(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? '',
      type: json['type']?.toString(),
      slug: json['slug']?.toString(),
      financeEnabled: json['finance_enabled'] == true,
    );
  }
}

class AuthUser {
  const AuthUser({required this.id, required this.name, this.email, this.phone});

  final int id;
  final String name;
  final String? email;
  final String? phone;

  factory AuthUser.fromJson(Map<String, dynamic> json) {
    return AuthUser(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? '',
      email: json['email']?.toString(),
      phone: json['phone']?.toString(),
    );
  }
}

class SessionPayload {
  const SessionPayload({
    required this.user,
    required this.workspaces,
    required this.permissions,
    this.token,
    this.workspace,
    this.financeEnabled = false,
    this.message,
  });

  final String? token;
  final AuthUser user;
  final WorkspaceInfo? workspace;
  final List<WorkspaceInfo> workspaces;
  final Map<String, bool> permissions;
  final bool financeEnabled;
  final String? message;

  factory SessionPayload.fromJson(Map<String, dynamic> json) {
    return SessionPayload(
      token: json['token']?.toString(),
      user: AuthUser.fromJson(Map<String, dynamic>.from(json['user'] as Map)),
      workspace: json['workspace'] is Map
          ? WorkspaceInfo.fromJson(Map<String, dynamic>.from(json['workspace'] as Map))
          : null,
      workspaces: (json['workspaces'] as List? ?? [])
          .whereType<Map>()
          .map((row) => WorkspaceInfo.fromJson(Map<String, dynamic>.from(row)))
          .toList(),
      permissions: (json['permissions'] as Map? ?? {}).map(
        (key, value) => MapEntry(key.toString(), value == true),
      ),
      financeEnabled: json['finance_enabled'] == true,
      message: json['message']?.toString(),
    );
  }
}

class GoogleStartResult {
  const GoogleStartResult({required this.ticket, required this.authUrl, this.expiresIn});

  final String ticket;
  final String authUrl;
  final int? expiresIn;

  factory GoogleStartResult.fromJson(Map<String, dynamic> json) {
    return GoogleStartResult(
      ticket: json['ticket']?.toString() ?? '',
      authUrl: json['auth_url']?.toString() ?? '',
      expiresIn: int.tryParse('${json['expires_in'] ?? ''}'),
    );
  }
}

class GoogleStatusResult {
  const GoogleStatusResult({required this.status, this.accessToken, this.error});

  final String status;
  final String? accessToken;
  final String? error;

  factory GoogleStatusResult.fromJson(Map<String, dynamic> json) {
    return GoogleStatusResult(
      status: json['status']?.toString() ?? '',
      accessToken: json['access_token']?.toString(),
      error: json['error']?.toString(),
    );
  }
}

class MoneyFields {
  static String asMoney(dynamic value) {
    if (value == null) return '0.00';
    return value.toString();
  }
}

class CustomerRecord {
  CustomerRecord({
    required this.id,
    required this.name,
    required this.outstandingBalance,
    this.partyType,
    this.email,
    this.phone,
    this.vatNumber,
    this.commercialRegistration,
    this.address,
    this.street,
    this.city,
    this.countryCode,
    this.notes,
    this.whatsapp,
    this.buildingNumber,
    this.district,
    this.postalCode,
    this.additionalNumber,
    this.paymentTerms,
    this.invoices = const [],
    this.quotes = const [],
    this.payments = const [],
    this.receipts = const [],
    this.contracts = const [],
  });

  final int id;
  final String name;
  final String? partyType;
  final String? email;
  final String? phone;
  final String? vatNumber;
  final String? commercialRegistration;
  final String? address;
  final String? street;
  final String? city;
  final String? countryCode;
  final String? notes;
  final String? whatsapp;
  final String? buildingNumber;
  final String? district;
  final String? postalCode;
  final String? additionalNumber;
  final String? paymentTerms;
  final String outstandingBalance;
  final List<InvoiceRecord> invoices;
  final List<QuoteRecord> quotes;
  final List<PaymentRecord> payments;
  final List<ReceiptRecord> receipts;
  final List<ContractRecord> contracts;

  factory CustomerRecord.fromJson(Map<String, dynamic> json) {
    return CustomerRecord(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? '',
      partyType: json['party_type']?.toString(),
      email: json['email']?.toString(),
      phone: json['phone']?.toString(),
      vatNumber: json['vat_number']?.toString(),
      commercialRegistration: json['commercial_registration']?.toString(),
      address: json['address']?.toString(),
      street: json['street']?.toString(),
      city: json['city']?.toString(),
      countryCode: json['country_code']?.toString(),
      notes: json['notes']?.toString(),
      whatsapp: json['whatsapp']?.toString(),
      buildingNumber: json['building_number']?.toString(),
      district: json['district']?.toString(),
      postalCode: json['postal_code']?.toString(),
      additionalNumber: json['additional_number']?.toString(),
      paymentTerms: json['payment_terms']?.toString(),
      outstandingBalance: MoneyFields.asMoney(json['outstanding_balance']),
      invoices: _mapList(json['invoices'], InvoiceRecord.fromJson),
      quotes: _mapList(json['quotes'], QuoteRecord.fromJson),
      payments: _mapList(json['payments'], PaymentRecord.fromJson),
      receipts: _mapList(json['receipts'], ReceiptRecord.fromJson),
      contracts: _mapList(json['contracts'], ContractRecord.fromJson),
    );
  }
}

class LineItem {
  const LineItem({
    this.id,
    this.productId,
    this.productName,
    this.description,
    this.unit,
    this.quantity,
    this.unitPrice,
    this.discount,
    this.taxRate,
    this.taxAmount,
    this.taxableAmount,
    this.taxProfileType,
    this.exemptionReason,
    this.exemptionCode,
    this.total,
  });

  final int? id;
  final int? productId;
  final String? productName;
  final String? description;
  final String? unit;
  final String? quantity;
  final String? unitPrice;
  final String? discount;
  final String? taxRate;
  final String? taxAmount;
  final String? taxableAmount;
  final String? taxProfileType;
  final String? exemptionReason;
  final String? exemptionCode;
  final String? total;

  factory LineItem.fromJson(Map<String, dynamic> json) {
    return LineItem(
      id: json['id'] == null ? null : int.tryParse('${json['id']}'),
      productId: json['product_id'] == null ? null : int.tryParse('${json['product_id']}'),
      productName: json['product_name']?.toString(),
      description: json['description']?.toString(),
      unit: json['unit']?.toString(),
      quantity: json['quantity']?.toString(),
      unitPrice: MoneyFields.asMoney(json['unit_price']),
      discount: MoneyFields.asMoney(json['discount']),
      taxRate: MoneyFields.asMoney(json['tax_rate']),
      taxAmount: MoneyFields.asMoney(json['tax_amount']),
      taxableAmount: MoneyFields.asMoney(json['taxable_amount']),
      taxProfileType: json['tax_profile_type']?.toString(),
      exemptionReason: json['exemption_reason']?.toString(),
      exemptionCode: json['exemption_code']?.toString(),
      total: MoneyFields.asMoney(json['total']),
    );
  }

  Map<String, dynamic> toPayload() => {
        if (productId != null) 'product_id': productId,
        'product_name': productName,
        'description': description,
        'unit': unit,
        'quantity': quantity,
        'unit_price': unitPrice,
        'discount': discount,
        'tax_rate': taxRate,
        if (taxProfileType != null) 'tax_profile_type': taxProfileType,
        if (exemptionReason != null && exemptionReason!.isNotEmpty) 'exemption_reason': exemptionReason,
      };
}

class DeliveryRecord {
  const DeliveryRecord({
    required this.id,
    this.channel,
    this.recipient,
    this.status,
    this.subject,
    this.error,
    this.sentAt,
  });

  final int id;
  final String? channel;
  final String? recipient;
  final String? status;
  final String? subject;
  final String? error;
  final String? sentAt;

  factory DeliveryRecord.fromJson(Map<String, dynamic> json) {
    return DeliveryRecord(
      id: int.parse('${json['id']}'),
      channel: json['channel']?.toString(),
      recipient: json['recipient']?.toString(),
      status: json['status']?.toString(),
      subject: json['subject']?.toString(),
      error: json['error']?.toString(),
      sentAt: json['sent_at']?.toString(),
    );
  }
}

class CheckoutInfo {
  const CheckoutInfo({
    required this.supported,
    this.checkoutUrl,
    this.code,
    this.message,
  });

  final bool supported;
  final String? checkoutUrl;
  final String? code;
  final String? message;

  bool get hasUrl => checkoutUrl != null && checkoutUrl!.isNotEmpty;

  factory CheckoutInfo.fromJson(Map<String, dynamic> json) {
    return CheckoutInfo(
      supported: json['supported'] == true,
      checkoutUrl: json['checkout_url']?.toString(),
      code: json['code']?.toString(),
      message: json['message']?.toString(),
    );
  }
}

class InvoiceRecord {
  InvoiceRecord({
    required this.id,
    this.invoiceNumber,
    this.customerId,
    this.customerName,
    this.supplierName,
    this.issueDate,
    this.dueDate,
    this.supplyDate,
    this.issuedAt,
    this.currency = 'SAR',
    this.subtotal = '0.00',
    this.discount = '0.00',
    this.taxableAmount = '0.00',
    this.taxAmount = '0.00',
    this.taxRate,
    this.taxProfileType,
    this.taxPriceMode,
    this.total = '0.00',
    this.amountPaid = '0.00',
    this.amountDue = '0.00',
    this.amountCredited = '0.00',
    this.amountDebited = '0.00',
    this.documentStatus,
    this.paymentStatus,
    this.deliveryStatus,
    this.notes,
    this.paymentTerms,
    this.contractId,
    this.contractNumber,
    this.contractTitle,
    this.projectId,
    this.projectName,
    this.type,
    this.supplierId,
    this.zatcaRequirement,
    this.zatcaSubtype,
    this.hasZatcaQr = false,
    this.zatcaXmlAvailable = false,
    this.zatcaQrAvailable = false,
    this.zatcaClearance = false,
    this.zatcaIntegration,
    this.zatcaReason,
    this.taxBreakdown = const [],
    this.companySnapshot,
    this.recipientSnapshot,
    this.lines = const [],
    this.payments = const [],
    this.receipts = const [],
    this.creditNotes = const [],
    this.deliveries = const [],
    this.checkout,
    this.audit = const [],
    this.attachments = const [],
    this.journalEntries = const [],
  });

  final int id;
  final String? invoiceNumber;
  final int? customerId;
  final String? customerName;
  final String? supplierName;
  final String? issueDate;
  final String? dueDate;
  final String? supplyDate;
  final String? issuedAt;
  final String currency;
  final String subtotal;
  final String discount;
  final String taxableAmount;
  final String taxAmount;
  final String? taxRate;
  final String? taxProfileType;
  final String? taxPriceMode;
  final String total;
  final String amountPaid;
  final String amountDue;
  final String amountCredited;
  final String amountDebited;
  final String? documentStatus;
  final String? paymentStatus;
  final String? deliveryStatus;
  final String? notes;
  final String? paymentTerms;
  final int? contractId;
  final String? contractNumber;
  final String? contractTitle;
  final int? projectId;
  final String? projectName;
  final String? type;
  final int? supplierId;
  final String? zatcaRequirement;
  final String? zatcaSubtype;
  final bool hasZatcaQr;
  final bool zatcaXmlAvailable;
  final bool zatcaQrAvailable;
  final bool zatcaClearance;
  final String? zatcaIntegration;
  final String? zatcaReason;
  final List<Map<String, dynamic>> taxBreakdown;
  final Map<String, dynamic>? companySnapshot;
  final Map<String, dynamic>? recipientSnapshot;
  final List<LineItem> lines;
  final List<PaymentRecord> payments;
  final List<ReceiptRecord> receipts;
  final List<NoteRecord> creditNotes;
  final List<DeliveryRecord> deliveries;
  final CheckoutInfo? checkout;
  final List<Map<String, dynamic>> audit;
  final List<Map<String, dynamic>> attachments;
  final List<Map<String, dynamic>> journalEntries;

  factory InvoiceRecord.fromJson(Map<String, dynamic> json) {
    final zatca = json['zatca'] is Map ? Map<String, dynamic>.from(json['zatca'] as Map) : null;
    return InvoiceRecord(
      id: int.parse('${json['id']}'),
      invoiceNumber: json['invoice_number']?.toString(),
      customerId: json['customer_id'] == null ? null : int.tryParse('${json['customer_id']}'),
      customerName: json['customer_name']?.toString(),
      supplierName: json['supplier_name']?.toString(),
      issueDate: json['issue_date']?.toString(),
      dueDate: json['due_date']?.toString(),
      supplyDate: json['supply_date']?.toString(),
      issuedAt: json['issued_at']?.toString(),
      currency: json['currency']?.toString() ?? 'SAR',
      subtotal: MoneyFields.asMoney(json['subtotal']),
      discount: MoneyFields.asMoney(json['discount']),
      taxableAmount: MoneyFields.asMoney(json['taxable_amount']),
      taxAmount: MoneyFields.asMoney(json['tax_amount']),
      taxRate: json['tax_rate']?.toString(),
      taxProfileType: json['tax_profile_type']?.toString(),
      taxPriceMode: json['tax_price_mode']?.toString(),
      total: MoneyFields.asMoney(json['total']),
      amountPaid: MoneyFields.asMoney(json['amount_paid']),
      amountDue: MoneyFields.asMoney(json['amount_due']),
      amountCredited: MoneyFields.asMoney(json['amount_credited']),
      amountDebited: MoneyFields.asMoney(json['amount_debited']),
      documentStatus: json['document_status']?.toString(),
      paymentStatus: json['payment_status']?.toString(),
      deliveryStatus: json['delivery_status']?.toString(),
      notes: json['notes']?.toString(),
      paymentTerms: json['payment_terms']?.toString(),
      contractId: json['contract_id'] == null ? null : int.tryParse('${json['contract_id']}'),
      contractNumber: json['contract_number']?.toString(),
      contractTitle: json['contract_title']?.toString(),
      projectId: json['project_id'] == null ? null : int.tryParse('${json['project_id']}'),
      projectName: json['project_name']?.toString(),
      type: json['type']?.toString(),
      supplierId: json['supplier_id'] == null ? null : int.tryParse('${json['supplier_id']}'),
      zatcaRequirement: zatca?['requirement']?.toString(),
      zatcaSubtype: zatca?['tax_document_subtype']?.toString(),
      hasZatcaQr: zatca?['qr_available'] == true || zatca?['has_qr'] == true,
      zatcaXmlAvailable: zatca?['xml_available'] == true || zatca?['has_xml'] == true,
      zatcaQrAvailable: zatca?['qr_available'] == true || zatca?['has_qr'] == true,
      zatcaClearance: zatca?['clearance'] == true,
      zatcaIntegration: zatca?['integration']?.toString(),
      zatcaReason: zatca?['reason']?.toString(),
      taxBreakdown: (json['tax_breakdown'] as List? ?? [])
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList(),
      companySnapshot: json['company_snapshot'] is Map
          ? Map<String, dynamic>.from(json['company_snapshot'] as Map)
          : null,
      recipientSnapshot: json['recipient_snapshot'] is Map
          ? Map<String, dynamic>.from(json['recipient_snapshot'] as Map)
          : null,
      lines: _mapList(json['lines'], LineItem.fromJson),
      payments: _mapList(json['payments'], PaymentRecord.fromJson),
      receipts: _mapList(json['receipts'], ReceiptRecord.fromJson),
      creditNotes: _mapList(json['credit_notes'], NoteRecord.fromJson),
      deliveries: _mapList(json['deliveries'], DeliveryRecord.fromJson),
      checkout: json['checkout'] is Map
          ? CheckoutInfo.fromJson(Map<String, dynamic>.from(json['checkout'] as Map))
          : null,
      audit: (json['audit'] as List? ?? [])
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList(),
      attachments: (json['attachments'] as List? ?? [])
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList(),
      journalEntries: (json['journal_entries'] as List? ?? [])
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList(),
    );
  }
}

class QuoteRecord {
  QuoteRecord({
    required this.id,
    this.quoteNumber,
    this.customerId,
    this.customerName,
    this.issueDate,
    this.expiryDate,
    this.total = '0.00',
    this.subtotal = '0.00',
    this.discount = '0.00',
    this.taxableAmount = '0.00',
    this.taxAmount = '0.00',
    this.taxRate,
    this.taxProfileType,
    this.documentStatus,
    this.outcome,
    this.deliveryStatus,
    this.convertedInvoiceId,
    this.convertedInvoiceNumber,
    this.notes,
    this.terms,
    this.rejectionReason,
    this.lines = const [],
    this.deliveries = const [],
    this.attachments = const [],
    this.currency = 'SAR',
  });

  final int id;
  final String? quoteNumber;
  final int? customerId;
  final String? customerName;
  final String? issueDate;
  final String? expiryDate;
  final String total;
  final String subtotal;
  final String discount;
  final String taxableAmount;
  final String taxAmount;
  final String? taxRate;
  final String? taxProfileType;
  final String? documentStatus;
  final String? outcome;
  final String? deliveryStatus;
  final int? convertedInvoiceId;
  final String? convertedInvoiceNumber;
  final String? notes;
  final String? terms;
  final String? rejectionReason;
  final List<LineItem> lines;
  final List<DeliveryRecord> deliveries;
  final List<Map<String, dynamic>> attachments;
  final String currency;

  factory QuoteRecord.fromJson(Map<String, dynamic> json) {
    return QuoteRecord(
      id: int.parse('${json['id']}'),
      quoteNumber: json['quote_number']?.toString(),
      customerId: json['customer_id'] == null ? null : int.tryParse('${json['customer_id']}'),
      customerName: json['customer_name']?.toString(),
      issueDate: json['issue_date']?.toString(),
      expiryDate: json['expiry_date']?.toString(),
      total: MoneyFields.asMoney(json['total']),
      subtotal: MoneyFields.asMoney(json['subtotal']),
      discount: MoneyFields.asMoney(json['discount']),
      taxableAmount: MoneyFields.asMoney(json['taxable_amount']),
      taxAmount: MoneyFields.asMoney(json['tax_amount']),
      taxRate: json['tax_rate']?.toString(),
      taxProfileType: json['tax_profile_type']?.toString(),
      documentStatus: json['document_status']?.toString(),
      outcome: json['outcome']?.toString(),
      deliveryStatus: json['delivery_status']?.toString(),
      convertedInvoiceId:
          json['converted_invoice_id'] == null ? null : int.tryParse('${json['converted_invoice_id']}'),
      convertedInvoiceNumber: json['converted_invoice_number']?.toString(),
      notes: json['notes']?.toString(),
      terms: json['terms']?.toString(),
      rejectionReason: json['rejection_reason']?.toString(),
      lines: _mapList(json['lines'], LineItem.fromJson),
      deliveries: _mapList(json['deliveries'], DeliveryRecord.fromJson),
      attachments: (json['attachments'] as List? ?? [])
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList(),
      currency: json['currency']?.toString() ?? 'SAR',
    );
  }
}

class PaymentRecord {
  const PaymentRecord({
    required this.id,
    this.invoiceId,
    this.invoiceNumber,
    this.customerId,
    this.customerName,
    this.paymentDate,
    this.amount = '0.00',
    this.method,
    this.reference,
    this.status,
    this.notes,
    this.reversedAt,
    this.receiptId,
    this.receiptNumber,
    this.receiptStatus,
    this.reversalReason,
    this.treasuryAccountId,
    this.treasuryAccountName,
  });

  final int id;
  final int? invoiceId;
  final String? invoiceNumber;
  final int? customerId;
  final String? customerName;
  final String? paymentDate;
  final String amount;
  final String? method;
  final String? reference;
  final String? status;
  final String? notes;
  final String? reversedAt;
  final int? receiptId;
  final String? receiptNumber;
  final String? receiptStatus;
  final String? reversalReason;
  final int? treasuryAccountId;
  final String? treasuryAccountName;

  factory PaymentRecord.fromJson(Map<String, dynamic> json) {
    return PaymentRecord(
      id: int.parse('${json['id']}'),
      invoiceId: json['invoice_id'] == null ? null : int.tryParse('${json['invoice_id']}'),
      invoiceNumber: json['invoice_number']?.toString(),
      customerId: json['customer_id'] == null ? null : int.tryParse('${json['customer_id']}'),
      customerName: json['customer_name']?.toString(),
      paymentDate: json['payment_date']?.toString(),
      amount: MoneyFields.asMoney(json['amount']),
      method: json['method']?.toString(),
      reference: json['reference']?.toString(),
      status: json['status']?.toString(),
      notes: json['notes']?.toString(),
      reversedAt: json['reversed_at']?.toString(),
      receiptId: json['receipt_id'] == null ? null : int.tryParse('${json['receipt_id']}'),
      receiptNumber: json['receipt_number']?.toString(),
      receiptStatus: json['receipt_status']?.toString(),
      reversalReason: json['reversal_reason']?.toString(),
      treasuryAccountId: json['treasury_account_id'] == null ? null : int.tryParse('${json['treasury_account_id']}'),
      treasuryAccountName: json['treasury_account_name']?.toString(),
    );
  }
}

class ReceiptRecord {
  ReceiptRecord({
    required this.id,
    this.receiptNumber,
    this.invoiceId,
    this.invoiceNumber,
    this.customerId,
    this.customerName,
    this.paymentId,
    this.amount = '0.00',
    this.paymentDate,
    this.method,
    this.reference,
    this.currency = 'SAR',
    this.status,
    this.voidedAt,
    this.deliveries = const [],
  });

  final int id;
  final String? receiptNumber;
  final int? invoiceId;
  final String? invoiceNumber;
  final int? customerId;
  final String? customerName;
  final int? paymentId;
  final String amount;
  final String? paymentDate;
  final String? method;
  final String? reference;
  final String currency;
  final String? status;
  final String? voidedAt;
  final List<DeliveryRecord> deliveries;

  factory ReceiptRecord.fromJson(Map<String, dynamic> json) {
    return ReceiptRecord(
      id: int.parse('${json['id']}'),
      receiptNumber: json['receipt_number']?.toString(),
      invoiceId: json['invoice_id'] == null ? null : int.tryParse('${json['invoice_id']}'),
      invoiceNumber: json['invoice_number']?.toString(),
      customerId: json['customer_id'] == null ? null : int.tryParse('${json['customer_id']}'),
      customerName: json['customer_name']?.toString(),
      paymentId: json['payment_id'] == null ? null : int.tryParse('${json['payment_id']}'),
      amount: MoneyFields.asMoney(json['amount']),
      paymentDate: json['payment_date']?.toString(),
      method: json['method']?.toString(),
      reference: json['reference']?.toString(),
      currency: json['currency']?.toString() ?? 'SAR',
      status: json['status']?.toString(),
      voidedAt: json['voided_at']?.toString(),
      deliveries: _mapList(json['deliveries'], DeliveryRecord.fromJson),
    );
  }
}

class NoteRecord {
  const NoteRecord({
    required this.id,
    this.noteNumber,
    this.type,
    this.status,
    this.invoiceId,
    this.invoiceNumber,
    this.customerId,
    this.customerName,
    this.issueDate,
    this.total = '0.00',
    this.subtotal = '0.00',
    this.taxAmount = '0.00',
    this.currency = 'SAR',
    this.reason,
    this.notes,
    this.lines = const [],
  });

  final int id;
  final String? noteNumber;
  final String? type;
  final String? status;
  final int? invoiceId;
  final String? invoiceNumber;
  final int? customerId;
  final String? customerName;
  final String? issueDate;
  final String total;
  final String subtotal;
  final String taxAmount;
  final String currency;
  final String? reason;
  final String? notes;
  final List<LineItem> lines;

  factory NoteRecord.fromJson(Map<String, dynamic> json) {
    return NoteRecord(
      id: int.parse('${json['id']}'),
      noteNumber: json['note_number']?.toString(),
      type: json['type']?.toString(),
      status: json['status']?.toString(),
      invoiceId: json['invoice_id'] == null ? null : int.tryParse('${json['invoice_id']}'),
      invoiceNumber: json['invoice_number']?.toString(),
      customerId: json['customer_id'] == null ? null : int.tryParse('${json['customer_id']}'),
      customerName: json['customer_name']?.toString(),
      issueDate: json['issue_date']?.toString(),
      total: MoneyFields.asMoney(json['total']),
      subtotal: MoneyFields.asMoney(json['subtotal']),
      taxAmount: MoneyFields.asMoney(json['tax_amount']),
      currency: json['currency']?.toString() ?? 'SAR',
      reason: json['reason']?.toString(),
      notes: json['notes']?.toString(),
      lines: _mapList(json['lines'], LineItem.fromJson),
    );
  }
}

class ExpenseRecord {
  const ExpenseRecord({
    required this.id,
    this.expenseNumber,
    this.expenseDate,
    this.description,
    this.categoryId,
    this.categoryName,
    this.supplierId,
    this.supplierName,
    this.amount = '0.00',
    this.taxRate,
    this.taxAmount = '0.00',
    this.total = '0.00',
    this.currency = 'SAR',
    this.paymentMethod,
    this.status,
    this.hasAttachment = false,
    this.treasuryAccountId,
    this.treasuryAccountName,
    this.isRecurring = false,
    this.recurringFrequency,
    this.nextDueDate,
    this.taxProfileType,
  });

  final int id;
  final String? expenseNumber;
  final String? expenseDate;
  final String? description;
  final int? categoryId;
  final String? categoryName;
  final int? supplierId;
  final String? supplierName;
  final String amount;
  final String? taxRate;
  final String taxAmount;
  final String total;
  final String currency;
  final String? paymentMethod;
  final String? status;
  final bool hasAttachment;
  final int? treasuryAccountId;
  final String? treasuryAccountName;
  final bool isRecurring;
  final String? recurringFrequency;
  final String? nextDueDate;
  final String? taxProfileType;

  factory ExpenseRecord.fromJson(Map<String, dynamic> json) {
    return ExpenseRecord(
      id: int.parse('${json['id']}'),
      expenseNumber: json['expense_number']?.toString(),
      expenseDate: json['expense_date']?.toString(),
      description: json['description']?.toString(),
      categoryId: json['category_id'] == null ? null : int.tryParse('${json['category_id']}'),
      categoryName: json['category_name']?.toString(),
      supplierId: json['supplier_id'] == null ? null : int.tryParse('${json['supplier_id']}'),
      supplierName: json['supplier_name']?.toString(),
      amount: MoneyFields.asMoney(json['amount']),
      taxRate: json['tax_rate']?.toString(),
      taxAmount: MoneyFields.asMoney(json['tax_amount']),
      total: MoneyFields.asMoney(json['total']),
      currency: json['currency']?.toString() ?? 'SAR',
      paymentMethod: json['payment_method']?.toString(),
      status: json['status']?.toString(),
      hasAttachment: json['has_attachment'] == true,
      treasuryAccountId: json['treasury_account_id'] == null ? null : int.tryParse('${json['treasury_account_id']}'),
      treasuryAccountName: json['treasury_account_name']?.toString(),
      isRecurring: json['is_recurring'] == true,
      recurringFrequency: json['recurring_frequency']?.toString(),
      nextDueDate: json['next_due_date']?.toString(),
      taxProfileType: json['tax_profile_type']?.toString(),
    );
  }
}

class ContractItemRecord {
  const ContractItemRecord({
    this.id,
    this.title,
    this.description,
    this.quantity,
    this.unitPrice,
    this.total,
  });

  final int? id;
  final String? title;
  final String? description;
  final String? quantity;
  final String? unitPrice;
  final String? total;

  factory ContractItemRecord.fromJson(Map<String, dynamic> json) {
    return ContractItemRecord(
      id: json['id'] == null ? null : int.tryParse('${json['id']}'),
      title: json['title']?.toString(),
      description: json['description']?.toString(),
      quantity: json['quantity']?.toString(),
      unitPrice: MoneyFields.asMoney(json['unit_price']),
      total: MoneyFields.asMoney(json['total']),
    );
  }
}

class BillingScheduleRecord {
  const BillingScheduleRecord({
    required this.id,
    this.title,
    this.frequency,
    this.status,
    this.startDate,
    this.endDate,
    this.nextRunOn,
    this.intervalCount,
    this.totalOccurrences,
    this.generatedCount,
    this.amount = '0.00',
    this.currency = 'SAR',
    this.autoIssue = false,
    this.notes,
  });

  final int id;
  final String? title;
  final String? frequency;
  final String? status;
  final String? startDate;
  final String? endDate;
  final String? nextRunOn;
  final int? intervalCount;
  final int? totalOccurrences;
  final int? generatedCount;
  final String amount;
  final String currency;
  final bool autoIssue;
  final String? notes;

  factory BillingScheduleRecord.fromJson(Map<String, dynamic> json) {
    return BillingScheduleRecord(
      id: int.parse('${json['id']}'),
      title: json['title']?.toString(),
      frequency: json['frequency']?.toString(),
      status: json['status']?.toString(),
      startDate: json['start_date']?.toString(),
      endDate: json['end_date']?.toString(),
      nextRunOn: json['next_run_on']?.toString(),
      intervalCount: json['interval_count'] == null ? null : int.tryParse('${json['interval_count']}'),
      totalOccurrences: json['total_occurrences'] == null ? null : int.tryParse('${json['total_occurrences']}'),
      generatedCount: json['generated_count'] == null ? null : int.tryParse('${json['generated_count']}'),
      amount: MoneyFields.asMoney(json['amount']),
      currency: json['currency']?.toString() ?? 'SAR',
      autoIssue: json['auto_issue'] == true,
      notes: json['notes']?.toString(),
    );
  }

  Map<String, dynamic> toLegacyMap() => {
        'id': id,
        'title': title,
        'frequency': frequency,
        'status': status,
        'start_date': startDate,
        'end_date': endDate,
        'next_run_on': nextRunOn,
        'interval_count': intervalCount,
        'total_occurrences': totalOccurrences,
        'generated_count': generatedCount,
        'amount': amount,
        'currency': currency,
        'auto_issue': autoIssue,
        'notes': notes,
      };
}

class ContractRecord {
  const ContractRecord({
    required this.id,
    this.contractNumber,
    this.title,
    this.status,
    this.customerId,
    this.customerName,
    this.startDate,
    this.endDate,
    this.value = '0.00',
    this.currency = 'SAR',
    this.notes,
    this.terms,
    this.items = const [],
    this.scheduleRecords = const [],
    this.generatedInvoices = const [],
    this.billingSummary = const {},
    this.attachments = const [],
  });

  final int id;
  final String? contractNumber;
  final String? title;
  final String? status;
  final int? customerId;
  final String? customerName;
  final String? startDate;
  final String? endDate;
  final String value;
  final String currency;
  final String? notes;
  final String? terms;
  final List<ContractItemRecord> items;
  final List<BillingScheduleRecord> scheduleRecords;
  final List<InvoiceRecord> generatedInvoices;
  final Map<String, dynamic> billingSummary;
  final List<Map<String, dynamic>> attachments;

  List<Map<String, dynamic>> get schedules => scheduleRecords.map((row) => row.toLegacyMap()).toList();

  factory ContractRecord.fromJson(Map<String, dynamic> json) {
    return ContractRecord(
      id: int.parse('${json['id']}'),
      contractNumber: json['contract_number']?.toString(),
      title: json['title']?.toString(),
      status: json['status']?.toString(),
      customerId: json['customer_id'] == null ? null : int.tryParse('${json['customer_id']}'),
      customerName: json['customer_name']?.toString(),
      startDate: json['start_date']?.toString(),
      endDate: json['end_date']?.toString(),
      value: MoneyFields.asMoney(json['value']),
      currency: json['currency']?.toString() ?? 'SAR',
      notes: json['notes']?.toString(),
      terms: json['terms']?.toString(),
      items: _mapList(json['items'], ContractItemRecord.fromJson),
      scheduleRecords: _mapList(json['billing_schedules'], BillingScheduleRecord.fromJson),
      generatedInvoices: _mapList(json['generated_invoices'], InvoiceRecord.fromJson),
      billingSummary: json['billing_summary'] is Map
          ? Map<String, dynamic>.from(json['billing_summary'] as Map)
          : const {},
      attachments: (json['attachments'] as List? ?? [])
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList(),
    );
  }
}

class StatementRecord {
  const StatementRecord({
    required this.openingBalance,
    required this.closingBalance,
    required this.lines,
    this.customerName,
    this.customerId,
    this.from,
    this.to,
    this.invoicesTotal = '0.00',
    this.paymentsTotal = '0.00',
    this.creditsTotal = '0.00',
    this.debitsTotal = '0.00',
  });

  final String? customerName;
  final int? customerId;
  final String? from;
  final String? to;
  final String openingBalance;
  final String closingBalance;
  final String invoicesTotal;
  final String paymentsTotal;
  final String creditsTotal;
  final String debitsTotal;
  final List<Map<String, dynamic>> lines;

  factory StatementRecord.fromJson(Map<String, dynamic> json) {
    final customer = json['customer'] is Map ? Map<String, dynamic>.from(json['customer'] as Map) : null;
    return StatementRecord(
      customerName: customer?['name']?.toString(),
      customerId: customer?['id'] == null ? null : int.tryParse('${customer?['id']}'),
      from: json['from']?.toString(),
      to: json['to']?.toString(),
      openingBalance: MoneyFields.asMoney(json['opening_balance']),
      closingBalance: MoneyFields.asMoney(json['closing_balance']),
      invoicesTotal: MoneyFields.asMoney(json['invoices_total']),
      paymentsTotal: MoneyFields.asMoney(json['payments_total']),
      creditsTotal: MoneyFields.asMoney(json['credits_total']),
      debitsTotal: MoneyFields.asMoney(json['debits_total']),
      lines: (json['lines'] as List? ?? [])
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList(),
    );
  }
}

class DashboardData {
  const DashboardData({
    required this.cards,
    required this.recentInvoices,
    required this.recentPayments,
    required this.overdueInvoices,
    this.recentExpenses = const [],
    this.analytics = const {},
  });

  final Map<String, String> cards;
  final List<InvoiceRecord> recentInvoices;
  final List<PaymentRecord> recentPayments;
  final List<InvoiceRecord> overdueInvoices;
  final List<ExpenseRecord> recentExpenses;
  final Map<String, dynamic> analytics;

  factory DashboardData.fromJson(Map<String, dynamic> json) {
    final cardsRaw = json['cards'] as Map? ?? {};
    return DashboardData(
      cards: cardsRaw.map((key, value) => MapEntry(key.toString(), value.toString())),
      recentInvoices: _mapList(json['recent_invoices'], InvoiceRecord.fromJson),
      recentPayments: _mapList(json['recent_payments'], PaymentRecord.fromJson),
      overdueInvoices: _mapList(json['overdue_invoices'], InvoiceRecord.fromJson),
      recentExpenses: _mapList(json['recent_expenses'], ExpenseRecord.fromJson),
      analytics: Map<String, dynamic>.from(json['analytics'] as Map? ?? {}),
    );
  }
}

class SupplierRecord {
  const SupplierRecord({
    required this.id,
    required this.name,
    this.arabicName,
    this.vatNumber,
    this.commercialRegistration,
    this.address,
    this.email,
    this.phone,
    this.paymentTerms,
    this.status,
    this.openingBalance = '0.00',
  });

  final int id;
  final String name;
  final String? arabicName;
  final String? vatNumber;
  final String? commercialRegistration;
  final String? address;
  final String? email;
  final String? phone;
  final String? paymentTerms;
  final String? status;
  final String openingBalance;

  factory SupplierRecord.fromJson(Map<String, dynamic> json) {
    return SupplierRecord(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? '',
      arabicName: json['arabic_name']?.toString(),
      vatNumber: json['vat_number']?.toString(),
      commercialRegistration: json['commercial_registration']?.toString(),
      address: json['address']?.toString(),
      email: json['email']?.toString(),
      phone: json['phone']?.toString(),
      paymentTerms: json['payment_terms']?.toString(),
      status: json['status']?.toString(),
      openingBalance: MoneyFields.asMoney(json['opening_balance']),
    );
  }
}

class PagedResult<T> {
  const PagedResult({
    required this.items,
    required this.page,
    required this.lastPage,
    required this.total,
    this.meta,
  });

  final List<T> items;
  final int page;
  final int lastPage;
  final int total;
  final Map<String, dynamic>? meta;
}

class ProductRecord {
  const ProductRecord({
    required this.id,
    required this.name,
    this.sku,
    this.barcode,
    this.description,
    this.price = '0.00',
    this.salePrice,
    this.costPrice,
    this.vatRate,
    this.currency = 'SAR',
    this.stock,
    this.inventoryTracking = false,
    this.status,
    this.productKind,
    this.brand,
    this.categoryName,
    this.soldQty,
    this.soldTotal,
  });

  final int id;
  final String name;
  final String? sku;
  final String? barcode;
  final String? description;
  final String price;
  final String? salePrice;
  final String? costPrice;
  final String? vatRate;
  final String currency;
  final dynamic stock;
  final bool inventoryTracking;
  final String? status;
  final String? productKind;
  final String? brand;
  final String? categoryName;
  final String? soldQty;
  final String? soldTotal;

  factory ProductRecord.fromJson(Map<String, dynamic> json) {
    return ProductRecord(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? '',
      sku: json['sku']?.toString(),
      barcode: json['barcode']?.toString(),
      description: json['description']?.toString(),
      price: MoneyFields.asMoney(json['price']),
      salePrice: json['sale_price'] == null ? null : MoneyFields.asMoney(json['sale_price']),
      costPrice: json['cost_price'] == null ? null : MoneyFields.asMoney(json['cost_price']),
      vatRate: json['vat_rate']?.toString(),
      currency: json['currency']?.toString() ?? 'SAR',
      stock: json['stock'],
      inventoryTracking: json['inventory_tracking'] == true,
      status: json['status']?.toString(),
      productKind: json['product_kind']?.toString(),
      brand: json['brand']?.toString(),
      categoryName: json['category_name']?.toString(),
      soldQty: json['sold_qty']?.toString(),
      soldTotal: json['sold_total'] == null ? null : MoneyFields.asMoney(json['sold_total']),
    );
  }
}

class InventoryMovementRecord {
  const InventoryMovementRecord({
    required this.id,
    this.productId,
    this.productName,
    this.type,
    this.quantity,
    this.beforeQuantity,
    this.afterQuantity,
    this.referenceType,
    this.notes,
    this.actorName,
    this.createdAt,
  });

  final int id;
  final int? productId;
  final String? productName;
  final String? type;
  final String? quantity;
  final String? beforeQuantity;
  final String? afterQuantity;
  final String? referenceType;
  final String? notes;
  final String? actorName;
  final String? createdAt;

  factory InventoryMovementRecord.fromJson(Map<String, dynamic> json) {
    return InventoryMovementRecord(
      id: int.parse('${json['id']}'),
      productId: json['product_id'] == null ? null : int.tryParse('${json['product_id']}'),
      productName: json['product_name']?.toString(),
      type: json['type']?.toString(),
      quantity: json['quantity']?.toString(),
      beforeQuantity: json['before_quantity']?.toString(),
      afterQuantity: json['after_quantity']?.toString(),
      referenceType: json['reference_type']?.toString(),
      notes: json['notes']?.toString(),
      actorName: json['actor_name']?.toString(),
      createdAt: json['created_at']?.toString(),
    );
  }
}

class ProjectRecord {
  const ProjectRecord({
    required this.id,
    required this.name,
    this.status,
    this.customerId,
    this.customerName,
    this.budget = '0.00',
    this.startsOn,
    this.endsOn,
    this.notes,
    this.revenue = '0.00',
    this.costs = '0.00',
    this.profit = '0.00',
  });

  final int id;
  final String name;
  final String? status;
  final int? customerId;
  final String? customerName;
  final String budget;
  final String? startsOn;
  final String? endsOn;
  final String? notes;
  final String revenue;
  final String costs;
  final String profit;

  factory ProjectRecord.fromJson(Map<String, dynamic> json) {
    return ProjectRecord(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? '',
      status: json['status']?.toString(),
      customerId: json['customer_id'] == null ? null : int.tryParse('${json['customer_id']}'),
      customerName: json['customer_name']?.toString(),
      budget: MoneyFields.asMoney(json['budget']),
      startsOn: json['starts_on']?.toString(),
      endsOn: json['ends_on']?.toString(),
      notes: json['notes']?.toString(),
      revenue: MoneyFields.asMoney(json['revenue']),
      costs: MoneyFields.asMoney(json['costs']),
      profit: MoneyFields.asMoney(json['profit']),
    );
  }
}

class PriceListItemRecord {
  const PriceListItemRecord({
    required this.id,
    this.productId,
    this.productName,
    this.sku,
    this.minQuantity,
    this.price = '0.00',
    this.taxRate,
    this.isActive = true,
  });

  final int id;
  final int? productId;
  final String? productName;
  final String? sku;
  final String? minQuantity;
  final String price;
  final String? taxRate;
  final bool isActive;

  factory PriceListItemRecord.fromJson(Map<String, dynamic> json) {
    return PriceListItemRecord(
      id: int.parse('${json['id']}'),
      productId: json['product_id'] == null ? null : int.tryParse('${json['product_id']}'),
      productName: json['product_name']?.toString(),
      sku: json['sku']?.toString(),
      minQuantity: json['min_quantity']?.toString(),
      price: MoneyFields.asMoney(json['price']),
      taxRate: json['tax_rate']?.toString(),
      isActive: json['is_active'] != false,
    );
  }
}

class PriceListRecord {
  const PriceListRecord({
    required this.id,
    required this.name,
    this.code,
    this.currency = 'SAR',
    this.status,
    this.effectiveFrom,
    this.effectiveTo,
    this.notes,
    this.itemsCount = 0,
    this.items = const [],
  });

  final int id;
  final String name;
  final String? code;
  final String currency;
  final String? status;
  final String? effectiveFrom;
  final String? effectiveTo;
  final String? notes;
  final int itemsCount;
  final List<PriceListItemRecord> items;

  factory PriceListRecord.fromJson(Map<String, dynamic> json) {
    return PriceListRecord(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? '',
      code: json['code']?.toString(),
      currency: json['currency']?.toString() ?? 'SAR',
      status: json['status']?.toString(),
      effectiveFrom: json['effective_from']?.toString(),
      effectiveTo: json['effective_to']?.toString(),
      notes: json['notes']?.toString(),
      itemsCount: int.tryParse('${json['items_count'] ?? 0}') ?? 0,
      items: _mapList(json['items'], PriceListItemRecord.fromJson),
    );
  }
}

class PurchaseOrderRecord {
  const PurchaseOrderRecord({
    required this.id,
    this.poNumber,
    this.status,
    this.supplierId,
    this.supplierName,
    this.orderDate,
    this.expectedDate,
    this.currency = 'SAR',
    this.subtotal = '0.00',
    this.taxAmount = '0.00',
    this.total = '0.00',
    this.notes,
    this.invoiceId,
    this.items = const [],
  });

  final int id;
  final String? poNumber;
  final String? status;
  final int? supplierId;
  final String? supplierName;
  final String? orderDate;
  final String? expectedDate;
  final String currency;
  final String subtotal;
  final String taxAmount;
  final String total;
  final String? notes;
  final int? invoiceId;
  final List<LineItem> items;

  factory PurchaseOrderRecord.fromJson(Map<String, dynamic> json) {
    return PurchaseOrderRecord(
      id: int.parse('${json['id']}'),
      poNumber: json['po_number']?.toString(),
      status: json['status']?.toString(),
      supplierId: json['supplier_id'] == null ? null : int.tryParse('${json['supplier_id']}'),
      supplierName: json['supplier_name']?.toString(),
      orderDate: json['order_date']?.toString(),
      expectedDate: json['expected_date']?.toString(),
      currency: json['currency']?.toString() ?? 'SAR',
      subtotal: MoneyFields.asMoney(json['subtotal']),
      taxAmount: MoneyFields.asMoney(json['tax_amount']),
      total: MoneyFields.asMoney(json['total']),
      notes: json['notes']?.toString(),
      invoiceId: json['invoice_id'] == null ? null : int.tryParse('${json['invoice_id']}'),
      items: _mapList(json['items'], LineItem.fromJson),
    );
  }
}

class LeadRecord {
  const LeadRecord({
    required this.id,
    required this.name,
    this.companyName,
    this.email,
    this.phone,
    this.source,
    this.status,
    this.estimatedValue = '0.00',
    this.currency = 'SAR',
    this.notes,
    this.customerId,
  });

  final int id;
  final String name;
  final String? companyName;
  final String? email;
  final String? phone;
  final String? source;
  final String? status;
  final String estimatedValue;
  final String currency;
  final String? notes;
  final int? customerId;

  factory LeadRecord.fromJson(Map<String, dynamic> json) {
    return LeadRecord(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? '',
      companyName: json['company_name']?.toString(),
      email: json['email']?.toString(),
      phone: json['phone']?.toString(),
      source: json['source']?.toString(),
      status: json['status']?.toString(),
      estimatedValue: MoneyFields.asMoney(json['estimated_value']),
      currency: json['currency']?.toString() ?? 'SAR',
      notes: json['notes']?.toString(),
      customerId: json['customer_id'] == null ? null : int.tryParse('${json['customer_id']}'),
    );
  }
}

class FiscalYearRecord {
  const FiscalYearRecord({
    required this.id,
    required this.name,
    this.startDate,
    this.endDate,
    this.status,
    this.periodsCount = 0,
    this.periods = const [],
  });

  final int id;
  final String name;
  final String? startDate;
  final String? endDate;
  final String? status;
  final int periodsCount;
  final List<AccountingPeriodRecord> periods;

  factory FiscalYearRecord.fromJson(Map<String, dynamic> json) {
    return FiscalYearRecord(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? '',
      startDate: json['start_date']?.toString(),
      endDate: json['end_date']?.toString(),
      status: json['status']?.toString(),
      periodsCount: int.tryParse('${json['periods_count'] ?? 0}') ?? 0,
      periods: _mapList(json['periods'], AccountingPeriodRecord.fromJson),
    );
  }
}

class AccountingPeriodRecord {
  const AccountingPeriodRecord({
    required this.id,
    this.fiscalYearId,
    this.name,
    this.startDate,
    this.endDate,
    this.status,
  });

  final int id;
  final int? fiscalYearId;
  final String? name;
  final String? startDate;
  final String? endDate;
  final String? status;

  factory AccountingPeriodRecord.fromJson(Map<String, dynamic> json) {
    return AccountingPeriodRecord(
      id: int.parse('${json['id']}'),
      fiscalYearId: json['fiscal_year_id'] == null ? null : int.tryParse('${json['fiscal_year_id']}'),
      name: json['name']?.toString(),
      startDate: json['start_date']?.toString(),
      endDate: json['end_date']?.toString(),
      status: json['status']?.toString(),
    );
  }
}

class TreasuryAccountRecord {
  const TreasuryAccountRecord({
    required this.id,
    required this.name,
    this.type,
    this.accountNumber,
    this.iban,
    this.bankName,
    this.currency = 'SAR',
    this.openingBalance = '0.00',
    this.currentBalance = '0.00',
    this.isActive = true,
  });

  final int id;
  final String name;
  final String? type;
  final String? accountNumber;
  final String? iban;
  final String? bankName;
  final String currency;
  final String openingBalance;
  final String currentBalance;
  final bool isActive;

  factory TreasuryAccountRecord.fromJson(Map<String, dynamic> json) {
    return TreasuryAccountRecord(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? '',
      type: json['type']?.toString(),
      accountNumber: json['account_number']?.toString(),
      iban: json['iban']?.toString(),
      bankName: json['bank_name']?.toString(),
      currency: json['currency']?.toString() ?? 'SAR',
      openingBalance: MoneyFields.asMoney(json['opening_balance']),
      currentBalance: MoneyFields.asMoney(json['current_balance']),
      isActive: json['is_active'] != false,
    );
  }
}

class TreasuryTransferRecord {
  const TreasuryTransferRecord({
    required this.id,
    this.fromAccountName,
    this.toAccountName,
    this.amount = '0.00',
    this.transferDate,
    this.reference,
    this.status,
    this.notes,
  });

  final int id;
  final String? fromAccountName;
  final String? toAccountName;
  final String amount;
  final String? transferDate;
  final String? reference;
  final String? status;
  final String? notes;

  factory TreasuryTransferRecord.fromJson(Map<String, dynamic> json) {
    return TreasuryTransferRecord(
      id: int.parse('${json['id']}'),
      fromAccountName: json['from_account_name']?.toString(),
      toAccountName: json['to_account_name']?.toString(),
      amount: MoneyFields.asMoney(json['amount']),
      transferDate: json['transfer_date']?.toString(),
      reference: json['reference']?.toString(),
      status: json['status']?.toString(),
      notes: json['notes']?.toString(),
    );
  }
}

class CatalogOption {
  const CatalogOption({required this.id, required this.name, this.extra = const {}});

  final int id;
  final String name;
  final Map<String, dynamic> extra;

  factory CatalogOption.fromJson(Map<String, dynamic> json) {
    return CatalogOption(
      id: int.parse('${json['id']}'),
      name: json['name']?.toString() ?? json['title']?.toString() ?? '#${json['id']}',
      extra: json,
    );
  }
}

class FinanceCatalog {
  const FinanceCatalog({
    this.products = const [],
    this.taxRates = const [],
    this.contracts = const [],
    this.projects = const [],
    this.treasuryAccounts = const [],
    this.expenseCategories = const [],
    this.suppliers = const [],
    this.allowManualInvoiceNumbers = false,
    this.defaultVatRate,
  });

  final List<CatalogOption> products;
  final List<CatalogOption> taxRates;
  final List<CatalogOption> contracts;
  final List<CatalogOption> projects;
  final List<CatalogOption> treasuryAccounts;
  final List<CatalogOption> expenseCategories;
  final List<CatalogOption> suppliers;
  final bool allowManualInvoiceNumbers;
  final String? defaultVatRate;

  factory FinanceCatalog.fromBootstrap(Map<String, dynamic> raw) {
    final catalog = raw['catalog'] is Map ? Map<String, dynamic>.from(raw['catalog'] as Map) : raw;
    final settings = raw['settings'] is Map ? Map<String, dynamic>.from(raw['settings'] as Map) : const <String, dynamic>{};
    List<CatalogOption> list(String key) => _mapList(catalog[key], CatalogOption.fromJson);
    return FinanceCatalog(
      products: list('products'),
      taxRates: list('tax_rates'),
      contracts: list('contracts'),
      projects: list('projects'),
      treasuryAccounts: list('treasury_accounts'),
      expenseCategories: list('expense_categories'),
      suppliers: list('suppliers'),
      allowManualInvoiceNumbers: settings['allow_manual_invoice_numbers'] == true,
      defaultVatRate: settings['default_vat_rate']?.toString(),
    );
  }
}

class FinanceEmployeeRecord {
  const FinanceEmployeeRecord({
    required this.id,
    required this.fullName,
    this.employeeCode,
    this.jobTitle,
    this.basicSalary = '0.00',
    this.hireDate,
    this.status,
    this.phone,
    this.email,
    this.address,
    this.emergencyContact,
    this.notes,
    this.payrollRecordsCount = 0,
    this.summary = const {},
    this.payrollRecords = const [],
    this.advances = const [],
    this.adjustments = const [],
  });

  final int id;
  final String fullName;
  final String? employeeCode;
  final String? jobTitle;
  final String basicSalary;
  final String? hireDate;
  final String? status;
  final String? phone;
  final String? email;
  final String? address;
  final String? emergencyContact;
  final String? notes;
  final int payrollRecordsCount;
  final Map<String, String> summary;
  final List<PayrollRecord> payrollRecords;
  final List<SalaryAdvanceRecord> advances;
  final List<PayrollAdjustmentRecord> adjustments;

  factory FinanceEmployeeRecord.fromJson(Map<String, dynamic> json) {
    final summaryRaw = json['financial_summary'] as Map? ?? {};
    return FinanceEmployeeRecord(
      id: int.parse('${json['id']}'),
      fullName: json['full_name']?.toString() ?? '',
      employeeCode: json['employee_code']?.toString(),
      jobTitle: json['job_title']?.toString(),
      basicSalary: MoneyFields.asMoney(json['basic_salary']),
      hireDate: json['hire_date']?.toString(),
      status: json['status']?.toString(),
      phone: json['phone']?.toString(),
      email: json['email']?.toString(),
      address: json['address']?.toString(),
      emergencyContact: json['emergency_contact']?.toString(),
      notes: json['notes']?.toString(),
      payrollRecordsCount: int.tryParse('${json['payroll_records_count'] ?? 0}') ?? 0,
      summary: summaryRaw.map((key, value) => MapEntry(key.toString(), value.toString())),
      payrollRecords: _mapList(json['payroll_records'], PayrollRecord.fromJson),
      advances: _mapList(json['advances'], SalaryAdvanceRecord.fromJson),
      adjustments: _mapList(json['adjustments'], PayrollAdjustmentRecord.fromJson),
    );
  }
}

class PayrollRecord {
  const PayrollRecord({
    required this.id,
    this.employeeId,
    this.employeeName,
    this.employeeCode,
    this.periodStart,
    this.periodEnd,
    this.basicSalary = '0.00',
    this.allowancesTotal = '0.00',
    this.deductionsTotal = '0.00',
    this.grossAmount = '0.00',
    this.netAmount = '0.00',
    this.remaining = '0.00',
    this.paymentStatus,
    this.paidAt,
    this.notes,
  });

  final int id;
  final int? employeeId;
  final String? employeeName;
  final String? employeeCode;
  final String? periodStart;
  final String? periodEnd;
  final String basicSalary;
  final String allowancesTotal;
  final String deductionsTotal;
  final String grossAmount;
  final String netAmount;
  final String remaining;
  final String? paymentStatus;
  final String? paidAt;
  final String? notes;

  factory PayrollRecord.fromJson(Map<String, dynamic> json) {
    return PayrollRecord(
      id: int.parse('${json['id']}'),
      employeeId: json['finance_employee_id'] == null ? null : int.tryParse('${json['finance_employee_id']}'),
      employeeName: json['employee_name']?.toString(),
      employeeCode: json['employee_code']?.toString(),
      periodStart: json['period_start']?.toString(),
      periodEnd: json['period_end']?.toString(),
      basicSalary: MoneyFields.asMoney(json['basic_salary']),
      allowancesTotal: MoneyFields.asMoney(json['allowances_total']),
      deductionsTotal: MoneyFields.asMoney(json['deductions_total']),
      grossAmount: MoneyFields.asMoney(json['gross_amount']),
      netAmount: MoneyFields.asMoney(json['net_amount']),
      remaining: MoneyFields.asMoney(json['remaining']),
      paymentStatus: json['payment_status']?.toString(),
      paidAt: json['paid_at']?.toString(),
      notes: json['notes']?.toString(),
    );
  }
}

class SalaryAdvanceRecord {
  const SalaryAdvanceRecord({
    required this.id,
    this.employeeId,
    this.employeeName,
    this.employeeCode,
    this.amount = '0.00',
    this.remainingAmount = '0.00',
    this.settledAmount = '0.00',
    this.issuedAt,
    this.status,
    this.type,
    this.paymentMethod,
    this.notes,
    this.repayments = const [],
  });

  final int id;
  final int? employeeId;
  final String? employeeName;
  final String? employeeCode;
  final String amount;
  final String remainingAmount;
  final String settledAmount;
  final String? issuedAt;
  final String? status;
  final String? type;
  final String? paymentMethod;
  final String? notes;
  final List<AdvanceRepaymentRecord> repayments;

  factory SalaryAdvanceRecord.fromJson(Map<String, dynamic> json) {
    return SalaryAdvanceRecord(
      id: int.parse('${json['id']}'),
      employeeId: json['finance_employee_id'] == null ? null : int.tryParse('${json['finance_employee_id']}'),
      employeeName: json['employee_name']?.toString(),
      employeeCode: json['employee_code']?.toString(),
      amount: MoneyFields.asMoney(json['amount']),
      remainingAmount: MoneyFields.asMoney(json['remaining_amount']),
      settledAmount: MoneyFields.asMoney(json['settled_amount']),
      issuedAt: json['issued_at']?.toString(),
      status: json['status']?.toString(),
      type: json['type']?.toString(),
      paymentMethod: json['payment_method']?.toString(),
      notes: json['notes']?.toString(),
      repayments: _mapList(json['repayments'], AdvanceRepaymentRecord.fromJson),
    );
  }
}

class AdvanceRepaymentRecord {
  const AdvanceRepaymentRecord({
    required this.id,
    this.paymentDate,
    this.amount = '0.00',
    this.method,
    this.status,
    this.notes,
  });

  final int id;
  final String? paymentDate;
  final String amount;
  final String? method;
  final String? status;
  final String? notes;

  factory AdvanceRepaymentRecord.fromJson(Map<String, dynamic> json) {
    return AdvanceRepaymentRecord(
      id: int.parse('${json['id']}'),
      paymentDate: json['payment_date']?.toString(),
      amount: MoneyFields.asMoney(json['amount']),
      method: json['method']?.toString(),
      status: json['status']?.toString(),
      notes: json['notes']?.toString(),
    );
  }
}

class PayrollAdjustmentRecord {
  const PayrollAdjustmentRecord({
    required this.id,
    this.employeeId,
    this.employeeName,
    this.employeeCode,
    this.type,
    this.title,
    this.amount = '0.00',
    this.effectiveDate,
    this.status,
    this.notes,
  });

  final int id;
  final int? employeeId;
  final String? employeeName;
  final String? employeeCode;
  final String? type;
  final String? title;
  final String amount;
  final String? effectiveDate;
  final String? status;
  final String? notes;

  factory PayrollAdjustmentRecord.fromJson(Map<String, dynamic> json) {
    return PayrollAdjustmentRecord(
      id: int.parse('${json['id']}'),
      employeeId: json['finance_employee_id'] == null ? null : int.tryParse('${json['finance_employee_id']}'),
      employeeName: json['employee_name']?.toString(),
      employeeCode: json['employee_code']?.toString(),
      type: json['type']?.toString(),
      title: json['title']?.toString(),
      amount: MoneyFields.asMoney(json['amount']),
      effectiveDate: json['effective_date']?.toString(),
      status: json['status']?.toString(),
      notes: json['notes']?.toString(),
    );
  }
}

class PayrollOverview {
  const PayrollOverview({this.cards = const {}, this.latestRecords = const []});

  final Map<String, String> cards;
  final List<PayrollRecord> latestRecords;

  factory PayrollOverview.fromJson(Map<String, dynamic> json) {
    final cardsRaw = json['cards'] as Map? ?? {};
    return PayrollOverview(
      cards: cardsRaw.map((key, value) => MapEntry(key.toString(), value.toString())),
      latestRecords: _mapList(json['latest_records'], PayrollRecord.fromJson),
    );
  }
}

List<T> _mapList<T>(dynamic raw, T Function(Map<String, dynamic> json) map) {
  if (raw is! List) return const [];
  return raw.whereType<Map>().map((row) => map(Map<String, dynamic>.from(row))).toList();
}
