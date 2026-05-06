import 'package:flutter/foundation.dart';

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
    final data = _asMap(body['data']);
    final firstWeeklyUser = _firstMap(data['top_users_weekly'] ?? data['users_weekly']);
    final firstWeeklyHost = _firstMap(data['top_hosts_weekly'] ?? data['hosts_weekly'] ?? data['hosts']);
    debugPrint(
      '[dashboard][raw] '
      'usersWeekly.first.avatar=${firstWeeklyUser?['avatar']} '
      'usersWeekly.first.frame=${firstWeeklyUser?['profile_frame']} '
      'hostsWeekly.first.avatar=${firstWeeklyHost?['avatar']} '
      'hostsWeekly.first.frame=${firstWeeklyHost?['profile_frame']}',
    );
    return DashboardLeaderboardsDto.fromJson(data);
  }

  Map<String, dynamic> _asMap(dynamic value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return <String, dynamic>{};
  }

  Map<String, dynamic>? _firstMap(dynamic value) {
    if (value is List && value.isNotEmpty && value.first is Map) {
      return Map<String, dynamic>.from(value.first as Map);
    }
    return null;
  }
}
