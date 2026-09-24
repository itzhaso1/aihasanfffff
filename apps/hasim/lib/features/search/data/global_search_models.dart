import 'package:hasim/core/models/models.dart';

class GlobalSearchResult {
  const GlobalSearchResult({
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

  factory GlobalSearchResult.fromJson(
    Map<String, dynamic> json, {
    String? query,
    int? workspaceId,
  }) {
    return GlobalSearchResult(
      conversations: _parseConversations(json['conversations']),
      customers: _parseCustomers(json['customers']),
      emails: _parseEmails(json['emails']),
      query: query,
      workspaceId: workspaceId,
    );
  }

  static List<ConversationModel> _parseConversations(dynamic raw) {
    if (raw is! List) return const [];
    return [
      for (final item in raw)
        if (item is Map<String, dynamic>) ConversationModel.fromJson(item),
    ];
  }

  static List<CustomerModel> _parseCustomers(dynamic raw) {
    if (raw is! List) return const [];
    return [
      for (final item in raw)
        if (item is Map<String, dynamic>) CustomerModel.fromJson(item),
    ];
  }

  static List<EmailMessageModel> _parseEmails(dynamic raw) {
    if (raw is! List) return const [];
    return [
      for (final item in raw)
        if (item is Map<String, dynamic>) EmailMessageModel.fromJson(item),
    ];
  }
}
