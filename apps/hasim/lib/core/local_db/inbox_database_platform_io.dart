import 'dart:io';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

QueryExecutor openExecutor() {
  return LazyDatabase(() async {
    final dir = await getApplicationSupportDirectory();
    final file = File(p.join(dir.path, 'hasim_inbox.sqlite'));
    return NativeDatabase.createInBackground(file);
  });
}

QueryExecutor memoryExecutor() => NativeDatabase.memory();

QueryExecutor fileExecutor(Object file) {
  if (file is! File) {
    throw ArgumentError.value(file, 'file', 'Expected a dart:io File.');
  }

  return NativeDatabase(file);
}
