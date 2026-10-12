import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  String? _token;
  @override
  Future<void> saveToken(String token) async => _token = token;
  @override
  Future<String?> readToken() async => _token;
  @override
  Future<void> clearToken() async => _token = null;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('uploadAvatar sends image bytes as multipart avatar', () async {
    SharedPreferences.setMockInitialValues({});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    String? uploadedPath;
    String? uploadedName;

    final dio = Dio(
      BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/'),
    );
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          uploadedPath = options.path;
          if (options.data is FormData) {
            final form = options.data as FormData;
            for (final file in form.files) {
              if (file.key == 'avatar') {
                uploadedName = file.value.filename;
              }
            }
          }
          handler.resolve(
            Response(
              requestOptions: options,
              statusCode: 200,
              data: {
                'success': true,
                'data': {
                  'id': 9,
                  'name': 'حسن',
                  'avatar_url': '/storage/avatars/9/avatar.jpg',
                },
              },
            ),
          );
        },
      ),
    );

    final api = ApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: prefs,
      dio: dio,
    );
    final user = await AuthRepository(api).uploadAvatar(
      bytes: const [1, 2, 3, 4],
      filename: 'avatar.jpg',
    );

    expect(uploadedPath, 'auth/avatar');
    expect(uploadedName, 'avatar.jpg');
    expect(user.avatarUrl, endsWith('/storage/avatars/9/avatar.jpg'));
    expect(user.avatarUrl, startsWith('http'));
  });
}
