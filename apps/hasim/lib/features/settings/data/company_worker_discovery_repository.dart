import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/network/api_response.dart';

class CompanyWorkerDiscoverySkill {
  const CompanyWorkerDiscoverySkill({
    required this.skillKey,
    required this.displayName,
  });

  final String skillKey;
  final String displayName;

  factory CompanyWorkerDiscoverySkill.fromJson(Map<String, dynamic> json) {
    return CompanyWorkerDiscoverySkill(
      skillKey: json['skill_key']?.toString() ?? '',
      displayName: json['display_name']?.toString() ?? '',
    );
  }
}

class CompanyWorkerDiscoveryExperience {
  const CompanyWorkerDiscoveryExperience({
    required this.title,
    required this.organizationName,
    required this.isCurrent,
    this.startedOn,
    this.endedOn,
    this.description,
  });

  final String title;
  final String organizationName;
  final bool isCurrent;
  final String? startedOn;
  final String? endedOn;
  final String? description;

  factory CompanyWorkerDiscoveryExperience.fromJson(Map<String, dynamic> json) {
    return CompanyWorkerDiscoveryExperience(
      title: json['title']?.toString() ?? '',
      organizationName: json['organization_name']?.toString() ?? '',
      isCurrent: json['is_current'] == true,
      startedOn: _text(json['started_on']),
      endedOn: _text(json['ended_on']),
      description: _text(json['description']),
    );
  }
}

class CompanyWorkerDiscoveryEngagement {
  const CompanyWorkerDiscoveryEngagement({
    required this.id,
    required this.status,
    this.jobTitle,
    this.startsAt,
    this.endsAt,
    this.durationValue,
    this.durationUnit,
  });

  final int id;
  final String status;
  final String? jobTitle;
  final String? startsAt;
  final String? endsAt;
  final int? durationValue;
  final String? durationUnit;

  bool get isOpen => const {
    'draft',
    'offered',
    'accepted',
    'active',
    'paused',
  }.contains(status);

  bool get isOpenContract =>
      const {'accepted', 'active', 'paused'}.contains(status);

  String get statusLabel => workerEngagementStatusLabel(status);

  factory CompanyWorkerDiscoveryEngagement.fromJson(Map<String, dynamic> json) {
    return CompanyWorkerDiscoveryEngagement(
      id: (json['id'] as num).toInt(),
      status: json['status']?.toString() ?? '',
      jobTitle: _text(json['job_title']),
      startsAt: _text(json['starts_at']),
      endsAt: _text(json['ends_at']),
      durationValue: (json['duration_value'] as num?)?.toInt(),
      durationUnit: _text(json['duration_unit']),
    );
  }
}

class CompanyWorkerOpenEngagement {
  const CompanyWorkerOpenEngagement({
    required this.id,
    required this.workerProfileId,
    required this.status,
    required this.workerDisplayName,
    required this.jobTitle,
    this.profilePhoto,
    this.startsAt,
    this.endsAt,
    this.durationValue,
    this.durationUnit,
    this.hasGuide = false,
    this.grantedCapabilitiesCount = 0,
    this.assignedProductsCount = 0,
  });

  final int id;
  final int workerProfileId;
  final String status;
  final String workerDisplayName;
  final String jobTitle;
  final String? profilePhoto;
  final String? startsAt;
  final String? endsAt;
  final int? durationValue;
  final String? durationUnit;
  final bool hasGuide;
  final int grantedCapabilitiesCount;
  final int assignedProductsCount;

  String get statusLabel => workerEngagementStatusLabel(status);

  String get durationLabel =>
      workerEngagementDurationLabel(durationValue, durationUnit);

  factory CompanyWorkerOpenEngagement.fromJson(Map<String, dynamic> json) {
    final worker = asMap(json['worker']) ?? const <String, dynamic>{};

    return CompanyWorkerOpenEngagement(
      id: (json['id'] as num).toInt(),
      workerProfileId: (json['worker_profile_id'] as num).toInt(),
      status: json['status']?.toString() ?? '',
      workerDisplayName: worker['display_name']?.toString() ?? 'عامل مستقل',
      profilePhoto: _text(worker['profile_photo']),
      jobTitle: json['job_title']?.toString().trim().isNotEmpty == true
          ? json['job_title'].toString()
          : 'تعاقد مستقل',
      startsAt: _text(json['starts_at']),
      endsAt: _text(json['ends_at']),
      durationValue: (json['duration_value'] as num?)?.toInt(),
      durationUnit: _text(json['duration_unit']),
      hasGuide: json['has_guide'] == true,
      grantedCapabilitiesCount:
          (json['granted_capabilities_count'] as num?)?.toInt() ?? 0,
      assignedProductsCount:
          (json['assigned_products_count'] as num?)?.toInt() ?? 0,
    );
  }
}

class CompanyWorkerDiscoveryWorker {
  const CompanyWorkerDiscoveryWorker({
    required this.id,
    required this.displayName,
    required this.countryCode,
    required this.professionalSummary,
    required this.salesExperienceLevel,
    required this.availabilityStatus,
    required this.verifiedBadge,
    required this.skills,
    required this.languages,
    required this.experiences,
    this.profilePhoto,
    this.existingEngagement,
  });

  final int id;
  final String displayName;
  final String? profilePhoto;
  final String countryCode;
  final String professionalSummary;
  final String salesExperienceLevel;
  final String availabilityStatus;
  final bool verifiedBadge;
  final List<CompanyWorkerDiscoverySkill> skills;
  final List<String> languages;
  final List<CompanyWorkerDiscoveryExperience> experiences;
  final CompanyWorkerDiscoveryEngagement? existingEngagement;

  bool get hasOpenEngagement => existingEngagement?.isOpen == true;

  String? get engagementStatusLabel => existingEngagement?.statusLabel;

  factory CompanyWorkerDiscoveryWorker.fromJson(Map<String, dynamic> json) {
    final engagement = asMap(json['existing_engagement']);
    return CompanyWorkerDiscoveryWorker(
      id: (json['id'] as num).toInt(),
      displayName: json['display_name']?.toString() ?? '',
      profilePhoto: _text(json['profile_photo']),
      countryCode: json['country_code']?.toString() ?? '',
      professionalSummary: json['professional_summary']?.toString() ?? '',
      salesExperienceLevel: json['sales_experience_level']?.toString() ?? '',
      availabilityStatus: json['availability_status']?.toString() ?? '',
      verifiedBadge: json['verified_badge'] == true,
      skills: _mapList(json['skills'], CompanyWorkerDiscoverySkill.fromJson),
      languages: [
        if (json['languages'] is List)
          for (final item in json['languages'] as List)
            item is Map
                ? (item['language_code']?.toString() ?? '')
                : item.toString(),
      ].where((item) => item.isNotEmpty).toList(),
      experiences: _mapList(
        json['experiences'],
        CompanyWorkerDiscoveryExperience.fromJson,
      ),
      existingEngagement: engagement == null
          ? null
          : CompanyWorkerDiscoveryEngagement.fromJson(engagement),
    );
  }
}

class CompanyWorkerDiscoveryPage {
  const CompanyWorkerDiscoveryPage({
    required this.workers,
    required this.skills,
    required this.total,
    required this.perPage,
    required this.currentPage,
    required this.lastPage,
  });

  final List<CompanyWorkerDiscoveryWorker> workers;
  final List<CompanyWorkerDiscoverySkill> skills;
  final int total;
  final int perPage;
  final int currentPage;
  final int lastPage;

  bool get hasMore => currentPage < lastPage;

  factory CompanyWorkerDiscoveryPage.fromResponse(
    ApiResponse<List<CompanyWorkerDiscoveryWorker>> response,
    int fallbackPerPage,
  ) {
    final meta = response.meta ?? const <String, dynamic>{};
    return CompanyWorkerDiscoveryPage(
      workers: response.data ?? const [],
      skills: _mapList(meta['skills'], CompanyWorkerDiscoverySkill.fromJson),
      total: _metaInt(meta['total'], response.data?.length ?? 0),
      perPage: _metaInt(meta['per_page'], fallbackPerPage),
      currentPage: _metaInt(meta['current_page'], 1),
      lastPage: _metaInt(meta['last_page'], 1),
    );
  }
}

class CompanyWorkerContractPrice {
  const CompanyWorkerContractPrice({
    required this.durationValue,
    required this.durationUnit,
    required this.amountMinor,
    required this.currency,
  });

  final int durationValue;
  final String durationUnit;
  final int amountMinor;
  final String currency;

  String get amount {
    final absolute = amountMinor.abs();
    final value =
        '${absolute ~/ 100}.${(absolute % 100).toString().padLeft(2, '0')}';
    return '${amountMinor < 0 ? '-' : ''}$value $currency';
  }

  factory CompanyWorkerContractPrice.fromJson(Map<String, dynamic> json) {
    return CompanyWorkerContractPrice(
      durationValue: (json['duration_value'] as num?)?.toInt() ?? 1,
      durationUnit: json['duration_unit']?.toString() ?? 'day',
      amountMinor: (json['amount_minor'] as num?)?.toInt() ?? 0,
      currency: json['currency']?.toString() ?? 'SAR',
    );
  }
}

class CompanyWorkerOfferProduct {
  const CompanyWorkerOfferProduct({
    required this.id,
    this.minimumSellingPrice,
    this.maximumSellingPrice,
  });

  final int id;
  final String? minimumSellingPrice;
  final String? maximumSellingPrice;

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      if ((minimumSellingPrice ?? '').trim().isNotEmpty)
        'minimum_selling_price': minimumSellingPrice!.trim(),
      if ((maximumSellingPrice ?? '').trim().isNotEmpty)
        'maximum_selling_price': maximumSellingPrice!.trim(),
    };
  }
}

class CompanyWorkerOfferGuideFaq {
  const CompanyWorkerOfferGuideFaq({
    required this.question,
    required this.answer,
  });

  final String question;
  final String answer;

  Map<String, dynamic> toJson() => {
    'question': question.trim(),
    'answer': answer.trim(),
  };
}

class CompanyWorkerOfferGuide {
  const CompanyWorkerOfferGuide({
    this.companyOverview,
    this.productsContext,
    this.responsibilities = const [],
    this.workflowSteps = const [],
    this.importantRules = const [],
    this.customerGuidance = const [],
    this.escalationRules = const [],
    this.workScheduleNotes,
    this.importantNotes,
    this.faqItems = const [],
  });

  final String? companyOverview;
  final String? productsContext;
  final List<String> responsibilities;
  final List<String> workflowSteps;
  final List<String> importantRules;
  final List<String> customerGuidance;
  final List<String> escalationRules;
  final String? workScheduleNotes;
  final String? importantNotes;
  final List<CompanyWorkerOfferGuideFaq> faqItems;

  Map<String, dynamic> toJson() => {
    if (_hasText(companyOverview)) 'company_overview': companyOverview!.trim(),
    if (_hasText(productsContext)) 'products_context': productsContext!.trim(),
    if (responsibilities.isNotEmpty) 'responsibilities': responsibilities,
    if (workflowSteps.isNotEmpty) 'workflow_steps': workflowSteps,
    if (importantRules.isNotEmpty) 'important_rules': importantRules,
    if (customerGuidance.isNotEmpty) 'customer_guidance': customerGuidance,
    if (escalationRules.isNotEmpty) 'escalation_rules': escalationRules,
    if (_hasText(workScheduleNotes))
      'work_schedule_notes': workScheduleNotes!.trim(),
    if (_hasText(importantNotes)) 'important_notes': importantNotes!.trim(),
    if (faqItems.isNotEmpty)
      'faq_items': faqItems.map((item) => item.toJson()).toList(),
  };

  bool get isEmpty => toJson().isEmpty;
}

class CompanyWorkerOfferRequest {
  const CompanyWorkerOfferRequest({
    required this.jobTitle,
    this.jobDescription,
    this.startsAt,
    required this.durationValue,
    required this.durationUnit,
    this.products = const [],
    this.guide,
  });

  final String jobTitle;
  final String? jobDescription;
  final String? startsAt;
  final int durationValue;
  final String durationUnit;
  final List<CompanyWorkerOfferProduct> products;
  final CompanyWorkerOfferGuide? guide;

  Map<String, dynamic> toJson() {
    return {
      'job_title': jobTitle.trim(),
      if ((jobDescription ?? '').trim().isNotEmpty)
        'job_description': jobDescription!.trim(),
      if ((startsAt ?? '').trim().isNotEmpty) 'starts_at': startsAt!.trim(),
      'duration_value': durationValue,
      'duration_unit': durationUnit,
      if (products.isNotEmpty)
        'products': products.map((product) => product.toJson()).toList(),
      if (guide != null && !guide!.isEmpty) 'guide': guide!.toJson(),
    };
  }
}

class CompanyWorkerDiscoveryRepository {
  CompanyWorkerDiscoveryRepository(this._api);

  final ApiClient _api;

  Future<CompanyWorkerDiscoveryPage> fetchWorkers({
    String? search,
    String? countryCode,
    String? availabilityStatus,
    String? salesExperienceLevel,
    String? skillKey,
    int perPage = 12,
    int page = 1,
  }) async {
    final query = <String, dynamic>{
      'per_page': perPage,
      'page': page,
      if ((search ?? '').trim().isNotEmpty) 'search': search!.trim(),
      if ((countryCode ?? '').trim().isNotEmpty)
        'country_code': countryCode!.trim(),
      if ((availabilityStatus ?? '').trim().isNotEmpty)
        'availability_status': availabilityStatus!.trim(),
      if ((salesExperienceLevel ?? '').trim().isNotEmpty)
        'sales_experience_level': salesExperienceLevel!.trim(),
      if ((skillKey ?? '').trim().isNotEmpty) 'skill_key': skillKey!.trim(),
    };

    final response = await _api.get<List<CompanyWorkerDiscoveryWorker>>(
      '/company/worker-discovery',
      query: query,
      mapData: _workerList,
    );
    if (!response.success) {
      throw ApiException(response.message ?? 'تعذر تحميل العمال المتاحين.');
    }
    return CompanyWorkerDiscoveryPage.fromResponse(response, perPage);
  }

  Future<CompanyWorkerDiscoveryWorker> fetchWorker(int workerProfileId) async {
    final response = await _api.get<CompanyWorkerDiscoveryWorker>(
      '/company/worker-discovery/$workerProfileId',
      mapData: _worker,
    );
    if (!response.success || response.data == null) {
      throw ApiException(response.message ?? 'تعذر تحميل الملف المهني للعامل.');
    }
    return response.data!;
  }

  Future<CompanyWorkerContractPrice> fetchContractPrice(
    int workerProfileId, {
    required int durationValue,
    required String durationUnit,
  }) async {
    final response = await _api.get<CompanyWorkerContractPrice>(
      '/company/worker-discovery/$workerProfileId/contract-price',
      query: {
        'duration_value': durationValue,
        'duration_unit': durationUnit,
      },
      mapData: (raw) {
        final map = asMap(raw);
        if (map == null) {
          throw ApiException('تعذر قراءة سعر التعاقد.');
        }
        return CompanyWorkerContractPrice.fromJson(map);
      },
    );
    if (!response.success || response.data == null) {
      throw ApiException(response.message ?? 'لا يوجد سعر منصة لهذه المدة.');
    }
    return response.data!;
  }

  Future<List<CompanyWorkerOpenEngagement>> fetchOpenEngagements() async {
    final response = await _api.get<List<CompanyWorkerOpenEngagement>>(
      '/company/worker-engagements/open',
      mapData: _openEngagementList,
    );
    if (!response.success) {
      throw ApiException(response.message ?? 'تعذر تحميل العقود المفتوحة.');
    }
    return response.data ?? const [];
  }

  Future<void> createOffer(
    int workerProfileId,
    CompanyWorkerOfferRequest request,
  ) async {
    final response = await _api.post(
      '/company/worker-discovery/$workerProfileId/engagements',
      body: request.toJson(),
    );
    if (!response.success) {
      throw ApiException(response.message ?? 'تعذر إرسال العرض.');
    }
  }

  List<CompanyWorkerDiscoveryWorker> _workerList(dynamic raw) {
    return _mapList(raw, CompanyWorkerDiscoveryWorker.fromJson);
  }

  CompanyWorkerDiscoveryWorker _worker(dynamic raw) {
    final map = asMap(raw);
    if (map == null) {
      throw ApiException('تعذر قراءة بيانات العامل.');
    }
    return CompanyWorkerDiscoveryWorker.fromJson(map);
  }

  List<CompanyWorkerOpenEngagement> _openEngagementList(dynamic raw) {
    return _mapList(raw, CompanyWorkerOpenEngagement.fromJson);
  }
}

int _metaInt(dynamic value, int fallback) {
  return value is num
      ? value.toInt()
      : int.tryParse(value?.toString() ?? '') ?? fallback;
}

String? _text(dynamic value) {
  final text = value?.toString().trim() ?? '';
  return text.isEmpty ? null : text;
}

List<T> _mapList<T>(
  dynamic raw,
  T Function(Map<String, dynamic> json) fromJson,
) {
  return [
    if (raw is List)
      for (final item in raw)
        if (asMap(item) != null) fromJson(asMap(item)!),
  ];
}

bool _hasText(String? value) => value?.trim().isNotEmpty == true;

String workerEngagementStatusLabel(String status) {
  return switch (status) {
    'draft' => 'مسودة',
    'offered' => 'تم إرسال العرض',
    'accepted' => 'تم قبول العرض',
    'active' => 'التعاقد نشط',
    'paused' => 'التعاقد متوقف مؤقتًا',
    'rejected' => 'العرض مرفوض',
    'completed' => 'التعاقد مكتمل',
    'terminated' => 'التعاقد منتهٍ',
    _ => 'حالة التعاقد غير معروفة',
  };
}

String workerEngagementDurationLabel(int? value, String? unit) {
  if (value == null || value < 1 || unit == null) {
    return 'غير محددة';
  }

  final label = switch (unit) {
    'day' => value == 1 ? 'يوم' : 'أيام',
    'week' => value == 1 ? 'أسبوع' : 'أسابيع',
    'month' => value == 1 ? 'شهر' : 'أشهر',
    _ => null,
  };

  return label == null ? 'غير محددة' : '$value $label';
}
