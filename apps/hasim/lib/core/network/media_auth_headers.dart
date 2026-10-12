import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';

/// Headers required to GET private signed chat attachment URLs.
///
/// Upload uses [ApiClient] (Bearer + workspace). [CachedNetworkImage] does not
/// go through Dio, so image/video widgets must send the same headers.
final mediaAuthHeadersProvider = FutureProvider<Map<String, String>>((ref) async {
  final token = await ref.watch(secureStoreProvider).readToken();
  final workspaceId = ref.watch(prefsStoreProvider).workspaceId;
  final headers = <String, String>{
    'Accept': 'image/*,application/octet-stream;q=0.8,*/*;q=0.5',
  };
  if (token != null && token.isNotEmpty) {
    headers['Authorization'] = 'Bearer $token';
  }
  if (workspaceId != null) {
    headers['X-Workspace-Id'] = workspaceId.toString();
  }
  return headers;
});
