import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:shared_preferences/shared_preferences.dart';

const _prefKey = 'desktop_conversation_panel_width';

final desktopConversationPanelWidthProvider =
    StateNotifierProvider<DesktopConversationPanelWidthNotifier, double?>(
      (ref) =>
          DesktopConversationPanelWidthNotifier(ref.watch(sharedPrefsProvider)),
    );

/// عرض قائمة المحادثات القابل للتعديل على سطح المكتب — يُحفظ محليًا.
class DesktopConversationPanelWidthNotifier extends StateNotifier<double?> {
  DesktopConversationPanelWidthNotifier(this._prefs)
    : super(_prefs.getDouble(_prefKey));

  final SharedPreferences _prefs;

  /// Clamped width for layout. Persists when stored width exceeds current max
  /// (e.g. drawer opened or window resized) — never mutates provider during build.
  double resolve(
    BuildContext context, {
    bool customerDrawerOpen = false,
    double navRailWidth = HasimLayout.navRailWidth,
  }) {
    final fallback = HasimLayout.suggestedConversationPanelWidth(context);
    final max = HasimLayout.conversationPanelMaxForScreen(
      context,
      customerDrawerOpen: customerDrawerOpen,
      navRailWidth: navRailWidth,
    );
    final stored = state;
    final value = (stored ?? fallback).clamp(
      HasimLayout.conversationPanelUserMin,
      max,
    );
    if (stored != null && stored != value) {
      final previous = stored;
      final clamped = value;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (state == previous) {
          state = clamped;
          _prefs.setDouble(_prefKey, clamped);
        }
      });
    }
    return value;
  }

  void adjustByDrag(
    BuildContext context,
    double deltaDx, {
    bool customerDrawerOpen = false,
    double navRailWidth = HasimLayout.navRailWidth,
  }) {
    final rtl = Directionality.of(context) == TextDirection.rtl;
    final delta = rtl ? -deltaDx : deltaDx;
    final max = HasimLayout.conversationPanelMaxForScreen(
      context,
      customerDrawerOpen: customerDrawerOpen,
      navRailWidth: navRailWidth,
    );
    final next =
        (resolve(
                  context,
                  customerDrawerOpen: customerDrawerOpen,
                  navRailWidth: navRailWidth,
                ) +
                delta)
            .clamp(HasimLayout.conversationPanelUserMin, max);
    state = next;
    _prefs.setDouble(_prefKey, next);
  }

  void resetToSuggested(BuildContext context) {
    state = null;
    _prefs.remove(_prefKey);
  }
}
