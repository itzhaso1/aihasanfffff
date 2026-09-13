// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Arabic (`ar`).
class AppLocalizationsAr extends AppLocalizations {
  AppLocalizationsAr([String locale = 'ar']) : super(locale);

  @override
  String get appName => 'حاسم للمالية';

  @override
  String get loginTitle => 'تسجيل الدخول';

  @override
  String get emailOrPhone => 'البريد أو الجوال';

  @override
  String get password => 'كلمة المرور';

  @override
  String get loginAction => 'دخول';

  @override
  String get loginSubtitle => 'سجّل الدخول بنفس حساب حاسم';

  @override
  String get orDivider => 'أو';

  @override
  String get continueWithGoogle => 'الدخول باستخدام Google';

  @override
  String get googleSigningIn => 'جار تسجيل الدخول...';

  @override
  String get googleFailed => 'تعذر تسجيل الدخول عبر Google.';

  @override
  String get forgotPassword => 'نسيت كلمة المرور؟';

  @override
  String get forgotPasswordHint =>
      'أدخل بريدك الإلكتروني لإرسال رابط إعادة التعيين.';

  @override
  String get forgotPasswordSent => 'تم إرسال رابط إعادة تعيين كلمة المرور.';

  @override
  String get sendResetLink => 'إرسال الرابط';

  @override
  String get haveResetToken => 'لدي رمز إعادة التعيين';

  @override
  String get resetPassword => 'إعادة تعيين كلمة المرور';

  @override
  String get resetToken => 'رمز إعادة التعيين';

  @override
  String get confirmPassword => 'تأكيد كلمة المرور';

  @override
  String get passwordResetDone => 'تم إعادة تعيين كلمة المرور بنجاح.';

  @override
  String get selectWorkspaceHint =>
      'اختر مساحة العمل. لن يتم اختيار منشأة تلقائياً عند وجود أكثر من واحدة.';

  @override
  String get financeEnabled => 'المالية مفعّلة';

  @override
  String get financeDisabled => 'المالية غير مفعّلة';

  @override
  String get financeUnavailableTitle => 'هذه المنشأة لا تملك منتج المالية';

  @override
  String get financeUnavailableBody =>
      'الحساب مسجّل الدخول، لكن صلاحية المالية غير مفعّلة لهذه المساحة. يمكنك تبديل المنشأة أو تسجيل الخروج.';

  @override
  String get googleAccountLinked =>
      'هذا البريد مرتبط بحساب موجود. سجّل الدخول بكلمة المرور أولاً لربط حساب Google.';

  @override
  String get logout => 'تسجيل الخروج';

  @override
  String get workspace => 'مساحة العمل';

  @override
  String get switchWorkspace => 'تبديل المنشأة';

  @override
  String get dashboard => 'لوحة المالية';

  @override
  String get customers => 'العملاء';

  @override
  String get quotes => 'عروض الأسعار';

  @override
  String get invoices => 'الفواتير';

  @override
  String get payments => 'المدفوعات';

  @override
  String get receipts => 'الإيصالات';

  @override
  String get statements => 'كشوف الحساب';

  @override
  String get notes => 'الإشعارات';

  @override
  String get contracts => 'العقود';

  @override
  String get expenses => 'المصروفات';

  @override
  String get purchases => 'المشتريات';

  @override
  String get reports => 'التقارير';

  @override
  String get settings => 'الإعدادات';

  @override
  String get search => 'بحث';

  @override
  String get retry => 'إعادة المحاولة';

  @override
  String get empty => 'لا توجد بيانات';

  @override
  String get save => 'حفظ';

  @override
  String get create => 'إنشاء';

  @override
  String get edit => 'تعديل';

  @override
  String get delete => 'حذف';

  @override
  String get cancel => 'إلغاء';

  @override
  String get issue => 'إصدار';

  @override
  String get send => 'إرسال';

  @override
  String get remind => 'تذكير';

  @override
  String get accept => 'قبول';

  @override
  String get reject => 'رفض';

  @override
  String get convert => 'تحويل إلى فاتورة';

  @override
  String get pdf => 'ملف PDF';

  @override
  String get download => 'تنزيل';

  @override
  String get copy => 'نسخ';

  @override
  String get open => 'فتح';

  @override
  String get refresh => 'تحديث';

  @override
  String get recordPayment => 'تسجيل دفعة';

  @override
  String get reversePayment => 'عكس الدفعة';

  @override
  String get checkout => 'رابط الدفع الإلكتروني';

  @override
  String get createCheckout => 'إنشاء رابط الدفع';

  @override
  String get paymentLinkHint => 'إنشاء الرابط لا يعني أن الفاتورة دُفعت.';

  @override
  String get permissionDenied => 'لا تملك صلاحية تنفيذ هذا الإجراء.';

  @override
  String get sessionExpired => 'انتهت جلسة تسجيل الدخول.';

  @override
  String get offline => 'تعذر الاتصال بالخادم. تحقق من الشبكة.';

  @override
  String get documentStatus => 'حالة المستند';

  @override
  String get outcome => 'النتيجة التجارية';

  @override
  String get deliveryStatus => 'حالة التسليم';

  @override
  String get paymentStatus => 'حالة التحصيل';

  @override
  String get outstanding => 'الرصيد المستحق';

  @override
  String get subtotal => 'المجموع الفرعي';

  @override
  String get tax => 'الضريبة';

  @override
  String get total => 'الإجمالي';

  @override
  String get paid => 'المدفوع';

  @override
  String get due => 'المستحق';

  @override
  String get customer => 'العميل';

  @override
  String get amount => 'المبلغ';

  @override
  String get method => 'طريقة الدفع';

  @override
  String get reference => 'المرجع';

  @override
  String get date => 'التاريخ';

  @override
  String get status => 'الحالة';

  @override
  String get confirm => 'تأكيد';

  @override
  String get confirmDestructive => 'هل أنت متأكد من تنفيذ هذا الإجراء؟';

  @override
  String get copied => 'تم النسخ';

  @override
  String get success => 'تم بنجاح';

  @override
  String get more => 'المزيد';

  @override
  String get overview => 'نظرة عامة';

  @override
  String get lines => 'البنود';

  @override
  String get notesField => 'ملاحظات';

  @override
  String get vatNumber => 'الرقم الضريبي';

  @override
  String get crNumber => 'السجل التجاري';

  @override
  String get email => 'البريد الإلكتروني';

  @override
  String get phone => 'الجوال';

  @override
  String get company => 'شركة';

  @override
  String get individual => 'فرد';

  @override
  String get draft => 'مسودة';

  @override
  String get issued => 'صادرة';

  @override
  String get cancelled => 'ملغاة';

  @override
  String get pending => 'قيد الانتظار';

  @override
  String get accepted => 'مقبولة';

  @override
  String get rejected => 'مرفوضة';

  @override
  String get expired => 'منتهية';

  @override
  String get converted => 'محوّلة';

  @override
  String get unpaid => 'غير مدفوعة';

  @override
  String get partial => 'مدفوعة جزئياً';

  @override
  String get overdue => 'متأخرة';

  @override
  String get posted => 'مرحّلة';

  @override
  String get voided => 'ملغاة';

  @override
  String get reversed => 'معكوسة';

  @override
  String get credit => 'إشعار دائن';

  @override
  String get debit => 'إشعار مدين';

  @override
  String get apiHost => 'عنوان الخادم';

  @override
  String get theme => 'المظهر';

  @override
  String get language => 'اللغة';

  @override
  String get arabic => 'العربية';

  @override
  String get english => 'English';

  @override
  String get sales => 'المبيعات';

  @override
  String get receivables => 'الذمم المدينة';

  @override
  String get payables => 'الذمم الدائنة';

  @override
  String get invoicesDue => 'فواتير مستحقة';

  @override
  String get overdueInvoices => 'فواتير متأخرة';

  @override
  String get paidThisPeriod => 'المحصّل هذه الفترة';

  @override
  String get recentInvoices => 'أحدث الفواتير';

  @override
  String get recentPayments => 'أحدث المدفوعات';

  @override
  String get noPermissionScreen => 'هذه الشاشة غير متاحة لصلاحياتك الحالية.';

  @override
  String get checkoutUnavailable => 'رابط الدفع الإلكتروني غير متاح.';

  @override
  String get neverMarkPaidLocally =>
      'لن تُعلَّم الفاتورة مدفوعة إلا بعد تأكيد الخادم.';

  @override
  String get recipient => 'المستلم';

  @override
  String get quantity => 'الكمية';

  @override
  String get price => 'السعر';

  @override
  String get description => 'الوصف';

  @override
  String get unit => 'الوحدة';

  @override
  String get issueDate => 'تاريخ الإصدار';

  @override
  String get dueDate => 'تاريخ الاستحقاق';

  @override
  String get expiryDate => 'تاريخ الانتهاء';

  @override
  String get from => 'من';

  @override
  String get to => 'إلى';

  @override
  String get openingBalance => 'الرصيد الافتتاحي';

  @override
  String get closingBalance => 'الرصيد الختامي';

  @override
  String get csv => 'CSV';

  @override
  String get suppliers => 'الموردون';

  @override
  String get category => 'التصنيف';

  @override
  String get attachment => 'مرفق';

  @override
  String get generateInvoice => 'توليد فاتورة مسودة';

  @override
  String get signContract => 'تفعيل / توقيع';

  @override
  String get closeContract => 'إغلاق';

  @override
  String get billingSchedule => 'جدول الفوترة';

  @override
  String get profitLoss => 'الأرباح والخسائر';

  @override
  String get trialBalance => 'ميزان المراجعة';

  @override
  String get cashFlow => 'التدفقات النقدية';

  @override
  String get balanceSheet => 'الميزانية';

  @override
  String get generalLedger => 'دفتر الأستاذ';

  @override
  String get arAging => 'أعمار الذمم المدينة';

  @override
  String get apAging => 'أعمار الذمم الدائنة';

  @override
  String get loadMore => 'تحميل المزيد';

  @override
  String get globalSearch => 'بحث مالي';

  @override
  String get exports => 'تصدير CSV';

  @override
  String get auditTrail => 'سجل التدقيق';

  @override
  String get downloadAttachment => 'تنزيل المرفق';

  @override
  String get noResults => 'لا توجد نتائج';

  @override
  String get selectCustomer => 'اختر العميل';

  @override
  String get selectSupplier => 'اختر المورد';

  @override
  String get addLine => 'إضافة بند';

  @override
  String get removeLine => 'حذف البند';

  @override
  String get taxRate => 'نسبة الضريبة';

  @override
  String get discount => 'الخصم';

  @override
  String get invoiceId => 'رقم الفاتورة';

  @override
  String get taxableAmount => 'المبلغ الخاضع للضريبة';

  @override
  String get amountCredited => 'المُشعَر دائناً';

  @override
  String get amountDebited => 'المُشعَر مديناً';

  @override
  String get whatsapp => 'واتساب';

  @override
  String get buildingNumber => 'رقم المبنى';

  @override
  String get district => 'الحي';

  @override
  String get postalCode => 'الرمز البريدي';

  @override
  String get city => 'المدينة';

  @override
  String get street => 'الشارع';

  @override
  String get country => 'الدولة';

  @override
  String get paymentTerms => 'شروط الدفع';

  @override
  String get treasuryAccount => 'حساب الخزينة';

  @override
  String get recurring => 'متكرر (علم فقط)';

  @override
  String get netProfit => 'صافي الربح';

  @override
  String get outputVat => 'ضريبة المخرجات';

  @override
  String get inputVat => 'ضريبة المدخلات';

  @override
  String get netVat => 'صافي الضريبة';

  @override
  String get cashBalance => 'النقد';

  @override
  String get bankBalance => 'البنك';

  @override
  String get activeContracts => 'عقود نشطة';

  @override
  String get recentExpenses => 'أحدث المصروفات';

  @override
  String get statementDebit => 'مدين';

  @override
  String get statementCredit => 'دائن';

  @override
  String get runningBalance => 'الرصيد الجاري';

  @override
  String get invoicesTotal => 'إجمالي الفواتير';

  @override
  String get paymentsTotal => 'إجمالي الدفعات';

  @override
  String get creditsTotal => 'إجمالي الدائن';

  @override
  String get debitsTotal => 'إجمالي المدين';

  @override
  String get zatcaQr => 'رمز الزكاة حاضر';

  @override
  String get terms => 'الشروط';

  @override
  String get rejectionReason => 'سبب الرفض';

  @override
  String get website => 'الموقع';

  @override
  String get currency => 'العملة';

  @override
  String get invoicePrefix => 'بادئة الفاتورة';

  @override
  String get defaultVatRate => 'نسبة الضريبة الافتراضية';

  @override
  String get zatcaMode => 'وضع الربط مع الزكاة';

  @override
  String get nextRun => 'التشغيل التالي';

  @override
  String get frequency => 'التكرار';

  @override
  String get autoIssue => 'إصدار تلقائي';

  @override
  String get generatedCount => 'المُولَّد';

  @override
  String get paymentDate => 'تاريخ الدفع';

  @override
  String get supplier => 'المورد';

  @override
  String get openingCash => 'افتتاحي النقد';

  @override
  String get netChange => 'صافي التغير';

  @override
  String get closingCash => 'ختامي النقد';

  @override
  String get assets => 'الأصول';

  @override
  String get liabilities => 'الالتزامات';

  @override
  String get equity => 'حقوق الملكية';

  @override
  String get revenue => 'الإيرادات';

  @override
  String get cogs => 'تكلفة المبيعات';

  @override
  String get grossProfit => 'مجمل الربح';

  @override
  String get additionalNumber => 'الرقم الإضافي';

  @override
  String get companyNameAr => 'اسم الشركة بالعربية';

  @override
  String get addressLine => 'سطر العنوان';

  @override
  String get filterAll => 'الكل';

  @override
  String get generatedInvoices => 'الفواتير المولّدة';

  @override
  String get invoicedTotal => 'المفوتر';

  @override
  String get snapshots => 'اللقطات';

  @override
  String get reason => 'السبب';

  @override
  String get inventoryValuation => 'تقييم المخزون';

  @override
  String get navControl => 'لوحة التحكم';

  @override
  String get navSales => 'المبيعات';

  @override
  String get navPayments => 'المدفوعات';

  @override
  String get navParties => 'العملاء والموردون';

  @override
  String get navPurchases => 'المشتريات';

  @override
  String get navOps => 'المخزون';

  @override
  String get navReports => 'التقارير';

  @override
  String get navAccounting => 'المحاسبة';

  @override
  String get navBanks => 'البنوك والخزينة';

  @override
  String get billingHub => 'لوحة الفوترة';

  @override
  String get salesHub => 'المبيعات';

  @override
  String get leads => 'العملاء المحتملون';

  @override
  String get priceLists => 'قوائم الأسعار';

  @override
  String get purchaseOrders => 'أوامر الشراء';

  @override
  String get products => 'المنتجات';

  @override
  String get inventory => 'المخزون';

  @override
  String get projects => 'المشاريع';

  @override
  String get accountingHub => 'لوحة المحاسبة';

  @override
  String get fiscalYears => 'السنوات والفترات';

  @override
  String get vatPage => 'VAT';

  @override
  String get alerts => 'التنبيهات';

  @override
  String get copilot => 'المساعد المالي';

  @override
  String get banks => 'الحسابات البنكية';

  @override
  String get treasury => 'الخزينة والتحويلات';

  @override
  String get walkInCustomer => 'عميل نقدي / عابر';

  @override
  String get taxDocumentSubtype => 'تصنيف المستند الضريبي';

  @override
  String get zatcaRequirement => 'متطلب الفوترة الإلكترونية';

  @override
  String get taxProfile => 'نوع الضريبة';

  @override
  String get taxPriceMode => 'سعر شامل/غير شامل';

  @override
  String get exclusive => 'غير شامل الضريبة';

  @override
  String get inclusive => 'شامل الضريبة';

  @override
  String get standardTax => 'قياسية';

  @override
  String get simplifiedTax => 'مبسطة';

  @override
  String get notRequired => 'غير مطلوب';

  @override
  String get requiredLater => 'مطلوب لاحقاً';

  @override
  String get headerSection => 'البيانات الأساسية';

  @override
  String get datesSection => 'التواريخ والشروط';

  @override
  String get taxSection => 'الضريبة';

  @override
  String get itemsSection => 'البنود';

  @override
  String get notesSection => 'ملاحظات ومرفقات';

  @override
  String get summarySection => 'الملخص';

  @override
  String get selectProduct => 'اختر منتجاً';

  @override
  String get freeTextItem => 'بند حر';

  @override
  String get exemptionReason => 'سبب الإعفاء';

  @override
  String get exemptionCode => 'رمز الإعفاء';

  @override
  String get project => 'المشروع';

  @override
  String get contract => 'العقد';

  @override
  String get sku => 'رمز SKU';

  @override
  String get stock => 'المخزون';

  @override
  String get budget => 'الميزانية';

  @override
  String get profit => 'الربح';

  @override
  String get costs => 'التكاليف';

  @override
  String get submitPo => 'إرسال';

  @override
  String get receivePo => 'استلام';

  @override
  String get billPo => 'تحويل إلى فاتورة';

  @override
  String get convertLead => 'تحويل إلى عميل';

  @override
  String get markLost => 'تعليم كضائع';

  @override
  String get askCopilot => 'اسأل';

  @override
  String get copilotHint =>
      'اسأل عن المبيعات، الأرباح، المتأخرات، أو ما يحتاج انتباهاً. المساعد لا يخترع مبالغ.';

  @override
  String get transfer => 'تحويل';

  @override
  String get fromAccount => 'من حساب';

  @override
  String get toAccount => 'إلى حساب';

  @override
  String get openYear => 'فتح';

  @override
  String get closeYear => 'إغلاق';

  @override
  String get generatePeriods => 'توليد فترات شهرية';

  @override
  String get approve => 'اعتماد';

  @override
  String get markDraft => 'إعادة لمسودة';

  @override
  String get addItem => 'إضافة عنصر';

  @override
  String get soldTotal => 'إجمالي المبيعات';

  @override
  String get currentBalance => 'الرصيد الحالي';

  @override
  String get iban => 'IBAN';

  @override
  String get bankName => 'اسم البنك';

  @override
  String get accountNumber => 'رقم الحساب';

  @override
  String get askQuestion => 'السؤال';

  @override
  String get severity => 'الأهمية';

  @override
  String get estimatedValue => 'القيمة المتوقعة';

  @override
  String get source => 'المصدر';

  @override
  String get orderDate => 'تاريخ الطلب';

  @override
  String get expectedDate => 'التاريخ المتوقع';

  @override
  String get createTaxRate => 'حفظ نسبة ضريبة';

  @override
  String get createTreasuryAccount => 'حفظ حساب خزينة';

  @override
  String get creditNoteFromInvoice => 'إشعار دائن / مدين';

  @override
  String get zeroRated => 'صفرية';

  @override
  String get exempt => 'معفاة';

  @override
  String get outOfScope => 'خارج النطاق';

  @override
  String get fieldName => 'الاسم';

  @override
  String get walkInName => 'اسم العميل العابر';

  @override
  String get addSchedule => 'إضافة جدول فوترة';

  @override
  String get editPurchase => 'تعديل فاتورة الشراء';

  @override
  String get supplierDetail => 'المورد';

  @override
  String get invoiceFooter => 'تذييل PDF';

  @override
  String get invoiceColor => 'لون الفاتورة';

  @override
  String get allowManualNumbers => 'السماح بأرقام فواتير يدوية';

  @override
  String get countryCode => 'رمز الدولة';

  @override
  String get methodCash => 'نقداً';

  @override
  String get methodBank => 'تحويل بنكي';

  @override
  String get methodCard => 'بطاقة';

  @override
  String get methodOther => 'أخرى';

  @override
  String get methodCredit => 'آجل';

  @override
  String get openPeriod => 'فتح الفترة';

  @override
  String get closePeriod => 'إغلاق الفترة';

  @override
  String get invoiceNumber => 'رقم الفاتورة';

  @override
  String get pauseSchedule => 'إيقاف الجدول';

  @override
  String get activateSchedule => 'تفعيل الجدول';

  @override
  String get cancelSchedule => 'إلغاء الجدول';

  @override
  String get deleteDraft => 'حذف المسودة';

  @override
  String get applyFilters => 'تطبيق التصفية';

  @override
  String get resetFilters => 'إعادة تعيين التصفية';

  @override
  String get decisionPeriod => 'فترة القرار';

  @override
  String get topCustomers => 'أعلى العملاء';

  @override
  String get attentionItems => 'ما يحتاج انتباهاً';

  @override
  String get lifecycleDraft => 'مسودة';

  @override
  String get lifecycleSent => 'صادرة غير مدفوعة';

  @override
  String get comparePrevious => 'مقارنة بالفترة السابقة';

  @override
  String get paymentMethod => 'طريقة الدفع';

  @override
  String get companyLogo => 'شعار المنشأة';

  @override
  String get chooseLogo => 'اختيار الشعار';

  @override
  String get replaceLogo => 'استبدال الشعار';

  @override
  String get removeLogo => 'حذف الشعار';

  @override
  String get bankStatements => 'كشوف البنك';

  @override
  String get addStatement => 'كشف جديد';

  @override
  String get addStatementLines => 'إضافة حركات';

  @override
  String get suggestMatches => 'اقتراح مطابقة';

  @override
  String get acceptSuggestion => 'قبول الاقتراح';

  @override
  String get ignoreLine => 'تجاهل الحركة';

  @override
  String get completeReconciliation => 'إكمال التسوية';

  @override
  String get statementDate => 'تاريخ الكشف';

  @override
  String get monthlyCashFlow => 'التدفق النقدي الشهري';

  @override
  String get journalEntries => 'قيود اليومية';

  @override
  String get uploading => 'جاري الرفع…';

  @override
  String get taxRates => 'نسب الضريبة';

  @override
  String get isDefault => 'افتراضية';

  @override
  String get isActive => 'فعّالة';

  @override
  String get linkedLedgerAccount => 'ربط بحساب محاسبي';

  @override
  String get accountType => 'نوع الحساب';

  @override
  String get cashAccount => 'نقدي';

  @override
  String get bankAccount => 'بنكي';

  @override
  String get navPeople => 'الموظفون والمستحقات';

  @override
  String get peopleObligations => 'الموظفون والمستحقات';

  @override
  String get peopleSubtitle =>
      'التزامات الشركة المالية تجاه موظفيها — وليست موارد بشرية لمنصة حاسم.';

  @override
  String get payroll => 'الرواتب والمستحقات';

  @override
  String get salaryAdvances => 'السلف';

  @override
  String get allowances => 'البدلات';

  @override
  String get bonuses => 'المكافآت';

  @override
  String get deductions => 'الخصومات';

  @override
  String get searchPlaceholder => 'بحث في النظام...';

  @override
  String get exportReport => 'تصدير التقرير';

  @override
  String get salesVsExpenses => 'المبيعات مقابل المصروفات';

  @override
  String get salesMix => 'توزيع المبيعات';

  @override
  String get totalOwed => 'المستحق';

  @override
  String get totalPaid => 'المدفوع';

  @override
  String get remainingBalance => 'المتبقي';

  @override
  String get advanceIssued => 'السلفة';

  @override
  String get advanceSettled => 'المسدد';

  @override
  String get advanceRemaining => 'المتبقي من السلفة';

  @override
  String get jobTitle => 'المسمى الوظيفي';

  @override
  String get employeeCode => 'كود الموظف';

  @override
  String get basicSalary => 'الراتب الأساسي';

  @override
  String get hireDate => 'تاريخ التعيين';

  @override
  String get emergencyContact => 'جهة اتصال للطوارئ';

  @override
  String get addEmployee => 'إضافة موظف';

  @override
  String get addPayrollRecord => 'حفظ سجل الاستحقاق';

  @override
  String get issueAdvance => 'تسجيل سلفة';

  @override
  String get settleAdvance => 'تسجيل سداد';

  @override
  String get periodLabel => 'الفترة';

  @override
  String get thisMonth => 'هذا الشهر';

  @override
  String get lastSixMonths => 'آخر 6 أشهر';

  @override
  String get paymentHistory => 'سجل الدفعات';

  @override
  String get outstandingObligations => 'الالتزامات القائمة';

  @override
  String get financialSummary => 'ملخص مالي';

  @override
  String get companyPeopleHint =>
      'هؤلاء موظفو شركتك داخل مساحة العمل المالية، وليسوا موظفي منصة حاسم.';

  @override
  String get activeStatus => 'نشط';

  @override
  String get inactiveStatus => 'غير نشط';

  @override
  String get suspendedStatus => 'موقوف';

  @override
  String get repay => 'سداد';

  @override
  String get methodPayrollDeduction => 'خصم من الراتب';

  @override
  String get employeeLoan => 'قرض موظف';

  @override
  String get salaryAdvanceType => 'سلفة راتب';

  @override
  String get payrollPaid => 'رواتب مدفوعة';

  @override
  String get openAdvances => 'سلف مفتوحة';

  @override
  String get companyEmployees => 'موظفو الشركة';

  @override
  String get dashboardSubtitle =>
      'نظرة شاملة على أدائك المالي في الفترة المحددة';

  @override
  String get address => 'العنوان';

  @override
  String get periodStart => 'من تاريخ';

  @override
  String get periodEnd => 'إلى تاريخ';

  @override
  String get allowancesTotal => 'البدلات';

  @override
  String get deductionsTotal => 'الخصومات';

  @override
  String get grossAmount => 'الإجمالي';

  @override
  String get netAmount => 'الصافي';

  @override
  String get effectiveDate => 'تاريخ السريان';

  @override
  String get issuedAt => 'تاريخ الإصدار';

  @override
  String get remainingAmount => 'المتبقي';

  @override
  String get settledAmount => 'المسدد';

  @override
  String get addAdjustment => 'حفظ الحركة';

  @override
  String get postAdjustment => 'ترحيل محاسبي';

  @override
  String get cancelAdjustment => 'إلغاء الحركة';

  @override
  String get selectEmployee => 'اختر الموظف';

  @override
  String get showAll => 'عرض الكل';

  @override
  String get peopleCount => 'عدد الموظفين';

  @override
  String get taxInvoice => 'فاتورة ضريبية';

  @override
  String get invoiceItems => 'بنود الفاتورة';

  @override
  String get customerInfo => 'معلومات العميل';

  @override
  String get invoiceSummary => 'ملخص الفاتورة';

  @override
  String get notesAndTerms => 'الملاحظات والشروط';

  @override
  String get attachmentsTitle => 'المرفقات';

  @override
  String get downloadPdf => 'تحميل PDF';

  @override
  String get printDocument => 'طباعة';

  @override
  String get goBack => 'رجوع';

  @override
  String get invoiceGrandTotal => 'الإجمالي النهائي';

  @override
  String get invoiceTotalAmount => 'إجمالي الفاتورة';

  @override
  String get noAttachments => 'لا توجد مرفقات';

  @override
  String get uploadInvoiceAttachmentsHint =>
      'يمكن رفع المرفقات المتعلقة بالفاتورة هنا.';

  @override
  String get noNotes => 'لا توجد ملاحظات.';

  @override
  String get noTerms => 'لم تُعلَم الفاتورة بشروط.';

  @override
  String get termsAndConditions => 'الشروط والأحكام';

  @override
  String get auditEvent => 'الحدث';

  @override
  String get auditDescription => 'الوصف';

  @override
  String get auditUser => 'المستخدم';

  @override
  String get auditDate => 'التاريخ';

  @override
  String get invoiceCreatedEvent => 'تم إنشاء الفاتورة';

  @override
  String get invoiceIssuedEvent => 'تم إصدار الفاتورة';

  @override
  String get invoiceCancelledEvent => 'تم إلغاء الفاتورة';

  @override
  String get invoiceSentEvent => 'تم إرسال الفاتورة';

  @override
  String get invoiceUpdatedEvent => 'تم تحديث الفاتورة';

  @override
  String get invoiceReminderSentEvent => 'تم إرسال تذكير بالفاتورة';

  @override
  String get tableTotal => 'المجموع';

  @override
  String get relatedDocuments => 'المستندات المرتبطة';

  @override
  String get zatcaInfo => 'الفوترة الإلكترونية';

  @override
  String get telephone => 'الهاتف';

  @override
  String get lineNumber => '#';

  @override
  String get paidInFull => 'مدفوعة';

  @override
  String get draftStatus => 'مسودة';

  @override
  String get supplyDate => 'تاريخ التوريد';

  @override
  String get issueImmediately => 'إصدار الفاتورة مباشرة عند الحفظ';

  @override
  String get downloadXml => 'تحميل XML';

  @override
  String get viewQr => 'عرض رمز QR';

  @override
  String get zatcaFoundation =>
      'أساس داخلي للفوترة الإلكترونية. لا يوجد تخليص FATOORA ولا إبلاغ إنتاج.';

  @override
  String get xmlAvailable => 'XML متوفر';

  @override
  String get qrAvailable => 'رمز QR متوفر';

  @override
  String get xmlUnavailable => 'XML غير متوفر';

  @override
  String get qrUnavailable => 'رمز QR غير متوفر';

  @override
  String get sortBy => 'الترتيب';

  @override
  String get invoiceStatusFilter => 'حالة الفاتورة';

  @override
  String get recurringFrequency => 'دورية التكرار';

  @override
  String get nextDueDate => 'تاريخ الاستحقاق التالي';

  @override
  String get pickAttachments => 'اختيار مرفقات';

  @override
  String get frequencyMonthly => 'شهري';

  @override
  String get frequencyWeekly => 'أسبوعي';

  @override
  String get frequencyQuarterly => 'ربع سنوي';

  @override
  String get frequencyYearly => 'سنوي';

  @override
  String get sortNewest => 'الأحدث';

  @override
  String get sortDirection => 'اتجاه الترتيب';

  @override
  String get sortAscending => 'تصاعدي';

  @override
  String get sortDescending => 'تنازلي';

  @override
  String get statusPosted => 'مرحّل';

  @override
  String get statusReversed => 'معكوس';

  @override
  String get statusVoided => 'ملغى';

  @override
  String get noInvoicesYet => 'لا توجد فواتير بعد';

  @override
  String get noInvoicesSubtitle =>
      'أنشئ أول فاتورة أو عدّل معايير التصفية لعرض النتائج.';

  @override
  String get noResultsForFilters =>
      'لا توجد نتائج مطابقة لمعايير التصفية الحالية.';

  @override
  String get advancedFilters => 'تصفية متقدمة';

  @override
  String get newInvoice => 'فاتورة جديدة';

  @override
  String get statusOpen => 'مفتوح';

  @override
  String get statusClosed => 'مغلق';

  @override
  String get statusApproved => 'معتمد';

  @override
  String get expenseLockedHint => 'لا يمكن تعديل مصروف مرحّل من التطبيق.';
}
