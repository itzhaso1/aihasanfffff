import 'package:hasim/core/local_db/domain_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class ContactRepository {
  ContactRepository(
    this._api, {
    DomainLocalStore? local,
    int? Function()? workspaceIdOf,
  }) : _local = local,
       _workspaceIdOf = workspaceIdOf;

  final ApiClient _api;
  final DomainLocalStore? _local;
  final int? Function()? _workspaceIdOf;
  bool lastReadFromCache = false;

  int? get _workspace => _workspaceIdOf?.call();

  Future<List<EmailContactModel>> cached({String? search, bool? favorite}) async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    return _local.contacts(workspaceId, search: search, favorite: favorite);
  }

  Stream<List<EmailContactModel>> watch({String? search, bool? favorite}) {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const Stream.empty();
    return _local.watchContacts(workspaceId, search: search, favorite: favorite);
  }

  Future<void> _remember(List<EmailContactModel> items) async {
    final workspaceId = _workspace;
    if (workspaceId == null || items.isEmpty) return;
    await _local?.upsertContacts(workspaceId, items);
  }

  Future<({List<EmailContactModel> items, String? nextCursor})> list({
    String? cursor,
    String? search,
    bool? favorite,
    int perPage = 20,
  }) async {
    final workspaceId = _workspace;
    try {
    final res = await _api.get('/contacts', query: {
      'cursor': ?cursor,
      if (search != null && search.isNotEmpty) 'q': search,
      if (favorite != null) 'favorite': favorite ? 1 : 0,
      'per_page': perPage,
    });
    final items = asMapList(res.data).map(EmailContactModel.fromJson).toList();
    lastReadFromCache = false;
    await _remember(items);
    return (items: items, nextCursor: res.nextCursor);
    } catch (_) {
      if (workspaceId != null && (cursor == null || cursor.isEmpty)) {
        final saved = await cached(search: search, favorite: favorite);
        if (saved.isNotEmpty) {
          lastReadFromCache = true;
          return (items: saved, nextCursor: null);
        }
      }
      rethrow;
    }
  }

  Future<EmailContactModel> show(int id) async {
    final res = await _api.get('/contacts/$id');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'جهة الاتصال غير موجودة.');
    final contact = EmailContactModel.fromJson(map);
    await _remember([contact]);
    return contact;
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
    final contact = EmailContactModel.fromJson(map);
    await _remember([contact]);
    return contact;
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
    final contact = EmailContactModel.fromJson(map);
    await _remember([contact]);
    return contact;
  }

  Future<void> delete(int id) async {
    await _api.delete('/contacts/$id');
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.deleteContact(workspaceId, id);
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
