import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/settings/data/company_worker_discovery_repository.dart';

class CompanyWorkerDiscoveryState {
  const CompanyWorkerDiscoveryState({
    this.workers = const [],
    this.openEngagements = const [],
    this.skills = const [],
    this.loading = false,
    this.openEngagementsLoading = false,
    this.loadingMore = false,
    this.saving = false,
    this.error,
    this.openEngagementsError,
    this.currentPage = 1,
    this.lastPage = 1,
    this.total = 0,
    this.search = '',
    this.countryCode,
    this.availabilityStatus,
    this.salesExperienceLevel = '',
    this.skillKey,
  });

  final List<CompanyWorkerDiscoveryWorker> workers;
  final List<CompanyWorkerOpenEngagement> openEngagements;
  final List<CompanyWorkerDiscoverySkill> skills;
  final bool loading;
  final bool openEngagementsLoading;
  final bool loadingMore;
  final bool saving;
  final String? error;
  final String? openEngagementsError;
  final int currentPage;
  final int lastPage;
  final int total;
  final String search;
  final String? countryCode;
  final String? availabilityStatus;
  final String salesExperienceLevel;
  final String? skillKey;

  bool get hasMore => currentPage < lastPage;

  CompanyWorkerDiscoveryState copyWith({
    List<CompanyWorkerDiscoveryWorker>? workers,
    List<CompanyWorkerOpenEngagement>? openEngagements,
    List<CompanyWorkerDiscoverySkill>? skills,
    bool? loading,
    bool? openEngagementsLoading,
    bool? loadingMore,
    bool? saving,
    String? error,
    bool clearError = false,
    String? openEngagementsError,
    bool clearOpenEngagementsError = false,
    int? currentPage,
    int? lastPage,
    int? total,
    String? search,
    String? countryCode,
    String? availabilityStatus,
    String? salesExperienceLevel,
    bool clearSalesExperienceLevel = false,
    String? skillKey,
  }) {
    return CompanyWorkerDiscoveryState(
      workers: workers ?? this.workers,
      openEngagements: openEngagements ?? this.openEngagements,
      skills: skills ?? this.skills,
      loading: loading ?? this.loading,
      openEngagementsLoading:
          openEngagementsLoading ?? this.openEngagementsLoading,
      loadingMore: loadingMore ?? this.loadingMore,
      saving: saving ?? this.saving,
      error: clearError ? null : (error ?? this.error),
      openEngagementsError: clearOpenEngagementsError
          ? null
          : (openEngagementsError ?? this.openEngagementsError),
      currentPage: currentPage ?? this.currentPage,
      lastPage: lastPage ?? this.lastPage,
      total: total ?? this.total,
      search: search ?? this.search,
      countryCode: countryCode ?? this.countryCode,
      availabilityStatus: availabilityStatus ?? this.availabilityStatus,
      salesExperienceLevel: clearSalesExperienceLevel
          ? ''
          : (salesExperienceLevel ?? this.salesExperienceLevel),
      skillKey: skillKey ?? this.skillKey,
    );
  }
}

final companyWorkerDiscoveryControllerProvider =
    StateNotifierProvider<
      CompanyWorkerDiscoveryController,
      CompanyWorkerDiscoveryState
    >((ref) {
      final controller = CompanyWorkerDiscoveryController(ref);
      return controller;
    });

class CompanyWorkerDiscoveryController
    extends StateNotifier<CompanyWorkerDiscoveryState> {
  CompanyWorkerDiscoveryController(this._ref)
    : super(const CompanyWorkerDiscoveryState()) {
    reload();
  }

  final Ref _ref;

  CompanyWorkerDiscoveryRepository get _repository =>
      _ref.read(companyWorkerDiscoveryRepositoryProvider);

  Future<void> reload() async {
    await Future.wait<void>([
      _load(page: 1, append: false),
      reloadOpenEngagements(),
    ]);
  }

  Future<void> reloadOpenEngagements() async {
    state = state.copyWith(
      openEngagementsLoading: true,
      clearOpenEngagementsError: true,
    );

    try {
      final engagements = await _repository.fetchOpenEngagements();
      state = state.copyWith(
        openEngagements: engagements,
        openEngagementsLoading: false,
      );
    } on ApiException catch (e) {
      state = state.copyWith(
        openEngagementsLoading: false,
        openEngagementsError: e.userMessage,
      );
    } catch (_) {
      state = state.copyWith(
        openEngagementsLoading: false,
        openEngagementsError: 'تعذر تحميل العقود المفتوحة.',
      );
    }
  }

  Future<void> loadMore() async {
    if (state.loading || state.loadingMore || !state.hasMore) return;
    await _load(page: state.currentPage + 1, append: true);
  }

  Future<void> applyFilters({
    String? search,
    String? countryCode,
    String? availabilityStatus,
    String? salesExperienceLevel,
    String? skillKey,
  }) async {
    state = state.copyWith(
      search: search ?? state.search,
      countryCode: countryCode,
      availabilityStatus: availabilityStatus,
      salesExperienceLevel: salesExperienceLevel,
      skillKey: skillKey,
      clearError: true,
    );
    await reload();
  }

  Future<void> clearFilters() async {
    state = state.copyWith(
      search: '',
      countryCode: null,
      availabilityStatus: null,
      salesExperienceLevel: '',
      skillKey: null,
      clearError: true,
    );
    await reload();
  }

  Future<bool> sendOffer(
    CompanyWorkerDiscoveryWorker worker, {
    required String jobTitle,
    String? jobDescription,
    String? startsAt,
    required int durationValue,
    required String durationUnit,
    List<CompanyWorkerOfferProduct> products = const [],
    CompanyWorkerOfferGuide? guide,
  }) async {
    state = state.copyWith(saving: true, clearError: true);
    try {
      await _repository.createOffer(
        worker.id,
        CompanyWorkerOfferRequest(
          jobTitle: jobTitle,
          jobDescription: jobDescription,
          startsAt: startsAt,
          durationValue: durationValue,
          durationUnit: durationUnit,
          products: products,
          guide: guide,
        ),
      );
      await reload();
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(saving: false, error: e.userMessage);
      return false;
    } catch (_) {
      state = state.copyWith(saving: false, error: 'تعذر إرسال العرض.');
      return false;
    }
  }

  Future<void> _load({required int page, required bool append}) async {
    state = state.copyWith(
      loading: !append,
      loadingMore: append,
      clearError: true,
    );

    try {
      final pageData = await _repository.fetchWorkers(
        search: state.search,
        countryCode: state.countryCode,
        availabilityStatus: state.availabilityStatus,
        salesExperienceLevel: state.salesExperienceLevel,
        skillKey: state.skillKey,
        page: page,
      );

      final workers = append
          ? [...state.workers, ...pageData.workers]
          : pageData.workers;
      state = state.copyWith(
        workers: workers,
        skills: pageData.skills.isNotEmpty ? pageData.skills : state.skills,
        loading: false,
        loadingMore: false,
        currentPage: pageData.currentPage,
        lastPage: pageData.lastPage,
        total: pageData.total,
      );
    } on ApiException catch (e) {
      state = state.copyWith(
        loading: false,
        loadingMore: false,
        error: e.userMessage,
      );
    } catch (_) {
      state = state.copyWith(
        loading: false,
        loadingMore: false,
        error: 'تعذر تحميل العمال المتاحين.',
      );
    }
  }
}
