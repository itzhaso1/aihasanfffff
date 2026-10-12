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
  /// **'حاسم'**
  String get appName;

  /// No description provided for @login.
  ///
  /// In ar, this message translates to:
  /// **'دخول'**
  String get login;

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

  /// No description provided for @forgotPassword.
  ///
  /// In ar, this message translates to:
  /// **'نسيت كلمة المرور؟'**
  String get forgotPassword;

  /// No description provided for @signInWithGoogle.
  ///
  /// In ar, this message translates to:
  /// **'الدخول عبر Google'**
  String get signInWithGoogle;

  /// No description provided for @googleNeedsSetup.
  ///
  /// In ar, this message translates to:
  /// **'يحتاج إعداد Google'**
  String get googleNeedsSetup;

  /// No description provided for @home.
  ///
  /// In ar, this message translates to:
  /// **'الرئيسية'**
  String get home;

  /// No description provided for @conversations.
  ///
  /// In ar, this message translates to:
  /// **'المحادثات'**
  String get conversations;

  /// No description provided for @email.
  ///
  /// In ar, this message translates to:
  /// **'البريد'**
  String get email;

  /// No description provided for @more.
  ///
  /// In ar, this message translates to:
  /// **'المزيد'**
  String get more;

  /// No description provided for @settings.
  ///
  /// In ar, this message translates to:
  /// **'الإعدادات'**
  String get settings;

  /// No description provided for @profile.
  ///
  /// In ar, this message translates to:
  /// **'الملف الشخصي'**
  String get profile;

  /// No description provided for @plans.
  ///
  /// In ar, this message translates to:
  /// **'الباقات'**
  String get plans;

  /// No description provided for @channels.
  ///
  /// In ar, this message translates to:
  /// **'القنوات'**
  String get channels;

  /// No description provided for @theme.
  ///
  /// In ar, this message translates to:
  /// **'المظهر'**
  String get theme;

  /// No description provided for @themeLight.
  ///
  /// In ar, this message translates to:
  /// **'فاتح'**
  String get themeLight;

  /// No description provided for @themeDark.
  ///
  /// In ar, this message translates to:
  /// **'داكن'**
  String get themeDark;

  /// No description provided for @themeSystem.
  ///
  /// In ar, this message translates to:
  /// **'تلقائي'**
  String get themeSystem;

  /// No description provided for @logout.
  ///
  /// In ar, this message translates to:
  /// **'تسجيل الخروج'**
  String get logout;

  /// No description provided for @retry.
  ///
  /// In ar, this message translates to:
  /// **'إعادة المحاولة'**
  String get retry;

  /// No description provided for @required.
  ///
  /// In ar, this message translates to:
  /// **'مطلوب'**
  String get required;

  /// No description provided for @loading.
  ///
  /// In ar, this message translates to:
  /// **'جارٍ التحميل...'**
  String get loading;

  /// No description provided for @greetingMorning.
  ///
  /// In ar, this message translates to:
  /// **'صباح الخير'**
  String get greetingMorning;

  /// No description provided for @greetingEvening.
  ///
  /// In ar, this message translates to:
  /// **'مساء الخير'**
  String get greetingEvening;

  /// No description provided for @greetingHello.
  ///
  /// In ar, this message translates to:
  /// **'مرحباً'**
  String get greetingHello;

  /// No description provided for @unreadConversations.
  ///
  /// In ar, this message translates to:
  /// **'محادثات غير مقروءة'**
  String get unreadConversations;

  /// No description provided for @unreadEmail.
  ///
  /// In ar, this message translates to:
  /// **'بريد جديد'**
  String get unreadEmail;

  /// No description provided for @notifications.
  ///
  /// In ar, this message translates to:
  /// **'إشعارات'**
  String get notifications;

  /// No description provided for @quickActions.
  ///
  /// In ar, this message translates to:
  /// **'إجراءات سريعة'**
  String get quickActions;

  /// No description provided for @recentConversations.
  ///
  /// In ar, this message translates to:
  /// **'أحدث المحادثات'**
  String get recentConversations;

  /// No description provided for @emptyTitle.
  ///
  /// In ar, this message translates to:
  /// **'لا توجد بيانات'**
  String get emptyTitle;

  /// No description provided for @sendResetLink.
  ///
  /// In ar, this message translates to:
  /// **'إرسال رابط إعادة التعيين'**
  String get sendResetLink;

  /// No description provided for @resetPassword.
  ///
  /// In ar, this message translates to:
  /// **'إعادة تعيين كلمة المرور'**
  String get resetPassword;

  /// No description provided for @newPassword.
  ///
  /// In ar, this message translates to:
  /// **'كلمة المرور الجديدة'**
  String get newPassword;

  /// No description provided for @confirmPassword.
  ///
  /// In ar, this message translates to:
  /// **'تأكيد كلمة المرور'**
  String get confirmPassword;

  /// No description provided for @resetToken.
  ///
  /// In ar, this message translates to:
  /// **'رمز إعادة التعيين'**
  String get resetToken;

  /// No description provided for @save.
  ///
  /// In ar, this message translates to:
  /// **'حفظ'**
  String get save;

  /// No description provided for @changePassword.
  ///
  /// In ar, this message translates to:
  /// **'تغيير كلمة المرور'**
  String get changePassword;

  /// No description provided for @currentPassword.
  ///
  /// In ar, this message translates to:
  /// **'كلمة المرور الحالية'**
  String get currentPassword;

  /// No description provided for @uploadAvatar.
  ///
  /// In ar, this message translates to:
  /// **'تغيير الصورة'**
  String get uploadAvatar;

  /// No description provided for @connected.
  ///
  /// In ar, this message translates to:
  /// **'متصل'**
  String get connected;

  /// No description provided for @disconnected.
  ///
  /// In ar, this message translates to:
  /// **'غير متصل'**
  String get disconnected;

  /// No description provided for @manageChannel.
  ///
  /// In ar, this message translates to:
  /// **'إدارة'**
  String get manageChannel;

  /// No description provided for @suggestReply.
  ///
  /// In ar, this message translates to:
  /// **'اقتراح رد'**
  String get suggestReply;

  /// No description provided for @summarize.
  ///
  /// In ar, this message translates to:
  /// **'تلخيص'**
  String get summarize;

  /// No description provided for @reply.
  ///
  /// In ar, this message translates to:
  /// **'رد'**
  String get reply;

  /// No description provided for @selectAccount.
  ///
  /// In ar, this message translates to:
  /// **'حساب الإرسال'**
  String get selectAccount;

  /// No description provided for @workspacePicker.
  ///
  /// In ar, this message translates to:
  /// **'اختر مساحة العمل'**
  String get workspacePicker;

  /// No description provided for @search.
  ///
  /// In ar, this message translates to:
  /// **'بحث'**
  String get search;

  /// No description provided for @customerProfile.
  ///
  /// In ar, this message translates to:
  /// **'ملف العميل'**
  String get customerProfile;

  /// No description provided for @notificationPrefs.
  ///
  /// In ar, this message translates to:
  /// **'تفضيلات الإشعارات'**
  String get notificationPrefs;

  /// No description provided for @usage.
  ///
  /// In ar, this message translates to:
  /// **'الاستخدام'**
  String get usage;

  /// No description provided for @settingsSaved.
  ///
  /// In ar, this message translates to:
  /// **'تم حفظ الإعدادات'**
  String get settingsSaved;

  /// No description provided for @aiSettingsTitle.
  ///
  /// In ar, this message translates to:
  /// **'إعدادات الذكاء الاصطناعي'**
  String get aiSettingsTitle;

  /// No description provided for @aiFieldAssistantName.
  ///
  /// In ar, this message translates to:
  /// **'اسم المساعد'**
  String get aiFieldAssistantName;

  /// No description provided for @aiFieldAssistantNamePlaceholder.
  ///
  /// In ar, this message translates to:
  /// **'ساره'**
  String get aiFieldAssistantNamePlaceholder;

  /// No description provided for @aiFieldAssistantNameHint.
  ///
  /// In ar, this message translates to:
  /// **'الاسم الذي سيعرّف به المساعد نفسه للعملاء.'**
  String get aiFieldAssistantNameHint;

  /// No description provided for @aiFieldAssistantNameInvalid.
  ///
  /// In ar, this message translates to:
  /// **'اسم المساعد غير صالح. استخدم حروفًا ومسافات فقط دون رموز أو وسوم.'**
  String get aiFieldAssistantNameInvalid;

  /// No description provided for @aiFieldInstructions.
  ///
  /// In ar, this message translates to:
  /// **'التعليمات'**
  String get aiFieldInstructions;

  /// No description provided for @aiFieldTone.
  ///
  /// In ar, this message translates to:
  /// **'النبرة'**
  String get aiFieldTone;

  /// No description provided for @aiFieldActive.
  ///
  /// In ar, this message translates to:
  /// **'تفعيل المساعد'**
  String get aiFieldActive;

  /// No description provided for @notificationPreferencesTitle.
  ///
  /// In ar, this message translates to:
  /// **'تفضيلات الإشعارات'**
  String get notificationPreferencesTitle;

  /// No description provided for @notificationPrefMessages.
  ///
  /// In ar, this message translates to:
  /// **'رسائل المحادثات'**
  String get notificationPrefMessages;

  /// No description provided for @notificationPrefEmail.
  ///
  /// In ar, this message translates to:
  /// **'البريد'**
  String get notificationPrefEmail;

  /// No description provided for @notificationPrefMarketing.
  ///
  /// In ar, this message translates to:
  /// **'التسويق'**
  String get notificationPrefMarketing;

  /// No description provided for @aiTabGeneral.
  ///
  /// In ar, this message translates to:
  /// **'عام'**
  String get aiTabGeneral;

  /// No description provided for @aiTabPersona.
  ///
  /// In ar, this message translates to:
  /// **'شخصية المساعد'**
  String get aiTabPersona;

  /// No description provided for @aiSettingsUnavailable.
  ///
  /// In ar, this message translates to:
  /// **'إعدادات الذكاء الاصطناعي غير متاحة.'**
  String get aiSettingsUnavailable;

  /// No description provided for @aiFieldRole.
  ///
  /// In ar, this message translates to:
  /// **'دور المساعد'**
  String get aiFieldRole;

  /// No description provided for @aiFieldProvider.
  ///
  /// In ar, this message translates to:
  /// **'المزود'**
  String get aiFieldProvider;

  /// No description provided for @aiFieldModel.
  ///
  /// In ar, this message translates to:
  /// **'النموذج'**
  String get aiFieldModel;

  /// No description provided for @aiPersonaName.
  ///
  /// In ar, this message translates to:
  /// **'اسم الشخصية'**
  String get aiPersonaName;

  /// No description provided for @aiPersonaRole.
  ///
  /// In ar, this message translates to:
  /// **'دور الشخصية'**
  String get aiPersonaRole;

  /// No description provided for @aiPersonaTraits.
  ///
  /// In ar, this message translates to:
  /// **'السمات'**
  String get aiPersonaTraits;

  /// No description provided for @aiPersonaLanguage.
  ///
  /// In ar, this message translates to:
  /// **'اللغة'**
  String get aiPersonaLanguage;

  /// No description provided for @aiPersonaDialect.
  ///
  /// In ar, this message translates to:
  /// **'اللهجة'**
  String get aiPersonaDialect;

  /// No description provided for @aiPersonaFormality.
  ///
  /// In ar, this message translates to:
  /// **'الرسمية'**
  String get aiPersonaFormality;

  /// No description provided for @aiPersonaWarmth.
  ///
  /// In ar, this message translates to:
  /// **'الدفء'**
  String get aiPersonaWarmth;

  /// No description provided for @aiPersonaVerbosity.
  ///
  /// In ar, this message translates to:
  /// **'التفصيل'**
  String get aiPersonaVerbosity;

  /// No description provided for @aiPersonaEmoji.
  ///
  /// In ar, this message translates to:
  /// **'استخدام الإيموجي'**
  String get aiPersonaEmoji;

  /// No description provided for @aiPersonaGreeting.
  ///
  /// In ar, this message translates to:
  /// **'أسلوب التحية'**
  String get aiPersonaGreeting;

  /// No description provided for @aiPersonaSales.
  ///
  /// In ar, this message translates to:
  /// **'أسلوب المبيعات'**
  String get aiPersonaSales;

  /// No description provided for @aiPersonaSupport.
  ///
  /// In ar, this message translates to:
  /// **'أسلوب الدعم'**
  String get aiPersonaSupport;

  /// No description provided for @aiPersonaRules.
  ///
  /// In ar, this message translates to:
  /// **'قواعد السلوك (سطر لكل قاعدة)'**
  String get aiPersonaRules;

  /// No description provided for @channelNoManageUrl.
  ///
  /// In ar, this message translates to:
  /// **'لا يوجد رابط إدارة لهذه القناة.'**
  String get channelNoManageUrl;

  /// No description provided for @channelOpenFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر فتح رابط الإدارة.'**
  String get channelOpenFailed;

  /// No description provided for @archiveConversation.
  ///
  /// In ar, this message translates to:
  /// **'أرشفة'**
  String get archiveConversation;

  /// No description provided for @unarchiveConversation.
  ///
  /// In ar, this message translates to:
  /// **'إلغاء الأرشفة'**
  String get unarchiveConversation;

  /// No description provided for @muteConversation.
  ///
  /// In ar, this message translates to:
  /// **'كتم'**
  String get muteConversation;

  /// No description provided for @unmuteConversation.
  ///
  /// In ar, this message translates to:
  /// **'إلغاء الكتم'**
  String get unmuteConversation;

  /// No description provided for @archiveFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر أرشفة المحادثة.'**
  String get archiveFailed;

  /// No description provided for @unarchiveFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر إلغاء أرشفة المحادثة.'**
  String get unarchiveFailed;

  /// No description provided for @muteFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر كتم المحادثة.'**
  String get muteFailed;

  /// No description provided for @unmuteFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر إلغاء كتم المحادثة.'**
  String get unmuteFailed;

  /// No description provided for @conversationActions.
  ///
  /// In ar, this message translates to:
  /// **'إجراءات المحادثة'**
  String get conversationActions;

  /// No description provided for @chatThemes.
  ///
  /// In ar, this message translates to:
  /// **'السمات'**
  String get chatThemes;

  /// No description provided for @chatThemesHint.
  ///
  /// In ar, this message translates to:
  /// **'اختر صورة أو خلفية لشاشة المحادثة'**
  String get chatThemesHint;

  /// No description provided for @chatThemeDefault.
  ///
  /// In ar, this message translates to:
  /// **'الافتراضية'**
  String get chatThemeDefault;

  /// No description provided for @chatAttachmentPhoto.
  ///
  /// In ar, this message translates to:
  /// **'صورة'**
  String get chatAttachmentPhoto;

  /// No description provided for @chatAttachmentCamera.
  ///
  /// In ar, this message translates to:
  /// **'الكاميرا'**
  String get chatAttachmentCamera;

  /// No description provided for @chatAttachmentVideo.
  ///
  /// In ar, this message translates to:
  /// **'فيديو'**
  String get chatAttachmentVideo;

  /// No description provided for @chatAttachmentAudio.
  ///
  /// In ar, this message translates to:
  /// **'صوت'**
  String get chatAttachmentAudio;

  /// No description provided for @chatAttachmentFile.
  ///
  /// In ar, this message translates to:
  /// **'ملف'**
  String get chatAttachmentFile;

  /// No description provided for @chatAttachmentUploading.
  ///
  /// In ar, this message translates to:
  /// **'جاري الرفع…'**
  String get chatAttachmentUploading;

  /// No description provided for @chatAttachmentSending.
  ///
  /// In ar, this message translates to:
  /// **'جاري الإرسال…'**
  String get chatAttachmentSending;

  /// No description provided for @chatAttachmentUploadFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر رفع المرفق.'**
  String get chatAttachmentUploadFailed;

  /// No description provided for @chatAttachmentUnsupported.
  ///
  /// In ar, this message translates to:
  /// **'نوع الملف غير مدعوم.'**
  String get chatAttachmentUnsupported;

  /// No description provided for @chatAttachmentTooLarge.
  ///
  /// In ar, this message translates to:
  /// **'حجم الملف يتجاوز 10 ميجابايت.'**
  String get chatAttachmentTooLarge;

  /// No description provided for @chatAttachmentOffline.
  ///
  /// In ar, this message translates to:
  /// **'المرفقات تتطلب اتصالاً بالإنترنت.'**
  String get chatAttachmentOffline;

  /// No description provided for @chatAttachmentMissing.
  ///
  /// In ar, this message translates to:
  /// **'الملف لم يعد متوفراً على الجهاز.'**
  String get chatAttachmentMissing;

  /// No description provided for @chatAttachmentRetry.
  ///
  /// In ar, this message translates to:
  /// **'إعادة المحاولة'**
  String get chatAttachmentRetry;

  /// No description provided for @chatAttachmentOpen.
  ///
  /// In ar, this message translates to:
  /// **'فتح'**
  String get chatAttachmentOpen;

  /// No description provided for @chatAttachmentOpenFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر فتح المرفق.'**
  String get chatAttachmentOpenFailed;

  /// No description provided for @chatAttachAction.
  ///
  /// In ar, this message translates to:
  /// **'إرفاق'**
  String get chatAttachAction;

  /// No description provided for @chatCatalogSettingsTitle.
  ///
  /// In ar, this message translates to:
  /// **'إعدادات المنتجات'**
  String get chatCatalogSettingsTitle;

  /// No description provided for @chatCatalogSettingsUnavailable.
  ///
  /// In ar, this message translates to:
  /// **'إعدادات المنتجات غير متاحة لهذه المساحة.'**
  String get chatCatalogSettingsUnavailable;

  /// No description provided for @chatCatalogTabGeneral.
  ///
  /// In ar, this message translates to:
  /// **'عام'**
  String get chatCatalogTabGeneral;

  /// No description provided for @chatCatalogTabChannels.
  ///
  /// In ar, this message translates to:
  /// **'القنوات والعرض'**
  String get chatCatalogTabChannels;

  /// No description provided for @chatCatalogTabCommunication.
  ///
  /// In ar, this message translates to:
  /// **'التواصل'**
  String get chatCatalogTabCommunication;

  /// No description provided for @chatCatalogStatusLabel.
  ///
  /// In ar, this message translates to:
  /// **'حالة المنتجات'**
  String get chatCatalogStatusLabel;

  /// No description provided for @chatCatalogFieldName.
  ///
  /// In ar, this message translates to:
  /// **'اسم المنتجات'**
  String get chatCatalogFieldName;

  /// No description provided for @chatCatalogFieldPublicName.
  ///
  /// In ar, this message translates to:
  /// **'الاسم العام'**
  String get chatCatalogFieldPublicName;

  /// No description provided for @chatCatalogFieldCurrency.
  ///
  /// In ar, this message translates to:
  /// **'العملة الافتراضية'**
  String get chatCatalogFieldCurrency;

  /// No description provided for @chatCatalogFieldRtl.
  ///
  /// In ar, this message translates to:
  /// **'وضع RTL'**
  String get chatCatalogFieldRtl;

  /// No description provided for @chatCatalogSectionChannels.
  ///
  /// In ar, this message translates to:
  /// **'ظهور القنوات'**
  String get chatCatalogSectionChannels;

  /// No description provided for @chatCatalogSectionDisplay.
  ///
  /// In ar, this message translates to:
  /// **'عرض المنتجات'**
  String get chatCatalogSectionDisplay;

  /// No description provided for @chatCatalogSectionDiscovery.
  ///
  /// In ar, this message translates to:
  /// **'الاكتشاف والبحث'**
  String get chatCatalogSectionDiscovery;

  /// No description provided for @chatCatalogShowStock.
  ///
  /// In ar, this message translates to:
  /// **'إظهار المخزون'**
  String get chatCatalogShowStock;

  /// No description provided for @chatCatalogShowSku.
  ///
  /// In ar, this message translates to:
  /// **'إظهار SKU'**
  String get chatCatalogShowSku;

  /// No description provided for @chatCatalogSearchable.
  ///
  /// In ar, this message translates to:
  /// **'قابل للبحث'**
  String get chatCatalogSearchable;

  /// No description provided for @chatCatalogSkuSearch.
  ///
  /// In ar, this message translates to:
  /// **'بحث SKU'**
  String get chatCatalogSkuSearch;

  /// No description provided for @chatCatalogPrimaryLanguage.
  ///
  /// In ar, this message translates to:
  /// **'اللغة الأساسية'**
  String get chatCatalogPrimaryLanguage;

  /// No description provided for @chatCatalogArabicDialect.
  ///
  /// In ar, this message translates to:
  /// **'اللهجة العربية'**
  String get chatCatalogArabicDialect;

  /// No description provided for @chatCatalogFormality.
  ///
  /// In ar, this message translates to:
  /// **'الرسمية'**
  String get chatCatalogFormality;

  /// No description provided for @chatCatalogTabAvailability.
  ///
  /// In ar, this message translates to:
  /// **'التوفر والخدمة'**
  String get chatCatalogTabAvailability;

  /// No description provided for @chatCatalogTabDiscovery.
  ///
  /// In ar, this message translates to:
  /// **'الاكتشاف والبحث'**
  String get chatCatalogTabDiscovery;

  /// No description provided for @chatCatalogFieldWebsite.
  ///
  /// In ar, this message translates to:
  /// **'الموقع العام'**
  String get chatCatalogFieldWebsite;

  /// No description provided for @chatCatalogAvailabilityMode.
  ///
  /// In ar, this message translates to:
  /// **'وضع التوفر'**
  String get chatCatalogAvailabilityMode;

  /// No description provided for @chatCatalogUnavailableBehavior.
  ///
  /// In ar, this message translates to:
  /// **'سلوك الأصناف غير المتاحة'**
  String get chatCatalogUnavailableBehavior;

  /// No description provided for @chatCatalogScheduleEnabled.
  ///
  /// In ar, this message translates to:
  /// **'تفعيل جدول العمل'**
  String get chatCatalogScheduleEnabled;

  /// No description provided for @chatCatalogClosedMessage.
  ///
  /// In ar, this message translates to:
  /// **'رسالة الإغلاق'**
  String get chatCatalogClosedMessage;

  /// No description provided for @chatCatalogServicePolicy.
  ///
  /// In ar, this message translates to:
  /// **'سياسة خدمة العملاء'**
  String get chatCatalogServicePolicy;

  /// No description provided for @chatCatalogInquiryMode.
  ///
  /// In ar, this message translates to:
  /// **'وضع الاستفسار'**
  String get chatCatalogInquiryMode;

  /// No description provided for @chatCatalogOutOfHoursMessage.
  ///
  /// In ar, this message translates to:
  /// **'رسالة خارج الدوام'**
  String get chatCatalogOutOfHoursMessage;

  /// No description provided for @chatCatalogUnavailableProductMessage.
  ///
  /// In ar, this message translates to:
  /// **'رسالة صنف غير متاح'**
  String get chatCatalogUnavailableProductMessage;

  /// No description provided for @chatCatalogSeo.
  ///
  /// In ar, this message translates to:
  /// **'تحسين محركات البحث'**
  String get chatCatalogSeo;

  /// No description provided for @chatCatalogSeoTitle.
  ///
  /// In ar, this message translates to:
  /// **'عنوان SEO'**
  String get chatCatalogSeoTitle;

  /// No description provided for @chatCatalogSeoDescription.
  ///
  /// In ar, this message translates to:
  /// **'وصف SEO'**
  String get chatCatalogSeoDescription;

  /// No description provided for @chatCatalogSeoRobots.
  ///
  /// In ar, this message translates to:
  /// **'robots'**
  String get chatCatalogSeoRobots;

  /// No description provided for @chatCatalogSearchWeights.
  ///
  /// In ar, this message translates to:
  /// **'أوزان البحث'**
  String get chatCatalogSearchWeights;

  /// No description provided for @chatCatalogCommunicationStyle.
  ///
  /// In ar, this message translates to:
  /// **'أسلوب التواصل'**
  String get chatCatalogCommunicationStyle;

  /// No description provided for @chatCatalogGreetingBehavior.
  ///
  /// In ar, this message translates to:
  /// **'سلوك التحية'**
  String get chatCatalogGreetingBehavior;

  /// No description provided for @chatCatalogEmojiPolicy.
  ///
  /// In ar, this message translates to:
  /// **'سياسة الإيموجي'**
  String get chatCatalogEmojiPolicy;

  /// No description provided for @catalogListTitle.
  ///
  /// In ar, this message translates to:
  /// **'المنتجات'**
  String get catalogListTitle;

  /// No description provided for @catalogSearchHint.
  ///
  /// In ar, this message translates to:
  /// **'بحث في المنتجات والخدمات'**
  String get catalogSearchHint;

  /// No description provided for @catalogCategoriesNav.
  ///
  /// In ar, this message translates to:
  /// **'التصنيفات'**
  String get catalogCategoriesNav;

  /// No description provided for @catalogLoadFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر تحميل المنتجات.'**
  String get catalogLoadFailed;

  /// No description provided for @catalogItemSaveFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر حفظ الصنف.'**
  String get catalogItemSaveFailed;

  /// No description provided for @catalogSaving.
  ///
  /// In ar, this message translates to:
  /// **'جارٍ الحفظ...'**
  String get catalogSaving;

  /// No description provided for @catalogItemKindProduct.
  ///
  /// In ar, this message translates to:
  /// **'منتج'**
  String get catalogItemKindProduct;

  /// No description provided for @catalogItemKindService.
  ///
  /// In ar, this message translates to:
  /// **'خدمة'**
  String get catalogItemKindService;

  /// No description provided for @catalogItemCategory.
  ///
  /// In ar, this message translates to:
  /// **'التصنيف'**
  String get catalogItemCategory;

  /// No description provided for @catalogItemNoCategory.
  ///
  /// In ar, this message translates to:
  /// **'بدون تصنيف'**
  String get catalogItemNoCategory;

  /// No description provided for @catalogCategoriesTitle.
  ///
  /// In ar, this message translates to:
  /// **'تصنيفات المنتجات'**
  String get catalogCategoriesTitle;

  /// No description provided for @catalogCategoriesEmptyTitle.
  ///
  /// In ar, this message translates to:
  /// **'لا توجد تصنيفات'**
  String get catalogCategoriesEmptyTitle;

  /// No description provided for @catalogCategoriesEmptySubtitle.
  ///
  /// In ar, this message translates to:
  /// **'أضف تصنيفاً لتنظيم المنتجات.'**
  String get catalogCategoriesEmptySubtitle;

  /// No description provided for @catalogCategoryAdd.
  ///
  /// In ar, this message translates to:
  /// **'إضافة تصنيف'**
  String get catalogCategoryAdd;

  /// No description provided for @catalogCategoryEdit.
  ///
  /// In ar, this message translates to:
  /// **'تعديل تصنيف'**
  String get catalogCategoryEdit;

  /// No description provided for @catalogCategoryName.
  ///
  /// In ar, this message translates to:
  /// **'اسم التصنيف'**
  String get catalogCategoryName;

  /// No description provided for @catalogCategoryDescription.
  ///
  /// In ar, this message translates to:
  /// **'الوصف'**
  String get catalogCategoryDescription;

  /// No description provided for @catalogCategoryParent.
  ///
  /// In ar, this message translates to:
  /// **'التصنيف الأب'**
  String get catalogCategoryParent;

  /// No description provided for @catalogCategoryNoParent.
  ///
  /// In ar, this message translates to:
  /// **'بدون تصنيف أب'**
  String get catalogCategoryNoParent;

  /// No description provided for @catalogCategorySortOrder.
  ///
  /// In ar, this message translates to:
  /// **'ترتيب العرض'**
  String get catalogCategorySortOrder;

  /// No description provided for @catalogCategoryActive.
  ///
  /// In ar, this message translates to:
  /// **'نشط'**
  String get catalogCategoryActive;

  /// No description provided for @catalogCategoryInactive.
  ///
  /// In ar, this message translates to:
  /// **'غير نشط'**
  String get catalogCategoryInactive;

  /// No description provided for @catalogCategoryDelete.
  ///
  /// In ar, this message translates to:
  /// **'حذف التصنيف'**
  String get catalogCategoryDelete;

  /// No description provided for @catalogCategoryDeleteConfirm.
  ///
  /// In ar, this message translates to:
  /// **'هل تريد حذف هذا التصنيف؟'**
  String get catalogCategoryDeleteConfirm;

  /// No description provided for @catalogCategoriesLoadFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر تحميل التصنيفات.'**
  String get catalogCategoriesLoadFailed;

  /// No description provided for @catalogCategorySaveFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر حفظ التصنيف.'**
  String get catalogCategorySaveFailed;

  /// No description provided for @catalogCategoryDeleteFailed.
  ///
  /// In ar, this message translates to:
  /// **'تعذر حذف التصنيف.'**
  String get catalogCategoryDeleteFailed;

  /// No description provided for @catalogCancel.
  ///
  /// In ar, this message translates to:
  /// **'إلغاء'**
  String get catalogCancel;

  /// No description provided for @catalogInventoryPolicy.
  ///
  /// In ar, this message translates to:
  /// **'طريقة التوفر'**
  String get catalogInventoryPolicy;

  /// No description provided for @catalogStockQuantity.
  ///
  /// In ar, this message translates to:
  /// **'الكمية المتاحة'**
  String get catalogStockQuantity;

  /// No description provided for @catalogSku.
  ///
  /// In ar, this message translates to:
  /// **'SKU'**
  String get catalogSku;

  /// No description provided for @catalogPrice.
  ///
  /// In ar, this message translates to:
  /// **'السعر'**
  String get catalogPrice;

  /// No description provided for @catalogVariantsTitle.
  ///
  /// In ar, this message translates to:
  /// **'النسخ والخيارات'**
  String get catalogVariantsTitle;

  /// No description provided for @catalogVariantAdd.
  ///
  /// In ar, this message translates to:
  /// **'إضافة نسخة جديدة'**
  String get catalogVariantAdd;

  /// No description provided for @catalogVariantRemove.
  ///
  /// In ar, this message translates to:
  /// **'إزالة النسخة'**
  String get catalogVariantRemove;

  /// No description provided for @catalogVariantsEmpty.
  ///
  /// In ar, this message translates to:
  /// **'لا توجد نسخ محددة. المنتج الأساسي هو النسخة الوحيدة. اضغط \"+ إضافة نسخة\" لإضافة أحجام أو ألوان أو خيارات أخرى.'**
  String get catalogVariantsEmpty;

  /// No description provided for @catalogVariantName.
  ///
  /// In ar, this message translates to:
  /// **'اسم النسخة'**
  String get catalogVariantName;

  /// No description provided for @catalogVariantDefaultName.
  ///
  /// In ar, this message translates to:
  /// **'النسخة {number}'**
  String catalogVariantDefaultName(int number);

  /// No description provided for @catalogInventoryPolicyNotTracked.
  ///
  /// In ar, this message translates to:
  /// **'متاح دائمًا'**
  String get catalogInventoryPolicyNotTracked;

  /// No description provided for @catalogInventoryPolicyFiniteStock.
  ///
  /// In ar, this message translates to:
  /// **'كمية محددة'**
  String get catalogInventoryPolicyFiniteStock;

  /// No description provided for @catalogInventoryPolicyUnlimited.
  ///
  /// In ar, this message translates to:
  /// **'غير محدود'**
  String get catalogInventoryPolicyUnlimited;

  /// No description provided for @catalogInventoryPolicyOnRequest.
  ///
  /// In ar, this message translates to:
  /// **'يُجهز عند الطلب'**
  String get catalogInventoryPolicyOnRequest;

  /// No description provided for @catalogInventoryPolicyDigitalPool.
  ///
  /// In ar, this message translates to:
  /// **'أكواد أو تراخيص'**
  String get catalogInventoryPolicyDigitalPool;

  /// No description provided for @catalogInventoryPolicyFileAsset.
  ///
  /// In ar, this message translates to:
  /// **'ملف للتحميل'**
  String get catalogInventoryPolicyFileAsset;

  /// No description provided for @catalogInventoryPolicyServiceCapacity.
  ///
  /// In ar, this message translates to:
  /// **'سعة خدمة'**
  String get catalogInventoryPolicyServiceCapacity;

  /// No description provided for @catalogInventoryPolicyNotTrackedHint.
  ///
  /// In ar, this message translates to:
  /// **'المنتج متاح للبيع دائمًا بدون متابعة كمية.'**
  String get catalogInventoryPolicyNotTrackedHint;

  /// No description provided for @catalogInventoryPolicyFiniteStockHint.
  ///
  /// In ar, this message translates to:
  /// **'حدد عدد الوحدات المتاحة للبيع.'**
  String get catalogInventoryPolicyFiniteStockHint;

  /// No description provided for @catalogInventoryPolicyUnlimitedHint.
  ///
  /// In ar, this message translates to:
  /// **'يمكن بيع المنتج بدون حد للكمية.'**
  String get catalogInventoryPolicyUnlimitedHint;

  /// No description provided for @catalogInventoryPolicyOnRequestHint.
  ///
  /// In ar, this message translates to:
  /// **'يتم تجهيز المنتج عند وصول الطلب.'**
  String get catalogInventoryPolicyOnRequestHint;

  /// No description provided for @catalogInventoryPolicyDigitalPoolHint.
  ///
  /// In ar, this message translates to:
  /// **'مناسب لأكواد التفعيل والمفاتيح والتراخيص الرقمية.'**
  String get catalogInventoryPolicyDigitalPoolHint;

  /// No description provided for @catalogInventoryPolicyFileAssetHint.
  ///
  /// In ar, this message translates to:
  /// **'مناسب للملفات الرقمية مثل PDF وZIP.'**
  String get catalogInventoryPolicyFileAssetHint;
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
