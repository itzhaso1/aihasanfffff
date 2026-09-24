import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/search/data/global_search_models.dart';

class SearchRepository {
  SearchRepository(this._api);

  final ApiClient _api;

  Future<GlobalSearchResult> search(String query, {int limit = 10}) async {
    final q = query.trim();
    if (q.isEmpty) {
      return const GlobalSearchResult();
    }

    final res = await _api.get<Map<String, dynamic>>(
      '/search',
      query: {
        'q': q,
        'limit': limit,
      },
      mapData: (raw) => raw is Map ? Map<String, dynamic>.from(raw) : <String, dynamic>{},
    );

    final map = res.data;
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر البحث.');
    }

    final meta = res.meta;
    return GlobalSearchResult.fromJson(
      map,
      query: meta?['query']?.toString() ?? q,
      workspaceId: (meta?['workspace_id'] as num?)?.toInt(),
    );
  }
}
