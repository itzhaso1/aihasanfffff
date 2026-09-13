import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_ar.dart';
import 'app_localizations_en.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppLocalizations
/// returned by `AppLocalizations.of(context)`.
///
/// Applications need to include `AppLocalizations.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'l10n/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppLocalizations.localizationsDelegates,
///   supportedLocales: AppLocalizations.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppLocalizations.supportedLocales
/// property.
abstract class AppLocalizations {
  AppLocalizations(String locale)
    : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppLocalizations of(BuildContext context) {
    return Localizations.of<AppLocalizations>(context, AppLocalizations)!;
  }

  static const LocalizationsDelegate<AppLocalizations> delegate =
      _AppLocalizationsDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
        delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[
    Locale('ar'),
    Locale('en'),
  ];

  /// No description provided for @appName.
  ///
  /// In ar, this message translates to:
  /// **'حاسم للمالية'**
  String get appName;

  /// No description provided for @loginTitle.
  ///
  /// In ar, this message translates to:
  /// **'تسجيل الدخول'**
  String get loginTitle;

  /// No description provided for @emailOrPhone.
  ///
  /// In ar, this message translates to:
  /// **'البريد أو الجوال'**
  String get emailOrPhone;

  /// No description provided for @password.
  ///
  /// In ar, this message translates to:
  /// **'كلمة المرور'**
  String get password;

  /// No description provided for @loginAction.
  ///
  /// In ar, this message translates to:
  /// **'دخول'**
  String get loginAction;

  /// No description provided for @loginSubtitle.
  ///
  /// In ar, this message translates to:
  /// **'سجّل الدخول بنفس حساب حاسم'**
  String get loginSubtitle;

  /// No description provided for @orDivider.
  ///
  /// In ar, this message translates to:
  /// **'أو'**
  String get orDivider;

  /// No description provided for @continueWithGoogle.
  ///
  /// In ar, this message translates to:
  /// **'الدخول باستخدام Google'**
  String get continueWithGoogle;

  /// No description provided for @googleSigningIn.
  ///
  /// In ar, this message translates to:
  /// **'جار تسجيل الدخول...'**
  String get googleSigningIn;

  /// No description provided for @googleFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر تسجيل الدخول عبر Google.'**
  String get googleFailed;

  /// No description provided for @forgotPassword.
  ///
  /// In ar, this message translates to:
  /// **'نسيت كلمة المرور؟'**
  String get forgotPassword;

  /// No description provided for @forgotPasswordHint.
  ///
  /// In ar, this message translates to:
  /// **'أدخل بريدك الإلكتروني لإرسال رابط إعادة التعيين.'**
  String get forgotPasswordHint;

  /// No description provided for @forgotPasswordSent.
  ///
  /// In ar, this message translates to:
  /// **'تم إرسال رابط إعادة تعيين كلمة المرور.'**
  String get forgotPasswordSent;

  /// No description provided for @sendResetLink.
  ///
  /// In ar, this message translates to:
  /// **'إرسال الرابط'**
  String get sendResetLink;

  /// No description provided for @haveResetToken.
  ///
  /// In ar, this message translates to:
  /// **'لدي رمز إعادة التعيين'**
  String get haveResetToken;

  /// No description provided for @resetPassword.
  ///
  /// In ar, this message translates to:
  /// **'إعادة تعيين كلمة المرور'**
  String get resetPassword;

  /// No description provided for @resetToken.
  ///
  /// In ar, this message translates to:
  /// **'رمز إعادة التعيين'**
  String get resetToken;

  /// No description provided for @confirmPassword.
  ///
  /// In ar, this message translates to:
  /// **'تأكيد كلمة المرور'**
  String get confirmPassword;

  /// No description provided for @passwordResetDone.
  ///
  /// In ar, this message translates to:
  /// **'تم إعادة تعيين كلمة المرور بنجاح.'**
  String get passwordResetDone;

  /// No description provided for @selectWorkspaceHint.
  ///
  /// In ar, this message translates to:
  /// **'اختر مساحة العمل. لن يتم اختيار منشأة تلقائياً عند وجود أكثر من واحدة.'**
  String get selectWorkspaceHint;

  /// No description provided for @financeEnabled.
  ///
  /// In ar, this message translates to:
  /// **'المالية مفعّلة'**
  String get financeEnabled;

  /// No description provided for @financeDisabled.
  ///
  /// In ar, this message translates to:
  /// **'المالية غير مفعّلة'**
  String get financeDisabled;

  /// No description provided for @financeUnavailableTitle.
  ///
  /// In ar, this message translates to:
  /// **'هذه المنشأة لا تملك منتج المالية'**
  String get financeUnavailableTitle;

  /// No description provided for @financeUnavailableBody.
  ///
  /// In ar, this message translates to:
  /// **'الحساب مسجّل الدخول، لكن صلاحية المالية غير مفعّلة لهذه المساحة. يمكنك تبديل المنشأة أو تسجيل الخروج.'**
  String get financeUnavailableBody;

  /// No description provided for @googleAccountLinked.
  ///
  /// In ar, this message translates to:
  /// **'هذا البريد مرتبط بحساب موجود. سجّل الدخول بكلمة المرور أولاً لربط حساب Google.'**
  String get googleAccountLinked;

  /// No description provided for @logout.
  ///
  /// In ar, this message translates to:
  /// **'تسجيل الخروج'**
  String get logout;

  /// No description provided for @workspace.
  ///
  /// In ar, this message translates to:
  /// **'مساحة العمل'**
  String get workspace;

  /// No description provided for @switchWorkspace.
  ///
  /// In ar, this message translates to:
  /// **'تبديل المنشأة'**
  String get switchWorkspace;

  /// No description provided for @dashboard.
  ///
  /// In ar, this message translates to:
  /// **'لوحة المالية'**
  String get dashboard;

  /// No description provided for @customers.
  ///
  /// In ar, this message translates to:
  /// **'العملاء'**
  String get customers;

  /// No description provided for @quotes.
  ///
  /// In ar, this message translates to:
  /// **'عروض الأسعار'**
  String get quotes;

  /// No description provided for @invoices.
  ///
  /// In ar, this message translates to:
  /// **'الفواتير'**
  String get invoices;

  /// No description provided for @payments.
  ///
  /// In ar, this message translates to:
  /// **'المدفوعات'**
  String get payments;

  /// No description provided for @receipts.
  ///
  /// In ar, this message translates to:
  /// **'الإيصالات'**
  String get receipts;

  /// No description provided for @statements.
  ///
  /// In ar, this message translates to:
  /// **'كشوف الحساب'**
  String get statements;

  /// No description provided for @notes.
  ///
  /// In ar, this message translates to:
  /// **'الإشعارات'**
  String get notes;

  /// No description provided for @contracts.
  ///
  /// In ar, this message translates to:
  /// **'العقود'**
  String get contracts;

  /// No description provided for @expenses.
  ///
  /// In ar, this message translates to:
  /// **'المصروفات'**
  String get expenses;

  /// No description provided for @purchases.
  ///
  /// In ar, this message translates to:
  /// **'المشتريات'**
  String get purchases;

  /// No description provided for @reports.
  ///
  /// In ar, this message translates to:
  /// **'التقارير'**
  String get reports;

  /// No description provided for @settings.
  ///
  /// In ar, this message translates to:
  /// **'الإعدادات'**
  String get settings;

  /// No description provided for @search.
  ///
  /// In ar, this message translates to:
  /// **'بحث'**
  String get search;

  /// No description provided for @retry.
  ///
  /// In ar, this message translates to:
  /// **'إعادة المحاولة'**
  String get retry;

  /// No description provided for @empty.
  ///
  /// In ar, this message translates to:
  /// **'لا توجد بيانات'**
  String get empty;

  /// No description provided for @save.
  ///
  /// In ar, this message translates to:
  /// **'حفظ'**
  String get save;

  /// No description provided for @create.
  ///
  /// In ar, this message translates to:
  /// **'إنشاء'**
  String get create;

  /// No description provided for @edit.
  ///
  /// In ar, this message translates to:
  /// **'تعديل'**
  String get edit;

  /// No description provided for @delete.
  ///
  /// In ar, this message translates to:
  /// **'حذف'**
  String get delete;

  /// No description provided for @cancel.
  ///
  /// In ar, this message translates to:
  /// **'إلغاء'**
  String get cancel;

  /// No description provided for @issue.
  ///
  /// In ar, this message translates to:
  /// **'إصدار'**
  String get issue;

  /// No description provided for @send.
  ///
  /// In ar, this message translates to:
  /// **'إرسال'**
  String get send;

  /// No description provided for @remind.
  ///
  /// In ar, this message translates to:
  /// **'تذكير'**
  String get remind;

  /// No description provided for @accept.
  ///
  /// In ar, this message translates to:
  /// **'قبول'**
  String get accept;

  /// No description provided for @reject.
  ///
  /// In ar, this message translates to:
  /// **'رفض'**
  String get reject;

  /// No description provided for @convert.
  ///
  /// In ar, this message translates to:
  /// **'تحويل إلى فاتورة'**
  String get convert;

  /// No description provided for @pdf.
  ///
  /// In ar, this message translates to:
  /// **'ملف PDF'**
  String get pdf;

  /// No description provided for @download.
  ///
  /// In ar, this message translates to:
  /// **'تنزيل'**
  String get download;

  /// No description provided for @copy.
  ///
  /// In ar, this message translates to:
  /// **'نسخ'**
  String get copy;

  /// No description provided for @open.
  ///
  /// In ar, this message translates to:
  /// **'فتح'**
  String get open;

  /// No description provided for @refresh.
  ///
  /// In ar, this message translates to:
  /// **'تحديث'**
  String get refresh;

  /// No description provided for @recordPayment.
  ///
  /// In ar, this message translates to:
  /// **'تسجيل دفعة'**
  String get recordPayment;

  /// No description provided for @reversePayment.
  ///
  /// In ar, this message translates to:
  /// **'عكس الدفعة'**
  String get reversePayment;

  /// No description provided for @checkout.
  ///
  /// In ar, this message translates to:
  /// **'رابط الدفع الإلكتروني'**
  String get checkout;

  /// No description provided for @createCheckout.
  ///
  /// In ar, this message translates to:
  /// **'إنشاء رابط الدفع'**
  String get createCheckout;

  /// No description provided for @paymentLinkHint.
  ///
  /// In ar, this message translates to:
  /// **'إنشاء الرابط لا يعني أن الفاتورة دُفعت.'**
  String get paymentLinkHint;

  /// No description provided for @permissionDenied.
  ///
  /// In ar, this message translates to:
  /// **'لا تملك صلاحية تنفيذ هذا الإجراء.'**
  String get permissionDenied;

  /// No description provided for @sessionExpired.
  ///
  /// In ar, this message translates to:
  /// **'انتهت جلسة تسجيل الدخول.'**
  String get sessionExpired;

  /// No description provided for @offline.
  ///
  /// In ar, this message translates to:
  /// **'تعذر الاتصال بالخادم. تحقق من الشبكة.'**
  String get offline;

  /// No description provided for @documentStatus.
  ///
  /// In ar, this message translates to:
  /// **'حالة المستند'**
  String get documentStatus;

  /// No description provided for @outcome.
  ///
  /// In ar, this message translates to:
  /// **'النتيجة التجارية'**
  String get outcome;

  /// No description provided for @deliveryStatus.
  ///
  /// In ar, this message translates to:
  /// **'حالة التسليم'**
  String get deliveryStatus;

  /// No description provided for @paymentStatus.
  ///
  /// In ar, this message translates to:
  /// **'حالة التحصيل'**
  String get paymentStatus;

  /// No description provided for @outstanding.
  ///
  /// In ar, this message translates to:
  /// **'الرصيد المستحق'**
  String get outstanding;

  /// No description provided for @subtotal.
  ///
  /// In ar, this message translates to:
  /// **'المجموع الفرعي'**
  String get subtotal;

  /// No description provided for @tax.
  ///
  /// In ar, this message translates to:
  /// **'الضريبة'**
  String get tax;

  /// No description provided for @total.
  ///
  /// In ar, this message translates to:
  /// **'الإجمالي'**
  String get total;

  /// No description provided for @paid.
  ///
  /// In ar, this message translates to:
  /// **'المدفوع'**
  String get paid;

  /// No description provided for @due.
  ///
  /// In ar, this message translates to:
  /// **'المستحق'**
  String get due;

  /// No description provided for @customer.
  ///
  /// In ar, this message translates to:
  /// **'العميل'**
  String get customer;

  /// No description provided for @amount.
  ///
  /// In ar, this message translates to:
  /// **'المبلغ'**
  String get amount;

  /// No description provided for @method.
  ///
  /// In ar, this message translates to:
  /// **'طريقة الدفع'**
  String get method;

  /// No description provided for @reference.
  ///
  /// In ar, this message translates to:
  /// **'المرجع'**
  String get reference;

  /// No description provided for @date.
  ///
  /// In ar, this message translates to:
  /// **'التاريخ'**
  String get date;

  /// No description provided for @status.
  ///
  /// In ar, this message translates to:
  /// **'الحالة'**
  String get status;

  /// No description provided for @confirm.
  ///
  /// In ar, this message translates to:
  /// **'تأكيد'**
  String get confirm;

  /// No description provided for @confirmDestructive.
  ///
  /// In ar, this message translates to:
  /// **'هل أنت متأكد من تنفيذ هذا الإجراء؟'**
  String get confirmDestructive;

  /// No description provided for @copied.
  ///
  /// In ar, this message translates to:
  /// **'تم النسخ'**
  String get copied;

  /// No description provided for @success.
  ///
  /// In ar, this message translates to:
  /// **'تم بنجاح'**
  String get success;

  /// No description provided for @more.
  ///
  /// In ar, this message translates to:
  /// **'المزيد'**
  String get more;

  /// No description provided for @overview.
  ///
  /// In ar, this message translates to:
  /// **'نظرة عامة'**
  String get overview;

  /// No description provided for @lines.
  ///
  /// In ar, this message translates to:
  /// **'البنود'**
  String get lines;

  /// No description provided for @notesField.
  ///
  /// In ar, this message translates to:
  /// **'ملاحظات'**
  String get notesField;

  /// No description provided for @vatNumber.
  ///
  /// In ar, this message translates to:
  /// **'الرقم الضريبي'**
  String get vatNumber;

  /// No description provided for @crNumber.
  ///
  /// In ar, this message translates to:
  /// **'السجل التجاري'**
  String get crNumber;

  /// No description provided for @email.
  ///
  /// In ar, this message translates to:
  /// **'البريد الإلكتروني'**
  String get email;

  /// No description provided for @phone.
  ///
  /// In ar, this message translates to:
  /// **'الجوال'**
  String get phone;

  /// No description provided for @company.
  ///
  /// In ar, this message translates to:
  /// **'شركة'**
  String get company;

  /// No description provided for @individual.
  ///
  /// In ar, this message translates to:
  /// **'فرد'**
  String get individual;

  /// No description provided for @draft.
  ///
  /// In ar, this message translates to:
  /// **'مسودة'**
  String get draft;

  /// No description provided for @issued.
  ///
  /// In ar, this message translates to:
  /// **'صادرة'**
  String get issued;

  /// No description provided for @cancelled.
  ///
  /// In ar, this message translates to:
  /// **'ملغاة'**
  String get cancelled;

  /// No description provided for @pending.
  ///
  /// In ar, this message translates to:
  /// **'قيد الانتظار'**
  String get pending;

  /// No description provided for @accepted.
  ///
  /// In ar, this message translates to:
  /// **'مقبولة'**
  String get accepted;

  /// No description provided for @rejected.
  ///
  /// In ar, this message translates to:
  /// **'مرفوضة'**
  String get rejected;

  /// No description provided for @expired.
  ///
  /// In ar, this message translates to:
  /// **'منتهية'**
  String get expired;

  /// No description provided for @converted.
  ///
  /// In ar, this message translates to:
  /// **'محوّلة'**
  String get converted;

  /// No description provided for @unpaid.
  ///
  /// In ar, this message translates to:
  /// **'غير مدفوعة'**
  String get unpaid;

  /// No description provided for @partial.
  ///
  /// In ar, this message translates to:
  /// **'مدفوعة جزئياً'**
  String get partial;

  /// No description provided for @overdue.
  ///
  /// In ar, this message translates to:
  /// **'متأخرة'**
  String get overdue;

  /// No description provided for @posted.
  ///
  /// In ar, this message translates to:
  /// **'مرحّلة'**
  String get posted;

  /// No description provided for @voided.
  ///
  /// In ar, this message translates to:
  /// **'ملغاة'**
  String get voided;

  /// No description provided for @reversed.
  ///
  /// In ar, this message translates to:
  /// **'معكوسة'**
  String get reversed;

  /// No description provided for @credit.
  ///
  /// In ar, this message translates to:
  /// **'إشعار دائن'**
  String get credit;

  /// No description provided for @debit.
  ///
  /// In ar, this message translates to:
  /// **'إشعار مدين'**
  String get debit;

  /// No description provided for @apiHost.
  ///
  /// In ar, this message translates to:
  /// **'عنوان الخادم'**
  String get apiHost;

  /// No description provided for @theme.
  ///
  /// In ar, this message translates to:
  /// **'المظهر'**
  String get theme;

  /// No description provided for @language.
  ///
  /// In ar, this message translates to:
  /// **'اللغة'**
  String get language;

  /// No description provided for @arabic.
  ///
  /// In ar, this message translates to:
  /// **'العربية'**
  String get arabic;

  /// No description provided for @english.
  ///
  /// In ar, this message translates to:
  /// **'English'**
  String get english;

  /// No description provided for @sales.
  ///
  /// In ar, this message translates to:
  /// **'المبيعات'**
  String get sales;

  /// No description provided for @receivables.
  ///
  /// In ar, this message translates to:
  /// **'الذمم المدينة'**
  String get receivables;

  /// No description provided for @payables.
  ///
  /// In ar, this message translates to:
  /// **'الذمم الدائنة'**
  String get payables;

  /// No description provided for @invoicesDue.
  ///
  /// In ar, this message translates to:
  /// **'فواتير مستحقة'**
  String get invoicesDue;

  /// No description provided for @overdueInvoices.
  ///
  /// In ar, this message translates to:
  /// **'فواتير متأخرة'**
  String get overdueInvoices;

  /// No description provided for @paidThisPeriod.
  ///
  /// In ar, this message translates to:
  /// **'المحصّل هذه الفترة'**
  String get paidThisPeriod;

  /// No description provided for @recentInvoices.
  ///
  /// In ar, this message translates to:
  /// **'أحدث الفواتير'**
  String get recentInvoices;

  /// No description provided for @recentPayments.
  ///
  /// In ar, this message translates to:
  /// **'أحدث المدفوعات'**
  String get recentPayments;

  /// No description provided for @noPermissionScreen.
  ///
  /// In ar, this message translates to:
  /// **'هذه الشاشة غير متاحة لصلاحياتك الحالية.'**
  String get noPermissionScreen;

  /// No description provided for @checkoutUnavailable.
  ///
  /// In ar, this message translates to:
  /// **'رابط الدفع الإلكتروني غير متاح.'**
  String get checkoutUnavailable;

  /// No description provided for @neverMarkPaidLocally.
  ///
  /// In ar, this message translates to:
  /// **'لن تُعلَّم الفاتورة مدفوعة إلا بعد تأكيد الخادم.'**
  String get neverMarkPaidLocally;

  /// No description provided for @recipient.
  ///
  /// In ar, this message translates to:
  /// **'المستلم'**
  String get recipient;

  /// No description provided for @quantity.
  ///
  /// In ar, this message translates to:
  /// **'الكمية'**
  String get quantity;

  /// No description provided for @price.
  ///
  /// In ar, this message translates to:
  /// **'السعر'**
  String get price;

  /// No description provided for @description.
  ///
  /// In ar, this message translates to:
  /// **'الوصف'**
  String get description;

  /// No description provided for @unit.
  ///
  /// In ar, this message translates to:
  /// **'الوحدة'**
  String get unit;

  /// No description provided for @issueDate.
  ///
  /// In ar, this message translates to:
  /// **'تاريخ الإصدار'**
  String get issueDate;

  /// No description provided for @dueDate.
  ///
  /// In ar, this message translates to:
  /// **'تاريخ الاستحقاق'**
  String get dueDate;

  /// No description provided for @expiryDate.
  ///
  /// In ar, this message translates to:
  /// **'تاريخ الانتهاء'**
  String get expiryDate;

  /// No description provided for @from.
  ///
  /// In ar, this message translates to:
  /// **'من'**
  String get from;

  /// No description provided for @to.
  ///
  /// In ar, this message translates to:
  /// **'إلى'**
  String get to;

  /// No description provided for @openingBalance.
  ///
  /// In ar, this message translates to:
  /// **'الرصيد الافتتاحي'**
  String get openingBalance;

  /// No description provided for @closingBalance.
  ///
  /// In ar, this message translates to:
  /// **'الرصيد الختامي'**
  String get closingBalance;

  /// No description provided for @csv.
  ///
  /// In ar, this message translates to:
  /// **'CSV'**
  String get csv;

  /// No description provided for @suppliers.
  ///
  /// In ar, this message translates to:
  /// **'الموردون'**
  String get suppliers;

  /// No description provided for @category.
  ///
  /// In ar, this message translates to:
  /// **'التصنيف'**
  String get category;

  /// No description provided for @attachment.
  ///
  /// In ar, this message translates to:
  /// **'مرفق'**
  String get attachment;

  /// No description provided for @generateInvoice.
  ///
  /// In ar, this message translates to:
  /// **'توليد فاتورة مسودة'**
  String get generateInvoice;

  /// No description provided for @signContract.
  ///
  /// In ar, this message translates to:
  /// **'تفعيل / توقيع'**
  String get signContract;

  /// No description provided for @closeContract.
  ///
  /// In ar, this message translates to:
  /// **'إغلاق'**
  String get closeContract;

  /// No description provided for @billingSchedule.
  ///
  /// In ar, this message translates to:
  /// **'جدول الفوترة'**
  String get billingSchedule;

  /// No description provided for @profitLoss.
  ///
  /// In ar, this message translates to:
  /// **'الأرباح والخسائر'**
  String get profitLoss;

  /// No description provided for @trialBalance.
  ///
  /// In ar, this message translates to:
  /// **'ميزان المراجعة'**
  String get trialBalance;

  /// No description provided for @cashFlow.
  ///
  /// In ar, this message translates to:
  /// **'التدفقات النقدية'**
  String get cashFlow;

  /// No description provided for @balanceSheet.
  ///
  /// In ar, this message translates to:
  /// **'الميزانية'**
  String get balanceSheet;

  /// No description provided for @generalLedger.
  ///
  /// In ar, this message translates to:
  /// **'دفتر الأستاذ'**
  String get generalLedger;

  /// No description provided for @arAging.
  ///
  /// In ar, this message translates to:
  /// **'أعمار الذمم المدينة'**
  String get arAging;

  /// No description provided for @apAging.
  ///
  /// In ar, this message translates to:
  /// **'أعمار الذمم الدائنة'**
  String get apAging;

  /// No description provided for @loadMore.
  ///
  /// In ar, this message translates to:
  /// **'تحميل المزيد'**
  String get loadMore;

  /// No description provided for @globalSearch.
  ///
  /// In ar, this message translates to:
  /// **'بحث مالي'**
  String get globalSearch;

  /// No description provided for @exports.
  ///
  /// In ar, this message translates to:
  /// **'تصدير CSV'**
  String get exports;

  /// No description provided for @auditTrail.
  ///
  /// In ar, this message translates to:
  /// **'سجل التدقيق'**
  String get auditTrail;

  /// No description provided for @downloadAttachment.
  ///
  /// In ar, this message translates to:
  /// **'تنزيل المرفق'**
  String get downloadAttachment;

  /// No description provided for @noResults.
  ///
  /// In ar, this message translates to:
  /// **'لا توجد نتائج'**
  String get noResults;

  /// No description provided for @selectCustomer.
  ///
  /// In ar, this message translates to:
  /// **'اختر العميل'**
  String get selectCustomer;

  /// No description provided for @selectSupplier.
  ///
  /// In ar, this message translates to:
  /// **'اختر المورد'**
  String get selectSupplier;

  /// No description provided for @addLine.
  ///
  /// In ar, this message translates to:
  /// **'إضافة بند'**
  String get addLine;

  /// No description provided for @removeLine.
  ///
  /// In ar, this message translates to:
  /// **'حذف البند'**
  String get removeLine;

  /// No description provided for @taxRate.
  ///
  /// In ar, this message translates to:
  /// **'نسبة الضريبة'**
  String get taxRate;

  /// No description provided for @discount.
  ///
  /// In ar, this message translates to:
  /// **'الخصم'**
  String get discount;

  /// No description provided for @invoiceId.
  ///
  /// In ar, this message translates to:
  /// **'رقم الفاتورة'**
  String get invoiceId;

  /// No description provided for @taxableAmount.
  ///
  /// In ar, this message translates to:
  /// **'المبلغ الخاضع للضريبة'**
  String get taxableAmount;

  /// No description provided for @amountCredited.
  ///
  /// In ar, this message translates to:
  /// **'المُشعَر دائناً'**
  String get amountCredited;

  /// No description provided for @amountDebited.
  ///
  /// In ar, this message translates to:
  /// **'المُشعَر مديناً'**
  String get amountDebited;

  /// No description provided for @whatsapp.
  ///
  /// In ar, this message translates to:
  /// **'واتساب'**
  String get whatsapp;

  /// No description provided for @buildingNumber.
  ///
  /// In ar, this message translates to:
  /// **'رقم المبنى'**
  String get buildingNumber;

  /// No description provided for @district.
  ///
  /// In ar, this message translates to:
  /// **'الحي'**
  String get district;

  /// No description provided for @postalCode.
  ///
  /// In ar, this message translates to:
  /// **'الرمز البريدي'**
  String get postalCode;

  /// No description provided for @city.
  ///
  /// In ar, this message translates to:
  /// **'المدينة'**
  String get city;

  /// No description provided for @street.
  ///
  /// In ar, this message translates to:
  /// **'الشارع'**
  String get street;

  /// No description provided for @country.
  ///
  /// In ar, this message translates to:
  /// **'الدولة'**
  String get country;

  /// No description provided for @paymentTerms.
  ///
  /// In ar, this message translates to:
  /// **'شروط الدفع'**
  String get paymentTerms;

  /// No description provided for @treasuryAccount.
  ///
  /// In ar, this message translates to:
  /// **'حساب الخزينة'**
  String get treasuryAccount;

  /// No description provided for @recurring.
  ///
  /// In ar, this message translates to:
  /// **'متكرر (علم فقط)'**
  String get recurring;

  /// No description provided for @netProfit.
  ///
  /// In ar, this message translates to:
  /// **'صافي الربح'**
  String get netProfit;

  /// No description provided for @outputVat.
  ///
  /// In ar, this message translates to:
  /// **'ضريبة المخرجات'**
  String get outputVat;

  /// No description provided for @inputVat.
  ///
  /// In ar, this message translates to:
  /// **'ضريبة المدخلات'**
  String get inputVat;

  /// No description provided for @netVat.
  ///
  /// In ar, this message translates to:
  /// **'صافي الضريبة'**
  String get netVat;

  /// No description provided for @cashBalance.
  ///
  /// In ar, this message translates to:
  /// **'النقد'**
  String get cashBalance;

  /// No description provided for @bankBalance.
  ///
  /// In ar, this message translates to:
  /// **'البنك'**
  String get bankBalance;

  /// No description provided for @activeContracts.
  ///
  /// In ar, this message translates to:
  /// **'عقود نشطة'**
  String get activeContracts;

  /// No description provided for @recentExpenses.
  ///
  /// In ar, this message translates to:
  /// **'أحدث المصروفات'**
  String get recentExpenses;

  /// No description provided for @statementDebit.
  ///
  /// In ar, this message translates to:
  /// **'مدين'**
  String get statementDebit;

  /// No description provided for @statementCredit.
  ///
  /// In ar, this message translates to:
  /// **'دائن'**
  String get statementCredit;

  /// No description provided for @runningBalance.
  ///
  /// In ar, this message translates to:
  /// **'الرصيد الجاري'**
  String get runningBalance;

  /// No description provided for @invoicesTotal.
  ///
  /// In ar, this message translates to:
  /// **'إجمالي الفواتير'**
  String get invoicesTotal;

  /// No description provided for @paymentsTotal.
  ///
  /// In ar, this message translates to:
  /// **'إجمالي الدفعات'**
  String get paymentsTotal;

  /// No description provided for @creditsTotal.
  ///
  /// In ar, this message translates to:
  /// **'إجمالي الدائن'**
  String get creditsTotal;

  /// No description provided for @debitsTotal.
  ///
  /// In ar, this message translates to:
  /// **'إجمالي المدين'**
  String get debitsTotal;

  /// No description provided for @zatcaQr.
  ///
  /// In ar, this message translates to:
  /// **'رمز الزكاة حاضر'**
  String get zatcaQr;

  /// No description provided for @terms.
  ///
  /// In ar, this message translates to:
  /// **'الشروط'**
  String get terms;

  /// No description provided for @rejectionReason.
  ///
  /// In ar, this message translates to:
  /// **'سبب الرفض'**
  String get rejectionReason;

  /// No description provided for @website.
  ///
  /// In ar, this message translates to:
  /// **'الموقع'**
  String get website;

  /// No description provided for @currency.
  ///
  /// In ar, this message translates to:
  /// **'العملة'**
  String get currency;

  /// No description provided for @invoicePrefix.
  ///
  /// In ar, this message translates to:
  /// **'بادئة الفاتورة'**
  String get invoicePrefix;

  /// No description provided for @defaultVatRate.
  ///
  /// In ar, this message translates to:
  /// **'نسبة الضريبة الافتراضية'**
  String get defaultVatRate;

  /// No description provided for @zatcaMode.
  ///
  /// In ar, this message translates to:
  /// **'وضع الربط مع الزكاة'**
  String get zatcaMode;

  /// No description provided for @nextRun.
  ///
  /// In ar, this message translates to:
  /// **'التشغيل التالي'**
  String get nextRun;

  /// No description provided for @frequency.
  ///
  /// In ar, this message translates to:
  /// **'التكرار'**
  String get frequency;

  /// No description provided for @autoIssue.
  ///
  /// In ar, this message translates to:
  /// **'إصدار تلقائي'**
  String get autoIssue;

  /// No description provided for @generatedCount.
  ///
  /// In ar, this message translates to:
  /// **'المُولَّد'**
  String get generatedCount;

  /// No description provided for @paymentDate.
  ///
  /// In ar, this message translates to:
  /// **'تاريخ الدفع'**
  String get paymentDate;

  /// No description provided for @supplier.
  ///
  /// In ar, this message translates to:
  /// **'المورد'**
  String get supplier;

  /// No description provided for @openingCash.
  ///
  /// In ar, this message translates to:
  /// **'افتتاحي النقد'**
  String get openingCash;

  /// No description provided for @netChange.
  ///
  /// In ar, this message translates to:
  /// **'صافي التغير'**
  String get netChange;

  /// No description provided for @closingCash.
  ///
  /// In ar, this message translates to:
  /// **'ختامي النقد'**
  String get closingCash;

  /// No description provided for @assets.
  ///
  /// In ar, this message translates to:
  /// **'الأصول'**
  String get assets;

  /// No description provided for @liabilities.
  ///
  /// In ar, this message translates to:
  /// **'الالتزامات'**
  String get liabilities;

  /// No description provided for @equity.
  ///
  /// In ar, this message translates to:
  /// **'حقوق الملكية'**
  String get equity;

  /// No description provided for @revenue.
  ///
  /// In ar, this message translates to:
  /// **'الإيرادات'**
  String get revenue;

  /// No description provided for @cogs.
  ///
  /// In ar, this message translates to:
  /// **'تكلفة المبيعات'**
  String get cogs;

  /// No description provided for @grossProfit.
  ///
  /// In ar, this message translates to:
  /// **'مجمل الربح'**
  String get grossProfit;

  /// No description provided for @additionalNumber.
  ///
  /// In ar, this message translates to:
  /// **'الرقم الإضافي'**
  String get additionalNumber;

  /// No description provided for @companyNameAr.
  ///
  /// In ar, this message translates to:
  /// **'اسم الشركة بالعربية'**
  String get companyNameAr;

  /// No description provided for @addressLine.
  ///
  /// In ar, this message translates to:
  /// **'سطر العنوان'**
  String get addressLine;

  /// No description provided for @filterAll.
  ///
  /// In ar, this message translates to:
  /// **'الكل'**
  String get filterAll;

  /// No description provided for @generatedInvoices.
  ///
  /// In ar, this message translates to:
  /// **'الفواتير المولّدة'**
  String get generatedInvoices;

  /// No description provided for @invoicedTotal.
  ///
  /// In ar, this message translates to:
  /// **'المفوتر'**
  String get invoicedTotal;

  /// No description provided for @snapshots.
  ///
  /// In ar, this message translates to:
  /// **'اللقطات'**
  String get snapshots;

  /// No description provided for @reason.
  ///
  /// In ar, this message translates to:
  /// **'السبب'**
  String get reason;

  /// No description provided for @inventoryValuation.
  ///
  /// In ar, this message translates to:
  /// **'تقييم المخزون'**
  String get inventoryValuation;

  /// No description provided for @navControl.
  ///
  /// In ar, this message translates to:
  /// **'لوحة التحكم'**
  String get navControl;

  /// No description provided for @navSales.
  ///
  /// In ar, this message translates to:
  /// **'المبيعات'**
  String get navSales;

  /// No description provided for @navPayments.
  ///
  /// In ar, this message translates to:
  /// **'المدفوعات'**
  String get navPayments;

  /// No description provided for @navParties.
  ///
  /// In ar, this message translates to:
  /// **'العملاء والموردون'**
  String get navParties;

  /// No description provided for @navPurchases.
  ///
  /// In ar, this message translates to:
  /// **'المشتريات'**
  String get navPurchases;

  /// No description provided for @navOps.
  ///
  /// In ar, this message translates to:
  /// **'المخزون'**
  String get navOps;

  /// No description provided for @navReports.
  ///
  /// In ar, this message translates to:
  /// **'التقارير'**
  String get navReports;

  /// No description provided for @navAccounting.
  ///
  /// In ar, this message translates to:
  /// **'المحاسبة'**
  String get navAccounting;

  /// No description provided for @navBanks.
  ///
  /// In ar, this message translates to:
  /// **'البنوك والخزينة'**
  String get navBanks;

  /// No description provided for @billingHub.
  ///
  /// In ar, this message translates to:
  /// **'لوحة الفوترة'**
  String get billingHub;

  /// No description provided for @salesHub.
  ///
  /// In ar, this message translates to:
  /// **'المبيعات'**
  String get salesHub;

  /// No description provided for @leads.
  ///
  /// In ar, this message translates to:
  /// **'العملاء المحتملون'**
  String get leads;

  /// No description provided for @priceLists.
  ///
  /// In ar, this message translates to:
  /// **'قوائم الأسعار'**
  String get priceLists;

  /// No description provided for @purchaseOrders.
  ///
  /// In ar, this message translates to:
  /// **'أوامر الشراء'**
  String get purchaseOrders;

  /// No description provided for @products.
  ///
  /// In ar, this message translates to:
  /// **'المنتجات'**
  String get products;

  /// No description provided for @inventory.
  ///
  /// In ar, this message translates to:
  /// **'المخزون'**
  String get inventory;

  /// No description provided for @projects.
  ///
  /// In ar, this message translates to:
  /// **'المشاريع'**
  String get projects;

  /// No description provided for @accountingHub.
  ///
  /// In ar, this message translates to:
  /// **'لوحة المحاسبة'**
  String get accountingHub;

  /// No description provided for @fiscalYears.
  ///
  /// In ar, this message translates to:
  /// **'السنوات والفترات'**
  String get fiscalYears;

  /// No description provided for @vatPage.
  ///
  /// In ar, this message translates to:
  /// **'VAT'**
  String get vatPage;

  /// No description provided for @alerts.
  ///
  /// In ar, this message translates to:
  /// **'التنبيهات'**
  String get alerts;

  /// No description provided for @copilot.
  ///
  /// In ar, this message translates to:
  /// **'المساعد المالي'**
  String get copilot;

  /// No description provided for @banks.
  ///
  /// In ar, this message translates to:
  /// **'الحسابات البنكية'**
  String get banks;

  /// No description provided for @treasury.
  ///
  /// In ar, this message translates to:
  /// **'الخزينة والتحويلات'**
  String get treasury;

  /// No description provided for @walkInCustomer.
  ///
  /// In ar, this message translates to:
  /// **'عميل نقدي / عابر'**
  String get walkInCustomer;

  /// No description provided for @taxDocumentSubtype.
  ///
  /// In ar, this message translates to:
  /// **'تصنيف المستند الضريبي'**
  String get taxDocumentSubtype;

  /// No description provided for @zatcaRequirement.
  ///
  /// In ar, this message translates to:
  /// **'متطلب الفوترة الإلكترونية'**
  String get zatcaRequirement;

  /// No description provided for @taxProfile.
  ///
  /// In ar, this message translates to:
  /// **'نوع الضريبة'**
  String get taxProfile;

  /// No description provided for @taxPriceMode.
  ///
  /// In ar, this message translates to:
  /// **'سعر شامل/غير شامل'**
  String get taxPriceMode;

  /// No description provided for @exclusive.
  ///
  /// In ar, this message translates to:
  /// **'غير شامل الضريبة'**
  String get exclusive;

  /// No description provided for @inclusive.
  ///
  /// In ar, this message translates to:
  /// **'شامل الضريبة'**
  String get inclusive;

  /// No description provided for @standardTax.
  ///
  /// In ar, this message translates to:
  /// **'قياسية'**
  String get standardTax;

  /// No description provided for @simplifiedTax.
  ///
  /// In ar, this message translates to:
  /// **'مبسطة'**
  String get simplifiedTax;

  /// No description provided for @notRequired.
  ///
  /// In ar, this message translates to:
  /// **'غير مطلوب'**
  String get notRequired;

  /// No description provided for @requiredLater.
  ///
  /// In ar, this message translates to:
  /// **'مطلوب لاحقاً'**
  String get requiredLater;

  /// No description provided for @headerSection.
  ///
  /// In ar, this message translates to:
  /// **'البيانات الأساسية'**
  String get headerSection;

  /// No description provided for @datesSection.
  ///
  /// In ar, this message translates to:
  /// **'التواريخ والشروط'**
  String get datesSection;

  /// No description provided for @taxSection.
  ///
  /// In ar, this message translates to:
  /// **'الضريبة'**
  String get taxSection;

  /// No description provided for @itemsSection.
  ///
  /// In ar, this message translates to:
  /// **'البنود'**
  String get itemsSection;

  /// No description provided for @notesSection.
  ///
  /// In ar, this message translates to:
  /// **'ملاحظات ومرفقات'**
  String get notesSection;

  /// No description provided for @summarySection.
  ///
  /// In ar, this message translates to:
  /// **'الملخص'**
  String get summarySection;

  /// No description provided for @selectProduct.
  ///
  /// In ar, this message translates to:
  /// **'اختر منتجاً'**
  String get selectProduct;

  /// No description provided for @freeTextItem.
  ///
  /// In ar, this message translates to:
  /// **'بند حر'**
  String get freeTextItem;

  /// No description provided for @exemptionReason.
  ///
  /// In ar, this message translates to:
  /// **'سبب الإعفاء'**
  String get exemptionReason;

  /// No description provided for @exemptionCode.
  ///
  /// In ar, this message translates to:
  /// **'رمز الإعفاء'**
  String get exemptionCode;

  /// No description provided for @project.
  ///
  /// In ar, this message translates to:
  /// **'المشروع'**
  String get project;

  /// No description provided for @contract.
  ///
  /// In ar, this message translates to:
  /// **'العقد'**
  String get contract;

  /// No description provided for @sku.
  ///
  /// In ar, this message translates to:
  /// **'رمز SKU'**
  String get sku;

  /// No description provided for @stock.
  ///
  /// In ar, this message translates to:
  /// **'المخزون'**
  String get stock;

  /// No description provided for @budget.
  ///
  /// In ar, this message translates to:
  /// **'الميزانية'**
  String get budget;

  /// No description provided for @profit.
  ///
  /// In ar, this message translates to:
  /// **'الربح'**
  String get profit;

  /// No description provided for @costs.
  ///
  /// In ar, this message translates to:
  /// **'التكاليف'**
  String get costs;

  /// No description provided for @submitPo.
  ///
  /// In ar, this message translates to:
  /// **'إرسال'**
  String get submitPo;

  /// No description provided for @receivePo.
  ///
  /// In ar, this message translates to:
  /// **'استلام'**
  String get receivePo;

  /// No description provided for @billPo.
  ///
  /// In ar, this message translates to:
  /// **'تحويل إلى فاتورة'**
  String get billPo;

  /// No description provided for @convertLead.
  ///
  /// In ar, this message translates to:
  /// **'تحويل إلى عميل'**
  String get convertLead;

  /// No description provided for @markLost.
  ///
  /// In ar, this message translates to:
  /// **'تعليم كضائع'**
  String get markLost;

  /// No description provided for @askCopilot.
  ///
  /// In ar, this message translates to:
  /// **'اسأل'**
  String get askCopilot;

  /// No description provided for @copilotHint.
  ///
  /// In ar, this message translates to:
  /// **'اسأل عن المبيعات، الأرباح، المتأخرات، أو ما يحتاج انتباهاً. المساعد لا يخترع مبالغ.'**
  String get copilotHint;

  /// No description provided for @transfer.
  ///
  /// In ar, this message translates to:
  /// **'تحويل'**
  String get transfer;

  /// No description provided for @fromAccount.
  ///
  /// In ar, this message translates to:
  /// **'من حساب'**
  String get fromAccount;

  /// No description provided for @toAccount.
  ///
  /// In ar, this message translates to:
  /// **'إلى حساب'**
  String get toAccount;

  /// No description provided for @openYear.
  ///
  /// In ar, this message translates to:
  /// **'فتح'**
  String get openYear;

  /// No description provided for @closeYear.
  ///
  /// In ar, this message translates to:
  /// **'إغلاق'**
  String get closeYear;

  /// No description provided for @generatePeriods.
  ///
  /// In ar, this message translates to:
  /// **'توليد فترات شهرية'**
  String get generatePeriods;

  /// No description provided for @approve.
  ///
  /// In ar, this message translates to:
  /// **'اعتماد'**
  String get approve;

  /// No description provided for @markDraft.
  ///
  /// In ar, this message translates to:
  /// **'إعادة لمسودة'**
  String get markDraft;

  /// No description provided for @addItem.
  ///
  /// In ar, this message translates to:
  /// **'إضافة عنصر'**
  String get addItem;

  /// No description provided for @soldTotal.
  ///
  /// In ar, this message translates to:
  /// **'إجمالي المبيعات'**
  String get soldTotal;

  /// No description provided for @currentBalance.
  ///
  /// In ar, this message translates to:
  /// **'الرصيد الحالي'**
  String get currentBalance;

  /// No description provided for @iban.
  ///
  /// In ar, this message translates to:
  /// **'IBAN'**
  String get iban;

  /// No description provided for @bankName.
  ///
  /// In ar, this message translates to:
  /// **'اسم البنك'**
  String get bankName;

  /// No description provided for @accountNumber.
  ///
  /// In ar, this message translates to:
  /// **'رقم الحساب'**
  String get accountNumber;

  /// No description provided for @askQuestion.
  ///
  /// In ar, this message translates to:
  /// **'السؤال'**
  String get askQuestion;

  /// No description provided for @severity.
  ///
  /// In ar, this message translates to:
  /// **'الأهمية'**
  String get severity;

  /// No description provided for @estimatedValue.
  ///
  /// In ar, this message translates to:
  /// **'القيمة المتوقعة'**
  String get estimatedValue;

  /// No description provided for @source.
  ///
  /// In ar, this message translates to:
  /// **'المصدر'**
  String get source;

  /// No description provided for @orderDate.
  ///
  /// In ar, this message translates to:
  /// **'تاريخ الطلب'**
  String get orderDate;

  /// No description provided for @expectedDate.
  ///
  /// In ar, this message translates to:
  /// **'التاريخ المتوقع'**
  String get expectedDate;

  /// No description provided for @createTaxRate.
  ///
  /// In ar, this message translates to:
  /// **'حفظ نسبة ضريبة'**
  String get createTaxRate;

  /// No description provided for @createTreasuryAccount.
  ///
  /// In ar, this message translates to:
  /// **'حفظ حساب خزينة'**
  String get createTreasuryAccount;

  /// No description provided for @creditNoteFromInvoice.
  ///
  /// In ar, this message translates to:
  /// **'إشعار دائن / مدين'**
  String get creditNoteFromInvoice;

  /// No description provided for @zeroRated.
  ///
  /// In ar, this message translates to:
  /// **'صفرية'**
  String get zeroRated;

  /// No description provided for @exempt.
  ///
  /// In ar, this message translates to:
  /// **'معفاة'**
  String get exempt;

  /// No description provided for @outOfScope.
  ///
  /// In ar, this message translates to:
  /// **'خارج النطاق'**
  String get outOfScope;

  /// No description provided for @fieldName.
  ///
  /// In ar, this message translates to:
  /// **'الاسم'**
  String get fieldName;

  /// No description provided for @walkInName.
  ///
  /// In ar, this message translates to:
  /// **'اسم العميل العابر'**
  String get walkInName;

  /// No description provided for @addSchedule.
  ///
  /// In ar, this message translates to:
  /// **'إضافة جدول فوترة'**
  String get addSchedule;

  /// No description provided for @editPurchase.
  ///
  /// In ar, this message translates to:
  /// **'تعديل فاتورة الشراء'**
  String get editPurchase;

  /// No description provided for @supplierDetail.
  ///
  /// In ar, this message translates to:
  /// **'المورد'**
  String get supplierDetail;

  /// No description provided for @invoiceFooter.
  ///
  /// In ar, this message translates to:
  /// **'تذييل PDF'**
  String get invoiceFooter;

  /// No description provided for @invoiceColor.
  ///
  /// In ar, this message translates to:
  /// **'لون الفاتورة'**
  String get invoiceColor;

  /// No description provided for @allowManualNumbers.
  ///
  /// In ar, this message translates to:
  /// **'السماح بأرقام فواتير يدوية'**
  String get allowManualNumbers;

  /// No description provided for @countryCode.
  ///
  /// In ar, this message translates to:
  /// **'رمز الدولة'**
  String get countryCode;

  /// No description provided for @methodCash.
  ///
  /// In ar, this message translates to:
  /// **'نقداً'**
  String get methodCash;

  /// No description provided for @methodBank.
  ///
  /// In ar, this message translates to:
  /// **'تحويل بنكي'**
  String get methodBank;

  /// No description provided for @methodCard.
  ///
  /// In ar, this message translates to:
  /// **'بطاقة'**
  String get methodCard;

  /// No description provided for @methodOther.
  ///
  /// In ar, this message translates to:
  /// **'أخرى'**
  String get methodOther;

  /// No description provided for @methodCredit.
  ///
  /// In ar, this message translates to:
  /// **'آجل'**
  String get methodCredit;

  /// No description provided for @openPeriod.
  ///
  /// In ar, this message translates to:
  /// **'فتح الفترة'**
  String get openPeriod;

  /// No description provided for @closePeriod.
  ///
  /// In ar, this message translates to:
  /// **'إغلاق الفترة'**
  String get closePeriod;

  /// No description provided for @invoiceNumber.
  ///
  /// In ar, this message translates to:
  /// **'رقم الفاتورة'**
  String get invoiceNumber;

  /// No description provided for @pauseSchedule.
  ///
  /// In ar, this message translates to:
  /// **'إيقاف الجدول'**
  String get pauseSchedule;

  /// No description provided for @activateSchedule.
  ///
  /// In ar, this message translates to:
  /// **'تفعيل الجدول'**
  String get activateSchedule;

  /// No description provided for @cancelSchedule.
  ///
  /// In ar, this message translates to:
  /// **'إلغاء الجدول'**
  String get cancelSchedule;

  /// No description provided for @deleteDraft.
  ///
  /// In ar, this message translates to:
  /// **'حذف المسودة'**
  String get deleteDraft;

  /// No description provided for @applyFilters.
  ///
  /// In ar, this message translates to:
  /// **'تطبيق التصفية'**
  String get applyFilters;

  /// No description provided for @resetFilters.
  ///
  /// In ar, this message translates to:
  /// **'إعادة تعيين التصفية'**
  String get resetFilters;

  /// No description provided for @decisionPeriod.
  ///
  /// In ar, this message translates to:
  /// **'فترة القرار'**
  String get decisionPeriod;

  /// No description provided for @topCustomers.
  ///
  /// In ar, this message translates to:
  /// **'أعلى العملاء'**
  String get topCustomers;

  /// No description provided for @attentionItems.
  ///
  /// In ar, this message translates to:
  /// **'ما يحتاج انتباهاً'**
  String get attentionItems;

  /// No description provided for @lifecycleDraft.
  ///
  /// In ar, this message translates to:
  /// **'مسودة'**
  String get lifecycleDraft;

  /// No description provided for @lifecycleSent.
  ///
  /// In ar, this message translates to:
  /// **'صادرة غير مدفوعة'**
  String get lifecycleSent;

  /// No description provided for @comparePrevious.
  ///
  /// In ar, this message translates to:
  /// **'مقارنة بالفترة السابقة'**
  String get comparePrevious;

  /// No description provided for @paymentMethod.
  ///
  /// In ar, this message translates to:
  /// **'طريقة الدفع'**
  String get paymentMethod;

  /// No description provided for @companyLogo.
  ///
  /// In ar, this message translates to:
  /// **'شعار المنشأة'**
  String get companyLogo;

  /// No description provided for @chooseLogo.
  ///
  /// In ar, this message translates to:
  /// **'اختيار الشعار'**
  String get chooseLogo;

  /// No description provided for @replaceLogo.
  ///
  /// In ar, this message translates to:
  /// **'استبدال الشعار'**
  String get replaceLogo;

  /// No description provided for @removeLogo.
  ///
  /// In ar, this message translates to:
  /// **'حذف الشعار'**
  String get removeLogo;

  /// No description provided for @bankStatements.
  ///
  /// In ar, this message translates to:
  /// **'كشوف البنك'**
  String get bankStatements;

  /// No description provided for @addStatement.
  ///
  /// In ar, this message translates to:
  /// **'كشف جديد'**
  String get addStatement;

  /// No description provided for @addStatementLines.
  ///
  /// In ar, this message translates to:
  /// **'إضافة حركات'**
  String get addStatementLines;

  /// No description provided for @suggestMatches.
  ///
  /// In ar, this message translates to:
  /// **'اقتراح مطابقة'**
  String get suggestMatches;

  /// No description provided for @acceptSuggestion.
  ///
  /// In ar, this message translates to:
  /// **'قبول الاقتراح'**
  String get acceptSuggestion;

  /// No description provided for @ignoreLine.
  ///
  /// In ar, this message translates to:
  /// **'تجاهل الحركة'**
  String get ignoreLine;

  /// No description provided for @completeReconciliation.
  ///
  /// In ar, this message translates to:
  /// **'إكمال التسوية'**
  String get completeReconciliation;

  /// No description provided for @statementDate.
  ///
  /// In ar, this message translates to:
  /// **'تاريخ الكشف'**
  String get statementDate;

  /// No description provided for @monthlyCashFlow.
  ///
  /// In ar, this message translates to:
  /// **'التدفق النقدي الشهري'**
  String get monthlyCashFlow;

  /// No description provided for @journalEntries.
  ///
  /// In ar, this message translates to:
  /// **'قيود اليومية'**
  String get journalEntries;

  /// No description provided for @uploading.
  ///
  /// In ar, this message translates to:
  /// **'جاري الرفع…'**
  String get uploading;

  /// No description provided for @taxRates.
  ///
  /// In ar, this message translates to:
  /// **'نسب الضريبة'**
  String get taxRates;

  /// No description provided for @isDefault.
  ///
  /// In ar, this message translates to:
  /// **'افتراضية'**
  String get isDefault;

  /// No description provided for @isActive.
  ///
  /// In ar, this message translates to:
  /// **'فعّالة'**
  String get isActive;

  /// No description provided for @linkedLedgerAccount.
  ///
  /// In ar, this message translates to:
  /// **'ربط بحساب محاسبي'**
  String get linkedLedgerAccount;

  /// No description provided for @accountType.
  ///
  /// In ar, this message translates to:
  /// **'نوع الحساب'**
  String get accountType;

  /// No description provided for @cashAccount.
  ///
  /// In ar, this message translates to:
  /// **'نقدي'**
  String get cashAccount;

  /// No description provided for @bankAccount.
  ///
  /// In ar, this message translates to:
  /// **'بنكي'**
  String get bankAccount;

  /// No description provided for @navPeople.
  ///
  /// In ar, this message translates to:
  /// **'الموظفون والمستحقات'**
  String get navPeople;

  /// No description provided for @peopleObligations.
  ///
  /// In ar, this message translates to:
  /// **'الموظفون والمستحقات'**
  String get peopleObligations;

  /// No description provided for @peopleSubtitle.
  ///
  /// In ar, this message translates to:
  /// **'التزامات الشركة المالية تجاه موظفيها — وليست موارد بشرية لمنصة حاسم.'**
  String get peopleSubtitle;

  /// No description provided for @payroll.
  ///
  /// In ar, this message translates to:
  /// **'الرواتب والمستحقات'**
  String get payroll;

  /// No description provided for @salaryAdvances.
  ///
  /// In ar, this message translates to:
  /// **'السلف'**
  String get salaryAdvances;

  /// No description provided for @allowances.
  ///
  /// In ar, this message translates to:
  /// **'البدلات'**
  String get allowances;

  /// No description provided for @bonuses.
  ///
  /// In ar, this message translates to:
  /// **'المكافآت'**
  String get bonuses;

  /// No description provided for @deductions.
  ///
  /// In ar, this message translates to:
  /// **'الخصومات'**
  String get deductions;

  /// No description provided for @searchPlaceholder.
  ///
  /// In ar, this message translates to:
  /// **'بحث في النظام...'**
  String get searchPlaceholder;

  /// No description provided for @exportReport.
  ///
  /// In ar, this message translates to:
  /// **'تصدير التقرير'**
  String get exportReport;

  /// No description provided for @salesVsExpenses.
  ///
  /// In ar, this message translates to:
  /// **'المبيعات مقابل المصروفات'**
  String get salesVsExpenses;

  /// No description provided for @salesMix.
  ///
  /// In ar, this message translates to:
  /// **'توزيع المبيعات'**
  String get salesMix;

  /// No description provided for @totalOwed.
  ///
  /// In ar, this message translates to:
  /// **'المستحق'**
  String get totalOwed;

  /// No description provided for @totalPaid.
  ///
  /// In ar, this message translates to:
  /// **'المدفوع'**
  String get totalPaid;

  /// No description provided for @remainingBalance.
  ///
  /// In ar, this message translates to:
  /// **'المتبقي'**
  String get remainingBalance;

  /// No description provided for @advanceIssued.
  ///
  /// In ar, this message translates to:
  /// **'السلفة'**
  String get advanceIssued;

  /// No description provided for @advanceSettled.
  ///
  /// In ar, this message translates to:
  /// **'المسدد'**
  String get advanceSettled;

  /// No description provided for @advanceRemaining.
  ///
  /// In ar, this message translates to:
  /// **'المتبقي من السلفة'**
  String get advanceRemaining;

  /// No description provided for @jobTitle.
  ///
  /// In ar, this message translates to:
  /// **'المسمى الوظيفي'**
  String get jobTitle;

  /// No description provided for @employeeCode.
  ///
  /// In ar, this message translates to:
  /// **'كود الموظف'**
  String get employeeCode;

  /// No description provided for @basicSalary.
  ///
  /// In ar, this message translates to:
  /// **'الراتب الأساسي'**
  String get basicSalary;

  /// No description provided for @hireDate.
  ///
  /// In ar, this message translates to:
  /// **'تاريخ التعيين'**
  String get hireDate;

  /// No description provided for @emergencyContact.
  ///
  /// In ar, this message translates to:
  /// **'جهة اتصال للطوارئ'**
  String get emergencyContact;

  /// No description provided for @addEmployee.
  ///
  /// In ar, this message translates to:
  /// **'إضافة موظف'**
  String get addEmployee;

  /// No description provided for @addPayrollRecord.
  ///
  /// In ar, this message translates to:
  /// **'حفظ سجل الاستحقاق'**
  String get addPayrollRecord;

  /// No description provided for @issueAdvance.
  ///
  /// In ar, this message translates to:
  /// **'تسجيل سلفة'**
  String get issueAdvance;

  /// No description provided for @settleAdvance.
  ///
  /// In ar, this message translates to:
  /// **'تسجيل سداد'**
  String get settleAdvance;

  /// No description provided for @periodLabel.
  ///
  /// In ar, this message translates to:
  /// **'الفترة'**
  String get periodLabel;

  /// No description provided for @thisMonth.
  ///
  /// In ar, this message translates to:
  /// **'هذا الشهر'**
  String get thisMonth;

  /// No description provided for @lastSixMonths.
  ///
  /// In ar, this message translates to:
  /// **'آخر 6 أشهر'**
  String get lastSixMonths;

  /// No description provided for @paymentHistory.
  ///
  /// In ar, this message translates to:
  /// **'سجل الدفعات'**
  String get paymentHistory;

  /// No description provided for @outstandingObligations.
  ///
  /// In ar, this message translates to:
  /// **'الالتزامات القائمة'**
  String get outstandingObligations;

  /// No description provided for @financialSummary.
  ///
  /// In ar, this message translates to:
  /// **'ملخص مالي'**
  String get financialSummary;

  /// No description provided for @companyPeopleHint.
  ///
  /// In ar, this message translates to:
  /// **'هؤلاء موظفو شركتك داخل مساحة العمل المالية، وليسوا موظفي منصة حاسم.'**
  String get companyPeopleHint;

  /// No description provided for @activeStatus.
  ///
  /// In ar, this message translates to:
  /// **'نشط'**
  String get activeStatus;

  /// No description provided for @inactiveStatus.
  ///
  /// In ar, this message translates to:
  /// **'غير نشط'**
  String get inactiveStatus;

  /// No description provided for @suspendedStatus.
  ///
  /// In ar, this message translates to:
  /// **'موقوف'**
  String get suspendedStatus;

  /// No description provided for @repay.
  ///
  /// In ar, this message translates to:
  /// **'سداد'**
  String get repay;

  /// No description provided for @methodPayrollDeduction.
  ///
  /// In ar, this message translates to:
  /// **'خصم من الراتب'**
  String get methodPayrollDeduction;

  /// No description provided for @employeeLoan.
  ///
  /// In ar, this message translates to:
  /// **'قرض موظف'**
  String get employeeLoan;

  /// No description provided for @salaryAdvanceType.
  ///
  /// In ar, this message translates to:
  /// **'سلفة راتب'**
  String get salaryAdvanceType;

  /// No description provided for @payrollPaid.
  ///
  /// In ar, this message translates to:
  /// **'رواتب مدفوعة'**
  String get payrollPaid;

  /// No description provided for @openAdvances.
  ///
  /// In ar, this message translates to:
  /// **'سلف مفتوحة'**
  String get openAdvances;

  /// No description provided for @companyEmployees.
  ///
  /// In ar, this message translates to:
  /// **'موظفو الشركة'**
  String get companyEmployees;

  /// No description provided for @dashboardSubtitle.
  ///
  /// In ar, this message translates to:
  /// **'نظرة شاملة على أدائك المالي في الفترة المحددة'**
  String get dashboardSubtitle;

  /// No description provided for @address.
  ///
  /// In ar, this message translates to:
  /// **'العنوان'**
  String get address;

  /// No description provided for @periodStart.
  ///
  /// In ar, this message translates to:
  /// **'من تاريخ'**
  String get periodStart;

  /// No description provided for @periodEnd.
  ///
  /// In ar, this message translates to:
  /// **'إلى تاريخ'**
  String get periodEnd;

  /// No description provided for @allowancesTotal.
  ///
  /// In ar, this message translates to:
  /// **'البدلات'**
  String get allowancesTotal;

  /// No description provided for @deductionsTotal.
  ///
  /// In ar, this message translates to:
  /// **'الخصومات'**
  String get deductionsTotal;

  /// No description provided for @grossAmount.
  ///
  /// In ar, this message translates to:
  /// **'الإجمالي'**
  String get grossAmount;

  /// No description provided for @netAmount.
  ///
  /// In ar, this message translates to:
  /// **'الصافي'**
  String get netAmount;

  /// No description provided for @effectiveDate.
  ///
  /// In ar, this message translates to:
  /// **'تاريخ السريان'**
  String get effectiveDate;

  /// No description provided for @issuedAt.
  ///
  /// In ar, this message translates to:
  /// **'تاريخ الإصدار'**
  String get issuedAt;

  /// No description provided for @remainingAmount.
  ///
  /// In ar, this message translates to:
  /// **'المتبقي'**
  String get remainingAmount;

  /// No description provided for @settledAmount.
  ///
  /// In ar, this message translates to:
  /// **'المسدد'**
  String get settledAmount;

  /// No description provided for @addAdjustment.
  ///
  /// In ar, this message translates to:
  /// **'حفظ الحركة'**
  String get addAdjustment;

  /// No description provided for @postAdjustment.
  ///
  /// In ar, this message translates to:
  /// **'ترحيل محاسبي'**
  String get postAdjustment;

  /// No description provided for @cancelAdjustment.
  ///
  /// In ar, this message translates to:
  /// **'إلغاء الحركة'**
  String get cancelAdjustment;

  /// No description provided for @selectEmployee.
  ///
  /// In ar, this message translates to:
  /// **'اختر الموظف'**
  String get selectEmployee;

  /// No description provided for @showAll.
  ///
  /// In ar, this message translates to:
  /// **'عرض الكل'**
  String get showAll;

  /// No description provided for @peopleCount.
  ///
  /// In ar, this message translates to:
  /// **'عدد الموظفين'**
  String get peopleCount;

  /// No description provided for @taxInvoice.
  ///
  /// In ar, this message translates to:
  /// **'فاتورة ضريبية'**
  String get taxInvoice;

  /// No description provided for @invoiceItems.
  ///
  /// In ar, this message translates to:
  /// **'بنود الفاتورة'**
  String get invoiceItems;

  /// No description provided for @customerInfo.
  ///
  /// In ar, this message translates to:
  /// **'معلومات العميل'**
  String get customerInfo;

  /// No description provided for @invoiceSummary.
  ///
  /// In ar, this message translates to:
  /// **'ملخص الفاتورة'**
  String get invoiceSummary;

  /// No description provided for @notesAndTerms.
  ///
  /// In ar, this message translates to:
  /// **'الملاحظات والشروط'**
  String get notesAndTerms;

  /// No description provided for @attachmentsTitle.
  ///
  /// In ar, this message translates to:
  /// **'المرفقات'**
  String get attachmentsTitle;

  /// No description provided for @downloadPdf.
  ///
  /// In ar, this message translates to:
  /// **'تحميل PDF'**
  String get downloadPdf;

  /// No description provided for @printDocument.
  ///
  /// In ar, this message translates to:
  /// **'طباعة'**
  String get printDocument;

  /// No description provided for @goBack.
  ///
  /// In ar, this message translates to:
  /// **'رجوع'**
  String get goBack;

  /// No description provided for @invoiceGrandTotal.
  ///
  /// In ar, this message translates to:
  /// **'الإجمالي النهائي'**
  String get invoiceGrandTotal;

  /// No description provided for @invoiceTotalAmount.
  ///
  /// In ar, this message translates to:
  /// **'إجمالي الفاتورة'**
  String get invoiceTotalAmount;

  /// No description provided for @noAttachments.
  ///
  /// In ar, this message translates to:
  /// **'لا توجد مرفقات'**
  String get noAttachments;

  /// No description provided for @uploadInvoiceAttachmentsHint.
  ///
  /// In ar, this message translates to:
  /// **'يمكن رفع المرفقات المتعلقة بالفاتورة هنا.'**
  String get uploadInvoiceAttachmentsHint;

  /// No description provided for @noNotes.
  ///
  /// In ar, this message translates to:
  /// **'لا توجد ملاحظات.'**
  String get noNotes;

  /// No description provided for @noTerms.
  ///
  /// In ar, this message translates to:
  /// **'لم تُعلَم الفاتورة بشروط.'**
  String get noTerms;

  /// No description provided for @termsAndConditions.
  ///
  /// In ar, this message translates to:
  /// **'الشروط والأحكام'**
  String get termsAndConditions;

  /// No description provided for @auditEvent.
  ///
  /// In ar, this message translates to:
  /// **'الحدث'**
  String get auditEvent;

  /// No description provided for @auditDescription.
  ///
  /// In ar, this message translates to:
  /// **'الوصف'**
  String get auditDescription;

  /// No description provided for @auditUser.
  ///
  /// In ar, this message translates to:
  /// **'المستخدم'**
  String get auditUser;

  /// No description provided for @auditDate.
  ///
  /// In ar, this message translates to:
  /// **'التاريخ'**
  String get auditDate;

  /// No description provided for @invoiceCreatedEvent.
  ///
  /// In ar, this message translates to:
  /// **'تم إنشاء الفاتورة'**
  String get invoiceCreatedEvent;

  /// No description provided for @invoiceIssuedEvent.
  ///
  /// In ar, this message translates to:
  /// **'تم إصدار الفاتورة'**
  String get invoiceIssuedEvent;

  /// No description provided for @invoiceCancelledEvent.
  ///
  /// In ar, this message translates to:
  /// **'تم إلغاء الفاتورة'**
  String get invoiceCancelledEvent;

  /// No description provided for @invoiceSentEvent.
  ///
  /// In ar, this message translates to:
  /// **'تم إرسال الفاتورة'**
  String get invoiceSentEvent;

  /// No description provided for @invoiceUpdatedEvent.
  ///
  /// In ar, this message translates to:
  /// **'تم تحديث الفاتورة'**
  String get invoiceUpdatedEvent;

  /// No description provided for @invoiceReminderSentEvent.
  ///
  /// In ar, this message translates to:
  /// **'تم إرسال تذكير بالفاتورة'**
  String get invoiceReminderSentEvent;

  /// No description provided for @tableTotal.
  ///
  /// In ar, this message translates to:
  /// **'المجموع'**
  String get tableTotal;

  /// No description provided for @relatedDocuments.
  ///
  /// In ar, this message translates to:
  /// **'المستندات المرتبطة'**
  String get relatedDocuments;

  /// No description provided for @zatcaInfo.
  ///
  /// In ar, this message translates to:
  /// **'الفوترة الإلكترونية'**
  String get zatcaInfo;

  /// No description provided for @telephone.
  ///
  /// In ar, this message translates to:
  /// **'الهاتف'**
  String get telephone;

  /// No description provided for @lineNumber.
  ///
  /// In ar, this message translates to:
  /// **'#'**
  String get lineNumber;

  /// No description provided for @paidInFull.
  ///
  /// In ar, this message translates to:
  /// **'مدفوعة'**
  String get paidInFull;

  /// No description provided for @draftStatus.
  ///
  /// In ar, this message translates to:
  /// **'مسودة'**
  String get draftStatus;

  /// No description provided for @supplyDate.
  ///
  /// In ar, this message translates to:
  /// **'تاريخ التوريد'**
  String get supplyDate;

  /// No description provided for @issueImmediately.
  ///
  /// In ar, this message translates to:
  /// **'إصدار الفاتورة مباشرة عند الحفظ'**
  String get issueImmediately;

  /// No description provided for @downloadXml.
  ///
  /// In ar, this message translates to:
  /// **'تحميل XML'**
  String get downloadXml;

  /// No description provided for @viewQr.
  ///
  /// In ar, this message translates to:
  /// **'عرض رمز QR'**
  String get viewQr;

  /// No description provided for @zatcaFoundation.
  ///
  /// In ar, this message translates to:
  /// **'أساس داخلي للفوترة الإلكترونية. لا يوجد تخليص FATOORA ولا إبلاغ إنتاج.'**
  String get zatcaFoundation;

  /// No description provided for @xmlAvailable.
  ///
  /// In ar, this message translates to:
  /// **'XML متوفر'**
  String get xmlAvailable;

  /// No description provided for @qrAvailable.
  ///
  /// In ar, this message translates to:
  /// **'رمز QR متوفر'**
  String get qrAvailable;

  /// No description provided for @xmlUnavailable.
  ///
  /// In ar, this message translates to:
  /// **'XML غير متوفر'**
  String get xmlUnavailable;

  /// No description provided for @qrUnavailable.
  ///
  /// In ar, this message translates to:
  /// **'رمز QR غير متوفر'**
  String get qrUnavailable;

  /// No description provided for @sortBy.
  ///
  /// In ar, this message translates to:
  /// **'الترتيب'**
  String get sortBy;

  /// No description provided for @invoiceStatusFilter.
  ///
  /// In ar, this message translates to:
  /// **'حالة الفاتورة'**
  String get invoiceStatusFilter;

  /// No description provided for @recurringFrequency.
  ///
  /// In ar, this message translates to:
  /// **'دورية التكرار'**
  String get recurringFrequency;

  /// No description provided for @nextDueDate.
  ///
  /// In ar, this message translates to:
  /// **'تاريخ الاستحقاق التالي'**
  String get nextDueDate;

  /// No description provided for @pickAttachments.
  ///
  /// In ar, this message translates to:
  /// **'اختيار مرفقات'**
  String get pickAttachments;

  /// No description provided for @frequencyMonthly.
  ///
  /// In ar, this message translates to:
  /// **'شهري'**
  String get frequencyMonthly;

  /// No description provided for @frequencyWeekly.
  ///
  /// In ar, this message translates to:
  /// **'أسبوعي'**
  String get frequencyWeekly;

  /// No description provided for @frequencyQuarterly.
  ///
  /// In ar, this message translates to:
  /// **'ربع سنوي'**
  String get frequencyQuarterly;

  /// No description provided for @frequencyYearly.
  ///
  /// In ar, this message translates to:
  /// **'سنوي'**
  String get frequencyYearly;

  /// No description provided for @sortNewest.
  ///
  /// In ar, this message translates to:
  /// **'الأحدث'**
  String get sortNewest;

  /// No description provided for @sortDirection.
  ///
  /// In ar, this message translates to:
  /// **'اتجاه الترتيب'**
  String get sortDirection;

  /// No description provided for @sortAscending.
  ///
  /// In ar, this message translates to:
  /// **'تصاعدي'**
  String get sortAscending;

  /// No description provided for @sortDescending.
  ///
  /// In ar, this message translates to:
  /// **'تنازلي'**
  String get sortDescending;

  /// No description provided for @statusPosted.
  ///
  /// In ar, this message translates to:
  /// **'مرحّل'**
  String get statusPosted;

  /// No description provided for @statusReversed.
  ///
  /// In ar, this message translates to:
  /// **'معكوس'**
  String get statusReversed;

  /// No description provided for @statusVoided.
  ///
  /// In ar, this message translates to:
  /// **'ملغى'**
  String get statusVoided;

  /// No description provided for @noInvoicesYet.
  ///
  /// In ar, this message translates to:
  /// **'لا توجد فواتير بعد'**
  String get noInvoicesYet;

  /// No description provided for @noInvoicesSubtitle.
  ///
  /// In ar, this message translates to:
  /// **'أنشئ أول فاتورة أو عدّل معايير التصفية لعرض النتائج.'**
  String get noInvoicesSubtitle;

  /// No description provided for @noResultsForFilters.
  ///
  /// In ar, this message translates to:
  /// **'لا توجد نتائج مطابقة لمعايير التصفية الحالية.'**
  String get noResultsForFilters;

  /// No description provided for @advancedFilters.
  ///
  /// In ar, this message translates to:
  /// **'تصفية متقدمة'**
  String get advancedFilters;

  /// No description provided for @newInvoice.
  ///
  /// In ar, this message translates to:
  /// **'فاتورة جديدة'**
  String get newInvoice;

  /// No description provided for @statusOpen.
  ///
  /// In ar, this message translates to:
  /// **'مفتوح'**
  String get statusOpen;

  /// No description provided for @statusClosed.
  ///
  /// In ar, this message translates to:
  /// **'مغلق'**
  String get statusClosed;

  /// No description provided for @statusApproved.
  ///
  /// In ar, this message translates to:
  /// **'معتمد'**
  String get statusApproved;

  /// No description provided for @expenseLockedHint.
  ///
  /// In ar, this message translates to:
  /// **'لا يمكن تعديل مصروف مرحّل من التطبيق.'**
  String get expenseLockedHint;
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  Future<AppLocalizations> load(Locale locale) {
    return SynchronousFuture<AppLocalizations>(lookupAppLocalizations(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['ar', 'en'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

AppLocalizations lookupAppLocalizations(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'ar':
      return AppLocalizationsAr();
    case 'en':
      return AppLocalizationsEn();
  }

  throw FlutterError(
    'AppLocalizations.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
