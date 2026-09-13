
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class WorkspaceRepository {
  WorkspaceRepository(this._api);
  final ApiClient _api;

  Future<List<WorkspaceModel>> list() async {
    final res = await _api.get('/workspaces');
    return asMapList(res.data).map(WorkspaceModel.fromJson).toList();
  }

  Future<WorkspaceModel> current() async {
    final res = await _api.get('/workspaces/current');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر جلب مساحة العمل.');
    return WorkspaceModel.fromJson(map);
  }

  Future<WorkspaceModel> switchTo(int workspaceId) async {
    final res = await _api.post(
      '/workspaces/switch',
      body: {'workspace_id': workspaceId},
    );
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر تبديل مساحة العمل.');
    return WorkspaceModel.fromJson(map);
  }
}
