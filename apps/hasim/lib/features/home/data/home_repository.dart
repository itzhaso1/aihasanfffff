
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class HomeRepository {
  HomeRepository(this._api);
  final ApiClient _api;

  Future<HomeSnapshot> home() async {
    final res = await _api.get('/home');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر جلب الرئيسية.');
    return HomeSnapshot.fromJson(map);
  }

  Future<HomeSnapshot> unread() async {
    final res = await _api.get('/unread');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر جلب غير المقروء.');
    return HomeSnapshot.fromJson({
      'unread_conversations': map['unread_conversations'],
      'unread_email': map['unread_email'],
      'unread_notifications': map['unread_notifications'],
    });
  }
}
