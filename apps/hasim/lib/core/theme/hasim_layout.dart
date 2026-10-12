import 'package:flutter/material.dart';

/// نقاط التحول للتخطيط المتجاوب — موبايل يبقى كما هو، وسطح المكتب يستخدم سكة + لوحة + مساحة الدردشة.
abstract final class HasimLayout {
  /// أسفل هذا العرض: شريط سفلي وتخطيط مكدّس (الموبايل الحالي).
  static const double compactMax = 700;

  /// من هذا العرض: لوحة الإعدادات جنبًا إلى جنب مع التفاصيل.
  static const double splitMin = 900;

  /// أقل عرض يكفي لحقلين متجاورين دون ضغط النص العربي.
  static const double formPairMin = 560;

  /// أقل من هذا العرض: بطاقة كتالوج واحدة في الصف.
  static const double catalogSingleColumnMax = 360;

  /// هامش الحوارات على الهاتف حتى لا تلامس الحواف.
  static const EdgeInsets dialogInset = EdgeInsets.symmetric(
    horizontal: 16,
    vertical: 24,
  );

  /// أقل عرض لقائمة المحادثات + الدردشة (سكة + لوحة + مساحة رسائل).
  static const double minConversationSplitWidth =
      navRailWidth +
      conversationPanelUserMin +
      desktopChatMinWidth +
      conversationPanelResizeHandleWidth;

  static const double navRailWidth = 68;
  static const double conversationPanelMin = 320;
  static const double conversationPanelMax = 400;

  /// حدود عرض قائمة المحادثات عند السحب على سطح المكتب.
  static const double conversationPanelUserMin = 280;
  static const double conversationPanelUserMax = 680;
  static const double conversationPanelResizeHandleWidth = 6;
  static const double desktopChatMinWidth = 360;
  static const double customerDrawerWidth = 336;

  static Size sizeOf(BuildContext context) => MediaQuery.sizeOf(context);

  static bool isCompact(BuildContext context) =>
      sizeOf(context).width < compactMax;

  static bool useNavRail(BuildContext context) => !isCompact(context);

  static bool useSplitPane(BuildContext context) =>
      sizeOf(context).width >= splitMin;

  /// قائمة + دردشة على سطح المكتب حتى مع نافذة ضيقة (تقسيم الشاشة)، بدون دفع شاشة كاملة.
  static bool useConversationSplit(
    BuildContext context, {
    double navRailWidth = HasimLayout.navRailWidth,
  }) {
    if (isCompact(context)) return false;
    return sizeOf(context).width >=
        navRailWidth +
            conversationPanelUserMin +
            desktopChatMinWidth +
            conversationPanelResizeHandleWidth;
  }

  static bool useSettingsSplit(BuildContext context) => useSplitPane(context);

  static bool pairFormFields(double width) => width >= formPairMin;

  static int catalogGridCount(double width) {
    if (width < catalogSingleColumnMax) {
      return 1;
    }
    if (width < 520) {
      return 2;
    }
    if (width < 860) {
      return 3;
    }
    if (width < 1100) {
      return 4;
    }
    return 5;
  }

  static double catalogCardAspectRatio(int columns) =>
      columns <= 1 ? 1.28 : 0.72;

  static int emojiGridCount(double width) {
    final count = (width / 40).floor();
    return count.clamp(4, 8);
  }

  static int attachmentGridCount(double width) => width < 360 ? 3 : 4;

  static double attachmentPanelHeight(double width) => width < 360 ? 248 : 188;

  static NavigationDestinationLabelBehavior bottomNavLabelBehavior(
    double width,
  ) {
    return NavigationDestinationLabelBehavior.alwaysShow;
  }

  static double conversationPanelWidth(BuildContext context) =>
      suggestedConversationPanelWidth(context);

  /// العرض الافتراضي قبل أي تعديل يدوي من المستخدم.
  static double suggestedConversationPanelWidth(BuildContext context) {
    final width = sizeOf(context).width;
    if (width >= 1400) return conversationPanelMax;
    if (width >= 1100) return 360;
    return conversationPanelMin;
  }

  /// ارتفاع ورقة نموذج طويلة بحيث تبقى تحت الشريط العلوي ولا تلامس شريط الحالة.
  static double modalSheetHeight(BuildContext context) {
    final media = MediaQuery.of(context);
    final compact = isCompact(context);
    final reservedTop =
        media.padding.top +
        kToolbarHeight +
        (compact ? compactSheetTopGap : spaceBelowAppBar);
    final reservedBottom =
        modalSheetMargin(context).vertical + media.viewInsets.bottom;
    final available = media.size.height - reservedTop - reservedBottom;
    final height = compact ? available * compactSheetHeightFactor : available;
    return height.clamp(280.0, media.size.height);
  }

  static EdgeInsets modalSheetMargin(BuildContext context) {
    if (!isCompact(context)) {
      return EdgeInsets.zero;
    }
    return const EdgeInsets.fromLTRB(
      compactSheetSideInset,
      0,
      compactSheetSideInset,
      compactSheetBottomInset,
    );
  }

  static BorderRadius modalSheetRadius(BuildContext context) {
    if (!isCompact(context)) {
      return const BorderRadius.vertical(top: Radius.circular(24));
    }
    return BorderRadius.circular(20);
  }

  static const double spaceBelowAppBar = 12;
  static const double compactSheetTopGap = 28;
  static const double compactSheetSideInset = 16;
  static const double compactSheetBottomInset = 12;
  static const double compactSheetHeightFactor = 0.84;

  /// أقصى عرض للوحة مع إبقاء مساحة كافية للدردشة (واختياريًا درج العميل).
  static double conversationPanelMaxForScreen(
    BuildContext context, {
    bool customerDrawerOpen = false,
    double navRailWidth = HasimLayout.navRailWidth,
  }) {
    final screen = sizeOf(context).width;
    final rail = useNavRail(context) ? navRailWidth : 0;
    final drawer = customerDrawerOpen ? customerDrawerWidth : 0;
    final reserved =
        rail +
        drawer +
        desktopChatMinWidth +
        conversationPanelResizeHandleWidth;
    final cap = screen - reserved;
    return cap.clamp(conversationPanelUserMin, conversationPanelUserMax);
  }
}
