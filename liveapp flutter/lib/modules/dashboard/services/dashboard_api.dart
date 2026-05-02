import '../../../services/api_client.dart';
import '../models/leaderboard_dto.dart';

class DashboardApi {
  final ApiClient _api;
  DashboardApi(this._api);

  Future<DashboardLeaderboardsDto> fetchLeaderboards({
    String type = 'all',
    String period = 'weekly',
    int limit = 10,
  }) async {
    final res = await _api.get<Map<String, dynamic>>(
      'dashboard/leaderboards',
      query: {
        'type': type,
        'period': period,
        'limit': limit,
      },
    );

    final body = _asMap(res.data);
    return DashboardLeaderboardsDto.fromJson(_asMap(body['data']));
  }

  Map<String, dynamic> _asMap(dynamic value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return <String, dynamic>{};
  }
}
