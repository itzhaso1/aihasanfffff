import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class ContactRepository {
  ContactRepository(this._api);
  final ApiClient _api;

  Future<({List<EmailContactModel> items, String? nextCursor})> list({
    String? cursor,
    String? search,
    bool? favorite,
    int perPage = 20,
  }) async {
    final res = await _api.get('/contacts', query: {
      'cursor': ?cursor,
      if (search != null && search.isNotEmpty) 'q': search,
      if (favorite != null) 'favorite': favorite ? 1 : 0,
      'per_page': perPage,
    });
    return (
      items: asMapList(res.data).map(EmailContactModel.fromJson).toList(),
      nextCursor: res.nextCursor,
    );
  }

  Future<EmailContactModel> show(int id) async {
    final res = await _api.get('/contacts/$id');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'جهة الاتصال غير موجودة.');
    return EmailContactModel.fromJson(map);
  }

  Future<EmailContactModel> create({
    required String name,
    required String email,
    String? phone,
    String? company,
    String? jobTitle,
    String? notes,
    bool isFavorite = false,
  }) async {
    final res = await _api.post(
      '/contacts',
      idempotent: true,
      body: {
        'name': name,
        'email': email,
        if (phone != null && phone.isNotEmpty) 'phone': phone,
        if (company != null && company.isNotEmpty) 'company': company,
        if (jobTitle != null && jobTitle.isNotEmpty) 'job_title': jobTitle,
        if (notes != null && notes.isNotEmpty) 'notes': notes,
        'is_favorite': isFavorite,
      },
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إضافة جهة الاتصال.');
    return EmailContactModel.fromJson(map);
  }

  Future<EmailContactModel> update(
    int id, {
    String? name,
    String? email,
    String? phone,
    String? company,
    String? jobTitle,
    String? notes,
    bool? isFavorite,
  }) async {
    final res = await _api.patch('/contacts/$id', body: {
      'name': ?name,
      'email': ?email,
      'phone': ?phone,
      'company': ?company,
      'job_title': ?jobTitle,
      'notes': ?notes,
      'is_favorite': ?isFavorite,
    });
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث جهة الاتصال.');
    return EmailContactModel.fromJson(map);
  }

  Future<void> delete(int id) async {
    await _api.delete('/contacts/$id');
  }

  Future<EmailContactModel> toggleFavorite(int id) async {
    final res = await _api.post('/contacts/$id/favorite');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث المفضلة.');
    return EmailContactModel.fromJson(map);
  }

  Future<List<RecentRecipientModel>> recentRecipients({int limit = 20}) async {
    final res = await _api.get('/contacts/recent-recipients', query: {'limit': limit});
    return asMapList(res.data).map(RecentRecipientModel.fromJson).toList();
  }

  /// Returns matching contacts for [email], or empty if none.
  Future<List<EmailContactModel>> findByEmail(String email) async {
    final result = await list(search: email.trim(), perPage: 10);
    final needle = email.trim().toLowerCase();
    return result.items
        .where((c) =>
            c.email.toLowerCase() == needle ||
            (c.normalizedEmail?.toLowerCase() == needle))
        .toList();
  }
}
