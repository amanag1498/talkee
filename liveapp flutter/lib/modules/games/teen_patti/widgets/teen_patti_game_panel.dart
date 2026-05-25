import 'dart:async';

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../../app/routes/app_urls.dart';
import '../../../../services/storage_service.dart';
import '../../../wallet/widgets/recharge_bottom_sheet.dart';
import '../models/teen_patti_models.dart';
import '../services/teen_patti_api.dart';
import '../services/teen_patti_socket_service.dart';

class TeenPattiGamesSheet extends StatefulWidget {
  const TeenPattiGamesSheet({super.key});

  @override
  State<TeenPattiGamesSheet> createState() => _TeenPattiGamesSheetState();
}

class _TeenPattiGamesSheetState extends State<TeenPattiGamesSheet> {
  bool _openTeenPatti = false;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      top: false,
      child: FractionallySizedBox(
        heightFactor: 0.94,
        child: DecoratedBox(
          decoration: const BoxDecoration(
            color: Color(0xFF120C1D),
            borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          ),
          child: Column(
            children: [
              const SizedBox(height: 12),
              Container(
                width: 46,
                height: 4,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .28),
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(18, 18, 18, 12),
                child: Row(
                  children: [
                    if (_openTeenPatti)
                      IconButton(
                        onPressed: () => setState(() => _openTeenPatti = false),
                        icon: const Icon(Icons.arrow_back_rounded),
                        color: Colors.white,
                      )
                    else
                      const SizedBox(width: 48),
                    Expanded(
                      child: Text(
                        _openTeenPatti ? 'Teen Patti' : 'Games',
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 20,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ),
                    IconButton(
                      onPressed: () => Navigator.of(context).maybePop(),
                      icon: const Icon(Icons.close_rounded),
                      color: Colors.white70,
                    ),
                  ],
                ),
              ),
              Expanded(
                child:
                    _openTeenPatti
                        ? const TeenPattiGamePanel()
                        : _GamesList(
                          onOpenTeenPatti: () {
                            setState(() => _openTeenPatti = true);
                          },
                        ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _GamesList extends StatelessWidget {
  const _GamesList({required this.onOpenTeenPatti});

  final VoidCallback onOpenTeenPatti;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(18, 8, 18, 24),
      children: [
        Text(
          'Choose a live room game',
          style: TextStyle(
            color: Colors.white.withValues(alpha: .9),
            fontSize: 13,
            fontWeight: FontWeight.w600,
          ),
        ),
        const SizedBox(height: 14),
        InkWell(
          onTap: onOpenTeenPatti,
          borderRadius: BorderRadius.circular(22),
          child: Ink(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(22),
              gradient: const LinearGradient(
                colors: [Color(0xFF26173C), Color(0xFF171124)],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              border: Border.all(color: Colors.white12),
            ),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(16),
                    child: Image.asset(
                      'assets/games/teen_patti/logo_teenpatti.png',
                      width: 68,
                      height: 68,
                      fit: BoxFit.cover,
                    ),
                  ),
                  const SizedBox(width: 14),
                  const Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Teen Patti',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 18,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        SizedBox(height: 6),
                        Text(
                          'Live round-based betting with A, B, and C pots inside the video room.',
                          style: TextStyle(
                            color: Colors.white70,
                            height: 1.35,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const Icon(
                    Icons.arrow_forward_ios_rounded,
                    color: Colors.white70,
                    size: 18,
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class TeenPattiGamePanel extends StatefulWidget {
  const TeenPattiGamePanel({super.key});

  @override
  State<TeenPattiGamePanel> createState() => _TeenPattiGamePanelState();
}

class _TeenPattiGamePanelState extends State<TeenPattiGamePanel> {
  static const _chipOptions = <int>[10, 50, 100, 200];

  final TeenPattiApi _api = Get.find<TeenPattiApi>();
  final TeenPattiSocketService _socket = Get.find<TeenPattiSocketService>();

  TeenPattiSnapshot? _snapshot;
  StreamSubscription<Map<String, dynamic>>? _snapshotSub;
  StreamSubscription<Map<String, dynamic>>? _eventSub;
  bool _loading = true;
  bool _placingBet = false;
  String? _error;
  int _selectedChip = 50;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  @override
  void dispose() {
    _snapshotSub?.cancel();
    _eventSub?.cancel();
    unawaited(_socket.stop());
    super.dispose();
  }

  Future<void> _bootstrap() async {
    await _loadSnapshot();

    final token = Get.find<StorageService>().token;
    if (token == null || token.isEmpty) return;

    _snapshotSub?.cancel();
    _snapshotSub = _socket.snapshotEvents.listen((payload) {
      final data = Map<String, dynamic>.from(payload['data'] as Map? ?? const {});
      if (data.isEmpty || !mounted) return;
      setState(() {
        _snapshot = TeenPattiSnapshot.fromJson(data);
        _error = null;
      });
    });
    _eventSub?.cancel();
    _eventSub = _socket.eventStream.listen((payload) {
      if (!mounted) return;
      if (payload['event'] == 'feature:error') {
        setState(() {
          _error = (payload['message'] ?? 'Game unavailable.').toString();
        });
      }
    });

    await _socket.start(
      wsGamesUrl: AppUrls.wsGames,
      bearerToken: token,
    );
  }

  Future<void> _loadSnapshot() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final snapshot = await _api.fetchSnapshot();
      if (!mounted) return;
      setState(() {
        _snapshot = snapshot;
        _selectedChip =
            _chipOptions.contains(snapshot.settings.minBet)
                ? snapshot.settings.minBet
                : _selectedChip.clamp(
                  snapshot.settings.minBet,
                  snapshot.settings.maxBet,
                );
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString().replaceFirst('Exception: ', '');
        _loading = false;
      });
    }
  }

  Future<void> _placeBet(String pot) async {
    final snapshot = _snapshot;
    if (_placingBet || snapshot == null) return;
    if (_selectedChip > snapshot.walletBalance) {
      _showRecharge();
      return;
    }

    setState(() {
      _placingBet = true;
      _error = null;
    });

    try {
      final next = await _api.placeBet(
        pot: pot,
        amount: _selectedChip,
        idempotencyKey: 'tp_${DateTime.now().microsecondsSinceEpoch}_$pot',
      );
      if (!mounted) return;
      setState(() => _snapshot = next);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString().replaceFirst('Exception: ', '');
      });
      if (_error?.toLowerCase().contains('insufficient') == true) {
        _showRecharge();
      }
    } finally {
      if (mounted) {
        setState(() => _placingBet = false);
      }
    }
  }

  void _showRecharge() {
    showRechargeWalletSheet(
      reasonMessage:
          'You need more coins to place this Teen Patti bet. Recharge and try again.',
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null && _snapshot == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(
                Icons.error_outline_rounded,
                color: Colors.white70,
                size: 34,
              ),
              const SizedBox(height: 12),
              Text(
                _error!,
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white70),
              ),
              const SizedBox(height: 16),
              FilledButton(
                onPressed: _loadSnapshot,
                child: const Text('Retry'),
              ),
            ],
          ),
        ),
      );
    }

    final snapshot = _snapshot!;
    final round = snapshot.round;
    final revealCards = round.phase == 'result' && round.winningPot != null;

    return Stack(
      fit: StackFit.expand,
      children: [
        Image.asset(
          'assets/games/teen_patti/background_teen_patti.jpg',
          fit: BoxFit.cover,
        ),
        DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [
                Colors.black.withValues(alpha: .35),
                Colors.black.withValues(alpha: .58),
              ],
            ),
          ),
        ),
        ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
          children: [
            Center(
              child: Image.asset(
                'assets/games/teen_patti/logo_teenpatti.png',
                height: 64,
              ),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(
                  child: _StatPill(
                    label: 'Wallet',
                    value: '${snapshot.walletBalance}',
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _StatPill(label: 'Round', value: round.roundKey),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _StatPill(
                    label: 'Countdown',
                    value: '${round.countdownSeconds}s',
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),
            _PhaseBanner(
              phase: round.phase,
              winningPot: round.winningPot,
              error: _error,
            ),
            const SizedBox(height: 14),
            for (final pot in const ['A', 'B', 'C']) ...[
              _PotCard(
                pot: pot,
                total: round.totals[pot] ?? 0,
                selected: round.winningPot == pot,
                reveal: revealCards,
                cards: _cardsForPot(round, pot),
                canBet: round.phase == 'betting',
                busy: _placingBet,
                onTap: () => _placeBet(pot),
              ),
              const SizedBox(height: 12),
            ],
            const SizedBox(height: 4),
            Text(
              'Select chip',
              style: TextStyle(
                color: Colors.white.withValues(alpha: .9),
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children:
                  _chipOptions.map((chip) {
                    final active = chip == _selectedChip;
                    final disabled =
                        chip < snapshot.settings.minBet ||
                        chip > snapshot.settings.maxBet;
                    return ChoiceChip(
                      label: Text('$chip'),
                      selected: active,
                      onSelected:
                          disabled
                              ? null
                              : (_) => setState(() => _selectedChip = chip),
                    );
                  }).toList(),
            ),
            const SizedBox(height: 18),
            if (round.viewerBets.isNotEmpty) ...[
              Text(
                'Your Bets',
                style: TextStyle(
                  color: Colors.white.withValues(alpha: .95),
                  fontWeight: FontWeight.w800,
                  fontSize: 15,
                ),
              ),
              const SizedBox(height: 8),
              ...round.viewerBets.map(
                (bet) => ListTile(
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text(
                    'Pot ${bet.pot} • ${bet.amount} coins',
                    style: const TextStyle(color: Colors.white),
                  ),
                  subtitle: Text(
                    bet.status.toUpperCase(),
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: .72),
                    ),
                  ),
                  trailing:
                      bet.payoutCoins > 0
                          ? Text(
                            '+${bet.payoutCoins}',
                            style: const TextStyle(
                              color: Color(0xFF73F0B3),
                              fontWeight: FontWeight.w800,
                            ),
                          )
                          : null,
                ),
              ),
              const SizedBox(height: 16),
            ],
            Text(
              'Recent Rounds',
              style: TextStyle(
                color: Colors.white.withValues(alpha: .95),
                fontWeight: FontWeight.w800,
                fontSize: 15,
              ),
            ),
            const SizedBox(height: 8),
            ...snapshot.history.take(5).map(
              (item) => Container(
                margin: const EdgeInsets.only(bottom: 8),
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 10,
                ),
                decoration: BoxDecoration(
                  color: Colors.black.withValues(alpha: .24),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: Colors.white10),
                ),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        item.roundKey,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                    Text(
                      'Winner ${item.winningPot ?? '—'}',
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: .76),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }

  List<String> _cardsForPot(TeenPattiRound round, String pot) {
    if (round.phase != 'result' || round.winningPot == null) {
      return const [
        'assets/games/teen_patti/card_back_1.jpeg',
        'assets/games/teen_patti/card_back_1.jpeg',
        'assets/games/teen_patti/card_back_1.jpeg',
      ];
    }

    if (round.winningPot == pot) {
      return round.winningHand
          .map((card) => 'assets/games/teen_patti/cards/$card')
          .toList();
    }

    if (pot == 'A') {
      return (round.winningPot == 'B'
              ? round.losingHandOne
              : round.losingHandTwo)
          .map((card) => 'assets/games/teen_patti/cards/$card')
          .toList();
    }
    if (pot == 'B') {
      return (round.winningPot == 'A'
              ? round.losingHandOne
              : round.losingHandTwo)
          .map((card) => 'assets/games/teen_patti/cards/$card')
          .toList();
    }
    return (round.winningPot == 'A'
            ? round.losingHandTwo
            : round.losingHandOne)
        .map((card) => 'assets/games/teen_patti/cards/$card')
        .toList();
  }
}

class _StatPill extends StatelessWidget {
  const _StatPill({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.black.withValues(alpha: .24),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: TextStyle(
              color: Colors.white.withValues(alpha: .68),
              fontSize: 11,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}

class _PhaseBanner extends StatelessWidget {
  const _PhaseBanner({
    required this.phase,
    required this.winningPot,
    required this.error,
  });

  final String phase;
  final String? winningPot;
  final String? error;

  @override
  Widget build(BuildContext context) {
    final title = switch (phase) {
      'betting' => 'Betting Open',
      'locked' => 'Bets Locked',
      'result' => winningPot == null ? 'Result Ready' : 'Winner: Pot $winningPot',
      'cancelled' => 'Round Cancelled',
      _ => 'Teen Patti',
    };

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .08),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white12),
      ),
      child: Row(
        children: [
          const Icon(Icons.bolt_rounded, color: Color(0xFFFFD966)),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              error?.isNotEmpty == true ? '$title • $error' : title,
              style: const TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _PotCard extends StatelessWidget {
  const _PotCard({
    required this.pot,
    required this.total,
    required this.selected,
    required this.reveal,
    required this.cards,
    required this.canBet,
    required this.busy,
    required this.onTap,
  });

  final String pot;
  final int total;
  final bool selected;
  final bool reveal;
  final List<String> cards;
  final bool canBet;
  final bool busy;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final borderColor = selected ? const Color(0xFFFFD966) : Colors.white12;

    return InkWell(
      onTap: canBet && !busy ? onTap : null,
      borderRadius: BorderRadius.circular(22),
      child: Ink(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(22),
          color: Colors.black.withValues(alpha: .22),
          border: Border.all(color: borderColor, width: selected ? 1.6 : 1),
          boxShadow:
              selected
                  ? [
                    BoxShadow(
                      color: const Color(0xFFFFD966).withValues(alpha: .16),
                      blurRadius: 24,
                      spreadRadius: 2,
                    ),
                  ]
                  : null,
        ),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Text(
                    'Pot $pot',
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w900,
                      fontSize: 18,
                    ),
                  ),
                  const Spacer(),
                  Text(
                    '$total coins',
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: .78),
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children:
                    cards.take(3).map((asset) {
                      return ClipRRect(
                        borderRadius: BorderRadius.circular(12),
                        child: Image.asset(
                          asset,
                          width: 88,
                          height: 118,
                          fit: BoxFit.cover,
                          errorBuilder:
                              (_, __, ___) => Container(
                                width: 88,
                                height: 118,
                                color: Colors.white12,
                                alignment: Alignment.center,
                                child: const Icon(
                                  Icons.style_rounded,
                                  color: Colors.white38,
                                ),
                              ),
                        ),
                      );
                    }).toList(),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      reveal
                          ? (selected ? 'Winning reveal' : 'Revealed hand')
                          : (canBet ? 'Tap to place bet' : 'Waiting for result'),
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: .7),
                      ),
                    ),
                  ),
                  if (busy)
                    const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
