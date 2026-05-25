class GreedySnapshot {
  const GreedySnapshot({
    required this.settings,
    required this.walletBalance,
    required this.round,
    required this.history,
  });

  final GreedySettings settings;
  final int walletBalance;
  final GreedyRound round;
  final List<GreedyRound> history;

  factory GreedySnapshot.fromJson(Map<String, dynamic> json) {
    int toInt(dynamic value, int fallback) {
      if (value is int) return value;
      if (value is double) return value.round();
      return int.tryParse(value?.toString() ?? '') ??
          double.tryParse(value?.toString() ?? '')?.round() ??
          fallback;
    }

    return GreedySnapshot(
      settings: GreedySettings.fromJson(
        Map<String, dynamic>.from(json['settings'] as Map? ?? const {}),
      ),
      walletBalance: toInt(json['wallet_balance'], 0),
      round: GreedyRound.fromJson(
        Map<String, dynamic>.from(json['round'] as Map? ?? const {}),
      ),
      history:
          (json['history'] as List? ?? const [])
              .whereType<Map>()
              .map((row) => GreedyRound.fromJson(Map<String, dynamic>.from(row)))
              .toList(),
    );
  }
}

class GreedySettings {
  const GreedySettings({
    required this.enabled,
    required this.visibleInVideoRoomStrip,
    required this.fakeBetsEnabled,
    required this.minBet,
    required this.maxBet,
    required this.roundDurationSeconds,
    required this.bettingLockSeconds,
    required this.resultDisplaySeconds,
    required this.winningStrategyMode,
    required this.potMultipliers,
    required this.potSectors,
  });

  final bool enabled;
  final bool visibleInVideoRoomStrip;
  final bool fakeBetsEnabled;
  final int minBet;
  final int maxBet;
  final int roundDurationSeconds;
  final int bettingLockSeconds;
  final int resultDisplaySeconds;
  final String winningStrategyMode;
  final Map<String, int> potMultipliers;
  final Map<String, int> potSectors;

  factory GreedySettings.fromJson(Map<String, dynamic> json) {
    bool toBool(dynamic value, {required bool fallback}) {
      if (value is bool) return value;
      if (value == null) return fallback;
      final normalized = value.toString().trim().toLowerCase();
      return normalized == '1' || normalized == 'true' || normalized == 'yes';
    }

    int toInt(dynamic value, int fallback) {
      if (value is int) return value;
      if (value is double) return value.round();
      return int.tryParse(value?.toString() ?? '') ??
          double.tryParse(value?.toString() ?? '')?.round() ??
          fallback;
    }

    final rawMultipliers = Map<String, dynamic>.from(
      json['pot_multipliers'] as Map? ?? const {},
    );
    final rawSectors = Map<String, dynamic>.from(
      json['pot_sectors'] as Map? ?? const {},
    );

    return GreedySettings(
      enabled: toBool(json['enabled'], fallback: false),
      visibleInVideoRoomStrip: toBool(
        json['visible_in_video_room_strip'],
        fallback: true,
      ),
      fakeBetsEnabled: toBool(json['fake_bets_enabled'], fallback: false),
      minBet: toInt(json['min_bet'], 10),
      maxBet: toInt(json['max_bet'], 5000),
      roundDurationSeconds: toInt(json['round_duration_seconds'], 30),
      bettingLockSeconds: toInt(json['betting_lock_seconds'], 5),
      resultDisplaySeconds: toInt(json['result_display_seconds'], 6),
      winningStrategyMode:
          (json['winning_strategy_mode'] ?? 'probability').toString(),
      potMultipliers: <String, int>{
        'A': toInt(rawMultipliers['A'], 2),
        'B': toInt(rawMultipliers['B'], 3),
        'C': toInt(rawMultipliers['C'], 5),
        'D': toInt(rawMultipliers['D'], 10),
      },
      potSectors: <String, int>{
        'A': toInt(rawSectors['A'], 22),
        'B': toInt(rawSectors['B'], 14),
        'C': toInt(rawSectors['C'], 8),
        'D': toInt(rawSectors['D'], 4),
      },
    );
  }
}

class GreedyRound {
  const GreedyRound({
    required this.id,
    required this.roundKey,
    required this.status,
    required this.phase,
    required this.startsAt,
    required this.locksAt,
    required this.endsAt,
    required this.settledAt,
    required this.displayUntil,
    required this.winningPot,
    required this.winningMultiplier,
    required this.countdownSeconds,
    required this.totals,
    required this.realTotals,
    required this.fakeTotals,
    required this.potMultipliers,
    required this.potSectors,
    required this.totalBetsCount,
    required this.participantCount,
    required this.viewerBets,
  });

  final int id;
  final String roundKey;
  final String status;
  final String phase;
  final DateTime? startsAt;
  final DateTime? locksAt;
  final DateTime? endsAt;
  final DateTime? settledAt;
  final DateTime? displayUntil;
  final String? winningPot;
  final int? winningMultiplier;
  final int countdownSeconds;
  final Map<String, int> totals;
  final Map<String, int> realTotals;
  final Map<String, int> fakeTotals;
  final Map<String, int> potMultipliers;
  final Map<String, int> potSectors;
  final int totalBetsCount;
  final int participantCount;
  final List<GreedyBet> viewerBets;

  factory GreedyRound.fromJson(Map<String, dynamic> json) {
    int toInt(dynamic value, int fallback) {
      if (value is int) return value;
      if (value is double) return value.round();
      return int.tryParse(value?.toString() ?? '') ??
          double.tryParse(value?.toString() ?? '')?.round() ??
          fallback;
    }

    DateTime? toDate(dynamic value) =>
        value == null ? null : DateTime.tryParse(value.toString());

    final totalsJson = Map<String, dynamic>.from(
      json['totals'] as Map? ?? const {},
    );
    final realTotalsJson = Map<String, dynamic>.from(
      json['real_totals'] as Map? ?? const {},
    );
    final fakeTotalsJson = Map<String, dynamic>.from(
      json['fake_totals'] as Map? ?? const {},
    );
    final multipliersJson = Map<String, dynamic>.from(
      json['pot_multipliers'] as Map? ?? const {},
    );
    final sectorsJson = Map<String, dynamic>.from(
      json['pot_sectors'] as Map? ?? const {},
    );

    return GreedyRound(
      id: toInt(json['id'], 0),
      roundKey: (json['round_key'] ?? '').toString(),
      status: (json['status'] ?? 'open').toString(),
      phase: (json['phase'] ?? 'betting').toString(),
      startsAt: toDate(json['starts_at']),
      locksAt: toDate(json['locks_at']),
      endsAt: toDate(json['ends_at']),
      settledAt: toDate(json['settled_at']),
      displayUntil: toDate(json['display_until']),
      winningPot: json['winning_pot']?.toString(),
      winningMultiplier:
          json['winning_multiplier'] == null
              ? null
              : toInt(json['winning_multiplier'], 0),
      countdownSeconds: toInt(json['countdown_seconds'], 0),
      totals: <String, int>{
        'A': toInt(totalsJson['A'], 0),
        'B': toInt(totalsJson['B'], 0),
        'C': toInt(totalsJson['C'], 0),
        'D': toInt(totalsJson['D'], 0),
      },
      realTotals: <String, int>{
        'A': toInt(realTotalsJson['A'], toInt(totalsJson['A'], 0)),
        'B': toInt(realTotalsJson['B'], toInt(totalsJson['B'], 0)),
        'C': toInt(realTotalsJson['C'], toInt(totalsJson['C'], 0)),
        'D': toInt(realTotalsJson['D'], toInt(totalsJson['D'], 0)),
      },
      fakeTotals: <String, int>{
        'A': toInt(fakeTotalsJson['A'], 0),
        'B': toInt(fakeTotalsJson['B'], 0),
        'C': toInt(fakeTotalsJson['C'], 0),
        'D': toInt(fakeTotalsJson['D'], 0),
      },
      potMultipliers: <String, int>{
        'A': toInt(multipliersJson['A'], 2),
        'B': toInt(multipliersJson['B'], 3),
        'C': toInt(multipliersJson['C'], 5),
        'D': toInt(multipliersJson['D'], 10),
      },
      potSectors: <String, int>{
        'A': toInt(sectorsJson['A'], 22),
        'B': toInt(sectorsJson['B'], 14),
        'C': toInt(sectorsJson['C'], 8),
        'D': toInt(sectorsJson['D'], 4),
      },
      totalBetsCount: toInt(json['total_bets_count'], 0),
      participantCount: toInt(json['participant_count'], 0),
      viewerBets:
          (json['viewer_bets'] as List? ?? const [])
              .whereType<Map>()
              .map((row) => GreedyBet.fromJson(Map<String, dynamic>.from(row)))
              .toList(),
    );
  }
}

class GreedyBet {
  const GreedyBet({
    required this.id,
    required this.roundId,
    required this.userId,
    required this.pot,
    required this.amount,
    required this.multiplier,
    required this.status,
    required this.payoutCoins,
  });

  final int id;
  final int roundId;
  final int userId;
  final String pot;
  final int amount;
  final int multiplier;
  final String status;
  final int payoutCoins;

  factory GreedyBet.fromJson(Map<String, dynamic> json) {
    int toInt(dynamic value, int fallback) {
      if (value is int) return value;
      if (value is double) return value.round();
      return int.tryParse(value?.toString() ?? '') ??
          double.tryParse(value?.toString() ?? '')?.round() ??
          fallback;
    }

    return GreedyBet(
      id: toInt(json['id'], 0),
      roundId: toInt(json['round_id'], 0),
      userId: toInt(json['user_id'], 0),
      pot: (json['pot'] ?? '').toString(),
      amount: toInt(json['amount'], 0),
      multiplier: toInt(json['multiplier'], 0),
      status: (json['status'] ?? 'placed').toString(),
      payoutCoins: toInt(json['payout_coins'], 0),
    );
  }
}
