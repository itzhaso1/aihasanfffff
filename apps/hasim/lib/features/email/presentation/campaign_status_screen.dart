import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';

class CampaignStatusScreen extends ConsumerStatefulWidget {
  const CampaignStatusScreen({super.key, required this.campaignId});

  final int campaignId;

  @override
  ConsumerState<CampaignStatusScreen> createState() => _CampaignStatusScreenState();
}

class _CampaignStatusScreenState extends ConsumerState<CampaignStatusScreen> {
  EmailCampaignModel? _campaign;
  String? _error;
  bool _loading = true;
  bool _cancelling = false;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  void _schedulePoll() {
    _timer?.cancel();
    final c = _campaign;
    if (c == null || c.isTerminal) return;
    _timer = Timer(const Duration(seconds: 3), _load);
  }

  Future<void> _load() async {
    try {
      final campaign = await ref.read(campaignRepositoryProvider).show(widget.campaignId);
      if (!mounted) return;
      setState(() {
        _campaign = campaign;
        _loading = false;
        _error = null;
      });
      _schedulePoll();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = 'تعذر تحميل حالة الحملة.';
        _loading = false;
      });
    }
  }

  Future<void> _cancel() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('إلغاء الحملة؟'),
        content: const Text('سيتم إيقاف الإرسال للمستلمين المتبقين.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('رجوع')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('إلغاء الحملة')),
        ],
      ),
    );
    if (ok != true) return;
    setState(() => _cancelling = true);
    try {
      final updated = await ref.read(campaignRepositoryProvider).cancel(widget.campaignId);
      if (!mounted) return;
      setState(() => _campaign = updated);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _cancelling = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = _campaign;
    final total = c?.recipientCount ?? 0;
    final sent = c?.sentCount ?? 0;
    final failed = c?.failedCount ?? 0;
    final progress = total == 0 ? 0.0 : (sent + failed) / total;

    return Scaffold(
      appBar: AppBar(title: const Text('حالة الحملة')),
      body: _loading && c == null
          ? const SkeletonList(itemCount: 3)
          : _error != null && c == null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(_error!),
                      TextButton(onPressed: _load, child: const Text('إعادة المحاولة')),
                    ],
                  ),
                )
              : ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    Text(
                      c!.subject ?? 'حملة #${c.id}',
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
                    ),
                    const SizedBox(height: 8),
                    Text(c.statusLabel, style: TextStyle(color: Theme.of(context).colorScheme.primary, fontWeight: FontWeight.w700)),
                    if (c.accountEmail != null) ...[
                      const SizedBox(height: 4),
                      Text('من: ${c.accountName ?? ''} · ${c.accountEmail}', style: TextStyle(color: Colors.grey.shade700)),
                    ],
                    const SizedBox(height: 20),
                    LinearProgressIndicator(value: progress.clamp(0, 1), minHeight: 8, borderRadius: BorderRadius.circular(8)),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        _Stat(label: 'الإجمالي', value: '$total'),
                        _Stat(label: 'أُرسل', value: '$sent'),
                        _Stat(label: 'فشل', value: '$failed'),
                      ],
                    ),
                    if (c.pendingCount != null) ...[
                      const SizedBox(height: 8),
                      Text('متبقي: ${c.pendingCount}', style: TextStyle(color: Colors.grey.shade700)),
                    ],
                    if (c.errorMessage != null && c.errorMessage!.isNotEmpty) ...[
                      const SizedBox(height: 16),
                      Text(c.errorMessage!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                    ],
                    const SizedBox(height: 24),
                    if (!c.isTerminal)
                      OutlinedButton(
                        onPressed: _cancelling ? null : _cancel,
                        child: Text(_cancelling ? 'جارٍ الإلغاء...' : 'إلغاء الحملة'),
                      ),
                    TextButton(onPressed: _load, child: const Text('تحديث الآن')),
                  ],
                ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Column(
        children: [
          Text(value, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
          Text(label, style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
        ],
      ),
    );
  }
}
