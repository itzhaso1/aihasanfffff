import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/features/analytics/data/analytics_models.dart';

final analyticsRepositoryProvider = Provider<AnalyticsRepository>((ref) {
  return AnalyticsRepository(ref.watch(apiClientProvider));
});

class AnalyticsRepository {
  const AnalyticsRepository(this._api);

  final ApiClient _api;

  Future<WorkspaceAnalyticsModel> summary({String range = 'month', String? from, String? to}) async {
    final query = <String, dynamic>{'range': range};
    if (from != null) query['from'] = from;
    if (to != null) query['to'] = to;

    final res = await _api.get<Map<String, dynamic>>(
      'analytics',
      query: query,
      mapData: (raw) => raw is Map<String, dynamic> ? raw : (raw is Map ? Map<String, dynamic>.from(raw) : <String, dynamic>{}),
    );
    final data = res.data ?? <String, dynamic>{};
    return WorkspaceAnalyticsModel.fromJson(data);
  }
}
