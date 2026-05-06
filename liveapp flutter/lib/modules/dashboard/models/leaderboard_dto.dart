import '../../../app/utils/profile_frame_payload.dart';

class DashboardLeaderboardsDto {
  final List<LeaderboardUserItemDto> usersAlltime;
  final List<LeaderboardUserItemDto> usersWeekly;
  final List<LeaderboardHostItemDto> hostsAlltime;
  final List<LeaderboardHostItemDto> hostsWeekly;
  final List<LeaderboardAgencyItemDto> agenciesAlltime;
  final List<LeaderboardAgencyItemDto> agenciesWeekly;

  const DashboardLeaderboardsDto({
    this.usersAlltime = const [],
    this.usersWeekly = const [],
    this.hostsAlltime = const [],
    this.hostsWeekly = const [],
    this.agenciesAlltime = const [],
    this.agenciesWeekly = const [],
  });

  factory DashboardLeaderboardsDto.fromJson(Map<String, dynamic> json) {
    List<T> parseList<T>(
      dynamic source,
      T Function(Map<String, dynamic>) parser,
    ) {
      final list = source is List ? source : const [];
      return list
          .whereType<Map>()
          .map((row) => parser(Map<String, dynamic>.from(row)))
          .toList(growable: false);
    }

    dynamic preferList(List<dynamic> sources) {
      for (final source in sources) {
        if (source is List && source.isNotEmpty) {
          return source;
        }
      }
      for (final source in sources) {
        if (source is List) {
          return source;
        }
      }
      return const [];
    }

    return DashboardLeaderboardsDto(
      usersAlltime: parseList(
        json['users_alltime'],
        LeaderboardUserItemDto.fromJson,
      ),
      usersWeekly: parseList(
        preferList([json['top_users_weekly'], json['users_weekly']]),
        LeaderboardUserItemDto.fromJson,
      ),
      hostsAlltime: parseList(
        preferList([json['hosts_alltime']]),
        LeaderboardHostItemDto.fromJson,
      ),
      hostsWeekly: parseList(
        preferList([json['top_hosts_weekly'], json['hosts_weekly'], json['hosts']]),
        LeaderboardHostItemDto.fromJson,
      ),
      agenciesAlltime: parseList(
        preferList([json['agencies_alltime']]),
        LeaderboardAgencyItemDto.fromJson,
      ),
      agenciesWeekly: parseList(
        preferList([json['top_agencies_weekly'], json['agencies_weekly'], json['agencies']]),
        LeaderboardAgencyItemDto.fromJson,
      ),
    );
  }
}

class LeaderboardUserItemDto {
  final int id;
  final String name;
  final String? avatar;
  final String? profileFrame;
  final int? level;
  final int lifetimeSpendCoins;
  final int giftCoins;
  final int callCoins;
  final int subscriptionCoins;
  final int entryCoins;
  final int totalCoins;
  final int rank;

  const LeaderboardUserItemDto({
    required this.id,
    required this.name,
    required this.lifetimeSpendCoins,
    required this.giftCoins,
    required this.callCoins,
    required this.subscriptionCoins,
    required this.entryCoins,
    required this.totalCoins,
    required this.rank,
    this.avatar,
    this.profileFrame,
    this.level,
  });

  factory LeaderboardUserItemDto.fromJson(Map<String, dynamic> json) {
    return LeaderboardUserItemDto(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: (json['name'] ?? '').toString(),
      avatar: json['avatar']?.toString(),
      profileFrame:
          profileFrameAssetUrlFromPayload(json) ??
          json['profile_frame']?.toString(),
      level: (json['level'] as num?)?.toInt(),
      lifetimeSpendCoins: (json['lifetime_spend_coins'] as num?)?.toInt() ?? 0,
      giftCoins: (json['gift_coins'] as num?)?.toInt() ?? 0,
      callCoins: (json['call_coins'] as num?)?.toInt() ?? 0,
      subscriptionCoins: (json['subscription_coins'] as num?)?.toInt() ?? 0,
      entryCoins: (json['entry_coins'] as num?)?.toInt() ?? 0,
      totalCoins: (json['total_coins'] as num?)?.toInt() ?? 0,
      rank: (json['rank'] as num?)?.toInt() ?? 0,
    );
  }
}

class LeaderboardHostItemDto {
  final int hostId;
  final int hostUserId;
  final String name;
  final String? avatar;
  final String? profileFrame;
  final int? agencyId;
  final int giftCoins;
  final int callCoins;
  final int totalCoins;
  final int rank;

  const LeaderboardHostItemDto({
    required this.hostId,
    required this.hostUserId,
    required this.name,
    required this.giftCoins,
    required this.callCoins,
    required this.totalCoins,
    required this.rank,
    this.avatar,
    this.profileFrame,
    this.agencyId,
  });

  factory LeaderboardHostItemDto.fromJson(Map<String, dynamic> json) {
    return LeaderboardHostItemDto(
      hostId: (json['host_id'] as num?)?.toInt() ?? 0,
      hostUserId: (json['host_user_id'] as num?)?.toInt() ?? 0,
      name: (json['name'] ?? '').toString(),
      avatar: json['avatar']?.toString(),
      profileFrame:
          profileFrameAssetUrlFromPayload(json) ??
          json['profile_frame']?.toString(),
      agencyId: (json['agency_id'] as num?)?.toInt(),
      giftCoins: (json['gift_coins'] as num?)?.toInt() ?? 0,
      callCoins: (json['call_coins'] as num?)?.toInt() ?? 0,
      totalCoins: (json['total_coins'] as num?)?.toInt() ?? 0,
      rank: (json['rank'] as num?)?.toInt() ?? 0,
    );
  }
}

class LeaderboardAgencyItemDto {
  final int agencyId;
  final String name;
  final int giftCoins;
  final int callCoins;
  final int totalCoins;
  final int rank;

  const LeaderboardAgencyItemDto({
    required this.agencyId,
    required this.name,
    required this.giftCoins,
    required this.callCoins,
    required this.totalCoins,
    required this.rank,
  });

  factory LeaderboardAgencyItemDto.fromJson(Map<String, dynamic> json) {
    return LeaderboardAgencyItemDto(
      agencyId: (json['agency_id'] as num?)?.toInt() ?? 0,
      name: (json['name'] ?? '').toString(),
      giftCoins: (json['gift_coins'] as num?)?.toInt() ?? 0,
      callCoins: (json['call_coins'] as num?)?.toInt() ?? 0,
      totalCoins: (json['total_coins'] as num?)?.toInt() ?? 0,
      rank: (json['rank'] as num?)?.toInt() ?? 0,
    );
  }
}
