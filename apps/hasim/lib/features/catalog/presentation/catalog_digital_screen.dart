import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/catalog/data/chat_catalog_repository.dart';

class CatalogDigitalScreen extends ConsumerStatefulWidget {
  const CatalogDigitalScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<CatalogDigitalScreen> createState() =>
      _CatalogDigitalScreenState();
}

class _CatalogDigitalScreenState extends ConsumerState<CatalogDigitalScreen> {
  List<Map<String, dynamic>> _pools = [];
  bool _loading = true;
  String? _error;

  ChatCatalogRepository get _repo =>
      ref.read(chatCatalogRepositoryProvider);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final cached = await _repo.cachedDigitalPools();
    if (!mounted) return;
    if (cached.isNotEmpty) {
      setState(() {
        _pools = cached;
        _loading = false;
        _error = null;
      });
    } else {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    try {
      final page = await _repo.digitalPools();
      if (!mounted) return;
      setState(() {
        _pools = page.items;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = _pools.isEmpty ? e.message : null;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = _pools.isEmpty ? 'تعذر تحميل المخزون الرقمي.' : null;
        _loading = false;
      });
    }
  }

  Future<void> _addPool() async {
    final products = await _repo.list(itemKind: 'digital', perPage: 50);
    if (!mounted) return;
    if (products.items.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('أضف منتجاً رقمياً أولاً.')),
      );
      return;
    }
    ChatCatalogItemModel? item = products.items.first;
    final name = TextEditingController(text: 'مخزون ${item.name}');
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('حوض مخزون رقمي'),
        content: SingleChildScrollView(
          child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            DropdownButtonFormField<ChatCatalogItemModel>(
              initialValue: item,
              isExpanded: true,
              items: [
                for (final product in products.items)
                  DropdownMenuItem(
                    value: product,
                    child: Text(
                      product.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
              onChanged: (value) => item = value,
            ),
            const SizedBox(height: 12),
            TextField(
              controller: name,
              decoration: const InputDecoration(labelText: 'اسم الحوض'),
            ),
          ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('إنشاء'),
          ),
        ],
      ),
    );
    final poolName = name.text.trim();
    final chosen = item;
    name.dispose();
    if (ok != true || chosen == null || poolName.isEmpty) {
      return;
    }
    try {
      await _repo.createDigitalPool(itemId: chosen.id, name: poolName);
      await _load();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _import(int poolId) async {
    final secrets = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('إضافة أكواد'),
        content: TextField(
          controller: secrets,
          maxLines: 6,
          decoration: const InputDecoration(hintText: 'سطر لكل كود'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('استيراد'),
          ),
        ],
      ),
    );
    final lines = secrets.text
        .split('\n')
        .map((line) => line.trim())
        .where((line) => line.isNotEmpty)
        .toList();
    secrets.dispose();
    if (ok != true || lines.isEmpty) {
      return;
    }
    try {
      await _repo.importDigitalSecrets(poolId, secrets: lines);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('تمت إضافة الأكواد')),
        );
      }
      await _load();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<String?> _askReservationKey({
    required String title,
    required String action,
  }) async {
    final key = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(title),
        content: TextField(
          key: const Key('digital-reservation-key'),
          controller: key,
          decoration: const InputDecoration(
            labelText: 'مفتاح الحجز',
            hintText: 'reservation_key',
          ),
        ),
        actions: [
          TextButton(
            key: const Key('digital-reservation-cancel'),
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            key: const Key('digital-reservation-confirm'),
            onPressed: () => Navigator.pop(dialogContext, true),
            child: Text(action),
          ),
        ],
      ),
    );
    final value = key.text.trim();
    Future<void>.delayed(Duration.zero, key.dispose);
    if (ok != true || value.isEmpty) {
      return null;
    }
    return value;
  }

  Future<void> _reserve(int poolId) async {
    final reservationKey = await _askReservationKey(
      title: 'حجز وحدة رقمية',
      action: 'حجز',
    );
    if (reservationKey == null) {
      return;
    }
    try {
      await _repo.reserveDigitalUnit(poolId, reservationKey: reservationKey);
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('تم حجز الوحدة الرقمية.')));
      }
      await _load();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _deletePool(int poolId) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('حذف الحوض'),
        content: const Text(
          'سيتم حذف الحوض وأكواده غير المحجوزة. لا يمكن التراجع عن هذا الإجراء.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('حذف'),
          ),
        ],
      ),
    );
    if (ok != true) {
      return;
    }
    try {
      await _repo.deleteDigitalPool(poolId);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('تم حذف الحوض')),
        );
      }
      await _load();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _openUnits(int poolId, String poolName) async {
    if (!mounted) {
      return;
    }
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (sheetContext) => _DigitalUnitsSheet(
        poolId: poolId,
        poolName: poolName,
        repository: _repo,
        onChanged: _load,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final muted = HasimTokens.mutedColor(context);
    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: widget.embedded ? null : AppBar(title: const Text('المخزون الرقمي')),
      floatingActionButton: FloatingActionButton(
        onPressed: _addPool,
        tooltip: 'إضافة حوض',
        child: const Icon(Icons.add),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
          ? Center(child: Text(_error!))
          : _pools.isEmpty
          ? const Center(child: Text('لا يوجد مخزون رقمي'))
          : ListView.separated(
              itemCount: _pools.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (context, index) {
                final pool = _pools[index];
                final id = (pool['id'] as num?)?.toInt() ?? 0;
                final available = pool['available_units_count'];
                final name = pool['name']?.toString() ?? 'حوض';
                return ListTile(
                  title: Text(name),
                  subtitle: Text(
                    available == null ? 'أكواد أو تراخيص' : 'المتاح: $available',
                  ),
                  onTap: id == 0 ? null : () => _openUnits(id, name),
                  trailing: PopupMenuButton<String>(
                    tooltip: 'خيارات الحوض',
                    enabled: id != 0,
                    icon: Icon(Icons.more_vert, color: muted),
                    onSelected: (value) {
                      switch (value) {
                        case 'import':
                          _import(id);
                        case 'reserve':
                          _reserve(id);
                        case 'units':
                          _openUnits(id, name);
                        case 'delete':
                          _deletePool(id);
                      }
                    },
                    itemBuilder: (context) => const [
                      PopupMenuItem(value: 'import', child: Text('إضافة أكواد')),
                      PopupMenuItem(value: 'reserve', child: Text('حجز وحدة')),
                      PopupMenuItem(value: 'units', child: Text('عرض الأكواد')),
                      PopupMenuItem(value: 'delete', child: Text('حذف الحوض')),
                    ],
                  ),
                );
              },
            ),
    );
  }
}

class _DigitalUnitsSheet extends StatefulWidget {
  const _DigitalUnitsSheet({
    required this.poolId,
    required this.poolName,
    required this.repository,
    required this.onChanged,
  });

  final int poolId;
  final String poolName;
  final ChatCatalogRepository repository;
  final Future<void> Function() onChanged;

  @override
  State<_DigitalUnitsSheet> createState() => _DigitalUnitsSheetState();
}

class _DigitalUnitsSheetState extends State<_DigitalUnitsSheet> {
  List<Map<String, dynamic>> _units = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final units = await widget.repository.digitalUnits(widget.poolId);
      if (!mounted) {
        return;
      }
      setState(() {
        _units = units;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) {
        return;
      }
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) {
        return;
      }
      setState(() {
        _error = 'تعذر تحميل الأكواد.';
        _loading = false;
      });
    }
  }

  Future<void> _confirmAndRun({
    required String title,
    required String body,
    required String action,
    required Future<void> Function() run,
  }) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(title),
        content: Text(body),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: Text(action),
          ),
        ],
      ),
    );
    if (ok != true) {
      return;
    }
    try {
      await run();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(title)));
      }
      await _load();
      await widget.onChanged();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<String?> _askReservationKey({
    required String title,
    required String action,
  }) async {
    final key = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(title),
        content: TextField(
          key: const Key('digital-reservation-key'),
          controller: key,
          decoration: const InputDecoration(
            labelText: 'مفتاح الحجز',
            hintText: 'reservation_key',
          ),
        ),
        actions: [
          TextButton(
            key: const Key('digital-reservation-cancel'),
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            key: const Key('digital-reservation-confirm'),
            onPressed: () => Navigator.pop(dialogContext, true),
            child: Text(action),
          ),
        ],
      ),
    );
    final value = key.text.trim();
    Future<void>.delayed(Duration.zero, key.dispose);
    if (ok != true || value.isEmpty) {
      return null;
    }
    return value;
  }

  Future<void> _release(int unitId) async {
    final reservationKey = await _askReservationKey(
      title: 'إلغاء حجز الوحدة',
      action: 'إلغاء الحجز',
    );
    if (reservationKey == null) {
      return;
    }
    try {
      await widget.repository.releaseDigitalUnit(
        unitId,
        reservationKey: reservationKey,
      );
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('تم إلغاء حجز الوحدة.')));
      }
      await _load();
      await widget.onChanged();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _consume(int unitId) async {
    final reservationKey = await _askReservationKey(
      title: 'استهلاك الوحدة',
      action: 'استهلاك',
    );
    if (reservationKey == null) {
      return;
    }
    try {
      await widget.repository.consumeDigitalUnit(
        unitId,
        reservationKey: reservationKey,
      );
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('تم استهلاك الوحدة.')));
      }
      await _load();
      await widget.onChanged();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  Future<void> _replace(int unitId) async {
    final secret = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('استبدال الكود'),
        content: TextField(
          controller: secret,
          maxLines: 3,
          decoration: const InputDecoration(hintText: 'الكود الجديد'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('استبدال'),
          ),
        ],
      ),
    );
    final value = secret.text.trim();
    secret.dispose();
    if (ok != true || value.isEmpty) {
      return;
    }
    try {
      await widget.repository.replaceDigitalSecret(unitId, secret: value);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('تم استبدال الكود')),
        );
      }
      await _load();
      await widget.onChanged();
    } on ApiException catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    }
  }

  String _statusLabel(String? status) {
    return switch (status) {
      'available' => 'متاح',
      'reserved' => 'محجوز',
      'delivered' => 'تم التسليم',
      'consumed' => 'مستهلك',
      'expired' => 'منتهي',
      'revoked' => 'ملغى',
      'disabled' => 'معطّل',
      _ => status ?? 'غير معروف',
    };
  }

  @override
  Widget build(BuildContext context) {
    final muted = HasimTokens.mutedColor(context);
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
        child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.7,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
              child: Align(
                alignment: AlignmentDirectional.centerStart,
                child: Text(
                  widget.poolName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
            ),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : _error != null
                  ? Center(child: Text(_error!))
                  : _units.isEmpty
                  ? const Center(child: Text('لا توجد أكواد في هذا الحوض'))
                  : ListView.separated(
                      itemCount: _units.length,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (context, index) {
                        final unit = _units[index];
                        final id = (unit['id'] as num?)?.toInt() ?? 0;
                        final status = unit['status']?.toString();
                        final type = unit['unit_type']?.toString() ?? 'code';
                        final canManage = status == 'available';
                        final canReservationActions = status == 'reserved';
                        final canDelete = const {
                          'available',
                          'disabled',
                          'expired',
                          'revoked',
                        }.contains(status);
                        return ListTile(
                          title: Text('وحدة #$id'),
                          subtitle: Text('${_statusLabel(status)} · $type'),
                          trailing: PopupMenuButton<String>(
                            tooltip: 'خيارات الوحدة',
                            enabled: id != 0 &&
                                (canManage || canReservationActions || canDelete),
                            icon: Icon(Icons.more_vert, color: muted),
                            onSelected: (value) {
                              switch (value) {
                                case 'replace':
                                  _replace(id);
                                case 'disable':
                                  _confirmAndRun(
                                    title: 'تعطيل الوحدة',
                                    body: 'لن تُباع هذه الوحدة حتى تُستبدل أو تُحذف.',
                                    action: 'تعطيل',
                                    run: () => widget.repository
                                        .updateDigitalUnitStatus(
                                          id,
                                          status: 'disabled',
                                        ),
                                  );
                                case 'revoke':
                                  _confirmAndRun(
                                    title: 'إلغاء الوحدة',
                                    body: 'سيتم إلغاء هذه الوحدة ولن تعود للبيع.',
                                    action: 'إلغاء',
                                    run: () => widget.repository
                                        .updateDigitalUnitStatus(
                                          id,
                                          status: 'revoked',
                                        ),
                                  );
                                case 'release':
                                  _release(id);
                                case 'consume':
                                  _consume(id);
                                case 'delete':
                                  _confirmAndRun(
                                    title: 'حذف الوحدة',
                                    body: 'سيتم حذف هذه الوحدة نهائياً.',
                                    action: 'حذف',
                                    run: () => widget.repository.deleteDigitalUnit(id),
                                  );
                              }
                            },
                            itemBuilder: (context) => [
                              if (canManage)
                                const PopupMenuItem(
                                  value: 'replace',
                                  child: Text('استبدال الكود'),
                                ),
                              if (canManage)
                                const PopupMenuItem(
                                  value: 'disable',
                                  child: Text('تعطيل'),
                                ),
                              if (canManage)
                                const PopupMenuItem(
                                  value: 'revoke',
                                  child: Text('إلغاء'),
                                ),
                              if (canReservationActions)
                                const PopupMenuItem(
                                  value: 'release',
                                  child: Text('إلغاء الحجز'),
                                ),
                              if (canReservationActions)
                                const PopupMenuItem(
                                  value: 'consume',
                                  child: Text('استهلاك'),
                                ),
                              if (canDelete)
                                const PopupMenuItem(
                                  value: 'delete',
                                  child: Text('حذف'),
                                ),
                            ],
                          ),
                        );
                      },
                    ),
            ),
          ],
        ),
        ),
      ),
    );
  }
}
