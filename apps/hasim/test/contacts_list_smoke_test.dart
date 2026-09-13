import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/contacts/data/contact_repository.dart';
import 'package:hasim/features/contacts/presentation/contacts_list_screen.dart';
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

class _FakeContactRepository extends ContactRepository {
  _FakeContactRepository(super.api);

  @override
  Future<({List<EmailContactModel> items, String? nextCursor})> list({
    String? cursor,
    String? search,
    bool? favorite,
    int perPage = 20,
  }) async {
    return (items: <EmailContactModel>[], nextCursor: null);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('ContactsListScreen empty state', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final api = ApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: PrefsStore(prefs),
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(prefs),
          contactRepositoryProvider.overrideWithValue(_FakeContactRepository(api)),
        ],
        child: MaterialApp(
          theme: AppTheme.light(),
          locale: const Locale('ar'),
          home: const ContactsListScreen(),
        ),
      ),
    );

    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.text('جهات الاتصال'), findsOneWidget);
    expect(find.text('لا توجد جهات اتصال'), findsOneWidget);
    expect(find.text('إضافة'), findsOneWidget);
  });
}
