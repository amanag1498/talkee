import 'package:flutter_test/flutter_test.dart';
import 'package:liveapp/modules/games/seven_up_down/models/seven_up_down_models.dart';

void main() {
  test('parses backend dice result, pots, multipliers, and viewer bets', () {
    final snapshot = SevenUpDownSnapshot.fromJson({
      'settings': {
        'display_name': 'Lucky 7',
        'enabled': true,
        'fake_bets_enabled': true,
        'min_bet': 10,
        'max_bet': 5000,
        'pot_multipliers': {'DOWN': 3, 'SEVEN': 4, 'UP': 3},
        'rules': {
          'DOWN': {'min_total': 2, 'max_total': 6, 'dice_combinations': 15},
          'SEVEN': {'min_total': 7, 'max_total': 7, 'dice_combinations': 6},
          'UP': {'min_total': 8, 'max_total': 12, 'dice_combinations': 15},
          'payout_type': 'total_return_including_stake',
          'result_authority': 'backend_persisted_dice',
        },
      },
      'wallet_balance': 900,
      'round': {
        'id': 7,
        'round_key': 'sud_test',
        'status': 'settled',
        'phase': 'result',
        'starts_at': '2026-09-01T10:00:00Z',
        'locks_at': '2026-09-01T10:00:25Z',
        'ends_at': '2026-09-01T10:00:30Z',
        'display_until': '2026-09-01T10:00:36Z',
        'winning_pot': 'SEVEN',
        'dice_one': 3,
        'dice_two': 4,
        'dice_total': 7,
        'totals': {'DOWN': 100, 'SEVEN': 200, 'UP': 300},
        'real_totals': {'DOWN': 50, 'SEVEN': 125, 'UP': 200},
        'fake_totals': {'DOWN': 50, 'SEVEN': 75, 'UP': 100},
        'pot_multipliers': {'DOWN': 3, 'SEVEN': 4, 'UP': 3},
        'viewer_bets': [
          {
            'id': 11,
            'pot': 'SEVEN',
            'amount': 50,
            'multiplier': 4,
            'status': 'won',
            'payout_coins': 200,
          },
        ],
      },
      'history': [],
    });

    expect(snapshot.walletBalance, 900);
    expect(snapshot.settings.displayName, 'Lucky 7');
    expect(snapshot.settings.fakeBetsEnabled, isTrue);
    expect(snapshot.settings.rules['SEVEN']!.diceCombinations, 6);
    expect(snapshot.settings.payoutType, 'total_return_including_stake');
    expect(snapshot.round.diceOne, 3);
    expect(snapshot.round.diceTwo, 4);
    expect(snapshot.round.diceTotal, 7);
    expect(snapshot.round.winningPot, 'SEVEN');
    expect(snapshot.round.multipliers['SEVEN'], 4);
    expect(snapshot.round.realTotals['SEVEN'], 125);
    expect(snapshot.round.fakeTotals['SEVEN'], 75);
    expect(snapshot.round.viewerBets.single.payoutCoins, 200);
    expect(snapshot.round.displayUntil, DateTime.utc(2026, 9, 1, 10, 0, 36));
  });

  test('public snapshots preserve private wallet and viewer bets', () {
    final private = SevenUpDownSnapshot.fromJson({
      'wallet_balance': 750,
      'round': {
        'round_key': 'same',
        'viewer_bets': [
          {'id': 1, 'pot': 'DOWN', 'amount': 50},
        ],
      },
    });
    final public = SevenUpDownSnapshot.fromJson({
      'round': {
        'round_key': 'same',
        'totals': {'DOWN': 500},
      },
    });

    final merged = private.mergePublic(public);
    expect(merged.walletBalance, 750);
    expect(merged.round.viewerBets.single.pot, 'DOWN');
    expect(merged.round.totals['DOWN'], 500);
  });
}
