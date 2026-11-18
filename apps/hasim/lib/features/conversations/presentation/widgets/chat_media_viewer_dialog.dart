import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/media_auth_headers.dart';
import 'package:intl/intl.dart' hide TextDirection;
import 'package:url_launcher/url_launcher.dart';

/// عارض الوسائط الكامل للمحادثة (Fullscreen Lightbox Viewer) بأسلوب واتساب
class ChatMediaViewerDialog extends ConsumerStatefulWidget {
  const ChatMediaViewerDialog({
    super.key,
    required this.attachments,
    this.initialIndex = 0,
    this.senderName,
    this.timestamp,
    this.caption,
  });

  final List<MessageAttachmentModel> attachments;
  final int initialIndex;
  final String? senderName;
  final DateTime? timestamp;
  final String? caption;

  static Future<void> show(
    BuildContext context, {
    required List<MessageAttachmentModel> attachments,
    int initialIndex = 0,
    String? senderName,
    DateTime? timestamp,
    String? caption,
  }) {
    return Navigator.of(context).push<void>(
      PageRouteBuilder<void>(
        opaque: false,
        barrierColor: Colors.black,
        pageBuilder: (context, _, _) => ChatMediaViewerDialog(
          attachments: attachments,
          initialIndex: initialIndex,
          senderName: senderName,
          timestamp: timestamp,
          caption: caption,
        ),
        transitionsBuilder: (context, animation, _, child) {
          return FadeTransition(opacity: animation, child: child);
        },
      ),
    );
  }

  @override
  ConsumerState<ChatMediaViewerDialog> createState() =>
      _ChatMediaViewerDialogState();
}

class _ChatMediaViewerDialogState extends ConsumerState<ChatMediaViewerDialog> {
  late int _currentIndex;
  late final PageController _pageController;
  bool _chromeVisible = true;

  @override
  void initState() {
    super.initState();
    _currentIndex = widget.initialIndex.clamp(0, widget.attachments.length - 1);
    _pageController = PageController(initialPage: _currentIndex);
  }

  @override
  void dispose() {
    _pageController.dispose();
    super.dispose();
  }

  void _toggleChrome() {
    setState(() => _chromeVisible = !_chromeVisible);
  }

  Future<void> _openExternal(String? url) async {
    if (url == null || url.isEmpty) return;
    final uri = Uri.tryParse(url);
    if (uri != null) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (widget.attachments.isEmpty) {
      return const SizedBox.shrink();
    }
    final active = widget.attachments[_currentIndex];
    final headersAsync = ref.watch(mediaAuthHeadersProvider);
    final headers = headersAsync.valueOrNull ?? const <String, String>{};

    String timeLabel = '';
    if (widget.timestamp != null) {
      final local = widget.timestamp!.toLocal();
      timeLabel = DateFormat('d MMM y · h:mm a').format(local);
    }

    return Scaffold(
      backgroundColor: const Color(0xFF0A0E10),
      body: Stack(
        fit: StackFit.expand,
        children: [
          // Media Swiper PageView
          GestureDetector(
            onTap: _toggleChrome,
            child: PageView.builder(
              controller: _pageController,
              itemCount: widget.attachments.length,
              onPageChanged: (index) {
                setState(() => _currentIndex = index);
              },
              itemBuilder: (context, index) {
                final item = widget.attachments[index];
                final url = item.displayUrl;

                if (item.kind == 'image' && url != null && url.isNotEmpty) {
                  return InteractiveViewer(
                    minScale: 0.8,
                    maxScale: 4.0,
                    child: Center(
                      child: CachedNetworkImage(
                        imageUrl: url,
                        httpHeaders: headers,
                        fit: BoxFit.contain,
                        memCacheWidth: 1600,
                        placeholder: (_, _) => const Center(
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Color(0xFF00BFAE),
                          ),
                        ),
                        errorWidget: (_, _, _) => const Icon(
                          Icons.broken_image_outlined,
                          size: 64,
                          color: Colors.white38,
                        ),
                      ),
                    ),
                  );
                }

                // Document or non-image
                return Center(
                  child: Container(
                    padding: const EdgeInsets.all(24),
                    margin: const EdgeInsets.all(32),
                    decoration: BoxDecoration(
                      color: const Color(0xFF1E282B),
                      borderRadius: BorderRadius.circular(16),
                    ),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(
                          Icons.insert_drive_file_outlined,
                          size: 64,
                          color: Color(0xFF00BFAE),
                        ),
                        const SizedBox(height: 16),
                        Text(
                          item.originalName,
                          textAlign: TextAlign.center,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 16),
                        FilledButton.icon(
                          onPressed: () => _openExternal(item.downloadUrl ?? url),
                          icon: const Icon(Icons.open_in_new_rounded, size: 18),
                          label: const Text('فتح المستند'),
                          style: FilledButton.styleFrom(
                            backgroundColor: const Color(0xFF00BFAE),
                            foregroundColor: Colors.white,
                          ),
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),

          // Top Header Chrome
          if (_chromeVisible)
            Positioned(
              top: 0,
              left: 0,
              right: 0,
              child: Container(
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [Colors.black87, Colors.transparent],
                  ),
                ),
                padding: EdgeInsets.fromLTRB(
                  8,
                  MediaQuery.paddingOf(context).top + 4,
                  8,
                  24,
                ),
                child: Row(
                  children: [
                    IconButton(
                      icon: const Icon(Icons.arrow_back_rounded, color: Colors.white),
                      tooltip: 'رجوع',
                      onPressed: () => Navigator.of(context).pop(),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            widget.senderName ?? 'الوسائط',
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 15,
                              fontWeight: FontWeight.bold,
                            ),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                          if (timeLabel.isNotEmpty)
                            Text(
                              timeLabel,
                              style: const TextStyle(
                                color: Colors.white60,
                                fontSize: 11.5,
                              ),
                            ),
                        ],
                      ),
                    ),
                    if (widget.attachments.length > 1)
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 8),
                        child: Text(
                          '${_currentIndex + 1} / ${widget.attachments.length}',
                          style: const TextStyle(
                            color: Colors.white70,
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    IconButton(
                      icon: const Icon(Icons.download_rounded, color: Colors.white),
                      tooltip: 'تنزيل أو فتح',
                      onPressed: () =>
                          _openExternal(active.downloadUrl ?? active.displayUrl),
                    ),
                  ],
                ),
              ),
            ),

          // Bottom Caption Chrome
          if (_chromeVisible &&
              widget.caption != null &&
              widget.caption!.trim().isNotEmpty &&
              widget.caption != '📎')
            Positioned(
              bottom: 0,
              left: 0,
              right: 0,
              child: Container(
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.bottomCenter,
                    end: Alignment.topCenter,
                    colors: [Colors.black87, Colors.transparent],
                  ),
                ),
                padding: EdgeInsets.fromLTRB(
                  20,
                  30,
                  20,
                  MediaQuery.paddingOf(context).bottom + 16,
                ),
                child: Directionality(
                  textDirection: TextDirection.rtl,
                  child: Text(
                    widget.caption!,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 14.5,
                      height: 1.4,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
