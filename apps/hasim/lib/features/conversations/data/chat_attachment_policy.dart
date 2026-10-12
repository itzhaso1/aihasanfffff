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
    'm4a',
    'aac',
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
      case 'm4a':
      case 'aac':
        return 'audio';
      case 'mp4':
        return 'video';
      default:
        return 'file';
    }
  }

  static String? mimeForExtension(String? ext) {
    return switch (ext) {
      'jpg' || 'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'webp' => 'image/webp',
      'gif' => 'image/gif',
      'pdf' => 'application/pdf',
      'mp3' || 'mpeg' => 'audio/mpeg',
      'wav' => 'audio/wav',
      'webm' => 'video/webm',
      'm4a' => 'audio/mp4',
      'aac' => 'audio/aac',
      'mp4' => 'video/mp4',
      _ => null,
    };
  }

  /// Returns a localization key suffix reason, or null if OK.
  static String? validateFile(File file) {
    if (!file.existsSync()) return 'missing';
    return validateBytes(file.lengthSync(), extensionOf(file));
  }

  static String? validateBytes(int length, String? extension) {
    if (length <= 0) return 'missing';
    if (length > maxBytes) return 'tooLarge';
    if (extension == null || !allowedExtensions.contains(extension)) {
      return 'unsupported';
    }
    return null;
  }
}
