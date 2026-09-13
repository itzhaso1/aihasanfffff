import 'dart:io';

import 'package:flutter/services.dart';
import 'package:hasim_finance/core/network/api_exception.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

Future<void> saveAndOpenBytes(Uint8List bytes, String filename) async {
  final dir = await getTemporaryDirectory();
  final file = File('${dir.path}/$filename');
  await file.writeAsBytes(bytes, flush: true);
  if (Platform.isWindows || Platform.isLinux || Platform.isMacOS) {
    await launchUrl(Uri.file(file.path));
    return;
  }
  await Share.shareXFiles([XFile(file.path)], text: filename);
}

Future<void> copyText(String value) => Clipboard.setData(ClipboardData(text: value));

Future<void> openExternalUrl(String url) async {
  final uri = Uri.parse(url);
  if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
    throw ApiException('تعذر فتح الرابط.');
  }
}
