import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';

class PlansScreen extends ConsumerStatefulWidget {
  const PlansScreen({super.key});

  @override
  ConsumerState<PlansScreen> createState() => _PlansScreenState();
}

class _PlansScreenState extends ConsumerState<PlansScreen> {
  PlanSnapshot? _current;
  PlansCatalog? _catalog;
  String? _error;
  bool _loading = true;

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
      final current = await ref.read(planRepositoryProvider).current();
      final catalog = await ref.read(planRepositoryProvider).catalog();
      if (!mounted) return;
      setState(() {
        _current = current;
        _catalog = catalog;
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (_) {
      setState(() {
        _error = 'تعذر تحميل الباقات.';
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('الباقات والاستخدام')),
      body: _loading
          ? const SkeletonList()
          : _error != null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(_error!),
                      TextButton(onPressed: _load, child: const Text('إعادة المحاولة')),
                    ],
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      Text('الباقة الحالية', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                      const SizedBox(height: 8),
                      Text('الميزات: ${_current!.features.isEmpty ? '—' : _current!.features.join(' · ')}'),
                      const SizedBox(height: 16),
                      Text('الاستخدام', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                      const SizedBox(height: 8),
                      if (_current!.meters.isEmpty)
                        const Text('لا توجد عدّادات استخدام.')
                      else
                        ..._current!.meters.entries.map((e) {
                          final value = e.value;
                          String subtitle = value.toString();
                          if (value is Map) {
                            final used = value['used'] ?? value['current'] ?? value['count'];
                            final limit = value['limit'] ?? value['max'];
                            subtitle = '${used ?? '—'} / ${limit ?? '∞'}';
                          }
                          return ListTile(
                            contentPadding: EdgeInsets.zero,
                            title: Text(e.key),
                            subtitle: Text(subtitle),
                          );
                        }),
                      const Divider(height: 32),
                      Text('مقارنة الباقات', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                      const SizedBox(height: 8),
                      ...?_catalog?.plans.map(
                        (p) => Card(
                          child: ListTile(
                            title: Text(p.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                            subtitle: Text(
                              [
                                if (p.description != null) p.description!,
                                if (p.price != null) '${p.price} ${p.currency ?? ''}',
                                if (p.features.isNotEmpty) p.features.take(4).join(' · '),
                              ].join('\n'),
                            ),
                            isThreeLine: true,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
    );
  }
}
