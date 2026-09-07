class SevenUpDownSnapshot {
  const SevenUpDownSnapshot({
    required this.settings,
    required this.walletBalance,
    required this.round,
    required this.history,
  });

  final SevenUpDownSettings settings;
  final int walletBalance;
  final SevenUpDownRound round;
  final List<SevenUpDownRound> history;

  factory SevenUpDownSnapshot.fromJson(Map<String, dynamic> json) {
    return SevenUpDownSnapshot(
      settings: SevenUpDownSettings.fromJson(_map(json['settings'])),
      walletBalance: _int(json['wallet_balance']),
      round: SevenUpDownRound.fromJson(_map(json['round'])),
      history:
          (json['history'] as List? ?? const [])
              .whereType<Map>()
              .map(
                (row) =>
                    SevenUpDownRound.fromJson(Map<String, dynamic>.from(row)),
              )
              .toList(),
    );
  }

  SevenUpDownSnapshot mergePublic(SevenUpDownSnapshot public) {
    return SevenUpDownSnapshot(
      settings: public.settings,
      walletBalance: walletBalance,
      round: public.round.copyWith(
        viewerBets:
            public.round.roundKey == round.roundKey
                ? round.viewerBets
                : const [],
      ),
      history: public.history,
    );
  }
}

class SevenUpDownSettings {
  const SevenUpDownSettings({
    required this.displayName,
    required this.enabled,
    required this.fakeBetsEnabled,
    required this.minBet,
    required this.maxBet,
    required this.roundDurationSeconds,
    required this.bettingLockSeconds,
    required this.resultDisplaySeconds,
    required this.multipliers,
    required this.rules,
    required this.payoutType,
    required this.resultAuthority,
  });

  final String displayName;
  final bool enabled;
  final bool fakeBetsEnabled;
  final int minBet;
  final int maxBet;
  final int roundDurationSeconds;
  final int bettingLockSeconds;
  final int resultDisplaySeconds;
  final Map<String, int> multipliers;
  final Map<String, SevenUpDownPotRule> rules;
  final String payoutType;
  final String resultAuthority;

  factory SevenUpDownSettings.fromJson(Map<String, dynamic> json) {
    final raw = _map(json['pot_multipliers']);
    final rules = _map(json['rules']);
    return SevenUpDownSettings(
      displayName: (json['display_name'] ?? 'Lucky 7').toString(),
      enabled: _bool(json['enabled']),
      fakeBetsEnabled: _bool(json['fake_bets_enabled']),
      minBet: _int(json['min_bet'], 10),
      maxBet: _int(json['max_bet'], 5000),
      roundDurationSeconds: _int(json['round_duration_seconds'], 30),
      bettingLockSeconds: _int(json['betting_lock_seconds'], 5),
      resultDisplaySeconds: _int(json['result_display_seconds'], 6),
      multipliers: {
        'DOWN': _int(raw['DOWN'], 3),
        'SEVEN': _int(raw['SEVEN'], 4),
        'UP': _int(raw['UP'], 3),
      },
      rules: {
        'DOWN': SevenUpDownPotRule.fromJson(
          _map(rules['DOWN']),
          fallbackMin: 2,
          fallbackMax: 6,
          fallbackCombinations: 15,
        ),
        'SEVEN': SevenUpDownPotRule.fromJson(
          _map(rules['SEVEN']),
          fallbackMin: 7,
          fallbackMax: 7,
          fallbackCombinations: 6,
        ),
        'UP': SevenUpDownPotRule.fromJson(
          _map(rules['UP']),
          fallbackMin: 8,
          fallbackMax: 12,
          fallbackCombinations: 15,
        ),
      },
      payoutType:
          (rules['payout_type'] ?? 'total_return_including_stake').toString(),
      resultAuthority:
          (rules['result_authority'] ?? 'backend_persisted_dice').toString(),
    );
  }
}

class SevenUpDownPotRule {
  const SevenUpDownPotRule({
    required this.minTotal,
    required this.maxTotal,
    required this.diceCombinations,
  });

  final int minTotal;
  final int maxTotal;
  final int diceCombinations;

  factory SevenUpDownPotRule.fromJson(
    Map<String, dynamic> json, {
    required int fallbackMin,
    required int fallbackMax,
    required int fallbackCombinations,
  }) => SevenUpDownPotRule(
    minTotal: _int(json['min_total'], fallbackMin),
    maxTotal: _int(json['max_total'], fallbackMax),
    diceCombinations: _int(json['dice_combinations'], fallbackCombinations),
  );
}

class SevenUpDownRound {
  const SevenUpDownRound({
    required this.id,
    required this.roundKey,
    required this.status,
    required this.phase,
    required this.startsAt,
    required this.locksAt,
    required this.endsAt,
    required this.displayUntil,
    required this.winningPot,
    required this.diceOne,
    required this.diceTwo,
    required this.diceTotal,
    required this.totals,
    required this.realTotals,
    required this.fakeTotals,
    required this.multipliers,
    required this.viewerBets,
  });

  final int id;
  final String roundKey;
  final String status;
  final String phase;
  final DateTime? startsAt;
  final DateTime? locksAt;
  final DateTime? endsAt;
  final DateTime? displayUntil;
  final String? winningPot;
  final int? diceOne;
  final int? diceTwo;
  final int? diceTotal;
  final Map<String, int> totals;
  final Map<String, int> realTotals;
  final Map<String, int> fakeTotals;
  final Map<String, int> multipliers;
  final List<SevenUpDownBet> viewerBets;

  factory SevenUpDownRound.fromJson(Map<String, dynamic> json) {
    final totals = _map(json['totals']);
    final realTotals = _map(json['real_totals']);
    final fakeTotals = _map(json['fake_totals']);
    final multipliers = _map(json['pot_multipliers']);
    return SevenUpDownRound(
      id: _int(json['id']),
      roundKey: (json['round_key'] ?? '').toString(),
      status: (json['status'] ?? 'open').toString(),
      phase: (json['phase'] ?? 'betting').toString(),
      startsAt: DateTime.tryParse(json['starts_at']?.toString() ?? ''),
      locksAt: DateTime.tryParse(json['locks_at']?.toString() ?? ''),
      endsAt: DateTime.tryParse(json['ends_at']?.toString() ?? ''),
      displayUntil: DateTime.tryParse(json['display_until']?.toString() ?? ''),
      winningPot: json['winning_pot']?.toString(),
      diceOne: json['dice_one'] == null ? null : _int(json['dice_one']),
      diceTwo: json['dice_two'] == null ? null : _int(json['dice_two']),
      diceTotal: json['dice_total'] == null ? null : _int(json['dice_total']),
      totals: {
        'DOWN': _int(totals['DOWN']),
        'SEVEN': _int(totals['SEVEN']),
        'UP': _int(totals['UP']),
      },
      realTotals: {
        'DOWN': _int(realTotals['DOWN'], _int(totals['DOWN'])),
        'SEVEN': _int(realTotals['SEVEN'], _int(totals['SEVEN'])),
        'UP': _int(realTotals['UP'], _int(totals['UP'])),
      },
      fakeTotals: {
        'DOWN': _int(fakeTotals['DOWN']),
        'SEVEN': _int(fakeTotals['SEVEN']),
        'UP': _int(fakeTotals['UP']),
      },
      multipliers: {
        'DOWN': _int(multipliers['DOWN'], 3),
        'SEVEN': _int(multipliers['SEVEN'], 4),
        'UP': _int(multipliers['UP'], 3),
      },
      viewerBets:
          (json['viewer_bets'] as List? ?? const [])
              .whereType<Map>()
              .map(
                (row) =>
                    SevenUpDownBet.fromJson(Map<String, dynamic>.from(row)),
              )
              .toList(),
    );
  }

  SevenUpDownRound copyWith({List<SevenUpDownBet>? viewerBets}) {
    return SevenUpDownRound(
      id: id,
      roundKey: roundKey,
      status: status,
      phase: phase,
      startsAt: startsAt,
      locksAt: locksAt,
      endsAt: endsAt,
      displayUntil: displayUntil,
      winningPot: winningPot,
      diceOne: diceOne,
      diceTwo: diceTwo,
      diceTotal: diceTotal,
      totals: totals,
      realTotals: realTotals,
      fakeTotals: fakeTotals,
      multipliers: multipliers,
      viewerBets: viewerBets ?? this.viewerBets,
    );
  }
}

class SevenUpDownBet {
  const SevenUpDownBet({
    required this.id,
    required this.pot,
    required this.amount,
    required this.multiplier,
    required this.status,
    required this.payoutCoins,
  });

  final int id;
  final String pot;
  final int amount;
  final int multiplier;
  final String status;
  final int payoutCoins;

  factory SevenUpDownBet.fromJson(Map<String, dynamic> json) => SevenUpDownBet(
    id: _int(json['id']),
    pot: (json['pot'] ?? '').toString(),
    amount: _int(json['amount']),
    multiplier: _int(json['multiplier']),
    status: (json['status'] ?? 'placed').toString(),
    payoutCoins: _int(json['payout_coins']),
  );
}

Map<String, dynamic> _map(dynamic value) =>
    Map<String, dynamic>.from(value as Map? ?? const {});

int _int(dynamic value, [int fallback = 0]) =>
    value is num
        ? value.round()
        : int.tryParse(value?.toString() ?? '') ?? fallback;

bool _bool(dynamic value) => value == true || value?.toString() == '1';
