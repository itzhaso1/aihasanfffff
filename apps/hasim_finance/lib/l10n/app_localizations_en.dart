// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

  @override
  String get appName => 'Hasim Finance';

  @override
  String get loginTitle => 'Sign in';

  @override
  String get emailOrPhone => 'Email or phone';

  @override
  String get password => 'Password';

  @override
  String get loginAction => 'Sign in';

  @override
  String get loginSubtitle => 'Sign in with the same HASEM account';

  @override
  String get orDivider => 'or';

  @override
  String get continueWithGoogle => 'Continue with Google';

  @override
  String get googleSigningIn => 'Signing in...';

  @override
  String get googleFailed => 'Google sign-in failed.';

  @override
  String get forgotPassword => 'Forgot password?';

  @override
  String get forgotPasswordHint => 'Enter your email to receive a reset link.';

  @override
  String get forgotPasswordSent => 'A password reset link was sent.';

  @override
  String get sendResetLink => 'Send link';

  @override
  String get haveResetToken => 'I have a reset token';

  @override
  String get resetPassword => 'Reset password';

  @override
  String get resetToken => 'Reset token';

  @override
  String get confirmPassword => 'Confirm password';

  @override
  String get passwordResetDone => 'Password was reset successfully.';

  @override
  String get selectWorkspaceHint =>
      'Choose a workspace. A workspace is not picked automatically when more than one is available.';

  @override
  String get financeEnabled => 'Finance enabled';

  @override
  String get financeDisabled => 'Finance disabled';

  @override
  String get financeUnavailableTitle =>
      'Finance is not enabled for this workspace';

  @override
  String get financeUnavailableBody =>
      'You are signed in, but Finance is not enabled for this workspace. Switch workspace or sign out.';

  @override
  String get googleAccountLinked =>
      'This email belongs to an existing account. Sign in with your password first to link Google.';

  @override
  String get logout => 'Sign out';

  @override
  String get workspace => 'Workspace';

  @override
  String get switchWorkspace => 'Switch workspace';

  @override
  String get dashboard => 'Finance dashboard';

  @override
  String get customers => 'Customers';

  @override
  String get quotes => 'Quotes';

  @override
  String get invoices => 'Invoices';

  @override
  String get payments => 'Payments';

  @override
  String get receipts => 'Receipts';

  @override
  String get statements => 'Statements';

  @override
  String get notes => 'Credit / debit notes';

  @override
  String get contracts => 'Contracts';

  @override
  String get expenses => 'Expenses';

  @override
  String get purchases => 'Purchases';

  @override
  String get reports => 'Reports';

  @override
  String get settings => 'Settings';

  @override
  String get search => 'Search';

  @override
  String get retry => 'Retry';

  @override
  String get empty => 'No data';

  @override
  String get save => 'Save';

  @override
  String get create => 'Create';

  @override
  String get edit => 'Edit';

  @override
  String get delete => 'Delete';

  @override
  String get cancel => 'Cancel';

  @override
  String get issue => 'Issue';

  @override
  String get send => 'Send';

  @override
  String get remind => 'Remind';

  @override
  String get accept => 'Accept';

  @override
  String get reject => 'Reject';

  @override
  String get convert => 'Convert to invoice';

  @override
  String get pdf => 'PDF';

  @override
  String get download => 'Download';

  @override
  String get copy => 'Copy';

  @override
  String get open => 'Open';

  @override
  String get refresh => 'Refresh';

  @override
  String get recordPayment => 'Record payment';

  @override
  String get reversePayment => 'Reverse payment';

  @override
  String get checkout => 'Electronic payment link';

  @override
  String get createCheckout => 'Create payment link';

  @override
  String get paymentLinkHint =>
      'Creating a link does not mark the invoice paid.';

  @override
  String get permissionDenied => 'You are not allowed to perform this action.';

  @override
  String get sessionExpired => 'Your session has expired.';

  @override
  String get offline => 'Could not reach the server. Check your network.';

  @override
  String get documentStatus => 'Document status';

  @override
  String get outcome => 'Commercial outcome';

  @override
  String get deliveryStatus => 'Delivery status';

  @override
  String get paymentStatus => 'Payment status';

  @override
  String get outstanding => 'Outstanding balance';

  @override
  String get subtotal => 'Subtotal';

  @override
  String get tax => 'Tax';

  @override
  String get total => 'Total';

  @override
  String get paid => 'Paid';

  @override
  String get due => 'Due';

  @override
  String get customer => 'Customer';

  @override
  String get amount => 'Amount';

  @override
  String get method => 'Method';

  @override
  String get reference => 'Reference';

  @override
  String get date => 'Date';

  @override
  String get status => 'Status';

  @override
  String get confirm => 'Confirm';

  @override
  String get confirmDestructive => 'Are you sure you want to continue?';

  @override
  String get copied => 'Copied';

  @override
  String get success => 'Success';

  @override
  String get more => 'More';

  @override
  String get overview => 'Overview';

  @override
  String get lines => 'Lines';

  @override
  String get notesField => 'Notes';

  @override
  String get vatNumber => 'VAT number';

  @override
  String get crNumber => 'CR number';

  @override
  String get email => 'Email';

  @override
  String get phone => 'Phone';

  @override
  String get company => 'Company';

  @override
  String get individual => 'Individual';

  @override
  String get draft => 'Draft';

  @override
  String get issued => 'Issued';

  @override
  String get cancelled => 'Cancelled';

  @override
  String get pending => 'Pending';

  @override
  String get accepted => 'Accepted';

  @override
  String get rejected => 'Rejected';

  @override
  String get expired => 'Expired';

  @override
  String get converted => 'Converted';

  @override
  String get unpaid => 'Unpaid';

  @override
  String get partial => 'Partially paid';

  @override
  String get overdue => 'Overdue';

  @override
  String get posted => 'Posted';

  @override
  String get voided => 'Voided';

  @override
  String get reversed => 'Reversed';

  @override
  String get credit => 'Credit note';

  @override
  String get debit => 'Debit note';

  @override
  String get apiHost => 'API host';

  @override
  String get theme => 'Theme';

  @override
  String get language => 'Language';

  @override
  String get arabic => 'Arabic';

  @override
  String get english => 'English';

  @override
  String get sales => 'Sales';

  @override
  String get receivables => 'Receivables';

  @override
  String get payables => 'Payables';

  @override
  String get invoicesDue => 'Invoices due';

  @override
  String get overdueInvoices => 'Overdue invoices';

  @override
  String get paidThisPeriod => 'Collected this period';

  @override
  String get recentInvoices => 'Recent invoices';

  @override
  String get recentPayments => 'Recent payments';

  @override
  String get noPermissionScreen =>
      'This screen is not available for your current permissions.';

  @override
  String get checkoutUnavailable => 'Electronic checkout is not available.';

  @override
  String get neverMarkPaidLocally =>
      'The invoice is marked paid only after the server confirms it.';

  @override
  String get recipient => 'Recipient';

  @override
  String get quantity => 'Quantity';

  @override
  String get price => 'Price';

  @override
  String get description => 'Description';

  @override
  String get unit => 'Unit';

  @override
  String get issueDate => 'Issue date';

  @override
  String get dueDate => 'Due date';

  @override
  String get expiryDate => 'Expiry date';

  @override
  String get from => 'From';

  @override
  String get to => 'To';

  @override
  String get openingBalance => 'Opening balance';

  @override
  String get closingBalance => 'Closing balance';

  @override
  String get csv => 'CSV';

  @override
  String get suppliers => 'Suppliers';

  @override
  String get category => 'Category';

  @override
  String get attachment => 'Attachment';

  @override
  String get generateInvoice => 'Generate draft invoice';

  @override
  String get signContract => 'Activate / sign';

  @override
  String get closeContract => 'Close';

  @override
  String get billingSchedule => 'Billing schedule';

  @override
  String get profitLoss => 'Profit & Loss';

  @override
  String get trialBalance => 'Trial balance';

  @override
  String get cashFlow => 'Cash flow';

  @override
  String get balanceSheet => 'Balance sheet';

  @override
  String get generalLedger => 'General ledger';

  @override
  String get arAging => 'AR aging';

  @override
  String get apAging => 'AP aging';

  @override
  String get loadMore => 'Load more';

  @override
  String get globalSearch => 'Finance search';

  @override
  String get exports => 'CSV export';

  @override
  String get auditTrail => 'Audit trail';

  @override
  String get downloadAttachment => 'Download attachment';

  @override
  String get noResults => 'No results';

  @override
  String get selectCustomer => 'Select customer';

  @override
  String get selectSupplier => 'Select supplier';

  @override
  String get addLine => 'Add line';

  @override
  String get removeLine => 'Remove line';

  @override
  String get taxRate => 'Tax rate';

  @override
  String get discount => 'Discount';

  @override
  String get invoiceId => 'Invoice ID';

  @override
  String get taxableAmount => 'Taxable amount';

  @override
  String get amountCredited => 'Credited';

  @override
  String get amountDebited => 'Debited';

  @override
  String get whatsapp => 'WhatsApp';

  @override
  String get buildingNumber => 'Building number';

  @override
  String get district => 'District';

  @override
  String get postalCode => 'Postal code';

  @override
  String get city => 'City';

  @override
  String get street => 'Street';

  @override
  String get country => 'Country';

  @override
  String get paymentTerms => 'Payment terms';

  @override
  String get treasuryAccount => 'Treasury account';

  @override
  String get recurring => 'Recurring flag';

  @override
  String get netProfit => 'Net profit';

  @override
  String get outputVat => 'Output VAT';

  @override
  String get inputVat => 'Input VAT';

  @override
  String get netVat => 'Net VAT';

  @override
  String get cashBalance => 'Cash';

  @override
  String get bankBalance => 'Bank';

  @override
  String get activeContracts => 'Active contracts';

  @override
  String get recentExpenses => 'Recent expenses';

  @override
  String get statementDebit => 'Debit';

  @override
  String get statementCredit => 'Credit';

  @override
  String get runningBalance => 'Running balance';

  @override
  String get invoicesTotal => 'Invoices total';

  @override
  String get paymentsTotal => 'Payments total';

  @override
  String get creditsTotal => 'Credits total';

  @override
  String get debitsTotal => 'Debits total';

  @override
  String get zatcaQr => 'ZATCA QR present';

  @override
  String get terms => 'Terms';

  @override
  String get rejectionReason => 'Rejection reason';

  @override
  String get website => 'Website';

  @override
  String get currency => 'Currency';

  @override
  String get invoicePrefix => 'Invoice prefix';

  @override
  String get defaultVatRate => 'Default VAT rate';

  @override
  String get zatcaMode => 'ZATCA mode';

  @override
  String get nextRun => 'Next run';

  @override
  String get frequency => 'Frequency';

  @override
  String get autoIssue => 'Auto issue';

  @override
  String get generatedCount => 'Generated';

  @override
  String get paymentDate => 'Payment date';

  @override
  String get supplier => 'Supplier';

  @override
  String get openingCash => 'Opening cash';

  @override
  String get netChange => 'Net change';

  @override
  String get closingCash => 'Closing cash';

  @override
  String get assets => 'Assets';

  @override
  String get liabilities => 'Liabilities';

  @override
  String get equity => 'Equity';

  @override
  String get revenue => 'Revenue';

  @override
  String get cogs => 'Cost of sales';

  @override
  String get grossProfit => 'Gross profit';

  @override
  String get additionalNumber => 'Additional number';

  @override
  String get companyNameAr => 'Arabic company name';

  @override
  String get addressLine => 'Address line';

  @override
  String get filterAll => 'All';

  @override
  String get generatedInvoices => 'Generated invoices';

  @override
  String get invoicedTotal => 'Invoiced';

  @override
  String get snapshots => 'Snapshots';

  @override
  String get reason => 'Reason';

  @override
  String get inventoryValuation => 'Inventory valuation';

  @override
  String get navControl => 'Control';

  @override
  String get navSales => 'Sales';

  @override
  String get navPayments => 'Payments';

  @override
  String get navParties => 'Customers & suppliers';

  @override
  String get navPurchases => 'Purchases';

  @override
  String get navOps => 'Inventory';

  @override
  String get navReports => 'Reports';

  @override
  String get navAccounting => 'Accounting';

  @override
  String get navBanks => 'Banks & treasury';

  @override
  String get billingHub => 'Billing dashboard';

  @override
  String get salesHub => 'Sales';

  @override
  String get leads => 'Leads';

  @override
  String get priceLists => 'Price lists';

  @override
  String get purchaseOrders => 'Purchase orders';

  @override
  String get products => 'Products';

  @override
  String get inventory => 'Inventory';

  @override
  String get projects => 'Projects';

  @override
  String get accountingHub => 'Accounting dashboard';

  @override
  String get fiscalYears => 'Fiscal years';

  @override
  String get vatPage => 'VAT';

  @override
  String get alerts => 'Alerts';

  @override
  String get copilot => 'Finance copilot';

  @override
  String get banks => 'Bank accounts';

  @override
  String get treasury => 'Treasury transfers';

  @override
  String get walkInCustomer => 'Walk-in customer';

  @override
  String get taxDocumentSubtype => 'Tax document subtype';

  @override
  String get zatcaRequirement => 'E-invoicing requirement';

  @override
  String get taxProfile => 'Tax type';

  @override
  String get taxPriceMode => 'Tax price mode';

  @override
  String get exclusive => 'Tax exclusive';

  @override
  String get inclusive => 'Tax inclusive';

  @override
  String get standardTax => 'Standard';

  @override
  String get simplifiedTax => 'Simplified';

  @override
  String get notRequired => 'Not required';

  @override
  String get requiredLater => 'Required later';

  @override
  String get headerSection => 'Header';

  @override
  String get datesSection => 'Dates & terms';

  @override
  String get taxSection => 'Tax';

  @override
  String get itemsSection => 'Lines';

  @override
  String get notesSection => 'Notes & attachments';

  @override
  String get summarySection => 'Summary';

  @override
  String get selectProduct => 'Select product';

  @override
  String get freeTextItem => 'Free-text item';

  @override
  String get exemptionReason => 'Exemption reason';

  @override
  String get exemptionCode => 'Exemption code';

  @override
  String get project => 'Project';

  @override
  String get contract => 'Contract';

  @override
  String get sku => 'SKU';

  @override
  String get stock => 'Stock';

  @override
  String get budget => 'Budget';

  @override
  String get profit => 'Profit';

  @override
  String get costs => 'Costs';

  @override
  String get submitPo => 'Submit';

  @override
  String get receivePo => 'Receive';

  @override
  String get billPo => 'Convert to bill';

  @override
  String get convertLead => 'Convert to customer';

  @override
  String get markLost => 'Mark lost';

  @override
  String get askCopilot => 'Ask';

  @override
  String get copilotHint =>
      'Ask about sales, profit, overdue invoices, or what needs attention. The copilot never invents amounts.';

  @override
  String get transfer => 'Transfer';

  @override
  String get fromAccount => 'From account';

  @override
  String get toAccount => 'To account';

  @override
  String get openYear => 'Open';

  @override
  String get closeYear => 'Close';

  @override
  String get generatePeriods => 'Generate monthly periods';

  @override
  String get approve => 'Approve';

  @override
  String get markDraft => 'Mark draft';

  @override
  String get addItem => 'Add item';

  @override
  String get soldTotal => 'Sold total';

  @override
  String get currentBalance => 'Current balance';

  @override
  String get iban => 'IBAN';

  @override
  String get bankName => 'Bank name';

  @override
  String get accountNumber => 'Account number';

  @override
  String get askQuestion => 'Question';

  @override
  String get severity => 'Severity';

  @override
  String get estimatedValue => 'Estimated value';

  @override
  String get source => 'Source';

  @override
  String get orderDate => 'Order date';

  @override
  String get expectedDate => 'Expected date';

  @override
  String get createTaxRate => 'Save tax rate';

  @override
  String get createTreasuryAccount => 'Save treasury account';

  @override
  String get creditNoteFromInvoice => 'Credit / debit note';

  @override
  String get zeroRated => 'Zero-rated';

  @override
  String get exempt => 'Exempt';

  @override
  String get outOfScope => 'Out of scope';

  @override
  String get fieldName => 'Name';

  @override
  String get walkInName => 'Walk-in customer name';

  @override
  String get addSchedule => 'Add billing schedule';

  @override
  String get editPurchase => 'Edit purchase invoice';

  @override
  String get supplierDetail => 'Supplier';

  @override
  String get invoiceFooter => 'PDF footer';

  @override
  String get invoiceColor => 'Invoice color';

  @override
  String get allowManualNumbers => 'Allow manual invoice numbers';

  @override
  String get countryCode => 'Country code';

  @override
  String get methodCash => 'Cash';

  @override
  String get methodBank => 'Bank transfer';

  @override
  String get methodCard => 'Card';

  @override
  String get methodOther => 'Other';

  @override
  String get methodCredit => 'Credit';

  @override
  String get openPeriod => 'Open period';

  @override
  String get closePeriod => 'Close period';

  @override
  String get invoiceNumber => 'Invoice number';

  @override
  String get pauseSchedule => 'Pause schedule';

  @override
  String get activateSchedule => 'Activate schedule';

  @override
  String get cancelSchedule => 'Cancel schedule';

  @override
  String get deleteDraft => 'Delete draft';

  @override
  String get applyFilters => 'Apply filters';

  @override
  String get resetFilters => 'Reset filters';

  @override
  String get decisionPeriod => 'Decision period';

  @override
  String get topCustomers => 'Top customers';

  @override
  String get attentionItems => 'Needs attention';

  @override
  String get lifecycleDraft => 'Draft';

  @override
  String get lifecycleSent => 'Issued unpaid';

  @override
  String get comparePrevious => 'Compared with previous period';

  @override
  String get paymentMethod => 'Payment method';

  @override
  String get companyLogo => 'Company logo';

  @override
  String get chooseLogo => 'Choose logo';

  @override
  String get replaceLogo => 'Replace logo';

  @override
  String get removeLogo => 'Remove logo';

  @override
  String get bankStatements => 'Bank statements';

  @override
  String get addStatement => 'New statement';

  @override
  String get addStatementLines => 'Add statement lines';

  @override
  String get suggestMatches => 'Suggest matches';

  @override
  String get acceptSuggestion => 'Accept suggestion';

  @override
  String get ignoreLine => 'Ignore line';

  @override
  String get completeReconciliation => 'Complete reconciliation';

  @override
  String get statementDate => 'Statement date';

  @override
  String get monthlyCashFlow => 'Monthly cash inflow';

  @override
  String get journalEntries => 'Journal entries';

  @override
  String get uploading => 'Uploading…';

  @override
  String get taxRates => 'Tax rates';

  @override
  String get isDefault => 'Default';

  @override
  String get isActive => 'Active';

  @override
  String get linkedLedgerAccount => 'Linked ledger account';

  @override
  String get accountType => 'Account type';

  @override
  String get cashAccount => 'Cash';

  @override
  String get bankAccount => 'Bank';

  @override
  String get navPeople => 'People & obligations';

  @override
  String get peopleObligations => 'People & obligations';

  @override
  String get peopleSubtitle =>
      'Your company\'s financial obligations to its own people — not HASEM HR.';

  @override
  String get payroll => 'Salaries & entitlements';

  @override
  String get salaryAdvances => 'Advances';

  @override
  String get allowances => 'Allowances';

  @override
  String get bonuses => 'Bonuses';

  @override
  String get deductions => 'Deductions';

  @override
  String get searchPlaceholder => 'Search the system...';

  @override
  String get exportReport => 'Export report';

  @override
  String get salesVsExpenses => 'Sales vs expenses';

  @override
  String get salesMix => 'Sales mix';

  @override
  String get totalOwed => 'Owed';

  @override
  String get totalPaid => 'Paid';

  @override
  String get remainingBalance => 'Remaining';

  @override
  String get advanceIssued => 'Advance';

  @override
  String get advanceSettled => 'Settled';

  @override
  String get advanceRemaining => 'Advance remaining';

  @override
  String get jobTitle => 'Job title';

  @override
  String get employeeCode => 'Employee code';

  @override
  String get basicSalary => 'Basic salary';

  @override
  String get hireDate => 'Hire date';

  @override
  String get emergencyContact => 'Emergency contact';

  @override
  String get addEmployee => 'Add person';

  @override
  String get addPayrollRecord => 'Save entitlement';

  @override
  String get issueAdvance => 'Issue advance';

  @override
  String get settleAdvance => 'Record settlement';

  @override
  String get periodLabel => 'Period';

  @override
  String get thisMonth => 'This month';

  @override
  String get lastSixMonths => 'Last 6 months';

  @override
  String get paymentHistory => 'Payment history';

  @override
  String get outstandingObligations => 'Outstanding obligations';

  @override
  String get financialSummary => 'Financial summary';

  @override
  String get companyPeopleHint =>
      'These are your company\'s people in the Finance workspace, not HASEM staff.';

  @override
  String get activeStatus => 'Active';

  @override
  String get inactiveStatus => 'Inactive';

  @override
  String get suspendedStatus => 'Suspended';

  @override
  String get repay => 'Repay';

  @override
  String get methodPayrollDeduction => 'Payroll deduction';

  @override
  String get employeeLoan => 'Employee loan';

  @override
  String get salaryAdvanceType => 'Salary advance';

  @override
  String get payrollPaid => 'Paid salaries';

  @override
  String get openAdvances => 'Open advances';

  @override
  String get companyEmployees => 'Company people';

  @override
  String get dashboardSubtitle =>
      'A complete view of financial performance for the selected period';

  @override
  String get address => 'Address';

  @override
  String get periodStart => 'Period start';

  @override
  String get periodEnd => 'Period end';

  @override
  String get allowancesTotal => 'Allowances';

  @override
  String get deductionsTotal => 'Deductions';

  @override
  String get grossAmount => 'Gross';

  @override
  String get netAmount => 'Net';

  @override
  String get effectiveDate => 'Effective date';

  @override
  String get issuedAt => 'Issued at';

  @override
  String get remainingAmount => 'Remaining';

  @override
  String get settledAmount => 'Settled';

  @override
  String get addAdjustment => 'Save movement';

  @override
  String get postAdjustment => 'Post to ledger';

  @override
  String get cancelAdjustment => 'Cancel movement';

  @override
  String get selectEmployee => 'Select person';

  @override
  String get showAll => 'Show all';

  @override
  String get peopleCount => 'People count';

  @override
  String get taxInvoice => 'Tax invoice';

  @override
  String get invoiceItems => 'Invoice items';

  @override
  String get customerInfo => 'Customer information';

  @override
  String get invoiceSummary => 'Invoice summary';

  @override
  String get notesAndTerms => 'Notes and terms';

  @override
  String get attachmentsTitle => 'Attachments';

  @override
  String get downloadPdf => 'Download PDF';

  @override
  String get printDocument => 'Print';

  @override
  String get goBack => 'Back';

  @override
  String get invoiceGrandTotal => 'Grand total';

  @override
  String get invoiceTotalAmount => 'Invoice total';

  @override
  String get noAttachments => 'No attachments';

  @override
  String get uploadInvoiceAttachmentsHint =>
      'Upload files related to this invoice here.';

  @override
  String get noNotes => 'No notes.';

  @override
  String get noTerms => 'No terms were set on this invoice.';

  @override
  String get termsAndConditions => 'Terms and conditions';

  @override
  String get auditEvent => 'Event';

  @override
  String get auditDescription => 'Description';

  @override
  String get auditUser => 'User';

  @override
  String get auditDate => 'Date';

  @override
  String get invoiceCreatedEvent => 'Invoice created';

  @override
  String get invoiceIssuedEvent => 'Invoice issued';

  @override
  String get invoiceCancelledEvent => 'Invoice cancelled';

  @override
  String get invoiceSentEvent => 'Invoice sent';

  @override
  String get invoiceUpdatedEvent => 'Invoice updated';

  @override
  String get invoiceReminderSentEvent => 'Invoice reminder sent';

  @override
  String get tableTotal => 'Total';

  @override
  String get relatedDocuments => 'Related documents';

  @override
  String get zatcaInfo => 'E-invoicing';

  @override
  String get telephone => 'Phone';

  @override
  String get lineNumber => '#';

  @override
  String get paidInFull => 'Paid';

  @override
  String get draftStatus => 'Draft';

  @override
  String get supplyDate => 'Supply date';

  @override
  String get issueImmediately => 'Issue immediately on save';

  @override
  String get downloadXml => 'Download XML';

  @override
  String get viewQr => 'View QR';

  @override
  String get zatcaFoundation =>
      'Internal e-invoice foundation. No FATOORA clearance or production reporting.';

  @override
  String get xmlAvailable => 'XML available';

  @override
  String get qrAvailable => 'QR available';

  @override
  String get xmlUnavailable => 'XML not available';

  @override
  String get qrUnavailable => 'QR not available';

  @override
  String get sortBy => 'Sort by';

  @override
  String get invoiceStatusFilter => 'Invoice status';

  @override
  String get recurringFrequency => 'Recurring frequency';

  @override
  String get nextDueDate => 'Next due date';

  @override
  String get pickAttachments => 'Choose attachments';

  @override
  String get frequencyMonthly => 'Monthly';

  @override
  String get frequencyWeekly => 'Weekly';

  @override
  String get frequencyQuarterly => 'Quarterly';

  @override
  String get frequencyYearly => 'Yearly';

  @override
  String get sortNewest => 'Newest';

  @override
  String get sortDirection => 'Sort direction';

  @override
  String get sortAscending => 'Ascending';

  @override
  String get sortDescending => 'Descending';

  @override
  String get statusPosted => 'Posted';

  @override
  String get statusReversed => 'Reversed';

  @override
  String get statusVoided => 'Voided';

  @override
  String get noInvoicesYet => 'No invoices yet';

  @override
  String get noInvoicesSubtitle =>
      'Create your first invoice or adjust the filters to see results.';

  @override
  String get noResultsForFilters => 'No results match the current filters.';

  @override
  String get advancedFilters => 'Advanced filters';

  @override
  String get newInvoice => 'New invoice';

  @override
  String get statusOpen => 'Open';

  @override
  String get statusClosed => 'Closed';

  @override
  String get statusApproved => 'Approved';

  @override
  String get expenseLockedHint =>
      'Posted expenses cannot be edited from the app.';
}
