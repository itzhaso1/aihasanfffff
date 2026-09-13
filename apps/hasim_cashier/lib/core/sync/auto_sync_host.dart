import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/app_config.dart';
import 'auto_sync_controller.dart';

/// Periodic SyncEngineV2 flush while the app is in the foreground.
///
/// Polling only (default 5s). Not WebSocket. Cancels its timer on pause,
/// detach, and dispose. Standalone workspace 900001 never talks to Laravel.
/// Each cycle is push → pull → catch-up push through one SingleFlight, and
/// covers kitchen orders + status, invoices, menu, table master, and table
/// sessions (open / close / QR orders) in both directions.
class AutoSyncHost extends ConsumerStatefulWidget {
  const AutoSyncHost({super.key, required this.child});

  final Widget child;

  @override
  ConsumerState<AutoSyncHost> createState() => _AutoSyncHostState();
}

class _AutoSyncHostState extends ConsumerState<AutoSyncHost>
    with WidgetsBindingObserver {
  Timer? _timer;
  var _foreground = true;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    if (AppConfig.autoSyncEnabled) {
      _startTimer();
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted && _foreground) {
          unawaited(_tick());
        }
      });
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _stopTimer();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.resumed:
        _foreground = true;
        if (AppConfig.autoSyncEnabled) {
          _startTimer();
          unawaited(_tick());
        }
        break;
      case AppLifecycleState.inactive:
        break;
      case AppLifecycleState.hidden:
      case AppLifecycleState.paused:
      case AppLifecycleState.detached:
        _foreground = false;
        _stopTimer();
        break;
    }
  }

  void _startTimer() {
    _timer?.cancel();
    _timer = Timer.periodic(
      Duration(seconds: AppConfig.autoSyncSeconds),
      (_) {
        if (_foreground) unawaited(_tick());
      },
    );
  }

  void _stopTimer() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> _tick() async {
    if (!_foreground || !mounted) return;
    if (!AppConfig.autoSyncEnabled) return;
    try {
      await ref.read(autoSyncStatusProvider.notifier).runCycle(manual: false);
    } catch (_) {
      // Offline / Laravel errors stay in SQLite. Never dialog on a tick.
    }
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
