import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/config/app_config.dart';

void main() {
  group('normalizeHostBase', () {
    test('strips accidental /api suffixes', () {
      expect(AppConfig.normalizeHostBase('http://127.0.0.1:8000'), 'http://127.0.0.1:8000');
      expect(AppConfig.normalizeHostBase('http://127.0.0.1:8000/'), 'http://127.0.0.1:8000');
      expect(AppConfig.normalizeHostBase('http://127.0.0.1:8000/api'), 'http://127.0.0.1:8000');
      expect(AppConfig.normalizeHostBase('http://127.0.0.1:8000/api/'), 'http://127.0.0.1:8000');
      expect(AppConfig.normalizeHostBase('http://127.0.0.1:8000/api/mobile/v1'), 'http://127.0.0.1:8000');
      expect(AppConfig.normalizeHostBase('https://hasim.test/api/mobile/v1/'), 'https://hasim.test');
    });

    test('mobileApiBase ends with mobile v1 slash', () {
      expect(AppConfig.mobileApiBase.endsWith('/api/mobile/v1/'), isTrue);
    });
  });

  group('assertProductionApiHost', () {
    test('accepts https production host', () {
      expect(
        AppConfig.assertProductionApiHost(
          'https://api.hasim.test',
          defineName: AppConfig.apiBaseDefineName,
        ),
        'https://api.hasim.test',
      );
    });

    test('rejects missing production host', () {
      expect(
        () => AppConfig.assertProductionApiHost('', defineName: AppConfig.apiBaseDefineName),
        throwsStateError,
      );
    });

    test('rejects http production host', () {
      expect(
        () => AppConfig.assertProductionApiHost(
          'http://api.hasim.test',
          defineName: AppConfig.apiBaseDefineName,
        ),
        throwsStateError,
      );
    });

    test('rejects development hosts', () {
      for (final host in ['http://127.0.0.1:8000', 'http://localhost:8000', 'http://10.0.2.2:8000']) {
        expect(
          () => AppConfig.assertProductionApiHost(
            AppConfig.normalizeHostBase(host),
            defineName: AppConfig.apiBaseDefineName,
          ),
          throwsStateError,
        );
      }
    });
  });

  test('debug configuration uses product fallback when dart-define is empty', () {
    expect(
      AppConfig.resolveConfiguredHost(''),
      AppConfig.normalizeHostBase(AppConfig.debugFallbackApiBase),
    );
  });

  test('debug override allows local host without production validation', () {
    expect(
      AppConfig.resolveConfiguredHost('', override: 'http://127.0.0.1:8000'),
      'http://127.0.0.1:8000',
    );
  });
}
