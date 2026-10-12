import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}

  @override
  Future<String?> readToken() async => null;

  @override
  Future<void> clearToken() async {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  Future<({AuthRepository repo, List<RequestOptions> calls})> repository({
    required int status,
    required Map<String, dynamic> data,
    String? message,
    Map<String, dynamic>? errors,
  }) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final calls = <RequestOptions>[];
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1/api/mobile/v1/'));
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          calls.add(options);
          if (status >= 400) {
            handler.reject(
              DioException(
                requestOptions: options,
                type: DioExceptionType.badResponse,
                response: Response(
                  requestOptions: options,
                  statusCode: status,
                  data: {
                    'success': false,
                    'message': message ?? 'The given data was invalid.',
                    'errors': ?errors,
                  },
                ),
              ),
            );
            return;
          }
          handler.resolve(
            Response(
              requestOptions: options,
              statusCode: status,
              data: {
                'success': true,
                'message': message ?? 'تم إنشاء الحساب بنجاح.',
                'data': data,
              },
            ),
          );
        },
      ),
    );
    return (
      repo: AuthRepository(
        ApiClient(
          secureStore: _MemorySecureStore(),
          prefsStore: prefs,
          dio: dio,
        ),
      ),
      calls: calls,
    );
  }

  Map<String, dynamic> loginEnvelope() {
    return {
      'token': 'reg-token',
      'token_type': 'Bearer',
      'user': {
        'id': 9,
        'name': 'نورة العتيبي',
        'email': 'noura.register@example.test',
      },
      'participant': {'type': 'company_staff', 'worker_profile_id': null},
      'workspace': {
        'id': 4,
        'name': 'متجر نورة',
        'type': 'company',
        'is_owner': true,
      },
      'workspaces': [
        {
          'id': 4,
          'name': 'متجر نورة',
          'type': 'company',
          'is_owner': true,
        },
      ],
    };
  }

  test('register posts the mobile auth contract and parses the login envelope', () async {
    final setup = await repository(status: 201, data: loginEnvelope());

    final result = await setup.repo.register(
      name: 'نورة العتيبي',
      email: 'noura.register@example.test',
      password: 'password',
      passwordConfirmation: 'password',
      workspaceName: 'متجر نورة',
    );

    expect(setup.calls, hasLength(1));
    expect(setup.calls.first.method, 'POST');
    expect(setup.calls.first.path, 'auth/register');
    expect(setup.calls.first.data, {
      'name': 'نورة العتيبي',
      'email': 'noura.register@example.test',
      'password': 'password',
      'password_confirmation': 'password',
      'workspace_name': 'متجر نورة',
      'device_name': 'حاسم Flutter',
      'device_type': 'mobile',
    });
    expect(result.token, 'reg-token');
    expect(result.user.email, 'noura.register@example.test');
    expect(result.workspace?.isOwner, isTrue);
    expect(result.participant.isIndependentWorker, isFalse);
  });

  test('duplicate email becomes a field validation ApiException', () async {
    final setup = await repository(
      status: 422,
      data: {},
      message: 'The email has already been taken.',
      errors: {
        'email': ['The email has already been taken.'],
      },
    );

    try {
      await setup.repo.register(
        name: 'نورة',
        email: 'taken@example.test',
        password: 'password',
        passwordConfirmation: 'password',
      );
      fail('expected ApiException');
    } on ApiException catch (e) {
      expect(e.statusCode, 422);
      expect(e.userMessage, contains('البريد الإلكتروني'));
    }
  });
}
