import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class CompanyWorkerEngagementCapability {
  const CompanyWorkerEngagementCapability({
    required this.key,
    required this.displayName,
    required this.description,
    required this.allowed,
  });

  final String key;
  final String displayName;
  final String description;
  final bool allowed;

  factory CompanyWorkerEngagementCapability.fromJson(
    Map<String, dynamic> json,
  ) {
    return CompanyWorkerEngagementCapability(
      key: json['capability_key']?.toString() ?? '',
      displayName: json['display_name']?.toString() ?? '',
      description: json['description']?.toString() ?? '',
      allowed: json['allowed'] == true,
    );
  }
}

class CompanyWorkerEngagementCapabilities {
  const CompanyWorkerEngagementCapabilities({
    required this.engagementId,
    required this.status,
    required this.items,
  });

  final int engagementId;
  final String status;
  final List<CompanyWorkerEngagementCapability> items;

  factory CompanyWorkerEngagementCapabilities.fromJson(
    Map<String, dynamic> json,
  ) {
    final rawItems = json['capabilities'];

    return CompanyWorkerEngagementCapabilities(
      engagementId: (json['engagement_id'] as num?)?.toInt() ?? 0,
      status: json['status']?.toString() ?? '',
      items: [
        if (rawItems is List)
          for (final item in rawItems)
            if (item is Map)
              CompanyWorkerEngagementCapability.fromJson(
                Map<String, dynamic>.from(item),
              ),
      ],
    );
  }
}

class CompanyWorkerEngagementCapabilitiesRepository {
  CompanyWorkerEngagementCapabilitiesRepository(this._api);

  final ApiClient _api;

  Future<CompanyWorkerEngagementCapabilities> fetch(int engagementId) {
    return _request(
      '/company/worker-engagements/$engagementId/capabilities',
      fallback: 'تعذر تحميل صلاحيات الموظف.',
    );
  }

  Future<CompanyWorkerEngagementCapabilities> grant(
    int engagementId,
    String capabilityKey,
  ) {
    return _request(
      '/company/worker-engagements/$engagementId/capabilities',
      method: 'POST',
      body: {'capability_key': capabilityKey},
      idempotent: true,
      fallback: 'تعذر منح الصلاحية.',
    );
  }

  Future<CompanyWorkerEngagementCapabilities> revoke(
    int engagementId,
    String capabilityKey,
  ) {
    return _request(
      '/company/worker-engagements/$engagementId/capabilities/$capabilityKey',
      method: 'DELETE',
      fallback: 'تعذر سحب الصلاحية.',
    );
  }

  Future<CompanyWorkerEngagementCapabilities> _request(
    String path, {
    String method = 'GET',
    Object? body,
    bool idempotent = false,
    required String fallback,
  }) async {
    final response = switch (method) {
      'POST' => await _api.post<CompanyWorkerEngagementCapabilities>(
        path,
        body: body,
        idempotent: idempotent,
        mapData: _parse,
      ),
      'DELETE' => await _api.delete<CompanyWorkerEngagementCapabilities>(
        path,
        mapData: _parse,
      ),
      _ => await _api.get<CompanyWorkerEngagementCapabilities>(
        path,
        mapData: _parse,
      ),
    };

    if (!response.success || response.data == null) {
      throw ApiException(response.message ?? fallback, errors: response.errors);
    }

    return response.data!;
  }

  CompanyWorkerEngagementCapabilities _parse(dynamic raw) {
    if (raw is! Map) {
      throw ApiException('استجابة صلاحيات الموظف غير صالحة.');
    }

    return CompanyWorkerEngagementCapabilities.fromJson(
      Map<String, dynamic>.from(raw),
    );
  }
}
