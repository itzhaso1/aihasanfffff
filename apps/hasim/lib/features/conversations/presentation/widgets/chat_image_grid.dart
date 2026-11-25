import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/media_auth_headers.dart';

/// شبكة عرض الصور المتعددة داخل فقاعة الرسالة بأسلوب واتساب
class ChatImageGrid extends ConsumerWidget {
  const ChatImageGrid({
    super.key,
    required this.attachments,
    required this.onImageTap,
    this.onMediaUrlExpired,
    this.mediaHeaders,
  });

  final List<MessageAttachmentModel> attachments;
  final void Function(int index) onImageTap;
  final VoidCallback? onMediaUrlExpired;
  final Map<String, String>? mediaHeaders;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (attachments.isEmpty) return const SizedBox.shrink();

    final headers = mediaHeaders ??
        ref.watch(mediaAuthHeadersProvider).valueOrNull ??
        const <String, String>{};

    const maxGridWidth = 240.0;

    if (attachments.length == 1) {
      return SizedBox(
        width: maxGridWidth,
        height: 180,
        child: _buildTile(
          attachment: attachments[0],
          headers: headers,
          index: 0,
          borderRadius: BorderRadius.circular(12),
          cacheSize: 480,
        ),
      );
    }

    if (attachments.length == 2) {
      return SizedBox(
        width: maxGridWidth,
        height: 125,
        child: Row(
          children: [
            Expanded(
              child: _buildTile(
                attachment: attachments[0],
                headers: headers,
                index: 0,
                borderRadius: const BorderRadius.horizontal(
                  right: Radius.circular(12),
                ),
              ),
            ),
            const SizedBox(width: 2.5),
            Expanded(
              child: _buildTile(
                attachment: attachments[1],
                headers: headers,
                index: 1,
                borderRadius: const BorderRadius.horizontal(
                  left: Radius.circular(12),
                ),
              ),
            ),
          ],
        ),
      );
    }

    if (attachments.length == 3) {
      return SizedBox(
        width: maxGridWidth,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              height: 110,
              width: double.infinity,
              child: _buildTile(
                attachment: attachments[0],
                headers: headers,
                index: 0,
                borderRadius: const BorderRadius.vertical(
                  top: Radius.circular(12),
                ),
              ),
            ),
            const SizedBox(height: 2.5),
            SizedBox(
              height: 85,
              child: Row(
                children: [
                  Expanded(
                    child: _buildTile(
                      attachment: attachments[1],
                      headers: headers,
                      index: 1,
                      borderRadius: const BorderRadius.only(
                        bottomRight: Radius.circular(12),
                      ),
                    ),
                  ),
                  const SizedBox(width: 2.5),
                  Expanded(
                    child: _buildTile(
                      attachment: attachments[2],
                      headers: headers,
                      index: 2,
                      borderRadius: const BorderRadius.only(
                        bottomLeft: Radius.circular(12),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      );
    }

    // 4 or more images -> 2x2 grid with +N on 4th item
    final remaining = attachments.length - 4;
    return SizedBox(
      width: maxGridWidth,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          SizedBox(
            height: 95,
            child: Row(
              children: [
                Expanded(
                  child: _buildTile(
                    attachment: attachments[0],
                    headers: headers,
                    index: 0,
                    borderRadius: const BorderRadius.only(
                      topRight: Radius.circular(12),
                    ),
                  ),
                ),
                const SizedBox(width: 2.5),
                Expanded(
                  child: _buildTile(
                    attachment: attachments[1],
                    headers: headers,
                    index: 1,
                    borderRadius: const BorderRadius.only(
                      topLeft: Radius.circular(12),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 2.5),
          SizedBox(
            height: 95,
            child: Row(
              children: [
                Expanded(
                  child: _buildTile(
                    attachment: attachments[2],
                    headers: headers,
                    index: 2,
                    borderRadius: const BorderRadius.only(
                      bottomRight: Radius.circular(12),
                    ),
                  ),
                ),
                const SizedBox(width: 2.5),
                Expanded(
                  child: _buildTile(
                    attachment: attachments[3],
                    headers: headers,
                    index: 3,
                    borderRadius: const BorderRadius.only(
                      bottomLeft: Radius.circular(12),
                    ),
                    overlayCount: remaining > 0 ? remaining : null,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTile({
    required MessageAttachmentModel attachment,
    required Map<String, String> headers,
    required int index,
    required BorderRadius borderRadius,
    int? overlayCount,
    int cacheSize = 280,
  }) {
    final url = attachment.previewUrl ?? attachment.displayUrl;

    return GestureDetector(
      onTap: () => onImageTap(index),
      child: ClipRRect(
        borderRadius: borderRadius,
        child: Stack(
          fit: StackFit.expand,
          children: [
            if (url != null && url.isNotEmpty)
              CachedNetworkImage(
                imageUrl: url,
                httpHeaders: headers,
                fit: BoxFit.cover,
                memCacheWidth: cacheSize,
                memCacheHeight: cacheSize,
                filterQuality: FilterQuality.low,
                fadeInDuration: Duration.zero,
                fadeOutDuration: Duration.zero,
                placeholderFadeInDuration: Duration.zero,
                placeholder: (_, _) => const ColoredBox(
                  color: Color(0x14000000),
                  child: Center(
                    child: Icon(Icons.image_outlined, size: 24, color: Color(0x66000000)),
                  ),
                ),
                errorWidget: (_, _, _) => const ColoredBox(
                  color: Color(0x14000000),
                  child: Icon(Icons.broken_image_outlined, size: 28),
                ),
              )
            else
              const ColoredBox(
                color: Color(0x14000000),
                child: Icon(Icons.image_outlined, size: 28),
              ),
            if (overlayCount != null)
              Container(
                color: Colors.black.withValues(alpha: 0.55),
                alignment: Alignment.center,
                child: Text(
                  '+$overlayCount',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 22,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
