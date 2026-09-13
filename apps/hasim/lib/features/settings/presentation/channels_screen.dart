import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:url_launcher/url_launcher.dart';

class ChannelsScreen extends ConsumerStatefulWidget {
  const ChannelsScreen({super.key});

  @override
  ConsumerState<ChannelsScreen> createState() => _ChannelsScreenState();
}

class _ChannelsScreenState extends ConsumerState<ChannelsScreen> {
  List<ChannelModel> _items = [];
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
      final items = await ref.read(channelRepositoryProvider).list();
      if (!mounted) return;
      setState(() {
        _items = items;
        _loading = false;
      });
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (_) {
      setState(() {
        _error = 'تعذر تحميل القنوات.';
        _loading = false;
      });
    }
  }

  Future<void> _openManage(ChannelModel channel) async {
    final url = channel.manageUrl;
    if (url == null || url.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('لا يوجد رابط إدارة لهذه القناة.')),
      );
      return;
    }
    final uri = Uri.tryParse(url);
    if (uri == null) return;
    final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!ok && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تعذر فتح رابط الإدارة.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('القنوات')),
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
                  child: ListView.separated(
                    padding: const EdgeInsets.all(16),
                    itemCount: _items.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, index) {
                      final c = _items[index];
                      final connected = c.connected || c.status == 'connected';
                      return ListTile(
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(14),
                          side: BorderSide(color: Colors.grey.shade300),
                        ),
                        leading: CircleAvatar(
                          backgroundColor: connected
                              ? Theme.of(context).colorScheme.primary.withValues(alpha: 0.15)
                              : Colors.grey.shade200,
                          child: Icon(
                            connected ? Icons.link : Icons.link_off,
                            color: connected ? Theme.of(context).colorScheme.primary : Colors.grey,
                          ),
                        ),
                        title: Text(c.name),
                        subtitle: Text([c.statusLabel ?? c.status, if (c.hint != null) c.hint!].join(' · ')),
                        trailing: TextButton(
                          onPressed: () => _openManage(c),
                          child: const Text('إدارة'),
                        ),
                      );
                    },
                  ),
                ),
    );
  }
}
