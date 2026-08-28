class FortuneWheelSnapshot {
  const FortuneWheelSnapshot({
    required this.settings,
    required this.walletBalance,
    required this.spunForDate,
    required this.freeSpinsRemaining,
    required this.segments,
    required this.recentSpins,
  });

  final FortuneWheelSettings settings;
  final int walletBalance;
  final String spunForDate;
  final int freeSpinsRemaining;
  final List<FortuneWheelSegment> segments;
  final List<FortuneWheelSpin> recentSpins;

  bool get canFreeSpin => freeSpinsRemaining > 0;
  bool get canPaidSpin => settings.paidSpinsEnabled;
  int get spinCost => canFreeSpin ? 0 : settings.paidSpinCostCoins;

  FortuneWheelSnapshot copyWith({
    int? walletBalance,
    int? freeSpinsRemaining,
    List<FortuneWheelSegment>? segments,
    List<FortuneWheelSpin>? recentSpins,
  }) {
    return FortuneWheelSnapshot(
      settings: settings,
      walletBalance: walletBalance ?? this.walletBalance,
      spunForDate: spunForDate,
      freeSpinsRemaining: freeSpinsRemaining ?? this.freeSpinsRemaining,
      segments: segments ?? this.segments,
      recentSpins: recentSpins ?? this.recentSpins,
    );
  }

  factory FortuneWheelSnapshot.fromJson(Map<String, dynamic> json) {
    return FortuneWheelSnapshot(
      settings: FortuneWheelSettings.fromJson(
        Map<String, dynamic>.from(json['settings'] as Map? ?? const {}),
      ),
      walletBalance: _toInt(json['wallet_balance'], 0),
      spunForDate: (json['spun_for_date'] ?? '').toString(),
      freeSpinsRemaining: _toInt(json['free_spins_remaining'], 0),
      segments:
          (json['segments'] as List? ?? const [])
              .whereType<Map>()
              .map(
                (row) => FortuneWheelSegment.fromJson(
                  Map<String, dynamic>.from(row),
                ),
              )
              .toList(),
      recentSpins:
          (json['recent_spins'] as List? ?? const [])
              .whereType<Map>()
              .map(
                (row) =>
                    FortuneWheelSpin.fromJson(Map<String, dynamic>.from(row)),
              )
              .toList(),
    );
  }
}

class FortuneWheelSettings {
  const FortuneWheelSettings({
    required this.enabled,
    required this.visibleInVideoRoomStrip,
    required this.freeSpinsPerDay,
    required this.paidSpinCostCoins,
    required this.paidSpinsEnabled,
    required this.timezone,
  });

  final bool enabled;
  final bool visibleInVideoRoomStrip;
  final int freeSpinsPerDay;
  final int paidSpinCostCoins;
  final bool paidSpinsEnabled;
  final String timezone;

  factory FortuneWheelSettings.fromJson(Map<String, dynamic> json) {
    return FortuneWheelSettings(
      enabled: _toBool(json['enabled'], fallback: false),
      visibleInVideoRoomStrip: _toBool(
        json['visible_in_video_room_strip'],
        fallback: true,
      ),
      freeSpinsPerDay: _toInt(json['free_spins_per_day'], 1),
      paidSpinCostCoins: _toInt(json['paid_spin_cost_coins'], 50),
      paidSpinsEnabled: _toBool(json['paid_spins_enabled'], fallback: true),
      timezone: (json['timezone'] ?? 'Asia/Kolkata').toString(),
    );
  }
}

class FortuneWheelSegment {
  const FortuneWheelSegment({
    required this.id,
    required this.label,
    required this.rewardType,
    required this.rewardValueCoins,
    required this.entryPackId,
    required this.entryPackName,
    required this.subscriptionPlanId,
    required this.subscriptionPlanName,
    required this.rewardDurationHours,
    required this.weight,
    required this.colorHex,
    required this.iconUrl,
    required this.sortOrder,
  });

  final int id;
  final String label;
  final String rewardType;
  final int rewardValueCoins;
  final int? entryPackId;
  final String? entryPackName;
  final int? subscriptionPlanId;
  final String? subscriptionPlanName;
  final int? rewardDurationHours;
  final int weight;
  final String? colorHex;
  final String? iconUrl;
  final int sortOrder;

  factory FortuneWheelSegment.fromJson(Map<String, dynamic> json) {
    return FortuneWheelSegment(
      id: _toInt(json['id'], 0),
      label: (json['label'] ?? 'Reward').toString(),
      rewardType: (json['reward_type'] ?? 'coins').toString(),
      rewardValueCoins: _toInt(json['reward_value_coins'], 0),
      entryPackId: _toNullableInt(json['entry_pack_id']),
      entryPackName: json['entry_pack_name']?.toString(),
      subscriptionPlanId: _toNullableInt(json['subscription_plan_id']),
      subscriptionPlanName: json['subscription_plan_name']?.toString(),
      rewardDurationHours: _toNullableInt(json['reward_duration_hours']),
      weight: _toInt(json['weight'], 1),
      colorHex: json['color']?.toString(),
      iconUrl: json['icon_url']?.toString(),
      sortOrder: _toInt(json['sort_order'], 0),
    );
  }
}

class FortuneWheelSpin {
  const FortuneWheelSpin({
    required this.id,
    required this.spinType,
    required this.spinCostCoins,
    required this.rewardType,
    required this.rewardValueCoins,
    required this.entryPackId,
    required this.entryPackName,
    required this.subscriptionPlanId,
    required this.subscriptionPlanName,
    required this.rewardDurationHours,
    required this.segment,
    required this.spunForDate,
    required this.createdAt,
  });

  final int id;
  final String spinType;
  final int spinCostCoins;
  final String rewardType;
  final int rewardValueCoins;
  final int? entryPackId;
  final String? entryPackName;
  final int? subscriptionPlanId;
  final String? subscriptionPlanName;
  final int? rewardDurationHours;
  final FortuneWheelSegment? segment;
  final String spunForDate;
  final DateTime? createdAt;

  factory FortuneWheelSpin.fromJson(Map<String, dynamic> json) {
    return FortuneWheelSpin(
      id: _toInt(json['id'], 0),
      spinType: (json['spin_type'] ?? 'free').toString(),
      spinCostCoins: _toInt(json['spin_cost_coins'], 0),
      rewardType: (json['reward_type'] ?? 'coins').toString(),
      rewardValueCoins: _toInt(json['reward_value_coins'], 0),
      entryPackId: _toNullableInt(json['entry_pack_id']),
      entryPackName: json['entry_pack_name']?.toString(),
      subscriptionPlanId: _toNullableInt(json['subscription_plan_id']),
      subscriptionPlanName: json['subscription_plan_name']?.toString(),
      rewardDurationHours: _toNullableInt(json['reward_duration_hours']),
      segment:
          json['segment'] is Map
              ? FortuneWheelSegment.fromJson(
                Map<String, dynamic>.from(json['segment'] as Map),
              )
              : null,
      spunForDate: (json['spun_for_date'] ?? '').toString(),
      createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
    );
  }
}

int _toInt(dynamic value, int fallback) {
  if (value is int) return value;
  if (value is double) return value.round();
  return int.tryParse(value?.toString() ?? '') ??
      double.tryParse(value?.toString() ?? '')?.round() ??
      fallback;
}

int? _toNullableInt(dynamic value) {
  if (value == null) return null;
  if (value is int) return value;
  if (value is double) return value.round();
  return int.tryParse(value.toString());
}

bool _toBool(dynamic value, {required bool fallback}) {
  if (value is bool) return value;
  if (value == null) return fallback;
  final normalized = value.toString().trim().toLowerCase();
  return normalized == '1' || normalized == 'true' || normalized == 'yes';
}
