import 'package:hasim/core/local_db/domain_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class ContactGroupRepository {
  ContactGroupRepository(
    this._api, {
    DomainLocalStore? local,
    int? Function()? workspaceIdOf,
  }) : _local = local,
       _workspaceIdOf = workspaceIdOf;

  final ApiClient _api;
  final DomainLocalStore? _local;
  final int? Function()? _workspaceIdOf;

  int? get _workspace => _workspaceIdOf?.call();

  Future<List<ContactGroupModel>> cached() async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    return _local.groups(workspaceId);
  }

  Future<void> _remember(List<ContactGroupModel> groups) async {
    final workspaceId = _workspace;
    if (workspaceId == null || groups.isEmpty) return;
    await _local?.upsertGroups(workspaceId, groups);
  }

  Future<List<ContactGroupModel>> list() async {
    try {
      final res = await _api.get('/contact-groups');
      final items = asMapList(res.data).map(ContactGroupModel.fromJson).toList();
      await _remember(items);
      return items;
    } catch (_) {
      final saved = await cached();
      if (saved.isNotEmpty) return saved;
      rethrow;
    }
  }

  Future<ContactGroupModel> create({required String name, String? description}) async {
    final res = await _api.post(
      '/contact-groups',
      idempotent: true,
      body: {
        'name': name,
        if (description != null && description.isNotEmpty) 'description': description,
      },
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إنشاء المجموعة.');
    final group = ContactGroupModel.fromJson(map);
    await _remember([group]);
    return group;
  }

  Future<ContactGroupModel> update(int id, {String? name, String? description}) async {
    final res = await _api.patch('/contact-groups/$id', body: {
      'name': ?name,
      'description': ?description,
    });
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث المجموعة.');
    final group = ContactGroupModel.fromJson(map);
    await _remember([group]);
    return group;
  }

  Future<void> delete(int id) async {
    await _api.delete('/contact-groups/$id');
    final workspaceId = _workspace;
    if (workspaceId != null) await _local?.deleteGroup(workspaceId, id);
  }

  Future<ContactGroupModel> syncMembers(int id, List<int> contactIds) async {
    final res = await _api.post(
      '/contact-groups/$id/members',
      body: {'contact_ids': contactIds},
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث الأعضاء.');
    final group = ContactGroupModel.fromJson(map);
    await _remember([group]);
    return group;
  }
}
