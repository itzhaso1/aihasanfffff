import 'package:drift/drift.dart';
// Drift's legacy sql.js backend is the smallest web-compatible executor for
// the current local inbox schema; the WASM backend requires additional web
// worker and binary assets.
// ignore: deprecated_member_use
import 'package:drift/web.dart';

QueryExecutor openExecutor() => WebDatabase('hasim_inbox');

QueryExecutor memoryExecutor() => WebDatabase('hasim_inbox_memory');

QueryExecutor fileExecutor(Object file) {
  throw UnsupportedError(
    'File-backed inbox databases are not available on Web.',
  );
}
