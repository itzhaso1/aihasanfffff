import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/settings/data/company_worker_discovery_repository.dart';
import 'package:hasim/features/settings/data/company_worker_engagement_capabilities_repository.dart';
import 'package:hasim/features/settings/presentation/company_worker_engagement_capabilities_screen.dart';
import 'package:hasim/features/settings/providers/company_worker_engagement_capabilities_controller.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets(
    'company engagement capabilities render and server-confirm grant/revoke',
    (tester) async {
      final repository = await _fakeRepository();

      await _pump(tester, repository);

      expect(
        find.descendant(
          of: find.byKey(const Key('company-engagement-capabilities')),
          matching: find.text('صلاحيات الموظف'),
        ),
        findsOneWidget,
      );
      expect(
        find.byKey(
          const Key('company-engagement-capability-inbox.messages.read'),
        ),
        findsOneWidget,
      );
      expect(
        find.byKey(
          const Key('company-engagement-capability-status-inbox.messages.read'),
        ),
        findsOneWidget,
      );
      expect(find.text('غير مفعلة'), findsOneWidget);
      expect(find.text('مفعلة'), findsOneWidget);

      await tester.tap(
        find.byKey(
          const Key('company-engagement-capability-inbox.messages.read'),
        ),
      );
      await tester.pumpAndSettle();

      expect(repository.grantedKey, 'inbox.messages.read');
      expect(
        find.byKey(
          const Key('company-engagement-capability-status-inbox.messages.read'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(
            const Key('company-engagement-capability-inbox.messages.read'),
          ),
          matching: find.text('مفعلة'),
        ),
        findsOneWidget,
      );

      await tester.tap(
        find.byKey(
          const Key('company-engagement-capability-inbox.messages.read'),
        ),
      );
      await tester.pumpAndSettle();

      expect(repository.revokedKey, 'inbox.messages.read');
      expect(
        find.descendant(
          of: find.byKey(
            const Key('company-engagement-capability-inbox.messages.read'),
          ),
          matching: find.text('غير مفعلة'),
        ),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'capability failure keeps the previous state and shows an error',
    (tester) async {
      final repository = await _fakeRepository();
      await _pump(tester, repository);
      repository.failure = ApiException(
        'لا تملك صلاحية تنفيذ هذا الإجراء.',
        statusCode: 403,
      );
      await tester.tap(
        find.byKey(
          const Key('company-engagement-capability-inbox.messages.read'),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('لا تملك صلاحية تنفيذ هذا الإجراء.'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(
            const Key('company-engagement-capability-inbox.messages.read'),
          ),
          matching: find.text('غير مفعلة'),
        ),
        findsOneWidget,
      );
      expect(repository.grantedKey, 'inbox.messages.read');
    },
  );

  testWidgets(
    'capability toggle shows loading until the server confirms the change',
    (tester) async {
      final repository = await _fakeRepository();
      final gate = Completer<CompanyWorkerEngagementCapabilities>();
      repository.grantGate = gate;

      await _pump(tester, repository);
      await tester.tap(
        find.byKey(
          const Key('company-engagement-capability-inbox.messages.read'),
        ),
      );
      await tester.pump();

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(
            const Key('company-engagement-capability-inbox.messages.read'),
          ),
          matching: find.text('غير مفعلة'),
        ),
        findsOneWidget,
      );

      gate.complete(repository._current(allowedForRead: true));
      await tester.pumpAndSettle();

      expect(
        find.descendant(
          of: find.byKey(
            const Key('company-engagement-capability-inbox.messages.read'),
          ),
          matching: find.text('مفعلة'),
        ),
        findsOneWidget,
      );
    },
  );
}

Future<_FakeCompanyWorkerEngagementCapabilitiesRepository>
_fakeRepository() async {
  SharedPreferences.setMockInitialValues({});
  final prefs = PrefsStore(await SharedPreferences.getInstance());
  return _FakeCompanyWorkerEngagementCapabilitiesRepository(
    ApiClient(
      secureStore: SecureStore(),
      prefsStore: prefs,
      dio: Dio(BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/')),
    ),
  );
}

Future<void> _pump(
  WidgetTester tester,
  _FakeCompanyWorkerEngagementCapabilitiesRepository repository,
) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        companyWorkerEngagementCapabilitiesRepositoryProvider.overrideWithValue(
          repository,
        ),
      ],
      child: MaterialApp(
        home: CompanyWorkerEngagementCapabilitiesScreen(
          engagementId: 22,
          engagement: const CompanyWorkerOpenEngagement(
            id: 22,
            workerProfileId: 9,
            status: 'active',
            workerDisplayName: 'عامل الدعم',
            jobTitle: 'مندوب مبيعات',
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

class _FakeCompanyWorkerEngagementCapabilitiesRepository
    extends CompanyWorkerEngagementCapabilitiesRepository {
  _FakeCompanyWorkerEngagementCapabilitiesRepository(super.api);

  String? grantedKey;
  String? revokedKey;
  Object? failure;
  Completer<CompanyWorkerEngagementCapabilities>? grantGate;
  var _readAllowed = false;

  @override
  Future<CompanyWorkerEngagementCapabilities> fetch(int engagementId) async {
    final error = failure;
    if (error != null) throw error;
    return _current();
  }

  @override
  Future<CompanyWorkerEngagementCapabilities> grant(
    int engagementId,
    String capabilityKey,
  ) async {
    grantedKey = capabilityKey;
    final error = failure;
    if (error != null) throw error;
    final gate = grantGate;
    if (gate != null) return gate.future;
    _readAllowed = true;
    return _current();
  }

  @override
  Future<CompanyWorkerEngagementCapabilities> revoke(
    int engagementId,
    String capabilityKey,
  ) async {
    revokedKey = capabilityKey;
    final error = failure;
    if (error != null) throw error;
    _readAllowed = false;
    return _current();
  }

  CompanyWorkerEngagementCapabilities _current({bool? allowedForRead}) {
    final allowed = allowedForRead ?? _readAllowed;
    return CompanyWorkerEngagementCapabilities(
      engagementId: 22,
      status: 'active',
      items: [
        CompanyWorkerEngagementCapability(
          key: 'inbox.messages.read',
          displayName: 'Read inbox messages',
          description: 'Read workspace inbox messages assigned to the worker.',
          allowed: allowed,
        ),
        const CompanyWorkerEngagementCapability(
          key: 'email.view',
          displayName: 'View workspace email',
          description: 'View workspace email messages.',
          allowed: true,
        ),
      ],
    );
  }
}
