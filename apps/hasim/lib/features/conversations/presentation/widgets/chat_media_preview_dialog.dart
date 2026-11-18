import 'dart:io';

import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:image_picker/image_picker.dart';

/// نتيجة معاينة الوسائط قبل الإرسال
class ChatMediaPreviewResult {
  const ChatMediaPreviewResult({required this.files, required this.caption});

  final List<File> files;
  final String caption;
}

/// شاشة/نافذة معاينة الوسائط المتعددة قبل الإرسال بأسلوب واتساب.
class ChatMediaPreviewDialog extends StatefulWidget {
  const ChatMediaPreviewDialog({
    super.key,
    required this.initialFiles,
    this.initialCaption = '',
  });

  final List<File> initialFiles;
  final String initialCaption;

  static Future<ChatMediaPreviewResult?> show(
    BuildContext context, {
    required List<File> files,
    String initialCaption = '',
  }) {
    return Navigator.of(context).push<ChatMediaPreviewResult>(
      PageRouteBuilder<ChatMediaPreviewResult>(
        opaque: true,
        barrierDismissible: false,
        pageBuilder: (context, _, _) => ChatMediaPreviewDialog(
          initialFiles: files,
          initialCaption: initialCaption,
        ),
        transitionsBuilder: (context, animation, _, child) {
          return FadeTransition(opacity: animation, child: child);
        },
      ),
    );
  }

  @override
  State<ChatMediaPreviewDialog> createState() => _ChatMediaPreviewDialogState();
}

class _ChatMediaPreviewDialogState extends State<ChatMediaPreviewDialog> {
  late List<File> _files;
  int _activeIndex = 0;
  late final TextEditingController _captionController;
  final _captionFocus = FocusNode();

  @override
  void initState() {
    super.initState();
    _files = List<File>.from(widget.initialFiles);
    _captionController = TextEditingController(text: widget.initialCaption);
  }

  @override
  void dispose() {
    _captionController.dispose();
    _captionFocus.dispose();
    super.dispose();
  }

  bool _isImage(File file) {
    final path = file.path.toLowerCase();
    return path.endsWith('.jpg') ||
        path.endsWith('.jpeg') ||
        path.endsWith('.png') ||
        path.endsWith('.webp') ||
        path.endsWith('.gif') ||
        path.endsWith('.heic') ||
        path.endsWith('.heif');
  }

  void _removeCurrent() {
    if (_files.isEmpty) return;
    setState(() {
      _files.removeAt(_activeIndex);
      if (_activeIndex >= _files.length) {
        _activeIndex = (_files.length - 1).clamp(0, _files.length);
      }
    });
    if (_files.isEmpty) {
      Navigator.of(context).pop(null);
    }
  }

  Future<void> _addMoreMedia() async {
    try {
      final picker = ImagePicker();
      final picked = await picker.pickMultiImage(imageQuality: 85);
      if (picked.isNotEmpty && mounted) {
        setState(() {
          _files.addAll(picked.map((x) => File(x.path)));
          _activeIndex = _files.length - 1;
        });
      }
    } catch (_) {}
  }

  void _submit() {
    if (_files.isEmpty) {
      Navigator.of(context).pop(null);
      return;
    }
    Navigator.of(context).pop(
      ChatMediaPreviewResult(
        files: _files,
        caption: _captionController.text.trim(),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_files.isEmpty) {
      return const SizedBox.shrink();
    }
    final activeFile = _files[_activeIndex];
    final isImg = _isImage(activeFile);
    final fileName = activeFile.uri.pathSegments.isNotEmpty
        ? activeFile.uri.pathSegments.last
        : 'ملف';

    return Scaffold(
      backgroundColor: const Color(0xFF0F1416),
      resizeToAvoidBottomInset: true,
      body: SafeArea(
        child: Column(
          children: [
            // Top Bar
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              child: Row(
                children: [
                  IconButton(
                    icon: const Icon(
                      Icons.close_rounded,
                      color: Colors.white,
                      size: 24,
                    ),
                    tooltip: 'إلغاء',
                    onPressed: () => Navigator.of(context).pop(null),
                  ),
                  const Spacer(),
                  if (_files.length > 1)
                    Text(
                      '${_activeIndex + 1} من ${_files.length}',
                      style: const TextStyle(
                        color: Colors.white70,
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  const Spacer(),
                  IconButton(
                    icon: const Icon(
                      Icons.delete_outline_rounded,
                      color: Colors.white,
                      size: 24,
                    ),
                    tooltip: 'حذف هذه الصورة',
                    onPressed: _removeCurrent,
                  ),
                ],
              ),
            ),

            // Main Media View
            Expanded(
              child: Center(
                child: isImg
                    ? InteractiveViewer(
                        minScale: 0.8,
                        maxScale: 3.5,
                        child: Image.file(
                          activeFile,
                          fit: BoxFit.contain,
                          errorBuilder: (_, _, _) => _errorPlaceholder(),
                        ),
                      )
                    : _filePlaceholder(fileName, activeFile),
              ),
            ),

            // Bottom Section: Thumbnails Strip + Caption + Send
            Container(
              color: const Color(0xFF131A1C),
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 10),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // Thumbnails carousel if multiple files or for adding more
                  SizedBox(
                    height: 58,
                    child: Row(
                      children: [
                        Expanded(
                          child: ListView.separated(
                            scrollDirection: Axis.horizontal,
                            itemCount: _files.length,
                            separatorBuilder: (_, _) =>
                                const SizedBox(width: 8),
                            itemBuilder: (context, index) {
                              final f = _files[index];
                              final isCurrent = index == _activeIndex;
                              final isThumbImg = _isImage(f);

                              return GestureDetector(
                                onTap: () =>
                                    setState(() => _activeIndex = index),
                                child: Container(
                                  width: 48,
                                  height: 48,
                                  decoration: BoxDecoration(
                                    borderRadius: BorderRadius.circular(8),
                                    border: Border.all(
                                      color: isCurrent
                                          ? HasimTokens.chatGreen
                                          : Colors.transparent,
                                      width: 2.2,
                                    ),
                                  ),
                                  child: ClipRRect(
                                    borderRadius: BorderRadius.circular(6),
                                    child: isThumbImg
                                        ? Image.file(
                                            f,
                                            fit: BoxFit.cover,
                                            errorBuilder: (_, _, _) =>
                                                _errorPlaceholder(),
                                          )
                                        : Container(
                                            color: Colors.white12,
                                            child: const Icon(
                                              Icons.insert_drive_file_outlined,
                                              color: Colors.white70,
                                              size: 20,
                                            ),
                                          ),
                                  ),
                                ),
                              );
                            },
                          ),
                        ),
                        const SizedBox(width: 8),
                        // Add more button
                        IconButton(
                          style: IconButton.styleFrom(
                            backgroundColor: Colors.white12,
                            padding: const EdgeInsets.all(10),
                          ),
                          icon: const Icon(
                            Icons.add_photo_alternate_outlined,
                            color: Colors.white,
                            size: 22,
                          ),
                          tooltip: 'إضافة المزيد',
                          onPressed: _addMoreMedia,
                        ),
                      ],
                    ),
                  ),

                  const SizedBox(height: 10),

                  // Caption Input & Send Button
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      // Caption Field
                      Expanded(
                        child: Container(
                          decoration: BoxDecoration(
                            color: const Color(0xFF222C30),
                            borderRadius: BorderRadius.circular(24),
                          ),
                          padding: const EdgeInsets.symmetric(horizontal: 14),
                          child: Directionality(
                            textDirection: TextDirection.rtl,
                            child: TextField(
                              controller: _captionController,
                              focusNode: _captionFocus,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 14.5,
                              ),
                              minLines: 1,
                              maxLines: 4,
                              decoration: const InputDecoration(
                                hintText: 'إضافة شرح أو تعليق...',
                                hintStyle: TextStyle(
                                  color: Colors.white38,
                                  fontSize: 14,
                                ),
                                border: InputBorder.none,
                                isDense: true,
                                contentPadding: EdgeInsets.symmetric(
                                  vertical: 12,
                                ),
                              ),
                            ),
                          ),
                        ),
                      ),

                      const SizedBox(width: 8),

                      // Send Button
                      Material(
                        color: HasimTokens.chatGreen,
                        shape: const CircleBorder(),
                        clipBehavior: Clip.antiAlias,
                        child: InkWell(
                          key: const Key('chat-preview-send-btn'),
                          onTap: _submit,
                          child: const SizedBox(
                            width: 48,
                            height: 48,
                            child: Icon(
                              Icons.send_rounded,
                              color: Colors.white,
                              size: 22,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _filePlaceholder(String fileName, File file) {
    final size = file.existsSync() ? file.lengthSync() : 0;
    final sizeStr = size > 1024 * 1024
        ? '${(size / (1024 * 1024)).toStringAsFixed(1)} MB'
        : '${(size / 1024).toStringAsFixed(1)} KB';

    return Container(
      margin: const EdgeInsets.all(24),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: const Color(0xFF1E282B),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(
            Icons.insert_drive_file_outlined,
            size: 56,
            color: Color(0xFF00BFAE),
          ),
          const SizedBox(height: 12),
          Text(
            fileName,
            textAlign: TextAlign.center,
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.bold,
              fontSize: 15,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            sizeStr,
            style: const TextStyle(color: Colors.white54, fontSize: 13),
          ),
        ],
      ),
    );
  }

  Widget _errorPlaceholder() {
    return const Icon(
      Icons.broken_image_outlined,
      size: 48,
      color: Colors.white38,
    );
  }
}
