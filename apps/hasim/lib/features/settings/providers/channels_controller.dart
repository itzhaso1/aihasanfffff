import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/settings/data/channel_connection.dart';
import 'package:hasim/features/settings/data/channel_connection_runtime.dart';

class ChannelsState {
  const ChannelsState({
    this.items = const [],
    this.loading = false,
    this.error,
    this.pendingChannel,
    this.busyChannel,
    this.notice,
    this.pageAttempt,
    this.pageChoices = const [],
  });

  final List<ChannelModel> items;
  final bool loading;
  final String? error;
  final String? pendingChannel;
  final String? busyChannel;
  final String? notice;
  final String? pageAttempt;
  final List<FacebookPageChoice> pageChoices;

  ChannelsState copyWith({
    List<ChannelModel>? items,
    bool? loading,
    String? error,
    String? pendingChannel,
    String? busyChannel,
    String? notice,
    String? pageAttempt,
    List<FacebookPageChoice>? pageChoices,
    bool clearError = false,
    bool clearNotice = false,
    bool clearPending = false,
    bool clearPages = false,
    bool clearBusy = false,
  }) {
    return ChannelsState(
      items: items ?? this.items,
      loading: loading ?? this.loading,
      error: clearError ? null : (error ?? this.error),
      pendingChannel: clearPending
          ? null
          : (pendingChannel ?? this.pendingChannel),
      busyChannel: clearBusy ? null : (busyChannel ?? this.busyChannel),
      notice: clearNotice ? null : (notice ?? this.notice),
      pageAttempt: clearPages ? null : (pageAttempt ?? this.pageAttempt),
      pageChoices: clearPages ? const [] : (pageChoices ?? this.pageChoices),
    );
  }
}

final channelBrowserLauncherProvider = Provider<ChannelBrowserLauncher>(
  (ref) => UrlChannelBrowserLauncher(),
);

final channelCallbackLinkSourceProvider = Provider<ChannelCallbackLinkSource>(
  (ref) => AppLinksChannelCallbackSource(),
);

final channelsControllerProvider =
    StateNotifierProvider<ChannelsController, ChannelsState>((ref) {
      final controller = ChannelsController(ref);
      ref.listen<String>(authControllerProvider.select((s) => s.isolationKey), (
        previous,
        next,
      ) {
        if (previous != next) {
          controller.reload(reset: true);
        }
      });
      return controller;
    });

class ChannelsController extends StateNotifier<ChannelsState> {
  ChannelsController(this._ref) : super(const ChannelsState()) {
    _links = _ref
        .read(channelCallbackLinkSourceProvider)
        .incoming
        .listen(_onIncomingLink);
    reload();
    unawaited(_consumeInitialLink());
  }

  final Ref _ref;
  StreamSubscription<Uri>? _links;

  Future<void> reload({bool reset = false}) async {
    final workspaceId = _ref.read(authControllerProvider).workspace?.id;
    if (workspaceId == null) {
      state = const ChannelsState();
      return;
    }

    final repo = _ref.read(channelRepositoryProvider);
    final cached = await repo.cached();
    if (cached.isNotEmpty && !reset) {
      state = state.copyWith(items: cached, loading: false, clearError: true);
    } else if (reset) {
      state = ChannelsState(items: cached, loading: cached.isEmpty);
    } else {
      state = state.copyWith(loading: state.items.isEmpty, clearError: true);
    }

    try {
      final items = await repo.list();
      state = state.copyWith(
        items: items,
        loading: false,
        clearError: !repo.lastReadFromCache,
        error: repo.lastReadFromCache
            ? 'تعذر التحديث. تُعرض البيانات المحفوظة على الجهاز.'
            : null,
        clearBusy: true,
        clearPending: reset,
        clearPages: reset,
      );
    } on ApiException catch (e) {
      state = state.copyWith(
        loading: false,
        error: state.items.isEmpty ? e.userMessage : null,
        clearBusy: true,
      );
    } catch (_) {
      state = state.copyWith(
        loading: false,
        error: state.items.isEmpty ? 'تعذر تحميل القنوات.' : null,
        clearBusy: true,
      );
    }
  }

  Future<void> connect(String channel, {String? botToken}) async {
    state = state.copyWith(
      busyChannel: channel,
      clearNotice: true,
      clearError: true,
    );
    try {
      final start = await _ref
          .read(channelRepositoryProvider)
          .startConnect(channel, botToken: botToken);
      if (start.connected) {
        await reload();
        state = state.copyWith(
          notice: 'تم ربط القناة بنجاح.',
          clearBusy: true,
          clearPending: true,
        );
        return;
      }
      final url = start.authorizationUrl;
      if (url == null || url.isEmpty) {
        state = state.copyWith(clearBusy: true, notice: 'تعذر بدء الربط.');
        return;
      }
      state = state.copyWith(pendingChannel: channel, busyChannel: channel);
      final opened = await _ref
          .read(channelBrowserLauncherProvider)
          .open(Uri.parse(url));
      if (!opened) {
        state = state.copyWith(
          clearBusy: true,
          notice: 'تعذر فتح صفحة الربط.',
          clearPending: true,
        );
      }
    } on ApiException catch (e) {
      state = state.copyWith(
        clearBusy: true,
        notice: e.userMessage,
        clearPending: true,
      );
    } catch (_) {
      state = state.copyWith(
        clearBusy: true,
        notice: 'تعذر بدء ربط القناة.',
        clearPending: true,
      );
    }
  }

  Future<void> disconnect(String channel) async {
    state = state.copyWith(busyChannel: channel, clearNotice: true);
    try {
      await _ref.read(channelRepositoryProvider).disconnect(channel);
      await reload();
      state = state.copyWith(notice: 'تم قطع اتصال القناة.');
    } on ApiException catch (e) {
      state = state.copyWith(clearBusy: true, notice: e.userMessage);
    } catch (_) {
      state = state.copyWith(clearBusy: true, notice: 'تعذر قطع الاتصال.');
    }
  }

  Future<void> selectFacebookPage(String pageId) async {
    final attempt = state.pageAttempt;
    if (attempt == null || attempt.isEmpty) {
      return;
    }
    state = state.copyWith(
      busyChannel: 'facebook_messenger',
      clearNotice: true,
    );
    try {
      await _ref
          .read(channelRepositoryProvider)
          .selectFacebookPage(attempt, pageId);
      await reload();
      state = state.copyWith(
        notice: 'تم ربط Facebook.',
        clearBusy: true,
        clearPages: true,
      );
    } on ApiException catch (e) {
      state = state.copyWith(clearBusy: true, notice: e.userMessage);
    } catch (_) {
      state = state.copyWith(clearBusy: true, notice: 'تعذر اختيار الصفحة.');
    }
  }

  void clearNotice() {
    if (state.notice != null) {
      state = state.copyWith(clearNotice: true);
    }
  }

  Future<void> handleResume() async {
    if (state.pendingChannel != null) {
      await reload();
    }
  }

  void handleCallback(Uri uri) {
    unawaited(_applyCallback(uri));
  }

  Future<void> _consumeInitialLink() async {
    final uri = await _ref.read(channelCallbackLinkSourceProvider).initial();
    if (uri != null) {
      await _applyCallback(uri);
    }
  }

  void _onIncomingLink(Uri uri) {
    unawaited(_applyCallback(uri));
  }

  Future<void> _applyCallback(Uri uri) async {
    final callback = ChannelOAuthCallback.tryParse(uri);
    if (callback == null) {
      return;
    }

    final currentWorkspace = _ref.read(authControllerProvider).workspace?.id;
    if (callback.workspaceId != null &&
        currentWorkspace != null &&
        callback.workspaceId != currentWorkspace) {
      state = state.copyWith(clearPending: true, clearBusy: true);
      return;
    }

    if (callback.isSelectPage) {
      final attempt = callback.attempt;
      if (attempt == null || attempt.isEmpty) {
        state = state.copyWith(
          notice: 'تعذر اختيار صفحة Facebook.',
          clearPending: true,
          clearBusy: true,
        );
        return;
      }
      try {
        final pages = await _ref
            .read(channelRepositoryProvider)
            .facebookPages(attempt);
        state = state.copyWith(
          pageAttempt: attempt,
          pageChoices: pages,
          clearBusy: true,
          clearPending: true,
        );
      } on ApiException catch (e) {
        state = state.copyWith(
          notice: e.userMessage,
          clearBusy: true,
          clearPending: true,
        );
      }
      return;
    }

    await reload();
    if (callback.isSuccess) {
      state = state.copyWith(
        notice: 'تم ربط القناة بنجاح.',
        clearPending: true,
        clearBusy: true,
      );
      return;
    }
    if (callback.isCancelled) {
      state = state.copyWith(
        notice: 'تم إلغاء الربط.',
        clearPending: true,
        clearBusy: true,
      );
      return;
    }
    state = state.copyWith(
      notice: (callback.message != null && callback.message!.trim().isNotEmpty)
          ? callback.message
          : 'فشل ربط القناة.',
      clearPending: true,
      clearBusy: true,
    );
  }

  @override
  void dispose() {
    unawaited(_links?.cancel());
    super.dispose();
  }
}
