import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart';
import 'package:hasim_finance/core/utils/download_io.dart'
    if (dart.library.html) 'package:hasim_finance/core/utils/download_web.dart' as download;

Future<void> saveAndOpenBytes(Uint8List bytes, String filename) {
  return download.saveAndOpenBytes(bytes, filename);
}

Future<void> copyText(String value) => download.copyText(value);

Future<void> openExternalUrl(String url) => download.openExternalUrl(url);

Future<MultipartFile?> multipartFromPicked(PlatformFile file) async {
  final path = file.path;
  if (path != null && path.isNotEmpty) {
    return MultipartFile.fromFile(path, filename: file.name);
  }
  final bytes = file.bytes;
  if (bytes != null && bytes.isNotEmpty) {
    return MultipartFile.fromBytes(bytes, filename: file.name);
  }
  return null;
}
