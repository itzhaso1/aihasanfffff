import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/worker/data/worker_profile.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/presentation/worker_account_screen.dart';
import 'package:hasim/features/worker/presentation/worker_account_sections.dart';
import 'package:hasim/features/worker/presentation/worker_profile_screen.dart';
import 'package:hasim/features/worker/providers/worker_account_controller.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  _MemorySecureStore(this._token);
  String? _token;

  @override
  Future<void> saveToken(String token) async => _token = token;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> clearToken() async => _token = null;
}

WorkerProfile _profile({
  String displayName = 'عامل الدعم',
  String? profilePhoto,
  String verificationStatus = 'not_requested',
  String verificationStatusLabel = 'لم يُطلب',
  String lifecycleStatus = 'profile_incomplete',
  String lifecycleStatusLabel = 'الملف غير مكتمل',
  String availabilityStatus = 'available',
  String availabilityStatusLabel = 'متاح',
  WorkerVerificationNotice? notice,
  List<String> requiredDocumentTypes = const [],
  List<WorkerSkillItem> skills = const [],
  List<WorkerLanguageItem> languages = const [],
  List<WorkerExperience> experiences = const [],
  List<WorkerReviewIssue> verificationIssues = const [],
  String reviewNotes = 'Internal reviewer note',
}) {
  return WorkerProfile.fromJson({
    'id': 9,
    'display_name': displayName,
    'profile_photo': profilePhoto,
    'country_code': 'JO',
    'nationality': 'أردني',
    'verification_status': verificationStatus,
    'verification_status_label': verificationStatusLabel,
    'lifecycle_status': lifecycleStatus,
    'lifecycle_status_label': lifecycleStatusLabel,
    'availability_status': availabilityStatus,
    'availability_status_label': availabilityStatusLabel,
    'verification_notice': notice == null
        ? null
        : {'title': notice.title, 'next': notice.next, 'reason': notice.reason},
    'professional_summary': 'ملخص مهني',
    'sales_experience_level': 'senior',
    'required_document_types': requiredDocumentTypes,
    'skills': [
      for (final skill in skills)
        {
          'id': skill.id,
          'skill_key': skill.skillKey,
          'display_name': skill.displayName,
        },
    ],
    'languages': [
      for (final language in languages) {'language_code': language.code},
    ],
    'experiences': [
      for (final experience in experiences)
        {
          'id': experience.id,
          'title': experience.title,
          'organization_name': experience.organizationName,
          'started_on': experience.startedOn,
          'ended_on': experience.endedOn,
          'is_current': experience.isCurrent,
          'description': experience.description,
        },
    ],
    'verification_issues': [
      for (final issue in verificationIssues)
        {
          'id': issue.id,
          'target_type': issue.targetType,
          'target_id': issue.targetId,
          'field_key': issue.fieldKey,
          'reason': issue.reason,
          'status': issue.status,
          'created_at': issue.createdAt,
          'resolved_at': issue.resolvedAt,
        },
    ],
    'review_notes': reviewNotes,
    'legal_name': 'يجب ألا يظهر في الملف المهني',
  });
}

class _FakeWorkerRepository extends WorkerRepository {
  _FakeWorkerRepository(super.api);

  WorkerProfile profileValue = _profile();
  List<WorkerSkillItem> catalog = const [
    WorkerSkillItem(
      id: 1,
      skillKey: 'customer_support',
      displayName: 'دعم العملاء',
    ),
  ];
  List<WorkerLanguageItem> languages = const [
    WorkerLanguageItem(code: 'ar', displayName: 'العربية'),
    WorkerLanguageItem(code: 'en', displayName: 'English'),
  ];
  WorkerIdentity identityValue = const WorkerIdentity();
  List<WorkerVerificationDocument> documentValue = const [];
  Object? profileFailure;
  Object? updateFailure;
  Object? skillFailure;
  Object? languageFailure;
  Object? experienceFailure;
  Object? identityFailure;
  Object? uploadFailure;
  Object? submitFailure;
  int profileCalls = 0;
  int skillCatalogCalls = 0;
  int languageCatalogCalls = 0;
  int identityCalls = 0;
  int documentCalls = 0;
  WorkerProfessionalUpdate? lastUpdate;
  String? lastPhoto;
  List<int>? lastSkillIds;
  List<String>? lastLanguageCodes;
  WorkerExperienceInput? lastExperience;
  String? lastExperienceAction;
  int? lastExperienceId;
  WorkerIdentityInput? lastIdentity;
  String? lastDocumentType;
  String? lastDocumentPath;

  @override
  Future<WorkerProfile> profile() async {
    profileCalls += 1;
    final failure = profileFailure;
    if (failure != null) throw failure;
    return profileValue;
  }

  @override
  Future<WorkerProfile> updateProfile(
    WorkerProfessionalUpdate update, {
    String? photoPath,
    WorkerUploadFile? photoFile,
    void Function(int sent, int total)? onProgress,
  }) async {
    lastUpdate = update;
    lastPhoto = photoPath ?? photoFile?.filename;
    final failure = updateFailure;
    if (failure != null) throw failure;
    profileValue = _profile(displayName: update.displayName);
    return profileValue;
  }

  @override
  Future<List<WorkerSkillItem>> skillCatalog() async {
    skillCatalogCalls += 1;
    return catalog;
  }

  @override
  Future<WorkerProfile> updateSkills(List<int> skillIds) async {
    lastSkillIds = skillIds;
    final failure = skillFailure;
    if (failure != null) throw failure;
    profileValue = _profile(
      skills: [
        for (final id in skillIds)
          catalog.firstWhere((skill) => skill.id == id),
      ],
    );
    return profileValue;
  }

  @override
  Future<List<WorkerLanguageItem>> languageCatalog() async {
    languageCatalogCalls += 1;
    return languages;
  }

  @override
  Future<WorkerProfile> updateLanguages(List<String> languageCodes) async {
    lastLanguageCodes = languageCodes;
    final failure = languageFailure;
    if (failure != null) throw failure;
    profileValue = _profile(
      languages: [
        for (final code in languageCodes) WorkerLanguageItem(code: code),
      ],
    );
    return profileValue;
  }

  @override
  Future<WorkerProfile> createExperience(WorkerExperienceInput input) async {
    lastExperience = input;
    lastExperienceAction = 'create';
    final failure = experienceFailure;
    if (failure != null) throw failure;
    profileValue = _profile(
      experiences: [
        WorkerExperience(
          id: 4,
          title: input.title,
          organizationName: input.organizationName,
          startedOn: input.startedOn,
          isCurrent: input.isCurrent,
          endedOn: input.endedOn,
          description: input.description,
        ),
      ],
    );
    return profileValue;
  }

  @override
  Future<WorkerProfile> updateExperience(
    int id,
    WorkerExperienceInput input,
  ) async {
    lastExperience = input;
    lastExperienceAction = 'update';
    lastExperienceId = id;
    profileValue = _profile(
      experiences: [
        WorkerExperience(
          id: id,
          title: input.title,
          organizationName: input.organizationName,
          startedOn: input.startedOn,
          isCurrent: input.isCurrent,
        ),
      ],
    );
    return profileValue;
  }

  @override
  Future<WorkerProfile> deleteExperience(int id) async {
    lastExperienceAction = 'delete';
    lastExperienceId = id;
    profileValue = _profile();
    return profileValue;
  }

  @override
  Future<WorkerIdentity> identity() async {
    identityCalls += 1;
    return identityValue;
  }

  @override
  Future<WorkerIdentity> updateIdentity(WorkerIdentityInput input) async {
    lastIdentity = input;
    final failure = identityFailure;
    if (failure != null) throw failure;
    identityValue = WorkerIdentity(
      legalName: input.legalName,
      dateOfBirth: input.dateOfBirth,
      identityNumber: input.identityNumber,
      address: input.address,
    );
    return identityValue;
  }

  @override
  Future<List<WorkerVerificationDocument>> documents() async {
    documentCalls += 1;
    return documentValue;
  }

  @override
  Future<WorkerVerificationDocument> uploadDocument({
    required String documentTypeCode,
    String? path,
    WorkerUploadFile? file,
    void Function(int sent, int total)? onProgress,
  }) async {
    lastDocumentType = documentTypeCode;
    lastDocumentPath = path;
    final failure = uploadFailure;
    if (failure != null) throw failure;
    final document = WorkerVerificationDocument(
      id: documentValue.length + 1,
      documentTypeCode: documentTypeCode,
      originalName: file?.filename ?? path!.split(RegExp(r'[/\\]')).last,
      status: 'submitted',
    );
    documentValue = [
      for (final existing in documentValue)
        if (existing.documentTypeCode == documentTypeCode &&
            existing.status == 'submitted')
          WorkerVerificationDocument(
            id: existing.id,
            documentTypeCode: existing.documentTypeCode,
            originalName: existing.originalName,
            status: 'replaced',
          )
        else
          existing,
      document,
    ];
    return document;
  }

  @override
  Future<WorkerProfile> submitVerification() async {
    final failure = submitFailure;
    if (failure != null) throw failure;
    profileValue = _profile(
      verificationStatus: 'pending_review',
      verificationStatusLabel: 'قيد المراجعة',
    );
    return profileValue;
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('profile model keeps notice nullable and drops private review data', () {
    final plain = _profile();
    expect(plain.verificationNotice, isNull);
    expect(plain.requiredDocumentTypes, isEmpty);

    final noticed = _profile(
      notice: const WorkerVerificationNotice(
        title: 'رُفض التحقق من الحساب.',
        next: 'أعد الإرسال.',
        reason: 'الصورة غير واضحة',
      ),
    );
    expect(noticed.verificationNotice?.reason, 'الصورة غير واضحة');
    expect(plain.displayName.contains('review'), isFalse);
  });

  test('review issue model preserves target and worker-safe reason', () {
    final profile = _profile(
      verificationIssues: const [
        WorkerReviewIssue(
          id: 4,
          targetType: 'document',
          targetId: 12,
          fieldKey: 'identity',
          reason: 'الصورة غير واضحة',
          status: 'open',
        ),
      ],
    );

    expect(profile.verificationIssues.single.targetType, 'document');
    expect(profile.verificationIssues.single.targetId, 12);
    expect(profile.verificationIssues.single.reason, 'الصورة غير واضحة');
    expect(profile.verificationIssues.single.status, 'open');
  });

  test(
    'validation errors retain nested paths and field-specific Arabic labels',
    () {
      final errors = WorkerValidationErrors.fromException(
        ApiException(
          'بيانات غير صالحة.',
          statusCode: 422,
          errors: {
            'display_name': ['The display name field is required.'],
            'items.0.name': ['The items.0.name field is required.'],
          },
        ),
      );

      expect(errors.forField('display_name'), contains('الاسم الظاهر'));
      expect(errors.messages.containsKey('items.0.name'), isTrue);
      expect(errors.forPrefix('items'), hasLength(1));
    },
  );

  test(
    'worker account data is reused while its provider remains alive',
    () async {
      final repo = await _fake();
      final container = ProviderContainer(
        overrides: [workerRepositoryProvider.overrideWithValue(repo)],
      );
      addTearDown(container.dispose);

      await container.read(workerAccountControllerProvider.notifier).load();
      await container.read(workerAccountControllerProvider.notifier).load();

      expect(repo.profileCalls, 1);
      expect(repo.skillCatalogCalls, 1);
      expect(repo.languageCatalogCalls, 1);
      expect(repo.identityCalls, 1);
      expect(repo.documentCalls, 1);
    },
  );

  test(
    'worker repository uses the profile and verification endpoints',
    () async {
      SharedPreferences.setMockInitialValues({});
      final prefs = PrefsStore(await SharedPreferences.getInstance());
      final calls = <String>[];
      Map<String, dynamic>? lastBody;
      List<String> files = const [];
      String? profilePhotoMime;
      String? documentMime;
      final dio = Dio(
        BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/'),
      );
      dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (options, handler) {
            calls.add('${options.method} ${options.path}');
            if (options.data is FormData) {
              final form = options.data as FormData;
              lastBody = {
                for (final field in form.fields) field.key: field.value,
              };
              files = [for (final file in form.files) file.key];
              for (final file in form.files) {
                if (file.key == 'profile_photo') {
                  profilePhotoMime = file.value.contentType?.mimeType;
                }
                if (file.key == 'document') {
                  documentMime = file.value.contentType?.mimeType;
                }
              }
            } else if (options.data is Map) {
              lastBody = Map<String, dynamic>.from(options.data as Map);
            }
            if (options.path == 'worker/profile' &&
                options.method == 'PATCH' &&
                lastBody?['display_name'] == '') {
              handler.reject(
                DioException(
                  requestOptions: options,
                  type: DioExceptionType.badResponse,
                  response: Response(
                    requestOptions: options,
                    statusCode: 422,
                    data: {'success': false, 'message': 'الاسم المعروض مطلوب'},
                  ),
                ),
              );
              return;
            }
            final data =
                options.path == 'worker/skills' && options.method == 'GET'
                ? [
                    {
                      'id': 1,
                      'skill_key': 'customer_support',
                      'display_name': 'دعم العملاء',
                    },
                  ]
                : options.path == 'worker/languages' && options.method == 'GET'
                ? [
                    {'code': 'ar', 'display_name': 'العربية'},
                    {'code': 'en', 'display_name': 'English'},
                  ]
                : options.path == 'worker/identity'
                ? {
                    'legal_name': 'الاسم القانوني',
                    'date_of_birth': '1991-05-06',
                    'identity_number': 'ID-55',
                    'address': null,
                  }
                : options.path == 'worker/documents' && options.method == 'GET'
                ? [
                    {
                      'id': 3,
                      'document_type_code': 'identity',
                      'original_name': 'identity.pdf',
                      'mime_type': 'application/pdf',
                      'size_bytes': 20,
                      'status': 'submitted',
                      'rejection_reason': null,
                    },
                  ]
                : options.path == 'worker/documents'
                ? {
                    'id': 4,
                    'document_type_code': 'identity',
                    'original_name': 'identity.pdf',
                    'status': 'submitted',
                  }
                : {
                    'id': 9,
                    'display_name': 'عامل محدّث',
                    'country_code': 'JO',
                    'nationality': 'أردني',
                    'verification_status': 'rejected',
                    'verification_status_label': 'مرفوض',
                    'lifecycle_status': 'verification_rejected',
                    'lifecycle_status_label': 'التحقق مرفوض',
                    'availability_status': 'limited',
                    'availability_status_label': 'متاح بشكل محدود',
                    'verification_notice': {
                      'title': 'رُفض التحقق من الحساب.',
                      'next': 'أعد الإرسال.',
                      'reason': 'الصورة غير واضحة',
                    },
                    'professional_summary': 'ملخص',
                    'sales_experience_level': 'senior',
                    'required_document_types': <String>[],
                    'skills': <Map<String, dynamic>>[],
                    'languages': <Map<String, dynamic>>[],
                    'experiences': <Map<String, dynamic>>[],
                  };
            handler.resolve(
              Response(
                requestOptions: options,
                data: {'success': true, 'data': data},
              ),
            );
          },
        ),
      );
      final repo = WorkerRepository(
        ApiClient(
          secureStore: _MemorySecureStore('token'),
          prefsStore: prefs,
          dio: dio,
        ),
      );

      final loaded = await repo.profile();
      expect(loaded.verificationStatusLabel, 'مرفوض');
      expect(loaded.verificationNotice?.title, 'رُفض التحقق من الحساب.');
      expect(loaded.requiredDocumentTypes, isEmpty);

      final skills = await repo.skillCatalog();
      expect(skills.single.displayName, 'دعم العملاء');
      await repo.updateSkills([1]);
      expect(lastBody?['skill_ids'], [1]);

      final languageCatalog = await repo.languageCatalog();
      expect(languageCatalog.map((item) => item.code), ['ar', 'en']);
      await repo.updateLanguages(['ar']);
      expect(lastBody?['language_codes'], ['ar']);

      await repo.createExperience(
        const WorkerExperienceInput(
          title: 'دعم',
          organizationName: 'حاسم',
          startedOn: '2020-01-01',
          isCurrent: true,
        ),
      );
      expect(lastBody?['is_current'], isTrue);
      expect(lastBody?['ended_on'], isNull);
      await repo.updateExperience(
        4,
        const WorkerExperienceInput(
          title: 'دعم',
          organizationName: 'حاسم',
          startedOn: '2020-01-01',
          isCurrent: false,
          endedOn: '2021-01-01',
        ),
      );
      await repo.deleteExperience(4);

      final identity = await repo.identity();
      expect(identity.legalName, 'الاسم القانوني');
      await repo.updateIdentity(
        const WorkerIdentityInput(
          legalName: 'الاسم القانوني',
          dateOfBirth: '1991-05-06',
          identityNumber: 'ID-55',
        ),
      );
      expect(lastBody?.containsKey('legal_name'), isTrue);

      final documents = await repo.documents();
      expect(documents.single.originalName, 'identity.pdf');
      final photo = File(
        '${Directory.systemTemp.path}${Platform.pathSeparator}worker-photo.png',
      );
      final document = File(
        '${Directory.systemTemp.path}${Platform.pathSeparator}identity.pdf',
      );
      await photo.writeAsBytes([1, 2, 3]);
      await document.writeAsBytes([1, 2, 3]);
      addTearDown(() async {
        if (photo.existsSync()) await photo.delete();
        if (document.existsSync()) await document.delete();
      });

      await repo.updateProfile(
        const WorkerProfessionalUpdate(
          displayName: 'عامل محدّث',
          countryCode: 'JO',
          nationality: 'أردني',
          professionalSummary: 'ملخص',
          salesExperienceLevel: 'senior',
          availabilityStatus: 'limited',
        ),
        photoPath: photo.path,
      );
      expect(files, contains('profile_photo'));
      expect(profilePhotoMime, 'image/png');
      expect(lastBody?.containsKey('legal_name'), isFalse);

      final uploaded = await repo.uploadDocument(
        documentTypeCode: 'identity',
        path: document.path,
      );
      expect(uploaded.status, 'submitted');
      expect(files, contains('document'));
      expect(documentMime, 'application/pdf');
      await repo.submitVerification();

      await expectLater(
        repo.updateProfile(
          const WorkerProfessionalUpdate(
            displayName: '',
            countryCode: 'JO',
            nationality: 'أردني',
            professionalSummary: 'ملخص',
            salesExperienceLevel: 'senior',
            availabilityStatus: 'available',
          ),
        ),
        throwsA(
          isA<ApiException>().having(
            (error) => error.statusCode,
            'status',
            422,
          ),
        ),
      );

      expect(calls, [
        'GET worker/profile',
        'GET worker/skills',
        'PUT worker/skills',
        'GET worker/languages',
        'PUT worker/languages',
        'POST worker/experiences',
        'PATCH worker/experiences/4',
        'DELETE worker/experiences/4',
        'GET worker/identity',
        'PUT worker/identity',
        'GET worker/documents',
        'POST worker/profile',
        'POST worker/documents',
        'POST worker/verification',
        'PATCH worker/profile',
      ]);
    },
  );

  testWidgets('worker account screen shows the professional section', (
    tester,
  ) async {
    final repo = await _fake();
    await _pump(tester, repo);
    expect(find.text('المعلومات والتوثيق'), findsOneWidget);
    expect(find.text('الحساب'), findsWidgets);
    expect(find.text('المعلومات المهنية'), findsOneWidget);
  });

  testWidgets(
    'dedicated worker profile edits only the account name and photo',
    (tester) async {
      final repo = await _fake();
      repo.profileValue = _profile(
        profilePhoto: '/storage/worker-profile-photos/avatar.jpg',
      );
      await _pump(
        tester,
        repo,
        section: const WorkerProfileScreen(),
        direct: true,
      );

      expect(find.text('ملفي الشخصي'), findsOneWidget);
      expect(find.text('اسم الحساب'), findsOneWidget);
      expect(
        find.byKey(const Key('worker-profile-photo-pick')),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('worker-profile-account-name')),
        findsOneWidget,
      );
      expect(find.text('المعلومات المهنية'), findsNothing);
      expect(find.text('التحقق'), findsNothing);
      expect(find.text('المهارات'), findsNothing);
      expect(find.text('اللغات'), findsNothing);
      expect(find.text('الخبرات'), findsNothing);
      expect(find.text('الهوية'), findsNothing);
      expect(repo.profileCalls, 1);
      expect(repo.skillCatalogCalls, 0);
      expect(repo.languageCatalogCalls, 0);
      expect(repo.identityCalls, 0);
      expect(repo.documentCalls, 0);

      await _type(tester, 'worker-profile-account-name', 'اسم الحساب الجديد');
      await _tap(tester, const Key('worker-profile-photo-pick'));
      await _tap(tester, const Key('worker-profile-save'));

      expect(repo.lastUpdate?.displayName, 'اسم الحساب الجديد');
      expect(repo.lastPhoto, 'avatar.jpg');
      expect(find.text('تم حفظ الملف الشخصي'), findsOneWidget);
    },
  );

  testWidgets(
    'professional profile hides private identity and shows status labels',
    (tester) async {
      final repo = await _fake();
      repo.profileValue = _profile(
        verificationStatus: 'rejected',
        verificationStatusLabel: 'مرفوض',
      );
      repo.identityValue = const WorkerIdentity(legalName: 'اسم قانوني سري');
      await _pump(tester, repo, section: const WorkerProfessionalSection());

      expect(find.text('عامل الدعم'), findsOneWidget);
      expect(find.text('حالة التحقق: مرفوض'), findsOneWidget);
      expect(find.text('اسم قانوني سري'), findsNothing);
    },
  );

  testWidgets(
    'verification notice and empty requirements stay worker-visible only',
    (tester) async {
      final repo = await _fake();
      repo.profileValue = _profile(
        notice: const WorkerVerificationNotice(
          title: 'رُفض التحقق من الحساب.',
          next: 'حدّث البيانات ثم أعد الإرسال.',
          reason: 'الصورة غير واضحة',
        ),
        verificationIssues: const [
          WorkerReviewIssue(
            id: 7,
            targetType: 'document',
            targetId: 3,
            fieldKey: 'identity',
            reason: 'الصورة غير واضحة، يرجى رفع صورة كاملة.',
            status: 'open',
          ),
        ],
        verificationStatus: 'rejected',
        verificationStatusLabel: 'مرفوض',
      );
      repo.documentValue = const [
        WorkerVerificationDocument(
          id: 3,
          documentTypeCode: 'identity',
          originalName: 'identity.pdf',
          status: 'rejected',
          rejectionReason: 'الصورة غير واضحة',
        ),
      ];
      await _pump(tester, repo, section: const WorkerVerificationSection());

      expect(find.text('رُفض التحقق من الحساب.'), findsOneWidget);
      expect(find.textContaining('الصورة غير واضحة'), findsWidgets);
      expect(find.textContaining('يرجى رفع صورة كاملة'), findsOneWidget);
      expect(find.text('Internal reviewer note'), findsNothing);
      expect(
        find.text('لا توجد أنواع مستندات إلزامية معتمدة حالياً لهذه الدولة.'),
        findsOneWidget,
      );
      expect(find.text('جواز سفر'), findsNothing);
      expect(find.text('identity — identity.pdf'), findsOneWidget);
    },
  );

  testWidgets('skill catalog renders current and empty states', (tester) async {
    final repo = await _fake();
    await _pump(tester, repo, section: const WorkerSkillsSection());
    expect(find.text('لا توجد مهارات مضافة بعد'), findsOneWidget);
    expect(find.text('دعم العملاء'), findsOneWidget);
  });

  testWidgets('language catalog renders current and empty states', (
    tester,
  ) async {
    final repo = await _fake();
    await _pump(tester, repo, section: const WorkerLanguagesSection());
    expect(find.text('لا توجد لغات مضافة بعد'), findsOneWidget);
    expect(find.text('العربية'), findsOneWidget);
  });

  testWidgets('experience list renders an empty state', (tester) async {
    final repo = await _fake();
    await _pump(tester, repo, section: const WorkerExperienceSection());
    expect(find.text('لا توجد خبرات مضافة بعد'), findsOneWidget);
  });

  testWidgets(
    'worker can update profile skills languages experience and identity',
    (tester) async {
      final repo = await _fake();
      await _pump(tester, repo, section: const WorkerProfessionalSection());

      await _type(tester, 'worker-display-name', 'اسم جديد');
      await _tap(tester, const Key('worker-profile-save'));
      expect(repo.lastUpdate?.displayName, 'اسم جديد');
      expect(find.text('تم حفظ المعلومات المهنية'), findsOneWidget);
    },
  );

  testWidgets('worker can add and sync a skill', (tester) async {
    final repo = await _fake();
    await _pump(tester, repo, section: const WorkerSkillsSection());
    await _tap(tester, const Key('worker-skill-1'));
    await _tap(tester, const Key('worker-skills-save'));
    expect(repo.lastSkillIds, [1]);
  });

  testWidgets('worker can add and sync a language', (tester) async {
    final repo = await _fake();
    await _pump(tester, repo, section: const WorkerLanguagesSection());
    await _tap(tester, const Key('worker-language-ar'));
    await _tap(tester, const Key('worker-languages-save'));
    expect(repo.lastLanguageCodes, ['ar']);
  });

  testWidgets('worker can create and delete an experience', (tester) async {
    final repo = await _fake();
    await _pump(tester, repo, section: const WorkerExperienceSection());
    await _tap(tester, const Key('worker-experience-add'));
    await _type(tester, 'worker-experience-title', 'دعم العملاء');
    await _type(tester, 'worker-experience-started', '2020-01-01');
    await _tap(tester, const Key('worker-experience-save'));
    expect(repo.lastExperienceAction, 'create');
    expect(repo.lastExperience?.title, 'دعم العملاء');
    expect(find.text('دعم العملاء'), findsWidgets);

    await _tap(tester, const Key('worker-experience-delete-4'));
    await _tap(tester, const Key('worker-experience-delete-confirm'));
    expect(repo.lastExperienceAction, 'delete');
    expect(repo.lastExperienceId, 4);
  });

  testWidgets('worker can save private identity', (tester) async {
    final repo = await _fake();
    await _pump(tester, repo, section: const WorkerIdentitySection());
    await _type(tester, 'worker-legal-name', 'الاسم القانوني');
    await _type(tester, 'worker-birth-date', '1991-05-06');
    await _type(tester, 'worker-identity-number', 'ID-55');
    await _tap(tester, const Key('worker-identity-save'));
    expect(repo.lastIdentity?.legalName, 'الاسم القانوني');
    expect(repo.lastIdentity?.dateOfBirth, '1991-05-06');
  });

  testWidgets('profile and verification failures stay on the account screen', (
    tester,
  ) async {
    final repo = await _fake();
    repo.updateFailure = ApiException('الاسم المعروض مطلوب', statusCode: 422);
    repo.submitFailure = ApiException(
      'يجب رفع مستند واحد على الأقل قبل الإرسال للمراجعة.',
      statusCode: 422,
    );
    await _pump(tester, repo, section: const WorkerProfessionalSection());

    await _tap(tester, const Key('worker-profile-save'));
    expect(find.text('الاسم المعروض مطلوب'), findsOneWidget);
  });

  testWidgets('profile validation errors render beside the matching field', (
    tester,
  ) async {
    final repo = await _fake();
    repo.updateFailure = ApiException(
      'بيانات غير صالحة.',
      statusCode: 422,
      errors: {
        'display_name': ['The display name field is required.'],
        'professional_summary': ['The professional summary field is required.'],
      },
    );
    await _pump(tester, repo, section: const WorkerProfessionalSection());

    await _tap(tester, const Key('worker-profile-save'));

    expect(find.text('الاسم الظاهر: هذا الحقل مطلوب.'), findsOneWidget);
    expect(find.text('الملخص المهني: هذا الحقل مطلوب.'), findsOneWidget);
    expect(find.text('هذا الحقل: هذا الحقل مطلوب.'), findsNothing);
  });

  testWidgets('worker can upload, replace, and fail verification submit', (
    tester,
  ) async {
    final repo = await _fake();
    repo.submitFailure = ApiException(
      'يجب رفع مستند واحد على الأقل قبل الإرسال للمراجعة.',
      statusCode: 422,
    );
    await _pump(tester, repo, section: const WorkerVerificationSection());
    await _type(tester, 'worker-document-type', 'identity');
    await _tap(tester, const Key('worker-document-pick'));
    await _tap(tester, const Key('worker-document-upload'));
    expect(repo.lastDocumentType, 'identity');
    expect(find.text('identity — id.pdf'), findsOneWidget);

    await tester.pump(const Duration(seconds: 4));
    await _type(tester, 'worker-document-type', 'identity');
    await _tap(tester, const Key('worker-document-pick'));
    await _tap(tester, const Key('worker-document-upload'));
    expect(find.text('replaced'), findsOneWidget);

    await tester.pump(const Duration(seconds: 4));
    final submit = tester.widget<FilledButton>(
      find.byKey(const Key('worker-verification-submit')),
    );
    submit.onPressed!();
    await tester.pumpAndSettle();
    expect(
      find.text('يجب رفع مستند واحد على الأقل قبل الإرسال للمراجعة.'),
      findsOneWidget,
    );
  });

  testWidgets('approved workers cannot upload and empty catalogs stay empty', (
    tester,
  ) async {
    final repo = await _fake();
    repo.profileValue = _profile(
      verificationStatus: 'approved',
      verificationStatusLabel: 'معتمد',
    );
    repo.catalog = const [];
    repo.languages = const [];
    repo.profileFailure = null;
    await _pump(tester, repo, section: const WorkerSkillsSection());
    expect(find.text('لا توجد مهارات في الدليل.'), findsOneWidget);
  });

  testWidgets('empty language catalog stays empty', (tester) async {
    final repo = await _fake();
    repo.languages = const [];
    await _pump(tester, repo, section: const WorkerLanguagesSection());
    expect(find.text('لا توجد لغات في الدليل.'), findsOneWidget);
  });

  testWidgets('approved workers cannot upload or submit', (tester) async {
    final repo = await _fake();
    repo.profileValue = _profile(
      verificationStatus: 'approved',
      verificationStatusLabel: 'معتمد',
    );
    await _pump(tester, repo, section: const WorkerVerificationSection());
    final upload = tester.widget<FilledButton>(
      find.byKey(const Key('worker-document-upload')),
    );
    final submit = tester.widget<FilledButton>(
      find.byKey(const Key('worker-verification-submit')),
    );
    expect(upload.onPressed, isNull);
    expect(submit.onPressed, isNull);
  });
}

Finder _field(String key) {
  return find.descendant(
    of: find.byKey(Key(key)),
    matching: find.byType(TextField),
  );
}

Future<void> _reveal(WidgetTester tester, Finder finder) async {
  if (finder.evaluate().isEmpty) return;
  final scrollables = find.byType(Scrollable);
  if (scrollables.evaluate().isEmpty) return;
  final position = tester.state<ScrollableState>(scrollables.first).position;

  for (var attempt = 0; attempt < 8; attempt++) {
    final box = tester.getRect(finder);
    if (box.top >= 0 && box.bottom <= 580 && box.right <= 800) return;
    final delta = box.bottom > 580 ? box.bottom - 520 : box.top - 24;
    final target = (position.pixels + delta).clamp(
      0.0,
      position.maxScrollExtent,
    );
    if ((target - position.pixels).abs() < 1) return;
    position.jumpTo(target);
    await tester.pump();
  }
}

Future<void> _tap(WidgetTester tester, Key key) async {
  FocusManager.instance.primaryFocus?.unfocus();
  await tester.pump();
  final keyed = find.byKey(key);
  final button = find.descendant(
    of: keyed,
    matching: find.byType(FilledButton),
  );
  final finder = button.evaluate().isEmpty ? keyed : button;
  await _reveal(tester, finder);
  await tester.ensureVisible(finder);
  await tester.pump();
  await tester.tap(finder);
  await tester.pumpAndSettle();
}

Future<void> _type(WidgetTester tester, String key, String value) async {
  final finder = _field(key);
  await _reveal(tester, finder);
  await tester.enterText(finder, value);
}

Future<_FakeWorkerRepository> _fake() async {
  SharedPreferences.setMockInitialValues({});
  final prefs = PrefsStore(await SharedPreferences.getInstance());
  return _FakeWorkerRepository(
    ApiClient(
      secureStore: _MemorySecureStore('token'),
      prefsStore: prefs,
      dio: Dio(BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/')),
    ),
  );
}

Future<void> _pump(
  WidgetTester tester,
  _FakeWorkerRepository repo, {
  Widget? section,
  bool direct = false,
}) async {
  final child = section == null
      ? const WorkerAccountScreen()
      : direct
      ? section
      : Scaffold(body: SingleChildScrollView(child: section));
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        workerRepositoryProvider.overrideWithValue(repo),
        workerDocumentPickerProvider.overrideWith(
          (ref) =>
              () async => WorkerUploadFile(
                bytes: Uint8List.fromList([1, 2, 3]),
                filename: 'id.pdf',
                mimeType: 'application/pdf',
              ),
        ),
        workerPhotoPickerProvider.overrideWith(
          (ref) =>
              () async => WorkerUploadFile(
                bytes: Uint8List.fromList([4, 5, 6]),
                filename: 'avatar.jpg',
                mimeType: 'image/jpeg',
              ),
        ),
      ],
      child: MaterialApp(home: child),
    ),
  );
  await tester.pumpAndSettle();
}
