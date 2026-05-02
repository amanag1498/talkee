import '../../../services/api_client.dart';

class HostFollowApi {
  HostFollowApi(this._api);

  final ApiClient _api;

  Future<Map<String, dynamic>> follow(int hostId) async {
    final res = await _api.post<Map<String, dynamic>>('hosts/$hostId/follow');
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? const <String, dynamic>{});
  }

  Future<Map<String, dynamic>> unfollow(int hostId) async {
    final res = await _api.delete<Map<String, dynamic>>('hosts/$hostId/follow');
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? const <String, dynamic>{});
  }

  Future<Map<String, dynamic>> fetchState(int hostId) async {
    final res = await _api.get<Map<String, dynamic>>('hosts/$hostId/follow-state');
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? const <String, dynamic>{});
  }

  Future<Map<String, dynamic>> fetchStateByUserId(int userId) async {
    final res = await _api.get<Map<String, dynamic>>('hosts/by-user/$userId/follow-state');
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? const <String, dynamic>{});
  }

  Future<List<Map<String, dynamic>>> fetchFollowing() async {
    final res = await _api.get<Map<String, dynamic>>('me/following');
    return ((res.data?['data'] as List?) ?? const <dynamic>[])
        .map((e) => Map<String, dynamic>.from(e as Map))
        .toList();
  }

  Future<List<Map<String, dynamic>>> fetchFollowers() async {
    final res = await _api.get<Map<String, dynamic>>('me/followers');
    return ((res.data?['data'] as List?) ?? const <dynamic>[])
        .map((e) => Map<String, dynamic>.from(e as Map))
        .toList();
  }
}
