import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/settings/data/company_worker_discovery_repository.dart';
import 'package:hasim/features/settings/presentation/company_worker_profile_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('profile reloads the current engagement status from the API', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final repository = _FakeCompanyWorkerDiscoveryRepository(
      ApiClient(secureStore: SecureStore(), prefsStore: prefs),
      _worker(status: 'accepted'),
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          companyWorkerDiscoveryRepositoryProvider.overrideWithValue(
            repository,
          ),
        ],
        child: MaterialApp(
          home: CompanyWorkerProfileScreen(worker: _worker(status: 'offered')),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(repository.fetchCount, 1);
    expect(find.text('تم قبول العرض'), findsWidgets);
    expect(find.text('تم إرسال العرض'), findsNothing);
  });
}

CompanyWorkerDiscoveryWorker _worker({required String status}) {
  return CompanyWorkerDiscoveryWorker(
    id: 9,
    displayName: 'عامل الدعم',
    countryCode: 'JO',
    professionalSummary: 'يدير محادثات العملاء',
    salesExperienceLevel: 'senior',
    availabilityStatus: 'available',
    verifiedBadge: true,
    skills: const [],
    languages: const ['ar'],
    experiences: const [],
    existingEngagement: CompanyWorkerDiscoveryEngagement(
      id: 22,
      status: status,
      jobTitle: 'مندوب مبيعات',
      startsAt: '2026-10-01',
      endsAt: '2026-11-01',
      durationValue: 1,
      durationUnit: 'month',
    ),
  );
}

class _FakeCompanyWorkerDiscoveryRepository
    extends CompanyWorkerDiscoveryRepository {
  // ignore: use_super_parameters
  _FakeCompanyWorkerDiscoveryRepository(ApiClient api, this.worker)
    : super(api);

  final CompanyWorkerDiscoveryWorker worker;
  var fetchCount = 0;

  @override
  Future<CompanyWorkerDiscoveryWorker> fetchWorker(int workerProfileId) async {
    fetchCount++;
    return worker;
  }
}
