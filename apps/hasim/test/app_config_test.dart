import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/config/app_config.dart';

void main() {
  test('normalizeHostBase strips accidental /api suffixes', () {
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
}
