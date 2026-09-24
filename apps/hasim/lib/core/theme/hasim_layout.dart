import 'package:flutter/material.dart';

/// نقاط التحول للتخطيط المتجاوب — موبايل يبقى كما هو، وسطح المكتب يستخدم سكة + لوحة + مساحة الدردشة.
abstract final class HasimLayout {
  /// أسفل هذا العرض: شريط سفلي وتخطيط مكدّس (الموبايل الحالي).
  static const double compactMax = 700;

  /// من هذا العرض: لوحة الإعدادات جنبًا إلى جنب مع التفاصيل.
  static const double splitMin = 900;

  /// أقل عرض لقائمة المحادثات + الدردشة (سكة + لوحة + مساحة رسائل).
  static const double minConversationSplitWidth =
      navRailWidth + conversationPanelUserMin + desktopChatMinWidth + conversationPanelResizeHandleWidth;

  static const double navRailWidth = 76;
  static const double conversationPanelMin = 320;
  static const double conversationPanelMax = 400;
  /// حدود عرض قائمة المحادثات عند السحب على سطح المكتب.
  static const double conversationPanelUserMin = 280;
  static const double conversationPanelUserMax = 680;
  static const double conversationPanelResizeHandleWidth = 6;
  static const double desktopChatMinWidth = 360;
  static const double customerDrawerWidth = 336;

  static Size sizeOf(BuildContext context) => MediaQuery.sizeOf(context);

  static bool isCompact(BuildContext context) => sizeOf(context).width < compactMax;

  static bool useNavRail(BuildContext context) => !isCompact(context);

  static bool useSplitPane(BuildContext context) => sizeOf(context).width >= splitMin;

  /// قائمة + دردشة على سطح المكتب حتى مع نافذة ضيقة (تقسيم الشاشة)، بدون دفع شاشة كاملة.
  static bool useConversationSplit(BuildContext context) {
    if (isCompact(context)) return false;
    return sizeOf(context).width >= minConversationSplitWidth;
  }

  static bool useSettingsSplit(BuildContext context) => useSplitPane(context);

  static double conversationPanelWidth(BuildContext context) =>
      suggestedConversationPanelWidth(context);

  /// العرض الافتراضي قبل أي تعديل يدوي من المستخدم.
  static double suggestedConversationPanelWidth(BuildContext context) {
    final width = sizeOf(context).width;
    if (width >= 1400) return conversationPanelMax;
    if (width >= 1100) return 360;
    return conversationPanelMin;
  }

  /// أقصى عرض للوحة مع إبقاء مساحة كافية للدردشة (واختياريًا درج العميل).
  static double conversationPanelMaxForScreen(
    BuildContext context, {
    bool customerDrawerOpen = false,
  }) {
    final screen = sizeOf(context).width;
    final rail = useNavRail(context) ? navRailWidth : 0;
    final drawer = customerDrawerOpen ? customerDrawerWidth : 0;
    final reserved = rail + drawer + desktopChatMinWidth + conversationPanelResizeHandleWidth;
    final cap = screen - reserved;
    return cap.clamp(conversationPanelUserMin, conversationPanelUserMax);
  }
}
