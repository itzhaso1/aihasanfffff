import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/cashier_api.dart';
import '../../core/audio/menu_sound_service.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/local_db/local_db_providers.dart';
import '../../core/pos/application/kitchen_local_service.dart';
import '../../core/pos/application/pos_providers.dart';
import '../../core/pos/pos_labels.dart';
import '../../core/sync/auto_sync_controller.dart';
import '../../core/sync/kitchen_sync_copy.dart';
import '../../core/theme/hasim_colors.dart';
import '../../core/theme/hasim_radius.dart';
import '../../core/util/json_numbers.dart';
import '../../core/widgets/hasim_widgets.dart';

/// Kitchen prep board — SQLite `watch()` only (no API polling).
class KitchenBoard extends ConsumerStatefulWidget {
  const KitchenBoard({super.key});

  @override
  ConsumerState<KitchenBoard> createState() => _KitchenBoardState();
}

class _KitchenBoardState extends ConsumerState<KitchenBoard> {
  StreamSubscription<KitchenBoardSnapshot>? _sub;
  Timer? _statusClock;
  KitchenBoardSnapshot _board = const KitchenBoardSnapshot();
  var _loading = true;
  var _manualBusy = false;
  String? _error;
  int? _workspaceId;
  final _seenOrderIds = <String>{};
  var _soundPrimed = false;

  static const _activeActions = [
    'preparing',
    'ready',
    'delivered',
    'cancelled',
  ];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) unawaited(_bind());
    });
  }

  @override
  void dispose() {
    _statusClock?.cancel();
    _sub?.cancel();
    super.dispose();
  }

  void _syncStatusClock(DateTime? lastSuccessAt) {
    if (lastSuccessAt == null) {
      _statusClock?.cancel();
      _statusClock = null;
      return;
    }
    if (_statusClock != null) return;
    _statusClock = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  Future<void> _syncNow() async {
    if (_manualBusy) return;
    setState(() => _manualBusy = true);
    final messenger = ScaffoldMessenger.maybeOf(context);
    messenger
      ?..hideCurrentSnackBar()
      ..showSnackBar(const SnackBar(content: Text(KitchenSyncCopy.syncing)));
    try {
      final result = await ref
          .read(autoSyncStatusProvider.notifier)
          .runCycle(manual: true);
      if (!mounted) return;
      messenger
        ?..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(content: Text(result.kitchenMessage)));
    } catch (_) {
      if (!mounted) return;
      messenger
        ?..hideCurrentSnackBar()
        ..showSnackBar(const SnackBar(content: Text(KitchenSyncCopy.offline)));
    } finally {
      if (mounted) setState(() => _manualBusy = false);
    }
  }

  Future<void> _bind() async {
    await _sub?.cancel();
    var workspaceId = ref.read(workspaceIdProvider);
    if (workspaceId == null || workspaceId <= 0) {
      final store = await ref.read(localAuthServiceProvider).anyStore();
      workspaceId = store?.workspaceId;
    }
    if (!mounted) return;
    if (workspaceId == null || workspaceId <= 0) {
      setState(() {
        _loading = false;
        _error = 'لم يتم إعداد المتجر المحلي بعد.';
        _board = const KitchenBoardSnapshot();
      });
      return;
    }
    _workspaceId = workspaceId;
    _sub = ref
        .read(kitchenLocalServiceProvider)
        .watchBoard(workspaceId)
        .listen(
          (board) {
            if (!mounted) return;
            final shouldPlay = _noteNewKitchenOrders(board.active);
            setState(() {
              _board = board;
              _loading = false;
              _error = null;
            });
            if (shouldPlay) {
              unawaited(ref.read(menuSoundServiceProvider).playNewOrder());
            }
          },
          onError: (Object e) {
            if (!mounted) return;
            setState(() {
              _loading = false;
              _error = e.toString();
            });
          },
        );
  }

  bool _noteNewKitchenOrders(List<Map<String, dynamic>> orders) {
    final ids = <String>{
      for (final order in orders)
        if (_kitchenOrderId(order) != null) _kitchenOrderId(order)!,
    };
    if (!_soundPrimed) {
      _seenOrderIds
        ..clear()
        ..addAll(ids);
      _soundPrimed = true;
      return false;
    }
    var shouldPlay = false;
    for (final id in ids) {
      if (_seenOrderIds.add(id)) shouldPlay = true;
    }
    _seenOrderIds.removeWhere((id) => !ids.contains(id));
    return shouldPlay;
  }

  String? _kitchenOrderId(Map<String, dynamic> order) {
    final localId = order['local_id']?.toString().trim();
    if (localId != null && localId.isNotEmpty) return localId;
    final id = order['id']?.toString().trim();
    if (id != null && id.isNotEmpty) return id;
    return null;
  }

  Future<void> _updateStatus(String localId, String status) async {
    final workspaceId = _workspaceId ?? ref.read(workspaceIdProvider);
    if (workspaceId == null) return;
    try {
      final deviceId =
          await ref.read(deviceIdentityProvider).getOrCreateDeviceId();
      await ref
          .read(kitchenLocalServiceProvider)
          .updateStatus(
            workspaceId: workspaceId,
            orderLocalId: localId,
            status: status,
            deviceId: deviceId,
            permissions: ref
                .read(authControllerProvider)
                .valueOrNull
                ?.permissions,
          );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
    }
  }

  String _ticketTitle(Map<String, dynamic> order) {
    return PosLabels.kitchenHeading(
      orderType: order['order_type']?.toString(),
      tableName: nestedName(order['table'], fallback: ''),
    );
  }

  @override
  Widget build(BuildContext context) {
    final syncStatus = ref.watch(autoSyncStatusProvider);
    _syncStatusClock(syncStatus.lastSuccessAt);

    final Widget body;
    if (_loading && _board.active.isEmpty && _board.delivered.isEmpty &&
        _board.cancelled.isEmpty) {
      body = const Center(child: CircularProgressIndicator());
    } else if (_error != null &&
        _board.active.isEmpty &&
        _board.delivered.isEmpty &&
        _board.cancelled.isEmpty) {
      body = Padding(
        padding: const EdgeInsets.all(16),
        child: HsEmpty(
          title: 'تعذر تحميل المطبخ',
          subtitle: _error,
          actionLabel: 'إعادة المحاولة',
          onAction: _bind,
        ),
      );
    } else {
      body = RefreshIndicator(
        onRefresh: _bind,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(12, 0, 12, 16),
          children: [
            _sectionHeader(
              key: const ValueKey('kitchen-active-section'),
              title: 'الطلبات الحالية',
              count: _board.active.length,
              countKey: const ValueKey('kitchen-active-count'),
            ),
            if (_board.active.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 16),
                child: HsEmpty(title: 'لا توجد طلبات تجهيز حالياً.'),
              )
            else
              ..._ticketRows(
                _board.active,
                rowPrefix: 'active',
                actions: _activeActions,
              ),
            const SizedBox(height: 16),
            const Text(
              'السجل',
              key: ValueKey('kitchen-history-section'),
              style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 8),
            _sectionHeader(
              key: const ValueKey('kitchen-delivered-section'),
              title: 'تم التسليم',
              count: _board.delivered.length,
            ),
            if (_board.delivered.isEmpty)
              const Padding(
                padding: EdgeInsets.only(bottom: 8),
                child: Text(
                  'لا توجد طلبات مسلّمة.',
                  style: TextStyle(fontSize: 12, color: HasimColors.muted),
                ),
              )
            else
              ..._ticketRows(
                _board.delivered,
                rowPrefix: 'delivered',
                actions: const [],
              ),
            const SizedBox(height: 12),
            _sectionHeader(
              key: const ValueKey('kitchen-cancelled-section'),
              title: 'ملغي',
              count: _board.cancelled.length,
            ),
            if (_board.cancelled.isEmpty)
              const Padding(
                padding: EdgeInsets.only(bottom: 8),
                child: Text(
                  'لا توجد طلبات ملغاة.',
                  style: TextStyle(fontSize: 12, color: HasimColors.muted),
                ),
              )
            else
              ..._ticketRows(
                _board.cancelled,
                rowPrefix: 'cancelled',
                actions: const [],
              ),
          ],
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'طلبات التجهيز',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 4),
              const Text(
                'الطاولات والطلبات الخارجية والتوصيل تظهر هنا للشيف فقط.',
                style: TextStyle(fontSize: 12, color: HasimColors.muted),
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: Align(
                      alignment: AlignmentDirectional.centerStart,
                      child: HsBadge(
                        label: syncStatus.label(DateTime.now()),
                        background: syncStatus.syncing
                            ? HasimColors.brandSoft
                            : (!syncStatus.eligible || !syncStatus.online)
                                ? HasimColors.warningSoft
                                : HasimColors.ctaSoft,
                        foreground: syncStatus.syncing
                            ? HasimColors.brandDark
                            : (!syncStatus.eligible || !syncStatus.online)
                                ? HasimColors.warning
                                : HasimColors.cta,
                      ),
                    ),
                  ),
                  SizedBox(
                    height: 40,
                    child: HsPrimaryButton(
                      key: const ValueKey('kitchen-sync-now'),
                      label: KitchenSyncCopy.button,
                      loading: _manualBusy,
                      onPressed: _manualBusy
                          ? null
                          : () => unawaited(_syncNow()),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
        Expanded(child: body),
      ],
    );
  }

  Widget _sectionHeader({
    required String title,
    required int count,
    Key? key,
    Key? countKey,
  }) {
    return Padding(
      key: key,
      padding: const EdgeInsets.only(bottom: 8, top: 4),
      child: Row(
        children: [
          Text(
            title,
            style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800),
          ),
          const SizedBox(width: 8),
          HsBadge(
            key: countKey,
            label: '$count',
            background: HasimColors.brandSoft,
            foreground: HasimColors.brandDark,
          ),
        ],
      ),
    );
  }

  List<Widget> _ticketRows(
    List<Map<String, dynamic>> orders, {
    required String rowPrefix,
    required List<String> actions,
  }) {
    final rows = <Widget>[];
    for (var i = 0; i < orders.length; i += 2) {
      final row = i ~/ 2;
      final rightIndex = i + 1;
      final hasRight = rightIndex < orders.length;
      rows.add(
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: IntrinsicHeight(
            child: Row(
              key: ValueKey(
                rowPrefix == 'active'
                    ? 'kitchen-row-$row'
                    : 'kitchen-$rowPrefix-row-$row',
              ),
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Expanded(child: _ticketCard(orders[i], actions: actions)),
                const SizedBox(width: 8),
                Expanded(
                  child: hasRight
                      ? _ticketCard(orders[rightIndex], actions: actions)
                      : const SizedBox.shrink(),
                ),
              ],
            ),
          ),
        ),
      );
    }
    return rows;
  }

  Widget _ticketCard(
    Map<String, dynamic> order, {
    required List<String> actions,
  }) {
    final current = order['pos_status'] as String? ?? 'new';
    final items = order['items'] is List
        ? (order['items'] as List).whereType<Map>()
        : const Iterable<Map>.empty();
    return HsCard(
      key: ValueKey('kitchen-${order['local_id']}'),
      color: PosLabels.statusSoft(current),
      borderColor: PosLabels.statusColor(current),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      _ticketTitle(order),
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 13,
                      ),
                    ),
                    Text(
                      '${orderDisplayLabel(order)} · ${PosLabels.orderType(order['order_type']?.toString())}',
                      style: const TextStyle(
                        fontSize: 11,
                        color: HasimColors.muted,
                      ),
                    ),
                  ],
                ),
              ),
              HsBadge(
                label: PosLabels.status(current),
                background: PosLabels.statusSoft(current),
                foreground: PosLabels.statusColor(current),
              ),
            ],
          ),
          if (items.isNotEmpty) ...[
            const SizedBox(height: 8),
            for (final item in items)
              Text(
                '• ${item['quantity']} × ${catalogItemName(item)}'
                '${item['notes'] != null && '${item['notes']}'.trim().isNotEmpty ? ' (${item['notes']})' : ''}',
                style: const TextStyle(fontSize: 12),
              ),
          ],
          if (order['notes'] != null &&
              '${order['notes']}'.trim().isNotEmpty) ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: HasimColors.surface,
                borderRadius: BorderRadius.circular(HasimRadius.sm),
              ),
              child: Text(
                'ملاحظات: ${order['notes']}',
                style: const TextStyle(fontSize: 12),
              ),
            ),
          ],
          if (actions.isNotEmpty) ...[
            const SizedBox(height: 10),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final s in actions)
                  HsActionChip(
                    label: PosLabels.status(s),
                    selected: s == current,
                    color: PosLabels.statusColor(s),
                    onTap: () {
                      final localId = order['local_id'] as String? ??
                          order['id']?.toString();
                      if (localId != null && localId.isNotEmpty) {
                        unawaited(_updateStatus(localId, s));
                      }
                    },
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
