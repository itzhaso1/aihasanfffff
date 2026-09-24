import 'dart:io';

/// Limits and MIME rules mirrored from [MessageAttachmentService] on Laravel.
abstract final class ChatAttachmentPolicy {
  static const maxBytes = 10 * 1024 * 1024;

  static const allowedExtensions = {
    'jpg',
    'jpeg',
    'png',
    'webp',
    'gif',
    'pdf',
    'mp3',
    'mpeg',
    'mp4',
    'wav',
    'webm',
  };

  static String? extensionOf(File file) {
    final name = file.uri.pathSegments.isNotEmpty ? file.uri.pathSegments.last : file.path;
    final dot = name.lastIndexOf('.');
    if (dot <= 0 || dot >= name.length - 1) return null;
    return name.substring(dot + 1).toLowerCase();
  }

  static String kindForExtension(String ext) {
    switch (ext) {
      case 'jpg':
      case 'jpeg':
      case 'png':
      case 'webp':
      case 'gif':
        return 'image';
      case 'mp3':
      case 'mpeg':
      case 'wav':
      case 'webm':
        return 'audio';
      case 'mp4':
        return 'video';
      default:
        return 'file';
    }
  }

  /// Returns a localization key suffix reason, or null if OK.
  static String? validateFile(File file) {
    if (!file.existsSync()) return 'missing';
    final length = file.lengthSync();
    if (length > maxBytes) return 'tooLarge';
    final ext = extensionOf(file);
    if (ext == null || !allowedExtensions.contains(ext)) return 'unsupported';
    return null;
  }
}
