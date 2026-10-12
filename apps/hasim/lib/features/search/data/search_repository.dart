import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class SearchPayload {
  const SearchPayload({
    this.conversations = const [],
    this.customers = const [],
    this.emails = const [],
    this.query,
    this.workspaceId,
  });

  final List<ConversationModel> conversations;
  final List<CustomerModel> customers;
  final List<EmailMessageModel> emails;
  final String? query;
  final int? workspaceId;

  bool get isEmpty =>
      conversations.isEmpty && customers.isEmpty && emails.isEmpty;
}

class SearchRepository {
  SearchRepository(this._api);

  final ApiClient _api;

  Future<SearchPayload> search(String query, {int limit = 10}) async {
    final q = query.trim();
    if (q.isEmpty) {
      throw ApiException('أدخل كلمة للبحث.');
    }
    final clipped = q.length > 255 ? q.substring(0, 255) : q;
    final boundedLimit = limit.clamp(1, 30);
    final res = await _api.get(
      '/search',
      query: {
        'q': clipped,
        'limit': boundedLimit,
      },
    );
    final map = asMap(res.data);
    if (map == null) {
      throw ApiException(res.message ?? 'تعذر تنفيذ البحث.');
    }
    final meta = res.meta;
    return SearchPayload(
      conversations: asMapList(
        map['conversations'],
      ).map(ConversationModel.fromJson).toList(),
      customers: asMapList(
        map['customers'],
      ).map(CustomerModel.fromJson).toList(),
      emails: asMapList(map['emails']).map(EmailMessageModel.fromJson).toList(),
      query: meta?['query']?.toString() ?? clipped,
      workspaceId: (meta?['workspace_id'] as num?)?.toInt(),
    );
  }
}
