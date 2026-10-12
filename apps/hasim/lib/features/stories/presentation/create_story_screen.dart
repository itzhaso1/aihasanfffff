import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:image_picker/image_picker.dart';

/// إنشاء تحديث — يستخدم Story API الحالية.
class CreateStoryScreen extends ConsumerStatefulWidget {
  const CreateStoryScreen({super.key});

  @override
  ConsumerState<CreateStoryScreen> createState() => _CreateStoryScreenState();
}

enum _CreateMode { choose, text, image, video }

class _CreateStoryScreenState extends ConsumerState<CreateStoryScreen> {
  _CreateMode _mode = _CreateMode.choose;
  final _text = TextEditingController();
  final _caption = TextEditingController();
  Color _bg = const Color(0xFF067E6B);
  XFile? _media;
  bool _publishing = false;
  double _uploadProgress = 0;
  static const _expiresInHours = 24;

  static const _colors = [
    Color(0xFF067E6B),
    Color(0xFF0F172A),
    Color(0xFF1D4ED8),
    Color(0xFFB45309),
    Color(0xFFBE123C),
    Color(0xFF7C3AED),
    Color(0xFF0E7490),
  ];

  @override
  void dispose() {
    _text.dispose();
    _caption.dispose();
    super.dispose();
  }

  Future<void> _pickImage({bool replace = false}) async {
    final file = await ImagePicker().pickImage(source: ImageSource.gallery, imageQuality: 85);
    if (file == null) return;
    setState(() {
      _media = file;
      _mode = _CreateMode.image;
    });
  }

  Future<void> _pickVideo({bool replace = false}) async {
    final file = await ImagePicker().pickVideo(source: ImageSource.gallery);
    if (file == null) return;
    setState(() {
      _media = file;
      _mode = _CreateMode.video;
    });
  }

  void _removeMedia() => setState(() => _media = null);

  String _hex(Color c) {
    final v = c.toARGB32().toRadixString(16).padLeft(8, '0');
    return '#${v.substring(2)}';
  }

  Future<void> _publish() async {
    final type = switch (_mode) {
      _CreateMode.text => 'text',
      _CreateMode.image => 'image',
      _CreateMode.video => 'video',
      _CreateMode.choose => null,
    };
    if (type == null) return;

    if (type == 'text' && _text.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('اكتب نص التحديث.')));
      return;
    }
    if (type != 'text' && _media == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('اختر ملفًا للتحديث.')));
      return;
    }

    setState(() {
      _publishing = true;
      _uploadProgress = 0;
    });
    try {
      await ref.read(storyRepositoryProvider).create(
            type: type,
            bodyText: type == 'text' ? _text.text.trim() : null,
            caption: _caption.text.trim().isEmpty ? null : _caption.text.trim(),
            backgroundColor: type == 'text' ? _hex(_bg) : null,
            visibility: 'workspace',
            expiresInHours: _expiresInHours,
            file: _media == null ? null : File(_media!.path),
            onSendProgress: (sent, total) {
              if (!mounted || total <= 0) return;
              setState(() => _uploadProgress = sent / total);
            },
          );
      await ref.read(storiesControllerProvider.notifier).refresh(force: true);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم نشر التحديث')));
        context.pop();
      }
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تعذر نشر التحديث')));
    } finally {
      if (mounted) setState(() => _publishing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('إنشاء تحديث'),
        actions: [
          if (_mode != _CreateMode.choose)
            TextButton(
              onPressed: _publishing ? null : _publish,
              child: Text(_publishing ? '...' : 'نشر'),
            ),
        ],
      ),
      body: Column(
        children: [
          if (_publishing) LinearProgressIndicator(value: _uploadProgress > 0 ? _uploadProgress : null),
          Expanded(
            child: switch (_mode) {
              _CreateMode.choose => _ChooseType(
                  onText: () => setState(() => _mode = _CreateMode.text),
                  onImage: () => _pickImage(),
                  onVideo: () => _pickVideo(),
                ),
              _CreateMode.text => _TextEditor(
                  controller: _text,
                  bg: _bg,
                  colors: _colors,
                  onColor: (c) => setState(() => _bg = c),
                  onBack: () => setState(() => _mode = _CreateMode.choose),
                ),
              _CreateMode.image => _MediaEditor(
                  isVideo: false,
                  media: _media,
                  caption: _caption,
                  onPick: () => _pickImage(replace: true),
                  onRemove: _removeMedia,
                  onBack: () => setState(() {
                    _media = null;
                    _mode = _CreateMode.choose;
                  }),
                ),
              _CreateMode.video => _MediaEditor(
                  isVideo: true,
                  media: _media,
                  caption: _caption,
                  onPick: () => _pickVideo(replace: true),
                  onRemove: _removeMedia,
                  onBack: () => setState(() {
                    _media = null;
                    _mode = _CreateMode.choose;
                  }),
                ),
            },
          ),
          if (_mode != _CreateMode.choose)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
              child: Text(
                'الظهور: مساحة العمل · ينتهي خلال $_expiresInHours ساعة (حسب الخادم)',
                style: TextStyle(fontSize: 12, color: Theme.of(context).colorScheme.onSurfaceVariant),
              ),
            ),
        ],
      ),
    );
  }
}

class _ChooseType extends StatelessWidget {
  const _ChooseType({required this.onText, required this.onImage, required this.onVideo});

  final VoidCallback onText;
  final VoidCallback onImage;
  final VoidCallback onVideo;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Text('اختر نوع التحديث', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
        const SizedBox(height: 16),
        _TypeTile(icon: Icons.image_outlined, title: 'صورة', onTap: onImage),
        _TypeTile(icon: Icons.videocam_outlined, title: 'فيديو', onTap: onVideo),
        _TypeTile(icon: Icons.edit_outlined, title: 'نص', onTap: onText),
      ],
    );
  }
}

class _TypeTile extends StatelessWidget {
  const _TypeTile({required this.icon, required this.title, required this.onTap});

  final IconData icon;
  final String title;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
      leading: CircleAvatar(
        backgroundColor: theme.colorScheme.primary.withValues(alpha: 0.12),
        child: Icon(icon, color: theme.colorScheme.primary),
      ),
      title: Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
      trailing: const Icon(Icons.chevron_left),
      onTap: onTap,
    );
  }
}

class _TextEditor extends StatelessWidget {
  const _TextEditor({
    required this.controller,
    required this.bg,
    required this.colors,
    required this.onColor,
    required this.onBack,
  });

  final TextEditingController controller;
  final Color bg;
  final List<Color> colors;
  final ValueChanged<Color> onColor;
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: TextButton.icon(onPressed: onBack, icon: const Icon(Icons.arrow_forward), label: const Text('رجوع')),
        ),
        Container(
          height: 280,
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(16)),
          child: TextField(
            controller: controller,
            maxLines: null,
            expands: true,
            textAlign: TextAlign.center,
            style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800),
            decoration: const InputDecoration(
              border: InputBorder.none,
              filled: false,
              hintText: 'اكتب هنا...',
              hintStyle: TextStyle(color: Colors.white54),
            ),
          ),
        ),
        const SizedBox(height: 14),
        Wrap(
          spacing: 10,
          children: [
            for (final c in colors)
              GestureDetector(
                onTap: () => onColor(c),
                child: Container(
                  width: 34,
                  height: 34,
                  decoration: BoxDecoration(
                    color: c,
                    shape: BoxShape.circle,
                    border: Border.all(color: c == bg ? AppTheme.brand : Colors.transparent, width: 2.5),
                  ),
                ),
              ),
          ],
        ),
      ],
    );
  }
}

class _MediaEditor extends StatelessWidget {
  const _MediaEditor({
    required this.isVideo,
    required this.media,
    required this.caption,
    required this.onPick,
    required this.onRemove,
    required this.onBack,
  });

  final bool isVideo;
  final XFile? media;
  final TextEditingController caption;
  final VoidCallback onPick;
  final VoidCallback onRemove;
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: TextButton.icon(onPressed: onBack, icon: const Icon(Icons.arrow_forward), label: const Text('رجوع')),
        ),
        AspectRatio(
          aspectRatio: 9 / 12,
          child: Material(
            color: Colors.black12,
            borderRadius: BorderRadius.circular(16),
            child: InkWell(
              onTap: onPick,
              borderRadius: BorderRadius.circular(16),
              child: media == null
                  ? Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(isVideo ? Icons.videocam_outlined : Icons.image_outlined, size: 40),
                          const SizedBox(height: 8),
                          Text(isVideo ? 'اختر فيديو' : 'اختر صورة'),
                        ],
                      ),
                    )
                  : isVideo
                      ? Center(
                          child: Padding(
                            padding: const EdgeInsets.all(16),
                            child: Text(media!.name, textAlign: TextAlign.center),
                          ),
                        )
                      : ClipRRect(
                          borderRadius: BorderRadius.circular(16),
                          child: Image.file(File(media!.path), fit: BoxFit.cover, width: double.infinity),
                        ),
            ),
          ),
        ),
        const SizedBox(height: 12),
        if (media != null)
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: onPick,
                  icon: const Icon(Icons.swap_horiz),
                  label: const Text('استبدال'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: onRemove,
                  icon: const Icon(Icons.delete_outline),
                  label: const Text('إزالة'),
                ),
              ),
            ],
          ),
        const SizedBox(height: 12),
        TextField(controller: caption, decoration: const InputDecoration(labelText: 'تعليق (اختياري)')),
      ],
    );
  }
}
