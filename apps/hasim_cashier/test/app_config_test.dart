import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/config/app_config.dart';

void main() {
  group('assertProductionApiHost', () {
    test('accepts https production host', () {
      expect(
        AppConfig.assertProductionApiHost(
          'https://cashier.hasim.test',
          defineName: AppConfig.apiBaseDefineName,
        ),
        'https://cashier.hasim.test',
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
          'http://cashier.hasim.test',
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

  test('debug configuration keeps localhost fallback', () {
    expect(AppConfig.resolveConfiguredHost(''), 'http://127.0.0.1:8000');
  });

  test('apiRoot uses cashier prefix', () {
    expect(
      AppConfig.resolveConfiguredHost('https://cashier.hasim.test'),
      'https://cashier.hasim.test',
    );
    expect(
      AppConfig.resolveConfiguredHost('https://cashier.hasim.test/api/cashier/v1'),
      'https://cashier.hasim.test',
    );
  });
}
