import 'dart:async';

import 'package:dio/dio.dart';
import 'package:hasim/core/local_db/worker_local_store.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/network/api_response.dart';
import 'package:hasim/features/worker/data/worker_dispute.dart';
import 'package:hasim/features/worker/data/worker_earning.dart';
import 'package:hasim/features/worker/data/worker_guide.dart';
import 'package:hasim/features/worker/data/worker_payout.dart';
import 'package:hasim/features/worker/data/worker_profile.dart';
import 'package:hasim/features/worker/data/worker_product.dart';
import 'package:hasim/features/worker/data/worker_work_session.dart';
import 'package:http_parser/http_parser.dart';

class WorkerCapability {
  const WorkerCapability({required this.key, required this.displayName});

  final String key;
  final String displayName;

  factory WorkerCapability.fromJson(Map<String, dynamic> json) {
    return WorkerCapability(
      key: json['capability_key']?.toString() ?? '',
      displayName: json['display_name']?.toString() ?? '',
    );
  }
}

class WorkerEngagement {
  const WorkerEngagement({
    required this.id,
    required this.status,
    required this.workspaceId,
    required this.workspaceName,
    required this.capabilities,
    this.guide,
    this.jobTitle,
    this.jobDescription,
    this.startsAt,
    this.endsAt,
    this.durationValue,
    this.durationUnit,
    this.offeredContractAmountMinor,
    this.offeredContractCurrency,
    this.agreedContractAmountMinor,
    this.agreedContractCurrency,
  });

  /// Arabic labels already defined by the engagement status enum.
  /// The mobile payload does not include a status label.
  static const statusLabels = {
    'draft': 'مسودة',
    'offered': 'عرض مقدّم',
    'accepted': 'مقبول',
    'rejected': 'مرفوض',
    'active': 'نشط',
    'paused': 'متوقف مؤقتاً',
    'completed': 'مكتمل',
    'terminated': 'منهي',
  };

  final int id;
  final String status;
  final int? workspaceId;
  final String? workspaceName;
  final String? jobTitle;
  final String? jobDescription;
  final String? startsAt;
  final String? endsAt;
  final int? durationValue;
  final String? durationUnit;
  final int? offeredContractAmountMinor;
  final String? offeredContractCurrency;
  final int? agreedContractAmountMinor;
  final String? agreedContractCurrency;
  final List<WorkerCapability> capabilities;
  final WorkerGuide? guide;

  bool get isOffer => status == 'offered';

  bool hasCapability(String key) =>
      capabilities.any((capability) => capability.key == key);

  bool get canViewAssignedProducts =>
      const {'offered', 'accepted', 'active', 'paused'}.contains(status);

  String get statusLabel => statusLabels[status] ?? 'حالة الارتباط';

  String get headline {
    final title = jobTitle?.trim() ?? '';
    if (title.isNotEmpty) return title;
    final workspace = workspaceName?.trim() ?? '';
    if (workspace.isNotEmpty) return workspace;
    return statusLabel;
  }

  String? get contractDurationLabel {
    final value = durationValue;
    final unit = durationUnit;
    if (value == null || value < 1 || unit == null) {
      return null;
    }

    return switch (unit) {
      'day' => value == 1 ? 'يوم واحد' : '$value أيام',
      'week' => value == 1 ? 'أسبوع واحد' : '$value أسابيع',
      'month' => value == 1 ? 'شهر واحد' : '$value أشهر',
      _ => null,
    };
  }

  String? get contractPriceLabel {
    final minor = agreedContractAmountMinor ?? offeredContractAmountMinor;
    final currency = agreedContractCurrency ?? offeredContractCurrency;
    if (minor == null || (currency == null || currency.isEmpty)) {
      return null;
    }

    final absolute = minor.abs();
    final formatted =
        '${absolute ~/ 100}.${(absolute % 100).toString().padLeft(2, '0')}';
    return '${minor < 0 ? '-' : ''}$formatted $currency';
  }

  factory WorkerEngagement.fromJson(Map<String, dynamic> json) {
    final workspace = json['workspace'];
    final workspaceMap = workspace is Map
        ? Map<String, dynamic>.from(workspace)
        : null;
    final rawCapabilities = json['capabilities'];
    final rawGuide = json['guide'];
    return WorkerEngagement(
      id: (json['id'] as num).toInt(),
      status: json['status']?.toString() ?? '',
      jobTitle: _text(json['job_title']),
      jobDescription: _text(json['job_description']),
      startsAt: _text(json['starts_at']),
      endsAt: _text(json['ends_at']),
      durationValue: json['duration_value'] is num
          ? (json['duration_value'] as num).toInt()
          : int.tryParse(json['duration_value']?.toString() ?? ''),
      durationUnit: _text(json['duration_unit']),
      offeredContractAmountMinor:
          (json['offered_contract_amount_minor'] as num?)?.toInt(),
      offeredContractCurrency: _text(json['offered_contract_currency']),
      agreedContractAmountMinor: (json['agreed_contract_amount_minor'] as num?)
          ?.toInt(),
      agreedContractCurrency: _text(json['agreed_contract_currency']),
      guide: rawGuide is Map
          ? WorkerGuide.fromJson(Map<String, dynamic>.from(rawGuide))
          : null,
      workspaceId: workspaceMap?['id'] is num
          ? (workspaceMap!['id'] as num).toInt()
          : null,
      workspaceName: _text(workspaceMap?['name']),
      capabilities: rawCapabilities is List
          ? [
              for (final item in rawCapabilities)
                if (item is Map)
                  WorkerCapability.fromJson(Map<String, dynamic>.from(item)),
            ]
          : const [],
    );
  }
}

String? _text(dynamic value) {
  final text = value?.toString().trim() ?? '';
  if (text.isEmpty) return null;
  return text;
}

class WorkerRepository {
  WorkerRepository(
    this._api, {
    WorkerLocalStore? local,
    int? Function()? workerProfileIdOf,
  }) : _local = local,
       _workerProfileIdOf = workerProfileIdOf;

  final ApiClient _api;
  final WorkerLocalStore? _local;
  final int? Function()? _workerProfileIdOf;
  Future<List<WorkerEngagement>>? _engagementsRequest;
  List<WorkerEngagement>? _engagementsCache;
  List<dynamic>? _engagementsRaw;
  int _engagementsGeneration = 0;

  int? get _workerId {
    final id = _workerProfileIdOf?.call();
    if (id == null || id <= 0) return null;
    return id;
  }

  void _remember(String resource, Object? payload, {int scopeId = 0}) {
    final workerId = _workerId;
    if (workerId == null || payload == null) return;
    unawaited(_local?.put(workerId, resource, payload, scopeId: scopeId));
  }

  Future<Object?> _recall(String resource, {int scopeId = 0}) async {
    final workerId = _workerId;
    if (workerId == null || _local == null) return null;
    return _local.get(workerId, resource, scopeId: scopeId);
  }

  void clearEngagementsCache() {
    _engagementsRequest = null;
    _engagementsCache = null;
    _engagementsGeneration++;
  }

  Future<List<WorkerEngagement>> cachedEngagements() async {
    final raw = await _recall('engagements');
    if (raw == null) return const [];
    return _engagementList(raw);
  }

  Future<List<WorkerEngagement>> engagements() async {
    final cached = _engagementsCache;
    if (cached != null) return cached;

    final existingRequest = _engagementsRequest;
    if (existingRequest != null) return existingRequest;

    final generation = _engagementsGeneration;
    final request = _fetchEngagements();
    _engagementsRequest = request;
    try {
      final result = await request;
      if (generation == _engagementsGeneration) {
        _engagementsCache = result;
      }
      return result;
    } finally {
      if (identical(_engagementsRequest, request)) {
        _engagementsRequest = null;
      }
    }
  }

  Future<List<WorkerEngagement>> _fetchEngagements() async {
    final res = await _api.get<List<WorkerEngagement>>(
      '/worker/engagements',
      mapData: (raw) {
        if (raw is List) _engagementsRaw = raw;
        _remember('engagements', raw);
        return _engagementList(raw);
      },
    );
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? 'تعذر تحميل الارتباطات.');
    }
    return res.data!;
  }

  Future<WorkerEngagement> engagement(int id) async {
    final res = await _api.get<WorkerEngagement>(
      '/worker/engagements/$id',
      mapData: (raw) => _engagement(raw),
    );
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? 'تعذر تحميل الارتباط.');
    }
    return res.data!;
  }

  Future<List<WorkerProduct>> products(int engagementId) async {
    final res = await _api.get<List<WorkerProduct>>(
      '/worker/engagements/$engagementId/products',
      mapData: (raw) {
        _remember('products', raw, scopeId: engagementId);
        return raw is List
            ? [
                for (final item in raw)
                  if (item is Map)
                    WorkerProduct.fromJson(Map<String, dynamic>.from(item)),
              ]
            : const <WorkerProduct>[];
      },
    );
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? 'تعذر تحميل منتجات الارتباط.');
    }
    return res.data!;
  }

  Future<List<WorkerProduct>> cachedProducts(int engagementId) async {
    final raw = await _recall('products', scopeId: engagementId);
    if (raw is! List) return const [];
    return [
      for (final item in raw)
        if (item is Map)
          WorkerProduct.fromJson(Map<String, dynamic>.from(item)),
    ];
  }

  Future<List<WorkerWorkSession>> cachedSessions(int engagementId) async {
    final raw = await _recall('sessions', scopeId: engagementId);
    if (raw == null) return const [];
    return _sessionList(raw);
  }

  Future<List<WorkerEarningPeriod>> cachedEarnings(int engagementId) async {
    final raw = await _recall('earnings', scopeId: engagementId);
    if (raw == null) return const [];
    return _earningList(raw);
  }

  Future<List<WorkerPayout>> cachedPayouts() async {
    final raw = await _recall('payouts');
    if (raw == null) return const [];
    return _payoutList(raw);
  }

  Future<List<WorkerDispute>> cachedDisputes() async {
    final raw = await _recall('disputes');
    if (raw == null) return const [];
    return _disputeList(raw);
  }

  Future<List<WorkerVerificationDocument>> cachedDocuments() async {
    final raw = await _recall('documents');
    if (raw is! List) return const [];
    return [
      for (final item in raw)
        if (item is Map)
          WorkerVerificationDocument.fromJson(Map<String, dynamic>.from(item)),
    ];
  }

  Future<WorkerEngagement> acceptOffer(int id) {
    return _respond('/worker/engagements/$id/accept');
  }

  Future<WorkerEngagement> rejectOffer(int id) {
    return _respond('/worker/engagements/$id/reject');
  }

  Future<List<WorkerWorkSession>> getWorkSession(int engagementId) async {
    final res = await _api.get<List<WorkerWorkSession>>(
      '/worker/engagements/$engagementId/session',
      mapData: (raw) {
        _remember('sessions', raw, scopeId: engagementId);
        return _sessionList(raw);
      },
    );
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? 'تعذر تحميل جلسات العمل.');
    }
    return res.data!;
  }

  Future<WorkerWorkSession> startWorkSession(int engagementId) {
    return _sessionRespond('/worker/engagements/$engagementId/session/start');
  }

  Future<WorkerWorkSession> breakWorkSession(int engagementId) {
    return _sessionRespond('/worker/engagements/$engagementId/session/break');
  }

  Future<WorkerWorkSession> resumeWorkSession(int engagementId) {
    return _sessionRespond('/worker/engagements/$engagementId/session/resume');
  }

  Future<WorkerWorkSession> endWorkSession(int engagementId) {
    return _sessionRespond('/worker/engagements/$engagementId/session/end');
  }

  Future<List<WorkerEarningPeriod>> getWorkerEarnings(int engagementId) async {
    final res = await _api.get<List<WorkerEarningPeriod>>(
      '/worker/engagements/$engagementId/earnings',
      mapData: (raw) {
        _remember('earnings', raw, scopeId: engagementId);
        return _earningList(raw);
      },
    );
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? 'تعذر تحميل الأرباح.');
    }
    return [
      for (final period in res.data!)
        if (!period.isDraft) period,
    ];
  }

  Future<WorkerEarningPeriod> disputeWorkerEarningPeriod(
    int engagementId,
    int periodId, {
    String? reason,
  }) async {
    final trimmed = reason?.trim();
    final res = await _api.post<WorkerEarningPeriod>(
      '/worker/engagements/$engagementId/earnings/$periodId/dispute',
      body: {if (trimmed != null && trimmed.isNotEmpty) 'reason': trimmed},
      mapData: _earning,
    );
    if (!res.success || res.data == null) {
      throw ApiException(
        res.message ?? 'تعذر تسجيل الاعتراض على فترة الأرباح.',
      );
    }
    return res.data!;
  }

  Future<List<WorkerPayout>> getWorkerPayouts() async {
    final res = await _api.get<List<WorkerPayout>>(
      '/worker/payouts',
      mapData: (raw) {
        _remember('payouts', raw);
        return _payoutList(raw);
      },
    );
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? 'تعذر تحميل الدفعات.');
    }
    return res.data!;
  }

  Future<WorkerDisputePage> getWorkerDisputes([
    WorkerDisputeFilters filters = const WorkerDisputeFilters(),
  ]) async {
    final res = await _api.get<List<WorkerDispute>>(
      '/worker/disputes',
      query: filters.toQuery(),
      mapData: (raw) {
        if (filters.page == 1 &&
            (filters.status == null || filters.status!.isEmpty)) {
          _remember('disputes', raw);
        }
        return _disputeList(raw);
      },
    );
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? 'تعذر تحميل النزاعات.');
    }
    final meta = res.meta ?? const <String, dynamic>{};
    return WorkerDisputePage(
      items: res.data!,
      total: _metaInt(meta['total'], res.data!.length),
      perPage: _metaInt(meta['per_page'], filters.perPage),
      currentPage: _metaInt(meta['current_page'], filters.page),
      lastPage: _metaInt(meta['last_page'], 1),
    );
  }

  Future<WorkerDispute> getWorkerDispute(int disputeId) async {
    final res = await _api.get<WorkerDispute>(
      '/worker/disputes/$disputeId',
      mapData: _dispute,
    );
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? 'تعذر تحميل النزاع.');
    }
    return res.data!;
  }

  Future<WorkerDispute> createWorkerDispute(
    int engagementId, {
    required String category,
    required String description,
  }) async {
    final res = await _api.post<WorkerDispute>(
      '/worker/engagements/$engagementId/disputes',
      body: {'category': category.trim(), 'description': description.trim()},
      mapData: _dispute,
    );
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? 'تعذر تسجيل النزاع.');
    }
    return res.data!;
  }

  Future<WorkerEngagement> _respond(String path) async {
    final res = await _api.post<WorkerEngagement>(
      path,
      mapData: (raw) {
        if (raw is Map) {
          final current = _engagementsRaw ?? const <dynamic>[];
          final replaced = current.any(
            (item) => item is Map && item['id'] == raw['id'],
          );
          _engagementsRaw = [
            for (final item in current)
              if (item is Map && item['id'] == raw['id']) raw else item,
            if (!replaced) raw,
          ];
          _remember('engagements', _engagementsRaw);
        }
        return _engagement(raw);
      },
    );
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? 'تعذر تحديث العرض.');
    }
    clearEngagementsCache();
    return res.data!;
  }

  WorkerEngagement _engagement(dynamic raw) {
    if (raw is! Map) {
      throw ApiException('استجابة الارتباط غير صالحة.');
    }
    return WorkerEngagement.fromJson(Map<String, dynamic>.from(raw));
  }

  List<WorkerEngagement> _engagementList(dynamic raw) {
    if (raw is! List) {
      throw ApiException('استجابة الارتباطات غير صالحة.');
    }
    return [
      for (final item in raw)
        if (item is Map)
          WorkerEngagement.fromJson(Map<String, dynamic>.from(item)),
    ];
  }

  Future<WorkerWorkSession> _sessionRespond(String path) async {
    final res = await _api.post<WorkerWorkSession>(path, mapData: _session);
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? 'تعذر تحديث جلسة العمل.');
    }
    return res.data!;
  }

  WorkerWorkSession _session(dynamic raw) {
    if (raw is! Map) {
      throw ApiException('استجابة جلسة العمل غير صالحة.');
    }
    return WorkerWorkSession.fromJson(Map<String, dynamic>.from(raw));
  }

  List<WorkerWorkSession> _sessionList(dynamic raw) {
    if (raw is! List) {
      throw ApiException('استجابة جلسات العمل غير صالحة.');
    }
    return [
      for (final item in raw)
        if (item is Map)
          WorkerWorkSession.fromJson(Map<String, dynamic>.from(item)),
    ];
  }

  WorkerEarningPeriod _earning(dynamic raw) {
    if (raw is! Map) {
      throw ApiException('استجابة الأرباح غير صالحة.');
    }
    return WorkerEarningPeriod.fromJson(Map<String, dynamic>.from(raw));
  }

  List<WorkerEarningPeriod> _earningList(dynamic raw) {
    if (raw is! List) {
      throw ApiException('استجابة الأرباح غير صالحة.');
    }
    return [
      for (final item in raw)
        if (item is Map)
          WorkerEarningPeriod.fromJson(Map<String, dynamic>.from(item)),
    ];
  }

  List<WorkerPayout> _payoutList(dynamic raw) {
    if (raw is! List) {
      throw ApiException('استجابة الدفعات غير صالحة.');
    }
    return [
      for (final item in raw)
        if (item is Map) WorkerPayout.fromJson(Map<String, dynamic>.from(item)),
    ];
  }

  WorkerDispute _dispute(dynamic raw) {
    if (raw is! Map) {
      throw ApiException('استجابة النزاع غير صالحة.');
    }
    return WorkerDispute.fromJson(Map<String, dynamic>.from(raw));
  }

  List<WorkerDispute> _disputeList(dynamic raw) {
    if (raw is! List) {
      throw ApiException('استجابة النزاعات غير صالحة.');
    }
    return [
      for (final item in raw)
        if (item is Map)
          WorkerDispute.fromJson(Map<String, dynamic>.from(item)),
    ];
  }

  int _metaInt(dynamic value, int fallback) {
    if (value is num) return value.toInt();
    return fallback;
  }

  Future<WorkerProfile> profile() {
    return _profile('/worker/profile');
  }

  Future<WorkerProfile> updateProfile(
    WorkerProfessionalUpdate update, {
    String? photoPath,
    WorkerUploadFile? photoFile,
    void Function(int sent, int total)? onProgress,
  }) async {
    if ((photoPath == null || photoPath.isEmpty) && photoFile == null) {
      return _profile(
        '/worker/profile',
        body: update.toJson(),
        method: 'PATCH',
      );
    }
    final form = FormData.fromMap({
      ...update.toJson(),
      'profile_photo': photoFile != null
          ? MultipartFile.fromBytes(
              photoFile.bytes,
              filename: photoFile.filename,
              contentType: _contentTypeForPath(
                photoFile.filename,
                mimeType: photoFile.mimeType,
              ),
            )
          : await MultipartFile.fromFile(
              photoPath!,
              filename: photoPath.split(RegExp(r'[/\\]')).last,
              contentType: _contentTypeForPath(photoPath),
            ),
    });
    final res = await _api.upload<WorkerProfile>(
      '/worker/profile',
      formData: form,
      onSendProgress: onProgress,
      mapData: _profileData,
    );
    return _unwrap(res, 'تعذر حفظ المعلومات المهنية.');
  }

  Future<List<WorkerSkillItem>> skillCatalog() {
    return _list(
      '/worker/skills',
      WorkerSkillItem.fromJson,
      'تعذر تحميل المهارات.',
    );
  }

  Future<WorkerProfile> updateSkills(List<int> skillIds) {
    return _profile(
      '/worker/skills',
      body: {'skill_ids': skillIds},
      method: 'PUT',
    );
  }

  Future<List<WorkerLanguageItem>> languageCatalog() {
    return _list(
      '/worker/languages',
      WorkerLanguageItem.catalog,
      'تعذر تحميل اللغات.',
    );
  }

  Future<WorkerProfile> updateLanguages(List<String> languageCodes) {
    return _profile(
      '/worker/languages',
      body: {'language_codes': languageCodes},
      method: 'PUT',
    );
  }

  Future<WorkerProfile> createExperience(WorkerExperienceInput input) {
    return _profile(
      '/worker/experiences',
      body: input.toJson(),
      method: 'POST',
    );
  }

  Future<WorkerProfile> updateExperience(int id, WorkerExperienceInput input) {
    return _profile(
      '/worker/experiences/$id',
      body: input.toJson(),
      method: 'PATCH',
    );
  }

  Future<WorkerProfile> deleteExperience(int id) {
    return _profile('/worker/experiences/$id', method: 'DELETE');
  }

  Future<WorkerIdentity> identity() async {
    final res = await _api.get<WorkerIdentity>(
      '/worker/identity',
      mapData: _identityData,
    );
    return _unwrap(res, 'تعذر تحميل الهوية.');
  }

  Future<WorkerIdentity> updateIdentity(WorkerIdentityInput input) async {
    final res = await _api.put<WorkerIdentity>(
      '/worker/identity',
      body: input.toJson(),
      mapData: _identityData,
    );
    return _unwrap(res, 'تعذر حفظ الهوية.');
  }

  Future<List<WorkerVerificationDocument>> documents() {
    return _list(
      '/worker/documents',
      WorkerVerificationDocument.fromJson,
      'تعذر تحميل المستندات.',
      resource: 'documents',
    );
  }

  Future<WorkerVerificationDocument> uploadDocument({
    required String documentTypeCode,
    String? path,
    WorkerUploadFile? file,
    void Function(int sent, int total)? onProgress,
  }) async {
    if ((path == null || path.isEmpty) && file == null) {
      throw ApiException('اختر ملفاً أولاً.');
    }

    final form = FormData.fromMap({
      'document_type_code': documentTypeCode,
      'document': file != null
          ? MultipartFile.fromBytes(
              file.bytes,
              filename: file.filename,
              contentType: _contentTypeForPath(
                file.filename,
                mimeType: file.mimeType,
              ),
            )
          : await MultipartFile.fromFile(
              path!,
              filename: path.split(RegExp(r'[/\\]')).last,
              contentType: _contentTypeForPath(path),
            ),
    });
    final res = await _api.upload<WorkerVerificationDocument>(
      '/worker/documents',
      formData: form,
      onSendProgress: onProgress,
      mapData: (raw) {
        if (raw is! Map) {
          throw ApiException('استجابة المستند غير صالحة.');
        }
        return WorkerVerificationDocument.fromJson(
          Map<String, dynamic>.from(raw),
        );
      },
    );
    return _unwrap(res, 'تعذر رفع المستند.');
  }

  Future<WorkerProfile> submitVerification() {
    return _profile('/worker/verification', method: 'POST');
  }

  Future<WorkerProfile> _profile(
    String path, {
    Object? body,
    String method = 'GET',
  }) async {
    final res = switch (method) {
      'POST' => await _api.post<WorkerProfile>(
        path,
        body: body,
        mapData: _profileData,
      ),
      'PUT' => await _api.put<WorkerProfile>(
        path,
        body: body,
        mapData: _profileData,
      ),
      'PATCH' => await _api.patch<WorkerProfile>(
        path,
        body: body,
        mapData: _profileData,
      ),
      'DELETE' => await _api.delete<WorkerProfile>(path, mapData: _profileData),
      _ => await _api.get<WorkerProfile>(path, mapData: _profileData),
    };
    return _unwrap(res, 'تعذر تحميل الملف المهني.');
  }

  Future<List<T>> _list<T>(
    String path,
    T Function(Map<String, dynamic> json) parse,
    String fallback, {
    String? resource,
  }) async {
    final res = await _api.get<List<T>>(
      path,
      mapData: (raw) {
        if (resource != null) _remember(resource, raw);
        if (raw is! List) {
          throw ApiException('استجابة غير صالحة.');
        }
        return [
          for (final item in raw)
            if (item is Map) parse(Map<String, dynamic>.from(item)),
        ];
      },
    );
    return _unwrap(res, fallback);
  }

  WorkerProfile _profileData(dynamic raw) {
    if (raw is! Map) {
      throw ApiException('استجابة الملف المهني غير صالحة.');
    }
    _remember('profile', raw);
    return WorkerProfile.fromJson(Map<String, dynamic>.from(raw));
  }

  Future<WorkerProfile?> cachedProfile() async {
    final raw = await _recall('profile');
    if (raw is! Map) return null;
    return WorkerProfile.fromJson(Map<String, dynamic>.from(raw));
  }

  MediaType? _contentTypeForPath(String path, {String? mimeType}) {
    if (mimeType != null && mimeType.contains('/')) {
      return MediaType.parse(mimeType);
    }

    final name = path.split(RegExp(r'[/\\]')).last.toLowerCase();
    final extension = name.contains('.') ? name.split('.').last : '';

    return switch (extension) {
      'jpg' || 'jpeg' => MediaType('image', 'jpeg'),
      'png' => MediaType('image', 'png'),
      'webp' => MediaType('image', 'webp'),
      'pdf' => MediaType('application', 'pdf'),
      _ => null,
    };
  }

  WorkerIdentity _identityData(dynamic raw) {
    if (raw is! Map) {
      throw ApiException('استجابة الهوية غير صالحة.');
    }
    return WorkerIdentity.fromJson(Map<String, dynamic>.from(raw));
  }

  T _unwrap<T>(ApiResponse<T> res, String fallback) {
    if (!res.success || res.data == null) {
      throw ApiException(res.message ?? fallback, errors: res.errors);
    }
    return res.data!;
  }
}
