import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/config/app_config.dart';

void main() {
  group('assertProductionApiHost', () {
    test('accepts https production host', () {
      expect(
        AppConfig.assertProductionApiHost(
          'https://finance.hasim.test',
          defineName: AppConfig.apiBaseDefineName,
        ),
        'https://finance.hasim.test',
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
          'http://finance.hasim.test',
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

  test('financeApiBase keeps finance prefix', () {
    expect(
      AppConfig.financeApiBase('https://finance.hasim.test'),
      'https://finance.hasim.test/api/finance/v1/',
    );
  });
}
