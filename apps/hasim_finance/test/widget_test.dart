import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/config/app_config.dart';
import 'package:hasim_finance/features/auth/login_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'helpers.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('finance API base strips other product prefixes', () {
    expect(AppConfig.financeApiBase('https://app.hasem.sa/api/mobile/v1'), 'https://app.hasem.sa/api/finance/v1/');
    expect(AppConfig.financeApiBase('https://app.hasem.sa/api/cashier/v1'), 'https://app.hasem.sa/api/finance/v1/');
    expect(AppConfig.financeApiBase('https://app.hasem.sa'), 'https://app.hasem.sa/api/finance/v1/');
  });

  testWidgets('login screen renders Arabic labels', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    await tester.pumpWidget(financeHarness(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
      ],
      child: const LoginScreen(),
    ));
    await tester.pump();

    expect(find.text('حاسم للمالية'), findsOneWidget);
    expect(find.text('دخول'), findsOneWidget);
  });
}
