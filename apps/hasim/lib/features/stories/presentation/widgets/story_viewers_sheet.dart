import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/app_theme.dart';

class StoryViewersSheet extends ConsumerStatefulWidget {
  const StoryViewersSheet({super.key, required this.storyId});

  final int storyId;

  @override
  ConsumerState<StoryViewersSheet> createState() => _StoryViewersSheetState();
}

class _StoryViewersSheetState extends ConsumerState<StoryViewersSheet> {
  bool _loading = true;
  String? _error;
  List<StoryViewerEntry> _viewers = const [];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final viewers = await ref
          .read(storyRepositoryProvider)
          .viewers(widget.storyId);
      if (!mounted) {
        return;
      }
      setState(() {
        _viewers = viewers;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted) {
        return;
      }
      setState(() {
        _error = e.userMessage;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) {
        return;
      }
      setState(() {
        _error = 'تعذر تحميل المشاهدين.';
        _loading = false;
      });
    }
  }

  String _formatViewedAt(DateTime? at) {
    if (at == null) {
      return '';
    }
    final local = at.toLocal();
    String two(int n) => n.toString().padLeft(2, '0');
    return '${local.year}-${two(local.month)}-${two(local.day)} ${two(local.hour)}:${two(local.minute)}';
  }

  @override
  Widget build(BuildContext context) {
    final height = MediaQuery.sizeOf(context).height;
    return SafeArea(
      child: SizedBox(
        height: height * 0.55,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 12),
              child: Text(
                'المشاهدون',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
            Expanded(child: _body()),
          ],
        ),
      ),
    );
  }

  Widget _body() {
    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(key: Key('story-viewers-loading')),
      );
    }
    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                _error!,
                key: const Key('story-viewers-error'),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 12),
              TextButton(onPressed: _load, child: const Text('إعادة المحاولة')),
            ],
          ),
        ),
      );
    }
    if (_viewers.isEmpty) {
      return const Center(
        child: Text(
          'لا يوجد مشاهدون بعد',
          key: Key('story-viewers-empty'),
        ),
      );
    }
    return ListView.separated(
      key: const Key('story-viewers-list'),
      padding: const EdgeInsets.fromLTRB(8, 0, 8, 16),
      itemCount: _viewers.length,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        final viewer = _viewers[index];
        final name = viewer.name.trim().isEmpty ? 'عضو' : viewer.name.trim();
        final avatar = viewer.avatarUrl;
        final hasHttp = avatar != null && avatar.startsWith('http');
        return ListTile(
          leading: CircleAvatar(
            backgroundColor: AppTheme.brand.withValues(alpha: 0.12),
            backgroundImage: hasHttp ? CachedNetworkImageProvider(avatar) : null,
            child: hasHttp
                ? null
                : Text(
                    name[0],
                    style: const TextStyle(
                      fontWeight: FontWeight.w800,
                      color: AppTheme.brandDark,
                    ),
                  ),
          ),
          title: Text(name, style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: viewer.viewedAt == null
              ? null
              : Text(_formatViewedAt(viewer.viewedAt)),
        );
      },
    );
  }
}
