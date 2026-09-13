import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class ContactGroupRepository {
  ContactGroupRepository(this._api);
  final ApiClient _api;

  Future<List<ContactGroupModel>> list() async {
    final res = await _api.get('/contact-groups');
    return asMapList(res.data).map(ContactGroupModel.fromJson).toList();
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
    return ContactGroupModel.fromJson(map);
  }

  Future<ContactGroupModel> update(int id, {String? name, String? description}) async {
    final res = await _api.patch('/contact-groups/$id', body: {
      'name': ?name,
      'description': ?description,
    });
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث المجموعة.');
    return ContactGroupModel.fromJson(map);
  }

  Future<void> delete(int id) async {
    await _api.delete('/contact-groups/$id');
  }

  Future<ContactGroupModel> syncMembers(int id, List<int> contactIds) async {
    final res = await _api.post(
      '/contact-groups/$id/members',
      body: {'contact_ids': contactIds},
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تحديث الأعضاء.');
    return ContactGroupModel.fromJson(map);
  }
}
