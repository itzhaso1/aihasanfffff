import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/settings/data/company_profile_model.dart';
import 'package:hasim/features/settings/data/plan_channel_repository.dart';
import 'package:hasim/features/settings/presentation/company_profile_screen.dart';
import 'package:hasim/features/settings/providers/company_profile_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}

  @override
  Future<String?> readToken() async => 'token';

  @override
  Future<void> clearToken() async {}
}

class _SeededCompanyProfileController extends CompanyProfileController {
  _SeededCompanyProfileController(super.ref, this._initial);

  final CompanyProfileState _initial;
  CompanyProfileModel? lastSaved;
  bool failSave = false;

  @override
  Future<void> reload() async {
    state = _initial;
  }

  @override
  Future<bool> save(CompanyProfileModel draft) async {
    lastSaved = draft;
    if (failSave) {
      state = state.copyWith(saving: false, error: 'تعذر حفظ نبذة الشركة.');
      return false;
    }
    state = CompanyProfileState(profile: draft);
    return true;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  test('company profile json round-trips through the shared API shape', () {
    const source = CompanyProfileModel(
      companyName: 'شركة النور',
      about: 'متجر',
      offerings: 'ملابس',
      services: 'توصيل',
      audience: 'شباب',
      replyGuidelines: 'اختصر',
    );

    final parsed = CompanyProfileModel.fromJson(source.toUpdateJson());

    expect(parsed, source);
    expect(parsed.fieldError('x' * 121, CompanyProfileModel.nameMax), isNotNull);
  });

  test('repository reads and writes /ai/company-profile', () async {
    SharedPreferences.setMockInitialValues({'workspace_id': 4});
    final prefs = PrefsStore(await SharedPreferences.getInstance());
    final calls = <RequestOptions>[];
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1/api/mobile/v1/'));
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          calls.add(options);
          handler.resolve(
            Response(
              requestOptions: options,
              statusCode: 200,
              data: {
                'success': true,
                'data': {
                  'company_name': options.method == 'PUT' ? 'شركة محفوظة' : 'شركة النور',
                  'about': 'متجر',
                  'offerings': 'ملابس',
                  'services': 'توصيل',
                  'audience': null,
                  'reply_guidelines': null,
                },
              },
            ),
          );
        },
      ),
    );
    final repo = AiRepository(
      ApiClient(secureStore: _MemorySecureStore(), prefsStore: prefs, dio: dio),
    );

    final loaded = await repo.fetchCompanyProfile();
    expect(loaded.companyName, 'شركة النور');
    expect(calls.single.path, 'ai/company-profile');
    expect(calls.single.method, 'GET');

    final saved = await repo.updateCompanyProfile({
      'company_name': 'شركة محفوظة',
      'about': 'متجر',
    });
    expect(saved.companyName, 'شركة محفوظة');
    expect(calls.last.method, 'PUT');
    expect(calls.last.path, 'ai/company-profile');
  });

  testWidgets('authorized user can edit and save company profile', (tester) async {
    tester.view.physicalSize = const Size(390, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final seeded = CompanyProfileState(
      profile: const CompanyProfileModel(companyName: 'شركة النور', about: 'متجر'),
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          companyProfileControllerProvider.overrideWith(
            (ref) => _SeededCompanyProfileController(ref, seeded),
          ),
        ],
        child: const MaterialApp(
          locale: Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: CompanyProfileScreen(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('نبذة عن الشركة'), findsWidgets);
    expect(find.text('ماذا نبيع؟'), findsWidgets);
    expect(find.text('إلغاء'), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, 'شركة التحديث');
    final save = find.byKey(const Key('company-profile-save'));
    await tester.ensureVisible(save);
    await tester.tap(save);
    await tester.pumpAndSettle();

    expect(find.text('تم حفظ نبذة الشركة.'), findsOneWidget);
  });

  testWidgets('forbidden state does not show the editor', (tester) async {
    tester.view.physicalSize = const Size(390, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          companyProfileControllerProvider.overrideWith(
            (ref) => _SeededCompanyProfileController(
              ref,
              const CompanyProfileState(
                forbidden: true,
                error: 'لا تملك صلاحية إعدادات الذكاء الاصطناعي.',
              ),
            ),
          ),
        ],
        child: const MaterialApp(
          locale: Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: CompanyProfileScreen(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('لا تملك صلاحية إعدادات الذكاء الاصطناعي.'), findsOneWidget);
    expect(find.text('حفظ'), findsNothing);
  });
}
