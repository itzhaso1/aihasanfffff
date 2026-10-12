import 'package:file_picker/file_picker.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/worker/data/worker_profile.dart';
import 'package:image_picker/image_picker.dart';

typedef WorkerFilePicker = Future<WorkerUploadFile?> Function();

final workerPhotoPickerProvider = Provider<WorkerFilePicker>((ref) {
  return () async {
    final file = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      imageQuality: 85,
    );
    if (file == null) return null;
    return WorkerUploadFile(
      bytes: await file.readAsBytes(),
      filename: file.name,
      mimeType: _mimeTypeForFilename(file.name),
    );
  };
});

final workerDocumentPickerProvider = Provider<WorkerFilePicker>((ref) {
  return () async {
    final result = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
      withData: true,
    );
    final file = result?.files.single;
    final bytes = file?.bytes;
    if (file == null || bytes == null) return null;
    return WorkerUploadFile(
      bytes: bytes,
      filename: file.name,
      mimeType: _mimeTypeForFilename(file.name),
    );
  };
});

String? _mimeTypeForFilename(String filename) {
  final name = filename.toLowerCase();
  final extension = name.contains('.') ? name.split('.').last : '';

  return switch (extension) {
    'jpg' || 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'pdf' => 'application/pdf',
    _ => null,
  };
}

class WorkerAccountState {
  const WorkerAccountState({
    this.profileLoading = true,
    this.profileError,
    this.profile,
    this.profileBusy = false,
    this.profileActionError,
    this.profileValidationErrors = WorkerValidationErrors.empty,
    this.photoProgress,
    this.skillCatalog = const [],
    this.skillsLoading = true,
    this.skillsError,
    this.skillsBusy = false,
    this.skillsValidationErrors = WorkerValidationErrors.empty,
    this.languageCatalog = const [],
    this.languagesLoading = true,
    this.languagesError,
    this.languagesBusy = false,
    this.languagesValidationErrors = WorkerValidationErrors.empty,
    this.experienceBusy = false,
    this.experienceError,
    this.experienceValidationErrors = WorkerValidationErrors.empty,
    this.identity,
    this.identityLoading = true,
    this.identityError,
    this.identityBusy = false,
    this.identityValidationErrors = WorkerValidationErrors.empty,
    this.documents = const [],
    this.documentsLoading = true,
    this.documentsError,
    this.documentsBusy = false,
    this.documentsValidationErrors = WorkerValidationErrors.empty,
    this.documentProgress,
    this.verificationBusy = false,
    this.verificationError,
    this.verificationValidationErrors = WorkerValidationErrors.empty,
  });

  final bool profileLoading;
  final String? profileError;
  final WorkerProfile? profile;
  final bool profileBusy;
  final String? profileActionError;
  final WorkerValidationErrors profileValidationErrors;
  final double? photoProgress;
  final List<WorkerSkillItem> skillCatalog;
  final bool skillsLoading;
  final String? skillsError;
  final bool skillsBusy;
  final WorkerValidationErrors skillsValidationErrors;
  final List<WorkerLanguageItem> languageCatalog;
  final bool languagesLoading;
  final String? languagesError;
  final bool languagesBusy;
  final WorkerValidationErrors languagesValidationErrors;
  final bool experienceBusy;
  final String? experienceError;
  final WorkerValidationErrors experienceValidationErrors;
  final WorkerIdentity? identity;
  final bool identityLoading;
  final String? identityError;
  final bool identityBusy;
  final WorkerValidationErrors identityValidationErrors;
  final List<WorkerVerificationDocument> documents;
  final bool documentsLoading;
  final String? documentsError;
  final bool documentsBusy;
  final WorkerValidationErrors documentsValidationErrors;
  final double? documentProgress;
  final bool verificationBusy;
  final String? verificationError;
  final WorkerValidationErrors verificationValidationErrors;

  WorkerAccountState copyWith({
    bool? profileLoading,
    Object? profileError = _keep,
    Object? profile = _keep,
    bool? profileBusy,
    Object? profileActionError = _keep,
    WorkerValidationErrors? profileValidationErrors,
    Object? photoProgress = _keep,
    List<WorkerSkillItem>? skillCatalog,
    bool? skillsLoading,
    Object? skillsError = _keep,
    bool? skillsBusy,
    WorkerValidationErrors? skillsValidationErrors,
    List<WorkerLanguageItem>? languageCatalog,
    bool? languagesLoading,
    Object? languagesError = _keep,
    bool? languagesBusy,
    WorkerValidationErrors? languagesValidationErrors,
    bool? experienceBusy,
    Object? experienceError = _keep,
    WorkerValidationErrors? experienceValidationErrors,
    Object? identity = _keep,
    bool? identityLoading,
    Object? identityError = _keep,
    bool? identityBusy,
    WorkerValidationErrors? identityValidationErrors,
    List<WorkerVerificationDocument>? documents,
    bool? documentsLoading,
    Object? documentsError = _keep,
    bool? documentsBusy,
    WorkerValidationErrors? documentsValidationErrors,
    Object? documentProgress = _keep,
    bool? verificationBusy,
    Object? verificationError = _keep,
    WorkerValidationErrors? verificationValidationErrors,
  }) {
    return WorkerAccountState(
      profileLoading: profileLoading ?? this.profileLoading,
      profileError: profileError == _keep
          ? this.profileError
          : profileError as String?,
      profile: profile == _keep ? this.profile : profile as WorkerProfile?,
      profileBusy: profileBusy ?? this.profileBusy,
      profileActionError: profileActionError == _keep
          ? this.profileActionError
          : profileActionError as String?,
      profileValidationErrors:
          profileValidationErrors ?? this.profileValidationErrors,
      photoProgress: photoProgress == _keep
          ? this.photoProgress
          : photoProgress as double?,
      skillCatalog: skillCatalog ?? this.skillCatalog,
      skillsLoading: skillsLoading ?? this.skillsLoading,
      skillsError: skillsError == _keep
          ? this.skillsError
          : skillsError as String?,
      skillsBusy: skillsBusy ?? this.skillsBusy,
      skillsValidationErrors:
          skillsValidationErrors ?? this.skillsValidationErrors,
      languageCatalog: languageCatalog ?? this.languageCatalog,
      languagesLoading: languagesLoading ?? this.languagesLoading,
      languagesError: languagesError == _keep
          ? this.languagesError
          : languagesError as String?,
      languagesBusy: languagesBusy ?? this.languagesBusy,
      languagesValidationErrors:
          languagesValidationErrors ?? this.languagesValidationErrors,
      experienceBusy: experienceBusy ?? this.experienceBusy,
      experienceError: experienceError == _keep
          ? this.experienceError
          : experienceError as String?,
      experienceValidationErrors:
          experienceValidationErrors ?? this.experienceValidationErrors,
      identity: identity == _keep ? this.identity : identity as WorkerIdentity?,
      identityLoading: identityLoading ?? this.identityLoading,
      identityError: identityError == _keep
          ? this.identityError
          : identityError as String?,
      identityBusy: identityBusy ?? this.identityBusy,
      identityValidationErrors:
          identityValidationErrors ?? this.identityValidationErrors,
      documents: documents ?? this.documents,
      documentsLoading: documentsLoading ?? this.documentsLoading,
      documentsError: documentsError == _keep
          ? this.documentsError
          : documentsError as String?,
      documentsBusy: documentsBusy ?? this.documentsBusy,
      documentsValidationErrors:
          documentsValidationErrors ?? this.documentsValidationErrors,
      documentProgress: documentProgress == _keep
          ? this.documentProgress
          : documentProgress as double?,
      verificationBusy: verificationBusy ?? this.verificationBusy,
      verificationError: verificationError == _keep
          ? this.verificationError
          : verificationError as String?,
      verificationValidationErrors:
          verificationValidationErrors ?? this.verificationValidationErrors,
    );
  }
}

const _keep = Object();

final workerAccountControllerProvider =
    StateNotifierProvider.autoDispose<
      WorkerAccountController,
      WorkerAccountState
    >((ref) {
      final keepAlive = ref.keepAlive();
      final controller = WorkerAccountController(ref);
      ref.listen<AuthState>(authControllerProvider, (previous, next) {
        if (previous?.isolationKey == next.isolationKey) return;
        if (!next.isAuthenticated || !next.isIndependentWorker) {
          keepAlive.close();
        }
      });
      controller.loadProfile();
      return controller;
    });

class WorkerAccountController extends StateNotifier<WorkerAccountState> {
  WorkerAccountController(this._ref) : super(const WorkerAccountState());

  final Ref _ref;
  Future<void>? _loadFuture;
  Future<void>? _profileFuture;
  Future<void>? _skillCatalogFuture;
  Future<void>? _languageCatalogFuture;
  Future<void>? _identityFuture;
  Future<void>? _documentsFuture;
  bool _profileLoaded = false;
  bool _skillCatalogLoaded = false;
  bool _languageCatalogLoaded = false;
  bool _identityLoaded = false;
  bool _documentsLoaded = false;
  bool _loaded = false;

  Future<void> load({bool force = false}) {
    if (_loadFuture != null) return _loadFuture!;
    if (_loaded && !force) return Future<void>.value();

    final future = _loadAll(force: force);
    _loadFuture = future.whenComplete(() {
      _loadFuture = null;
      _loaded =
          _profileLoaded &&
          _skillCatalogLoaded &&
          _languageCatalogLoaded &&
          _identityLoaded &&
          _documentsLoaded;
    });
    return _loadFuture!;
  }

  Future<void> _loadAll({required bool force}) async {
    await Future.wait([
      loadProfile(force: force),
      loadSkillCatalog(force: force),
      loadLanguageCatalog(force: force),
      loadIdentity(force: force),
      loadDocuments(force: force),
    ]);
  }

  Future<void> loadProfile({bool force = false}) {
    final ongoing = _profileFuture;
    if (ongoing != null) return ongoing;
    if (_profileLoaded && !force) return Future<void>.value();

    final future = _loadProfile();
    _profileFuture = future.whenComplete(() => _profileFuture = null);
    return _profileFuture!;
  }

  Future<void> _loadProfile() async {
    final repository = _ref.read(workerRepositoryProvider);
    final cached = await repository.cachedProfile();
    if (cached != null) {
      state = state.copyWith(profileLoading: false, profile: cached, profileError: null);
    } else {
      state = state.copyWith(profileLoading: true, profileError: null);
    }
    try {
      final profile = await repository.profile();
      state = state.copyWith(
        profileLoading: false,
        profile: profile,
        profileError: null,
      );
      _profileLoaded = true;
    } on ApiException catch (error) {
      state = state.copyWith(
        profileLoading: false,
        profileError: state.profile == null ? error.userMessage : null,
      );
      _profileLoaded = state.profile != null;
    } catch (_) {
      state = state.copyWith(
        profileLoading: false,
        profileError: state.profile == null ? 'تعذر تحميل الملف المهني.' : null,
      );
      _profileLoaded = state.profile != null;
    }
  }

  Future<void> loadSkillCatalog({bool force = false}) {
    final ongoing = _skillCatalogFuture;
    if (ongoing != null) return ongoing;
    if (_skillCatalogLoaded && !force) return Future<void>.value();

    final future = _loadSkillCatalog();
    _skillCatalogFuture = future.whenComplete(() => _skillCatalogFuture = null);
    return _skillCatalogFuture!;
  }

  Future<void> _loadSkillCatalog() async {
    state = state.copyWith(skillsLoading: true, skillsError: null);
    try {
      final catalog = await _ref.read(workerRepositoryProvider).skillCatalog();
      state = state.copyWith(
        skillsLoading: false,
        skillCatalog: catalog,
        skillsError: null,
      );
      _skillCatalogLoaded = true;
    } on ApiException catch (error) {
      state = state.copyWith(
        skillsLoading: false,
        skillsError: error.userMessage,
      );
      _skillCatalogLoaded = false;
    } catch (_) {
      state = state.copyWith(
        skillsLoading: false,
        skillsError: 'تعذر تحميل المهارات.',
      );
      _skillCatalogLoaded = false;
    }
  }

  Future<void> loadLanguageCatalog({bool force = false}) {
    final ongoing = _languageCatalogFuture;
    if (ongoing != null) return ongoing;
    if (_languageCatalogLoaded && !force) return Future<void>.value();

    final future = _loadLanguageCatalog();
    _languageCatalogFuture = future.whenComplete(
      () => _languageCatalogFuture = null,
    );
    return _languageCatalogFuture!;
  }

  Future<void> _loadLanguageCatalog() async {
    state = state.copyWith(languagesLoading: true, languagesError: null);
    try {
      final catalog = await _ref
          .read(workerRepositoryProvider)
          .languageCatalog();
      state = state.copyWith(
        languagesLoading: false,
        languageCatalog: catalog,
        languagesError: null,
      );
      _languageCatalogLoaded = true;
    } on ApiException catch (error) {
      state = state.copyWith(
        languagesLoading: false,
        languagesError: error.userMessage,
      );
      _languageCatalogLoaded = false;
    } catch (_) {
      state = state.copyWith(
        languagesLoading: false,
        languagesError: 'تعذر تحميل اللغات.',
      );
      _languageCatalogLoaded = false;
    }
  }

  Future<void> loadIdentity({bool force = false}) {
    final ongoing = _identityFuture;
    if (ongoing != null) return ongoing;
    if (_identityLoaded && !force) return Future<void>.value();

    final future = _loadIdentity();
    _identityFuture = future.whenComplete(() => _identityFuture = null);
    return _identityFuture!;
  }

  Future<void> _loadIdentity() async {
    state = state.copyWith(identityLoading: true, identityError: null);
    try {
      final identity = await _ref.read(workerRepositoryProvider).identity();
      state = state.copyWith(
        identityLoading: false,
        identity: identity,
        identityError: null,
      );
      _identityLoaded = true;
    } on ApiException catch (error) {
      state = state.copyWith(
        identityLoading: false,
        identityError: error.userMessage,
      );
      _identityLoaded = false;
    } catch (_) {
      state = state.copyWith(
        identityLoading: false,
        identityError: 'تعذر تحميل الهوية.',
      );
      _identityLoaded = false;
    }
  }

  Future<void> loadDocuments({bool force = false}) {
    final ongoing = _documentsFuture;
    if (ongoing != null) return ongoing;
    if (_documentsLoaded && !force) return Future<void>.value();

    final future = _loadDocuments();
    _documentsFuture = future.whenComplete(() => _documentsFuture = null);
    return _documentsFuture!;
  }

  Future<void> _loadDocuments() async {
    final repository = _ref.read(workerRepositoryProvider);
    final cached = await repository.cachedDocuments();
    if (cached.isNotEmpty) {
      state = state.copyWith(documentsLoading: false, documents: cached, documentsError: null);
    } else {
      state = state.copyWith(documentsLoading: true, documentsError: null);
    }
    try {
      final documents = await repository.documents();
      state = state.copyWith(
        documentsLoading: false,
        documents: documents,
        documentsError: null,
      );
      _documentsLoaded = true;
    } on ApiException catch (error) {
      state = state.copyWith(
        documentsLoading: false,
        documentsError: state.documents.isEmpty ? error.userMessage : null,
      );
      _documentsLoaded = state.documents.isNotEmpty;
    } catch (_) {
      state = state.copyWith(
        documentsLoading: false,
        documentsError: state.documents.isEmpty ? 'تعذر تحميل المستندات.' : null,
      );
      _documentsLoaded = state.documents.isNotEmpty;
    }
  }

  Future<bool> saveProfile(
    WorkerProfessionalUpdate update, {
    WorkerUploadFile? photoFile,
  }) async {
    state = state.copyWith(
      profileBusy: true,
      profileActionError: null,
      profileValidationErrors: WorkerValidationErrors.empty,
      photoProgress: null,
    );
    try {
      final profile = await _ref
          .read(workerRepositoryProvider)
          .updateProfile(
            update,
            photoFile: photoFile,
            onProgress: photoFile == null
                ? null
                : (sent, total) {
                    if (total <= 0) return;
                    state = state.copyWith(photoProgress: sent / total);
                  },
          );
      state = state.copyWith(
        profileBusy: false,
        profile: profile,
        profileActionError: null,
        photoProgress: null,
      );
      return true;
    } on ApiException catch (error) {
      final validationErrors = WorkerValidationErrors.fromException(error);
      state = state.copyWith(
        profileBusy: false,
        profileActionError: validationErrors.isEmpty ? error.userMessage : null,
        profileValidationErrors: validationErrors,
        photoProgress: null,
      );
      return false;
    } catch (_) {
      state = state.copyWith(
        profileBusy: false,
        profileActionError: 'تعذر حفظ المعلومات المهنية.',
        profileValidationErrors: WorkerValidationErrors.empty,
        photoProgress: null,
      );
      return false;
    }
  }

  Future<bool> saveSkills(List<int> skillIds) async {
    state = state.copyWith(
      skillsBusy: true,
      skillsError: null,
      skillsValidationErrors: WorkerValidationErrors.empty,
    );
    try {
      await _ref.read(workerRepositoryProvider).updateSkills(skillIds);
      final profile = await _ref.read(workerRepositoryProvider).profile();
      state = state.copyWith(
        skillsBusy: false,
        profile: profile,
        skillsError: null,
      );
      return true;
    } on ApiException catch (error) {
      final validationErrors = WorkerValidationErrors.fromException(error);
      state = state.copyWith(
        skillsBusy: false,
        skillsError: validationErrors.isEmpty ? error.userMessage : null,
        skillsValidationErrors: validationErrors,
      );
      return false;
    } catch (_) {
      state = state.copyWith(
        skillsBusy: false,
        skillsError: 'تعذر حفظ المهارات.',
        skillsValidationErrors: WorkerValidationErrors.empty,
      );
      return false;
    }
  }

  Future<bool> saveLanguages(List<String> languageCodes) async {
    state = state.copyWith(
      languagesBusy: true,
      languagesError: null,
      languagesValidationErrors: WorkerValidationErrors.empty,
    );
    try {
      await _ref.read(workerRepositoryProvider).updateLanguages(languageCodes);
      final profile = await _ref.read(workerRepositoryProvider).profile();
      state = state.copyWith(
        languagesBusy: false,
        profile: profile,
        languagesError: null,
      );
      return true;
    } on ApiException catch (error) {
      final validationErrors = WorkerValidationErrors.fromException(error);
      state = state.copyWith(
        languagesBusy: false,
        languagesError: validationErrors.isEmpty ? error.userMessage : null,
        languagesValidationErrors: validationErrors,
      );
      return false;
    } catch (_) {
      state = state.copyWith(
        languagesBusy: false,
        languagesError: 'تعذر حفظ اللغات.',
        languagesValidationErrors: WorkerValidationErrors.empty,
      );
      return false;
    }
  }

  Future<bool> createExperience(WorkerExperienceInput input) {
    return _mutateExperience(
      () => _ref.read(workerRepositoryProvider).createExperience(input),
    );
  }

  Future<bool> updateExperience(int id, WorkerExperienceInput input) {
    return _mutateExperience(
      () => _ref.read(workerRepositoryProvider).updateExperience(id, input),
    );
  }

  Future<bool> deleteExperience(int id) {
    return _mutateExperience(
      () => _ref.read(workerRepositoryProvider).deleteExperience(id),
    );
  }

  Future<bool> _mutateExperience(
    Future<WorkerProfile> Function() action,
  ) async {
    state = state.copyWith(
      experienceBusy: true,
      experienceError: null,
      experienceValidationErrors: WorkerValidationErrors.empty,
    );
    try {
      final profile = await action();
      state = state.copyWith(
        experienceBusy: false,
        profile: profile,
        experienceError: null,
      );
      return true;
    } on ApiException catch (error) {
      final validationErrors = WorkerValidationErrors.fromException(error);
      state = state.copyWith(
        experienceBusy: false,
        experienceError: validationErrors.isEmpty ? error.userMessage : null,
        experienceValidationErrors: validationErrors,
      );
      return false;
    } catch (_) {
      state = state.copyWith(
        experienceBusy: false,
        experienceError: 'تعذر حفظ الخبرة.',
        experienceValidationErrors: WorkerValidationErrors.empty,
      );
      return false;
    }
  }

  Future<bool> saveIdentity(WorkerIdentityInput input) async {
    state = state.copyWith(
      identityBusy: true,
      identityError: null,
      identityValidationErrors: WorkerValidationErrors.empty,
    );
    try {
      final identity = await _ref
          .read(workerRepositoryProvider)
          .updateIdentity(input);
      state = state.copyWith(
        identityBusy: false,
        identity: identity,
        identityError: null,
      );
      return true;
    } on ApiException catch (error) {
      final validationErrors = WorkerValidationErrors.fromException(error);
      state = state.copyWith(
        identityBusy: false,
        identityError: validationErrors.isEmpty ? error.userMessage : null,
        identityValidationErrors: validationErrors,
      );
      return false;
    } catch (_) {
      state = state.copyWith(
        identityBusy: false,
        identityError: 'تعذر حفظ الهوية.',
        identityValidationErrors: WorkerValidationErrors.empty,
      );
      return false;
    }
  }

  Future<bool> uploadDocument({
    required String documentTypeCode,
    required WorkerUploadFile file,
  }) async {
    state = state.copyWith(
      documentsBusy: true,
      documentsError: null,
      documentsValidationErrors: WorkerValidationErrors.empty,
      documentProgress: null,
    );
    try {
      await _ref
          .read(workerRepositoryProvider)
          .uploadDocument(
            documentTypeCode: documentTypeCode,
            file: file,
            onProgress: (sent, total) {
              if (total <= 0) return;
              state = state.copyWith(documentProgress: sent / total);
            },
          );
      final documents = await _ref.read(workerRepositoryProvider).documents();
      state = state.copyWith(
        documentsBusy: false,
        documents: documents,
        documentsError: null,
        documentProgress: null,
      );
      return true;
    } on ApiException catch (error) {
      final validationErrors = WorkerValidationErrors.fromException(error);
      state = state.copyWith(
        documentsBusy: false,
        documentsError: validationErrors.isEmpty ? error.userMessage : null,
        documentsValidationErrors: validationErrors,
        documentProgress: null,
      );
      return false;
    } catch (_) {
      state = state.copyWith(
        documentsBusy: false,
        documentsError: 'تعذر رفع المستند.',
        documentsValidationErrors: WorkerValidationErrors.empty,
        documentProgress: null,
      );
      return false;
    }
  }

  Future<bool> submitVerification() async {
    state = state.copyWith(
      verificationBusy: true,
      verificationError: null,
      verificationValidationErrors: WorkerValidationErrors.empty,
    );
    try {
      final profile = await _ref
          .read(workerRepositoryProvider)
          .submitVerification();
      state = state.copyWith(
        verificationBusy: false,
        profile: profile,
        verificationError: null,
      );
      return true;
    } on ApiException catch (error) {
      final validationErrors = WorkerValidationErrors.fromException(error);
      state = state.copyWith(
        verificationBusy: false,
        verificationError: validationErrors.isEmpty ? error.userMessage : null,
        verificationValidationErrors: validationErrors,
      );
      return false;
    } catch (_) {
      state = state.copyWith(
        verificationBusy: false,
        verificationError: 'تعذر إرسال طلب التحقق.',
      );
      return false;
    }
  }
}
