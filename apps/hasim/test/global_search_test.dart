import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/search/data/global_search_models.dart';
import 'package:hasim/features/search/data/search_repository.dart';
import 'package:hasim/features/search/presentation/global_search_screen.dart';
import 'package:hasim/features/search/providers/global_search_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}
  @override
  Future<String?> readToken() async => null;
  @override
  Future<void> clearToken() async {}
}

class FakeSearchRepository extends SearchRepository {
  FakeSearchRepository(this.onSearch, PrefsStore prefs)
      : super(
          isolatedTestApiClient(
            secureStore: _MemorySecureStore(),
            prefsStore: prefs,
          ),
        );

  final Future<GlobalSearchResult> Function(String query) onSearch;
  var calls = 0;

  @override
  Future<GlobalSearchResult> search(String query, {int limit = 10}) async {
    calls++;
    return onSearch(query);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late SharedPreferences sharedPrefs;
  late PrefsStore prefs;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    sharedPrefs = await SharedPreferences.getInstance();
    prefs = PrefsStore(sharedPrefs);
  });

  List<Override> testOverrides(FakeSearchRepository fake) {
    return [
      sharedPrefsProvider.overrideWithValue(sharedPrefs),
      secureStoreProvider.overrideWithValue(_MemorySecureStore()),
      apiClientProvider.overrideWithValue(
        isolatedTestApiClient(
          secureStore: _MemorySecureStore(),
          prefsStore: prefs,
        ),
      ),
      searchRepositoryProvider.overrideWithValue(fake),
      globalSearchConnectivityProvider.overrideWithValue(() async => true),
    ];
  }

  test('GlobalSearchResult parses Laravel mobile payload', () {
    final result = GlobalSearchResult.fromJson(
      {
        'conversations': [
          {
            'id': 9,
            'channel': 'whatsapp',
            'status': 'open',
            'external_id': 'ext',
            'unread_count': 0,
            'muted': false,
            'archived': false,
          },
        ],
        'customers': [
          {'id': 3, 'name': 'Ali', 'phone': '+9665', 'email': null, 'whatsapp': null},
        ],
        'emails': [
          {
            'id': 4,
            'sender': 'a@example.com',
            'recipient': 'b@example.com',
            'subject': 'Hello',
            'type': 'inbox',
            'is_read': true,
            'created_at': '2026-01-01T00:00:00Z',
          },
        ],
      },
      query: 'Ali',
      workspaceId: 7,
    );

    expect(result.conversations.single.id, 9);
    expect(result.customers.single.name, 'Ali');
    expect(result.emails.single.subject, 'Hello');
    expect(result.query, 'Ali');
    expect(result.workspaceId, 7);
  });

  test('resetForWorkspaceChange clears search state', () async {
    final fake = FakeSearchRepository(
      (_) async => GlobalSearchResult(
        conversations: [
          ConversationModel(id: 1, channel: 'whatsapp', status: 'open', externalId: 'x'),
        ],
      ),
      prefs,
    );

    final container = ProviderContainer(overrides: testOverrides(fake));
    addTearDown(container.dispose);

    final notifier = container.read(globalSearchControllerProvider.notifier);
    notifier.setQuery('alpha');
    await Future<void>.delayed(const Duration(milliseconds: 400));
    expect(container.read(globalSearchControllerProvider).result?.conversations, isNotEmpty);

    notifier.resetForWorkspaceChange();
    final state = container.read(globalSearchControllerProvider);
    expect(state.query, isEmpty);
    expect(state.result, isNull);
    expect(state.submittedQuery, isNull);
  });

  testWidgets('global search shows results and conversation tile', (tester) async {
    final fake = FakeSearchRepository(
      (_) async => const GlobalSearchResult(
        conversations: [
          ConversationModel(id: 42, channel: 'whatsapp', status: 'open', externalId: 'Zaid'),
        ],
      ),
      prefs,
    );

    final container = ProviderContainer(overrides: testOverrides(fake));
    addTearDown(container.dispose);

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: MaterialApp(
          theme: AppTheme.light(),
          locale: const Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: const GlobalSearchScreen(),
        ),
      ),
    );

    await tester.enterText(find.byType(TextField), 'Zaid');
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('global-search-conversation-42')), findsOneWidget);
    expect(fake.calls, 1);
  });

  testWidgets('global search shows empty state after no matches', (tester) async {
    final fake = FakeSearchRepository((_) async => const GlobalSearchResult(), prefs);

    final container = ProviderContainer(overrides: testOverrides(fake));
    addTearDown(container.dispose);

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: MaterialApp(
          locale: const Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: const GlobalSearchScreen(),
        ),
      ),
    );

    await tester.enterText(find.byType(TextField), 'missing');
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump();

    expect(find.text('لا توجد نتائج'), findsOneWidget);
  });

  testWidgets('global search shows retry on repository failure', (tester) async {
    final fake = FakeSearchRepository((_) async {
      throw ApiException('blocked');
    }, prefs);

    final container = ProviderContainer(overrides: testOverrides(fake));
    addTearDown(container.dispose);

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: MaterialApp(
          locale: const Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: const GlobalSearchScreen(),
        ),
      ),
    );

    await tester.enterText(find.byType(TextField), 'fail');
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump();

    expect(find.text('إعادة المحاولة'), findsOneWidget);
  });
}
