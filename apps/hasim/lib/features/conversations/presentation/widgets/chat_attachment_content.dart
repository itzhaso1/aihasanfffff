import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:video_player/video_player.dart';

class ChatAttachmentContent extends StatelessWidget {
  const ChatAttachmentContent({
    super.key,
    required this.attachment,
    this.onImageTap,
    this.onOpenFailed,
    this.onMediaUrlExpired,
  });

  final MessageAttachmentModel attachment;
  final void Function(String url)? onImageTap;
  final VoidCallback? onOpenFailed;
  final VoidCallback? onMediaUrlExpired;

  @override
  Widget build(BuildContext context) {
    final url = attachment.displayUrl;
    if (url == null || url.isEmpty) {
      return _FileRow(attachment: attachment, onOpen: null);
    }

    return switch (attachment.kind) {
      'image' => _ImageAttachment(
        url: url,
        previewUrl: attachment.previewUrl,
        onTap: () => onImageTap?.call(url),
        onError: onMediaUrlExpired,
      ),
      'video' => _VideoAttachment(url: url, onError: onMediaUrlExpired),
      'audio' => _AudioAttachment(attachment: attachment, url: url, onOpenFailed: onOpenFailed),
      _ => _FileRow(
          attachment: attachment,
          onOpen: () => _launch(context, url, onOpenFailed),
        ),
    };
  }

  static Future<void> _launch(BuildContext context, String url, VoidCallback? onOpenFailed) async {
    final uri = Uri.tryParse(url);
    if (uri == null) {
      onOpenFailed?.call();
      return;
    }
    final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!ok && context.mounted) onOpenFailed?.call();
  }
}

class _ImageAttachment extends StatelessWidget {
  const _ImageAttachment({
    required this.url,
    this.previewUrl,
    required this.onTap,
    this.onError,
  });

  final String url;
  final String? previewUrl;
  final VoidCallback onTap;
  final VoidCallback? onError;

  @override
  Widget build(BuildContext context) {
    final src = previewUrl ?? url;
    return GestureDetector(
      onTap: onTap,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(8),
        child: CachedNetworkImage(
          imageUrl: src,
          height: 160,
          fit: BoxFit.cover,
          errorWidget: (_, __, ___) {
            onError?.call();
            return _MediaErrorPlaceholder(onRetry: onError);
          },
        ),
      ),
    );
  }
}

class _VideoAttachment extends StatefulWidget {
  const _VideoAttachment({required this.url, this.onError});

  final String url;
  final VoidCallback? onError;

  @override
  State<_VideoAttachment> createState() => _VideoAttachmentState();
}

class _VideoAttachmentState extends State<_VideoAttachment> {
  VideoPlayerController? _controller;
  var _failed = false;

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    final uri = Uri.tryParse(widget.url);
    if (uri == null) {
      setState(() => _failed = true);
      widget.onError?.call();
      return;
    }
    final c = VideoPlayerController.networkUrl(uri);
    try {
      await c.initialize();
      if (!mounted) {
        await c.dispose();
        return;
      }
      setState(() => _controller = c);
    } catch (_) {
      await c.dispose();
      if (mounted) setState(() => _failed = true);
      widget.onError?.call();
    }
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_failed) return _MediaErrorPlaceholder(onRetry: widget.onError);
    final c = _controller;
    if (c == null || !c.value.isInitialized) {
      return const SizedBox(
        height: 120,
        child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
      );
    }
    return ClipRRect(
      borderRadius: BorderRadius.circular(8),
      child: AspectRatio(
        aspectRatio: c.value.aspectRatio == 0 ? 16 / 9 : c.value.aspectRatio,
        child: Stack(
          alignment: Alignment.center,
          children: [
            VideoPlayer(c),
            IconButton(
              icon: Icon(
                c.value.isPlaying ? Icons.pause_circle_filled : Icons.play_circle_fill,
                size: 48,
                color: Colors.white.withValues(alpha: 0.92),
              ),
              onPressed: () {
                setState(() {
                  c.value.isPlaying ? c.pause() : c.play();
                });
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _AudioAttachment extends StatelessWidget {
  const _AudioAttachment({
    required this.attachment,
    required this.url,
    this.onOpenFailed,
  });

  final MessageAttachmentModel attachment;
  final String url;
  final VoidCallback? onOpenFailed;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final meta = HasimTokens.mutedColor(context);
    final duration = _formatDuration(attachment.durationMs);

    return Material(
      color: HasimTokens.mintFill(context),
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => ChatAttachmentContent._launch(context, url, onOpenFailed),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.audiotrack_rounded, color: HasimTokens.chatGreen),
              const SizedBox(width: 10),
              Flexible(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      attachment.originalName,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(fontWeight: FontWeight.w600, color: HasimTokens.inkColor(context)),
                    ),
                    if (duration != null)
                      Text(duration, style: TextStyle(fontSize: 12, color: meta)),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Text(l10n.chatAttachmentOpen, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: meta)),
            ],
          ),
        ),
      ),
    );
  }

  static String? _formatDuration(int? ms) {
    if (ms == null || ms <= 0) return null;
    final total = (ms / 1000).round();
    final m = total ~/ 60;
    final s = total % 60;
    return '${m.toString().padLeft(2, '0')}:${s.toString().padLeft(2, '0')}';
  }
}

class _FileRow extends StatelessWidget {
  const _FileRow({required this.attachment, this.onOpen});

  final MessageAttachmentModel attachment;
  final VoidCallback? onOpen;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final meta = HasimTokens.mutedColor(context);
    final size = _formatBytes(attachment.sizeBytes);

    return InkWell(
      onTap: onOpen,
      borderRadius: BorderRadius.circular(10),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(_iconForKind(attachment.kind), size: 20, color: meta),
          const SizedBox(width: 8),
          Flexible(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  attachment.originalName,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: HasimTokens.inkColor(context),
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                if (size != null)
                  Text(size, style: TextStyle(fontSize: 11, color: meta)),
              ],
            ),
          ),
          if (onOpen != null) ...[
            const SizedBox(width: 6),
            Text(l10n.chatAttachmentOpen, style: TextStyle(fontSize: 11, color: meta, fontWeight: FontWeight.w600)),
          ],
        ],
      ),
    );
  }

  static IconData _iconForKind(String kind) {
    return switch (kind) {
      'audio' => Icons.audiotrack_outlined,
      'video' => Icons.videocam_outlined,
      'image' => Icons.image_outlined,
      _ => Icons.insert_drive_file_outlined,
    };
  }

  static String? _formatBytes(int? bytes) {
    if (bytes == null || bytes <= 0) return null;
    if (bytes < 1024) return '$bytes B';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(1)} KB';
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }
}

class _MediaErrorPlaceholder extends StatelessWidget {
  const _MediaErrorPlaceholder({this.onRetry});

  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    return SizedBox(
      height: 80,
      child: Center(
        child: TextButton.icon(
          onPressed: onRetry,
          icon: const Icon(Icons.refresh_rounded, size: 18),
          label: Text(l10n.chatAttachmentRetry),
        ),
      ),
    );
  }
}
