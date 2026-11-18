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
  });

  final List<MessageAttachmentModel> attachments;
  final void Function(int index) onImageTap;
  final VoidCallback? onMediaUrlExpired;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (attachments.isEmpty) return const SizedBox.shrink();

    final headersAsync = ref.watch(mediaAuthHeadersProvider);
    final headers = headersAsync.valueOrNull ?? const <String, String>{};

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
                memCacheWidth: 600,
                memCacheHeight: 600,
                placeholder: (_, _) => Container(
                  color: Colors.black12,
                  child: const Center(
                    child: SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  ),
                ),
                errorWidget: (_, _, _) => Container(
                  color: Colors.black12,
                  child: const Icon(Icons.broken_image_outlined, size: 28),
                ),
              )
            else
              Container(
                color: Colors.black12,
                child: const Icon(Icons.image_outlined, size: 28),
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
