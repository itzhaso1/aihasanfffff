import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

class ChannelsState {
  const ChannelsState({
    this.items = const [],
    this.loading = false,
    this.error,
  });

  final List<ChannelModel> items;
  final bool loading;
  final String? error;
}

final channelsControllerProvider = StateNotifierProvider<ChannelsController, ChannelsState>((ref) {
  final controller = ChannelsController(ref);
  ref.listen<String>(
    authControllerProvider.select((s) => s.isolationKey),
    (previous, next) {
      if (previous != next) {
        controller.reload();
      }
    },
  );
  return controller;
});

class ChannelsController extends StateNotifier<ChannelsState> {
  ChannelsController(this._ref) : super(const ChannelsState()) {
    reload();
  }

  final Ref _ref;

  Future<void> reload() async {
    final workspaceId = _ref.read(authControllerProvider).workspace?.id;
    if (workspaceId == null) {
      state = const ChannelsState();
      return;
    }

    state = const ChannelsState(loading: true);
    try {
      final items = await _ref.read(channelRepositoryProvider).list();
      state = ChannelsState(items: items);
    } on ApiException catch (e) {
      state = ChannelsState(error: e.message);
    } catch (_) {
      state = const ChannelsState(error: 'تعذر تحميل القنوات.');
    }
  }
}
