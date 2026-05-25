import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import '../../../../app/routes/app_urls.dart';
import '../../../../app/widgets/haptics.dart';
import '../../../../services/storage_service.dart';
import '../../../wallet/widgets/recharge_bottom_sheet.dart';
import '../models/greedy_models.dart';
import '../services/greedy_api.dart';
import '../services/greedy_socket_service.dart';

class GreedyGamePanel extends StatefulWidget {
  const GreedyGamePanel({super.key});

  @override
  State<GreedyGamePanel> createState() => _GreedyGamePanelState();
}

class _GreedyGamePanelState extends State<GreedyGamePanel>
    with TickerProviderStateMixin {
  static const List<int> _chipValues = <int>[10, 50, 100, 200, 500, 1000, 5000];
  static const List<String> _pots = <String>['A', 'B', 'C', 'D'];

  final GreedyApi _api = Get.find<GreedyApi>();
  final GreedySocketService _socket = Get.find<GreedySocketService>();

  late final AnimationController _wheelController;
  late final AnimationController _pulseController;

  StreamSubscription<Map<String, dynamic>>? _snapshotSub;
  StreamSubscription<Map<String, dynamic>>? _eventSub;
  Timer? _timer;

  GreedySnapshot? _snapshot;
  bool _loading = true;
  bool _placing = false;
  String? _error;
  String? _selectedPot;
  int _selectedAmount = 50;
  double _wheelTurns = 0;
  String? _lastSettledRoundKey;
  Map<String, int> _displayTotals = const <String, int>{};
  Map<String, int> _localViewerPotTotals = <String, int>{};
  String? _localViewerRoundKey;
  String? _lastWinningPot;

  @override
  void initState() {
    super.initState();
    _wheelController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1800),
    );
    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1400),
    )..repeat(reverse: true);

    unawaited(_bootstrap());
  }

  @override
  void dispose() {
    _timer?.cancel();
    _snapshotSub?.cancel();
    _eventSub?.cancel();
    _wheelController.dispose();
    _pulseController.dispose();
    _socket.stop();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    await _loadSnapshot();
    if (!mounted) return;

    final token = Get.find<StorageService>().token;
    if (token == null || token.isEmpty) {
      return;
    }

    await _socket.start(
      wsGamesUrl: AppUrls.wsGames,
      bearerToken: token,
    );

    _snapshotSub = _socket.snapshotEvents.listen((payload) {
      try {
        final next = GreedySnapshot.fromJson(Map<String, dynamic>.from(payload));
        _applySnapshot(next, syncViewerBets: false, fromSocket: true);
      } catch (_) {}
    });
    _eventSub = _socket.eventStream.listen((payload) {
      if ((payload['event'] ?? '').toString() == 'feature:error') {
        final message = payload['message']?.toString();
        if (!mounted) return;
        setState(() => _error = message ?? 'Greedy is currently unavailable.');
      }
    });
  }

  Future<void> _loadSnapshot() async {
    try {
      final next = await _api.fetchSnapshot();
      if (!mounted) return;
      _applySnapshot(next, syncViewerBets: true);
      setState(() {
        _loading = false;
        _error = null;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.toString().replaceFirst('Exception: ', '');
      });
    }
  }

  void _applySnapshot(
    GreedySnapshot next, {
    required bool syncViewerBets,
    bool fromSocket = false,
  }) {
    final previous = _snapshot;
    if (_localViewerRoundKey != next.round.roundKey) {
      _localViewerRoundKey = next.round.roundKey;
      _localViewerPotTotals = <String, int>{};
      _lastSettledRoundKey = next.round.phase == 'result' ? next.round.roundKey : null;
      _lastWinningPot = next.round.phase == 'result' ? next.round.winningPot : null;
    }
    if (syncViewerBets) {
      _syncLocalViewerBets(next.round);
    }
    _syncDisplayTotals(next);
    _maybeSpinForResult(next.round, previous?.round);

    final round =
        syncViewerBets
            ? next.round
            : GreedyRound(
              id: next.round.id,
              roundKey: next.round.roundKey,
              status: next.round.status,
              phase: next.round.phase,
              startsAt: next.round.startsAt,
              locksAt: next.round.locksAt,
              endsAt: next.round.endsAt,
              settledAt: next.round.settledAt,
              displayUntil: next.round.displayUntil,
              winningPot: next.round.winningPot,
              winningMultiplier: next.round.winningMultiplier,
              countdownSeconds: next.round.countdownSeconds,
              totals: next.round.totals,
              realTotals: next.round.realTotals,
              fakeTotals: next.round.fakeTotals,
              potMultipliers: next.round.potMultipliers,
              potSectors: next.round.potSectors,
              totalBetsCount: next.round.totalBetsCount,
              participantCount: next.round.participantCount,
              viewerBets:
                  previous?.round.viewerBets ??
                  next.round.viewerBets,
            );

    _snapshot = GreedySnapshot(
      settings: next.settings,
      walletBalance: syncViewerBets ? next.walletBalance : (_snapshot?.walletBalance ?? next.walletBalance),
      round: round,
      history: next.history,
    );

    _ensureTimer();
    if (!mounted) return;
    setState(() {});
    if (!fromSocket && next.round.phase == 'result') {
      unawaited(_refreshAfterResult());
    }
  }

  void _ensureTimer() {
    _timer ??= Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted || _snapshot == null) return;
      _syncDisplayTotals(_snapshot!);
      setState(() {});
    });
  }

  void _syncLocalViewerBets(GreedyRound round) {
    final next = <String, int>{for (final pot in _pots) pot: 0};
    for (final bet in round.viewerBets) {
      next[bet.pot] = (next[bet.pot] ?? 0) + bet.amount;
    }
    for (final pot in _pots) {
      final existing = _localViewerPotTotals[pot] ?? 0;
      if ((next[pot] ?? 0) < existing) {
        next[pot] = existing;
      }
    }
    _localViewerPotTotals = next;
  }

  void _syncDisplayTotals(GreedySnapshot snapshot) {
    final round = snapshot.round;
    final ratio = _roundProgressRatio(round);
    _displayTotals = <String, int>{
      for (final pot in _pots)
        pot:
            (round.realTotals[pot] ?? 0) +
            ((round.fakeTotals[pot] ?? 0) * ratio).round(),
    };
  }

  double _roundProgressRatio(GreedyRound round) {
    final start = round.startsAt;
    final lock = round.locksAt;
    if (start == null || lock == null) {
      return round.phase == 'betting' ? 0.5 : 1;
    }

    final total = max(1, lock.difference(start).inMilliseconds);
    final elapsed = DateTime.now().difference(start).inMilliseconds.clamp(0, total);
    if (round.phase == 'betting') {
      return elapsed / total;
    }
    return 1;
  }

  void _maybeSpinForResult(GreedyRound round, GreedyRound? previous) {
    if (round.phase != 'result' || round.winningPot == null) {
      return;
    }
    if (_lastSettledRoundKey == round.roundKey) {
      return;
    }
    _lastSettledRoundKey = round.roundKey;
    _lastWinningPot = round.winningPot;

    final targetPot = round.winningPot!;
    final index = _pots.indexOf(targetPot);
    final baseTurn = 4.5;
    final targetOffset = switch (index) {
      0 => 0.125,
      1 => 0.375,
      2 => 0.625,
      _ => 0.875,
    };
    _wheelTurns += baseTurn + targetOffset;
    _wheelController
      ..reset()
      ..forward();

    if (previous?.roundKey != round.roundKey) {
      Future<void>.delayed(const Duration(milliseconds: 1300), () {
        if (!mounted || _snapshot?.round.roundKey != round.roundKey) return;
        _showResultDialog(round);
      });
    }
  }

  Future<void> _refreshAfterResult() async {
    await Future<void>.delayed(const Duration(milliseconds: 500));
    if (!mounted) return;
    try {
      final next = await _api.fetchSnapshot();
      if (!mounted) return;
      _applySnapshot(next, syncViewerBets: true);
    } catch (_) {}
  }

  Future<void> _placeBet() async {
    final snapshot = _snapshot;
    final selectedPot = _selectedPot;
    if (snapshot == null || selectedPot == null || _placing) {
      return;
    }

    if (snapshot.walletBalance < _selectedAmount) {
      await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        backgroundColor: Colors.transparent,
        builder: (_) => const RechargeBottomSheet(),
      );
      return;
    }

    try {
      setState(() => _placing = true);
      final key =
          'greedy_${DateTime.now().microsecondsSinceEpoch}_${selectedPot}_$_selectedAmount';
      final next = await _api.placeBet(
        pot: selectedPot,
        amount: _selectedAmount,
        idempotencyKey: key,
      );
      _localViewerPotTotals[selectedPot] =
          (_localViewerPotTotals[selectedPot] ?? 0) + _selectedAmount;
      Haptics.selection();
      SystemSound.play(SystemSoundType.click);
      if (!mounted) return;
      _applySnapshot(next, syncViewerBets: true);
      setState(() => _placing = false);
    } catch (e) {
      if (!mounted) return;
      setState(() => _placing = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString().replaceFirst('Exception: ', ''))),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(color: Colors.white),
      );
    }

    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline_rounded, color: Colors.white70),
              const SizedBox(height: 12),
              Text(
                _error!,
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white70),
              ),
              const SizedBox(height: 12),
              ElevatedButton(
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

    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [Color(0xFF0B1020), Color(0xFF1A1026), Color(0xFF090D18)],
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ),
      ),
      child: Stack(
        children: [
          Positioned(
            top: -70,
            left: -40,
            child: IgnorePointer(
              child: AnimatedBuilder(
                animation: _pulseController,
                builder: (context, _) {
                  return Transform.scale(
                    scale: 1 + (_pulseController.value * .08),
                    child: Container(
                      width: 220,
                      height: 220,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: RadialGradient(
                          colors: [
                            const Color(0xFF4C7BFF).withValues(alpha: .22),
                            Colors.transparent,
                          ],
                        ),
                      ),
                    ),
                  );
                },
              ),
            ),
          ),
          Positioned(
            top: 140,
            right: -30,
            child: IgnorePointer(
              child: AnimatedBuilder(
                animation: _pulseController,
                builder: (context, _) {
                  return Transform.scale(
                    scale: 1 + (_pulseController.value * .05),
                    child: Container(
                      width: 190,
                      height: 190,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: RadialGradient(
                          colors: [
                            const Color(0xFFFF8B42).withValues(alpha: .18),
                            Colors.transparent,
                          ],
                        ),
                      ),
                    ),
                  );
                },
              ),
            ),
          ),
          ListView(
            padding: const EdgeInsets.fromLTRB(18, 8, 18, 28),
            children: [
              _GreedyHeader(
                phase: round.phase,
                countdownSeconds: _displayCountdown(round),
                walletBalance: snapshot.walletBalance,
                strategy: snapshot.settings.winningStrategyMode,
                selectedPot: _selectedPot,
                selectedAmount: _selectedAmount,
                lastWinningPot: _lastWinningPot,
              ),
              const SizedBox(height: 14),
              _GreedyPotOverview(
                totalBetsCount: round.totalBetsCount,
                participantCount: round.participantCount,
                totalPool: _displayTotals.values.fold<int>(0, (sum, value) => sum + value),
              ),
              const SizedBox(height: 18),
              _buildWheel(round),
              const SizedBox(height: 18),
              _buildPotGrid(round),
              const SizedBox(height: 18),
              _GreedyBetConsole(
                selectedPot: _selectedPot,
                selectedAmount: _selectedAmount,
                placing: _placing,
                onPlaceBet: _placeBet,
              ),
              const SizedBox(height: 12),
              _ChipTray(
                values: _chipValues,
                selectedAmount: _selectedAmount,
                onSelect: (value) {
                  Haptics.selection();
                  SystemSound.play(SystemSoundType.click);
                  setState(() => _selectedAmount = value);
                },
              ),
              const SizedBox(height: 20),
              _GreedyHistoryStrip(history: snapshot.history),
            ],
          ),
        ],
      ),
    );
  }

  int _displayCountdown(GreedyRound round) {
    final target =
        round.phase == 'betting' ? round.locksAt : round.displayUntil;
    if (target == null) return round.countdownSeconds;
    return max(0, target.difference(DateTime.now()).inSeconds);
  }

  Widget _buildWheel(GreedyRound round) {
    final turns = Tween<double>(begin: 0, end: _wheelTurns).animate(
      CurvedAnimation(parent: _wheelController, curve: Curves.easeOutCubic),
    );

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(28),
        gradient: const LinearGradient(
          colors: [Color(0xFF1E1430), Color(0xFF0E0D16)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(color: Colors.white10),
        boxShadow: const [
          BoxShadow(
            color: Colors.black54,
            blurRadius: 24,
            offset: Offset(0, 12),
          ),
        ],
      ),
      child: Column(
        children: [
          const Row(
            children: [
              Expanded(
                child: Text(
                  'Greedy Wheel',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 20,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Stack(
            alignment: Alignment.center,
            children: [
              Positioned(
                top: 4,
                child: Icon(
                  Icons.arrow_drop_down_rounded,
                  color: Colors.amber.shade200,
                  size: 42,
                ),
              ),
              SizedBox(
                width: 250,
                height: 250,
                child: AnimatedBuilder(
                  animation: Listenable.merge([_wheelController, _pulseController]),
                  builder: (context, child) {
                    return Transform.rotate(
                      angle: turns.value * 2 * pi,
                      child: CustomPaint(
                        painter: _GreedyWheelPainter(
                          multipliers: round.potMultipliers,
                          sectors: round.potSectors,
                          pulse:
                              round.phase == 'betting'
                                  ? _pulseController.value
                                  : 0,
                          winningPot:
                              round.phase == 'result' ? round.winningPot : null,
                        ),
                        child: const SizedBox.expand(),
                      ),
                    );
                  },
                ),
              ),
              Container(
                width: 86,
                height: 86,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: const LinearGradient(
                    colors: [Color(0xFFFFD54F), Color(0xFFF57F17)],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  boxShadow: const [
                    BoxShadow(
                      color: Colors.black45,
                      blurRadius: 18,
                      offset: Offset(0, 8),
                    ),
                  ],
                ),
                child: Center(
                  child: Text(
                    round.phase == 'result'
                        ? (round.winningPot ?? '—')
                        : '${round.totalBetsCount}',
                    style: const TextStyle(
                      color: Colors.black,
                      fontSize: 26,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildPotGrid(GreedyRound round) {
    return GridView.count(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      crossAxisCount: 2,
      crossAxisSpacing: 12,
      mainAxisSpacing: 12,
      childAspectRatio: 1.28,
      children:
          _pots.map((pot) {
            final selected = _selectedPot == pot;
            final winning = round.phase == 'result' && round.winningPot == pot;
            final yourAmount = _localViewerPotTotals[pot] ?? 0;
            return GestureDetector(
              onTap:
                  round.phase == 'betting'
                      ? () {
                        Haptics.selection();
                        setState(() => _selectedPot = pot);
                      }
                      : null,
              child: _GreedyPotCard(
                pot: pot,
                selected: selected,
                winning: winning,
                totalAmount: _displayTotals[pot] ?? 0,
                yourAmount: yourAmount,
                multiplier: round.potMultipliers[pot] ?? 0,
                sectors: round.potSectors[pot] ?? 0,
              ),
            );
          }).toList(),
    );
  }

  void _showResultDialog(GreedyRound round) {
    final winningPot = round.winningPot;
    if (winningPot == null) return;
    final yourBet = _localViewerPotTotals[winningPot] ?? 0;
    final payout =
        round.viewerBets
            .where((bet) => bet.pot == winningPot)
            .fold<int>(0, (sum, bet) => sum + bet.payoutCoins);
    final displayPayout =
        payout > 0 ? payout : yourBet * (round.winningMultiplier ?? 0);

    showDialog<void>(
      context: context,
      barrierColor: Colors.black87,
      builder:
          (_) => _GreedyResultDialog(
            winningPot: winningPot,
            winningMultiplier: round.winningMultiplier ?? 0,
            yourBet: yourBet,
            payout: displayPayout,
            won: yourBet > 0,
          ),
    );
  }

}

class _GreedyHeader extends StatelessWidget {
  const _GreedyHeader({
    required this.phase,
    required this.countdownSeconds,
    required this.walletBalance,
    required this.strategy,
    required this.selectedPot,
    required this.selectedAmount,
    required this.lastWinningPot,
  });

  final String phase;
  final int countdownSeconds;
  final int walletBalance;
  final String strategy;
  final String? selectedPot;
  final int selectedAmount;
  final String? lastWinningPot;

  @override
  Widget build(BuildContext context) {
    final title = switch (phase) {
      'betting' => 'GREEDY',
      'locked' => 'LOCKED',
      'result' => 'RESULT',
      _ => 'GREEDY',
    };
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(28),
        gradient: const LinearGradient(
          colors: [Color(0xFF251438), Color(0xFF11111A), Color(0xFF0E1321)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(color: Colors.white.withValues(alpha: .12)),
        boxShadow: const [
          BoxShadow(
            color: Colors.black54,
            blurRadius: 28,
            offset: Offset(0, 16),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w900,
                        fontSize: 28,
                        letterSpacing: 1.3,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      _phaseCopy(phase),
                      style: const TextStyle(
                        color: Colors.white70,
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
              _HeaderTimePill(seconds: countdownSeconds),
            ],
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              _HeaderMiniPill(
                icon: Icons.tune_rounded,
                label: strategy.replaceAll('_', ' ').toUpperCase(),
              ),
              if (selectedPot != null)
                _HeaderMiniPill(
                  icon: Icons.place_rounded,
                  label: 'POT $selectedPot',
                  accent: _potColor(selectedPot!),
                ),
              _HeaderMiniPill(
                icon: Icons.diamond_rounded,
                label: _formatGreedyCoins(selectedAmount),
                accent: const Color(0xFFFFD54F),
              ),
              if (lastWinningPot != null)
                _HeaderMiniPill(
                  icon: Icons.workspace_premium_rounded,
                  label: 'LAST $lastWinningPot',
                  accent: _potColor(lastWinningPot!),
                ),
              _HeaderMiniPill(
                icon: Icons.account_balance_wallet_rounded,
                label: '${_formatGreedyCoins(walletBalance)} COINS',
                accent: const Color(0xFF66E0B7),
              ),
            ],
          ),
        ],
      ),
    );
  }

  String _phaseCopy(String value) => switch (value) {
    'betting' => 'Wheel open. Pick a zone and stack the pot.',
    'locked' => 'Bets locked. The wheel is about to resolve.',
    'result' => 'Result live. Payout window is open.',
    _ => 'Live room wheel game',
  };
}

class _HeaderTimePill extends StatelessWidget {
  const _HeaderTimePill({required this.seconds});

  final int seconds;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        gradient: const LinearGradient(
          colors: [Color(0xFFFFE082), Color(0xFFFFB300)],
        ),
        boxShadow: const [
          BoxShadow(
            color: Color(0x55FFB300),
            blurRadius: 18,
            offset: Offset(0, 8),
          ),
        ],
      ),
      child: Text(
        '${seconds.toString().padLeft(2, '0')}s',
        style: const TextStyle(
          color: Colors.black,
          fontWeight: FontWeight.w900,
          fontSize: 18,
        ),
      ),
    );
  }
}

class _HeaderMiniPill extends StatelessWidget {
  const _HeaderMiniPill({
    required this.icon,
    required this.label,
    this.accent,
  });

  final IconData icon;
  final String label;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final tone = accent ?? Colors.white70;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: Colors.black.withValues(alpha: .28),
        border: Border.all(color: tone.withValues(alpha: .22)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: tone),
          const SizedBox(width: 7),
          Text(
            label,
            style: TextStyle(
              color: Colors.white.withValues(alpha: .92),
              fontWeight: FontWeight.w800,
              fontSize: 12,
              letterSpacing: .3,
            ),
          ),
        ],
      ),
    );
  }
}

class _GreedyPotOverview extends StatelessWidget {
  const _GreedyPotOverview({
    required this.totalBetsCount,
    required this.participantCount,
    required this.totalPool,
  });

  final int totalBetsCount;
  final int participantCount;
  final int totalPool;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: _OverviewMetric(
            label: 'Pool',
            value: _formatGreedyCoins(totalPool),
            icon: Icons.casino_rounded,
            accent: const Color(0xFFFFC447),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _OverviewMetric(
            label: 'Bets',
            value: totalBetsCount.toString(),
            icon: Icons.stacked_line_chart_rounded,
            accent: const Color(0xFF6B9CFF),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _OverviewMetric(
            label: 'Players',
            value: participantCount.toString(),
            icon: Icons.groups_rounded,
            accent: const Color(0xFF64DAA6),
          ),
        ),
      ],
    );
  }
}

class _OverviewMetric extends StatelessWidget {
  const _OverviewMetric({
    required this.label,
    required this.value,
    required this.icon,
    required this.accent,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        color: Colors.black.withValues(alpha: .18),
        border: Border.all(color: Colors.white10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: accent),
          const SizedBox(height: 10),
          Text(
            value,
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
              fontSize: 18,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            label,
            style: const TextStyle(
              color: Colors.white60,
              fontSize: 12,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

class _GreedyBetConsole extends StatelessWidget {
  const _GreedyBetConsole({
    required this.selectedPot,
    required this.selectedAmount,
    required this.placing,
    required this.onPlaceBet,
  });

  final String? selectedPot;
  final int selectedAmount;
  final bool placing;
  final VoidCallback onPlaceBet;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(26),
        gradient: const LinearGradient(
          colors: [Color(0xFF151C2B), Color(0xFF1D1329), Color(0xFF101119)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(color: Colors.white10),
        boxShadow: const [
          BoxShadow(
            color: Colors.black45,
            blurRadius: 20,
            offset: Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Bet Console',
            style: TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
              fontSize: 20,
              letterSpacing: .6,
            ),
          ),
          const SizedBox(height: 4),
          const Text(
            'Lock a zone, set a chip, push the wheel.',
            style: TextStyle(
              color: Colors.white60,
              fontSize: 12,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    _ConsolePill(
                      label: selectedPot == null ? 'No Pot' : 'Pot $selectedPot',
                    ),
                    _ConsolePill(label: _formatGreedyCoins(selectedAmount)),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              ElevatedButton(
                onPressed: selectedPot == null || placing ? null : onPlaceBet,
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFFFFB300),
                  foregroundColor: Colors.black,
                  padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(16),
                  ),
                ),
                child:
                    placing
                        ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                        : const Text(
                          'Place Bet',
                          style: TextStyle(fontWeight: FontWeight.w900),
                        ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ConsolePill extends StatelessWidget {
  const _ConsolePill({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: Colors.white10,
      ),
      child: Text(
        label,
        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
      ),
    );
  }
}

class _ChipTray extends StatelessWidget {
  const _ChipTray({
    required this.values,
    required this.selectedAmount,
    required this.onSelect,
  });

  final List<int> values;
  final int selectedAmount;
  final ValueChanged<int> onSelect;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        color: Colors.black.withValues(alpha: .2),
        border: Border.all(color: Colors.white10),
        boxShadow: const [
          BoxShadow(
            color: Colors.black38,
            blurRadius: 20,
            offset: Offset(0, 8),
          ),
        ],
      ),
      child: Wrap(
        spacing: 10,
        runSpacing: 10,
        children:
            values.map((value) {
              final selected = value == selectedAmount;
              return GestureDetector(
                onTap: () => onSelect(value),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 180),
                  curve: Curves.easeOut,
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                  transform: Matrix4.identity()..translate(0.0, selected ? -2.0 : 0.0),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(999),
                    gradient:
                        selected
                            ? const LinearGradient(
                              colors: [Color(0xFFFFE082), Color(0xFFFFA000)],
                            )
                            : const LinearGradient(
                              colors: [Color(0xFF2A2237), Color(0xFF17121F)],
                            ),
                    border: Border.all(
                      color: selected ? const Color(0xFFFFF3C2) : Colors.white12,
                    ),
                    boxShadow:
                        selected
                            ? const [
                              BoxShadow(
                                color: Color(0x66FFB300),
                                blurRadius: 18,
                                offset: Offset(0, 8),
                              ),
                            ]
                            : const [
                              BoxShadow(
                                color: Colors.black26,
                                blurRadius: 10,
                                offset: Offset(0, 4),
                              ),
                            ],
                  ),
                  child: Text(
                    _formatGreedyCoins(value),
                    style: TextStyle(
                      color: selected ? Colors.black : Colors.white,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
              );
            }).toList(),
      ),
    );
  }
}

class _GreedyPotCard extends StatelessWidget {
  const _GreedyPotCard({
    required this.pot,
    required this.selected,
    required this.winning,
    required this.totalAmount,
    required this.yourAmount,
    required this.multiplier,
    required this.sectors,
  });

  final String pot;
  final bool selected;
  final bool winning;
  final int totalAmount;
  final int yourAmount;
  final int multiplier;
  final int sectors;

  Color get accent => _potColor(pot);

  @override
  Widget build(BuildContext context) {
    return AnimatedContainer(
      duration: const Duration(milliseconds: 220),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        gradient: LinearGradient(
          colors:
              winning
                  ? [accent.withValues(alpha: .45), const Color(0xFF151318)]
                  : [accent.withValues(alpha: .18), const Color(0xFF151318)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(
          color:
              selected || winning ? accent.withValues(alpha: .95) : Colors.white12,
          width: selected || winning ? 1.6 : 1,
        ),
        boxShadow:
            selected || winning
                ? [
                  BoxShadow(
                    color: accent.withValues(alpha: .28),
                    blurRadius: 24,
                    offset: const Offset(0, 10),
                  ),
                ]
                : null,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(999),
                  color: accent.withValues(alpha: .18),
                ),
                child: Text(
                  'Pot $pot',
                  style: TextStyle(
                    color: accent,
                    fontWeight: FontWeight.w900,
                    letterSpacing: .4,
                  ),
                ),
              ),
              const Spacer(),
              Text(
                '${multiplier}x',
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Container(
            height: 8,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(999),
              gradient: LinearGradient(
                colors: [
                  accent.withValues(alpha: .18),
                  accent.withValues(alpha: .72),
                  accent.withValues(alpha: .18),
                ],
              ),
            ),
          ),
          const Spacer(),
          Text(
            _formatGreedyCoins(totalAmount),
            style: const TextStyle(
              color: Colors.white,
              fontSize: 28,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            'YOU ${_formatGreedyCoins(yourAmount)}  •  $sectors SECTORS',
            style: const TextStyle(
              color: Colors.white70,
              fontWeight: FontWeight.w600,
              fontSize: 12,
              letterSpacing: .3,
            ),
          ),
        ],
      ),
    );
  }
}

class _GreedyHistoryStrip extends StatelessWidget {
  const _GreedyHistoryStrip({required this.history});

  final List<GreedyRound> history;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Last Winners',
          style: TextStyle(
            color: Colors.white,
            fontSize: 18,
            fontWeight: FontWeight.w900,
          ),
        ),
        const SizedBox(height: 10),
        SizedBox(
          height: 112,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            itemCount: history.length,
            separatorBuilder: (_, _) => const SizedBox(width: 12),
            itemBuilder: (context, index) {
              final round = history[index];
              final accent =
                  round.winningPot == null
                      ? Colors.white54
                      : _potColor(round.winningPot!);
              return Container(
                width: 132,
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(18),
                  gradient: LinearGradient(
                    colors: [
                      accent.withValues(alpha: .16),
                      const Color(0xFF171422),
                    ],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  border: Border.all(color: accent.withValues(alpha: .35)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(999),
                        color: accent.withValues(alpha: .18),
                      ),
                      child: Text(
                        round.winningPot == null ? 'PENDING' : 'POT ${round.winningPot}',
                        style: TextStyle(
                          color: accent,
                          fontWeight: FontWeight.w900,
                          fontSize: 11,
                          letterSpacing: .5,
                        ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      round.roundKey,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: Colors.white70, fontSize: 12),
                    ),
                    const Spacer(),
                    Text(
                      round.winningMultiplier == null
                          ? 'Awaiting result'
                          : '${round.winningMultiplier}x settled',
                      style: TextStyle(
                        color:
                            round.winningMultiplier == null
                                ? Colors.white60
                                : Colors.amber.shade200,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}

class _GreedyResultDialog extends StatefulWidget {
  const _GreedyResultDialog({
    required this.winningPot,
    required this.winningMultiplier,
    required this.yourBet,
    required this.payout,
    required this.won,
  });

  final String winningPot;
  final int winningMultiplier;
  final int yourBet;
  final int payout;
  final bool won;

  @override
  State<_GreedyResultDialog> createState() => _GreedyResultDialogState();
}

class _GreedyResultDialogState extends State<_GreedyResultDialog>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    )..forward();
    Haptics.medium();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return ScaleTransition(
      scale: CurvedAnimation(parent: _controller, curve: Curves.easeOutBack),
      child: AlertDialog(
        backgroundColor: const Color(0xFF120E17),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(28)),
        titlePadding: const EdgeInsets.fromLTRB(24, 22, 24, 0),
        contentPadding: const EdgeInsets.fromLTRB(24, 18, 24, 8),
        title: Text(
          widget.won ? 'Winning Spin' : 'Round Result',
          style: const TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.w900,
            fontSize: 24,
            letterSpacing: .4,
          ),
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 94,
              height: 94,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: LinearGradient(
                  colors:
                      widget.won
                          ? [const Color(0xFFFFD54F), const Color(0xFFF57F17)]
                          : [const Color(0xFF5AA7FF), const Color(0xFF1E88E5)],
                ),
              ),
              child: Center(
                child: Text(
                  widget.winningPot,
                  style: const TextStyle(
                    color: Colors.black,
                    fontWeight: FontWeight.w900,
                    fontSize: 30,
                  ),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Text(
              'Pot ${widget.winningPot} • ${widget.winningMultiplier}x',
              style: TextStyle(
                color: _potColor(widget.winningPot),
                fontWeight: FontWeight.w900,
                fontSize: 18,
              ),
            ),
            const SizedBox(height: 14),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(18),
                gradient: LinearGradient(
                  colors:
                      widget.won
                          ? [
                            const Color(0x33FFD54F),
                            const Color(0x2217A673),
                          ]
                          : [
                            const Color(0x225AA7FF),
                            const Color(0x221B2134),
                          ],
                ),
                border: Border.all(color: Colors.white10),
              ),
              child: Column(
                children: [
                  Text(
                    'YOUR BET  ${_formatGreedyCoins(widget.yourBet)}',
                    style: const TextStyle(
                      color: Colors.white70,
                      fontWeight: FontWeight.w800,
                      letterSpacing: .4,
                    ),
                  ),
                  const SizedBox(height: 10),
                  TweenAnimationBuilder<int>(
                    tween: IntTween(begin: 0, end: widget.won ? widget.payout : 0),
                    duration: const Duration(milliseconds: 900),
                    builder: (context, value, _) {
                      return Text(
                        widget.won
                            ? 'PAYOUT  ${_formatGreedyCoins(value)}'
                            : 'MISS',
                        style: TextStyle(
                          color: widget.won ? Colors.amber.shade200 : Colors.white70,
                          fontWeight: FontWeight.w900,
                          fontSize: 22,
                          letterSpacing: .6,
                        ),
                      );
                    },
                  ),
                ],
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }
}

class _GreedyWheelPainter extends CustomPainter {
  _GreedyWheelPainter({
    required this.multipliers,
    required this.sectors,
    required this.pulse,
    required this.winningPot,
  });

  final Map<String, int> multipliers;
  final Map<String, int> sectors;
  final double pulse;
  final String? winningPot;

  static const List<String> _pots = <String>['A', 'B', 'C', 'D'];

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final center = rect.center;
    final radius = min(size.width, size.height) / 2;
    final totalSectors =
        _pots.fold<int>(0, (sum, pot) => sum + (sectors[pot] ?? 0));
    var startAngle = -pi / 2;

    canvas.drawCircle(
      center,
      radius * .92,
      Paint()
        ..shader = RadialGradient(
          colors: [
            const Color(0xFFFFD54F).withValues(alpha: .16),
            const Color(0xFF5AA7FF).withValues(alpha: .08),
            Colors.transparent,
          ],
        ).createShader(Rect.fromCircle(center: center, radius: radius)),
    );

    for (final pot in _pots) {
      final sweep = ((sectors[pot] ?? 0) / max(1, totalSectors)) * 2 * pi;
      final color = _potColor(pot);
      final isWinner = winningPot == pot;
      final paint =
          Paint()
            ..style = PaintingStyle.fill
            ..shader = RadialGradient(
              colors: [
                color.withValues(alpha: isWinner ? .95 : (.65 + pulse * .18)),
                color.withValues(alpha: .28),
              ],
            ).createShader(rect);

      canvas.drawArc(
        Rect.fromCircle(center: center, radius: radius),
        startAngle,
        sweep,
        true,
        paint,
      );

      final border =
          Paint()
            ..style = PaintingStyle.stroke
            ..strokeWidth = isWinner ? 4 : 2
            ..color = isWinner ? Colors.white : Colors.white24;
      canvas.drawArc(
        Rect.fromCircle(center: center, radius: radius),
        startAngle,
        sweep,
        true,
        border,
      );

      final textAngle = startAngle + sweep / 2;
      final labelRadius = radius * .70;
      final labelOffset = Offset(
        center.dx + cos(textAngle) * labelRadius,
        center.dy + sin(textAngle) * labelRadius,
      );
      final textPainter = TextPainter(
        text: TextSpan(
          text: '$pot\n${multipliers[pot] ?? 0}x',
          style: const TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.w900,
            fontSize: 16,
          ),
        ),
        textAlign: TextAlign.center,
        textDirection: TextDirection.ltr,
      )..layout(maxWidth: 80);

      canvas.save();
      canvas.translate(labelOffset.dx, labelOffset.dy);
      canvas.rotate(textAngle + pi / 2);
      textPainter.paint(
        canvas,
        Offset(-textPainter.width / 2, -textPainter.height / 2),
      );
      canvas.restore();

      startAngle += sweep;
    }

    final tickPaint =
        Paint()
          ..color = Colors.white24
          ..strokeWidth = 2
          ..strokeCap = StrokeCap.round;
    for (var i = 0; i < max(1, totalSectors); i++) {
      final angle = (-pi / 2) + ((i / max(1, totalSectors)) * 2 * pi);
      final outer = Offset(
        center.dx + cos(angle) * radius,
        center.dy + sin(angle) * radius,
      );
      final inner = Offset(
        center.dx + cos(angle) * (radius - 10),
        center.dy + sin(angle) * (radius - 10),
      );
      canvas.drawLine(inner, outer, tickPaint);
    }

    canvas.drawCircle(
      center,
      radius,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 6
        ..color = Colors.white24,
    );
  }

  @override
  bool shouldRepaint(covariant _GreedyWheelPainter oldDelegate) {
    return oldDelegate.pulse != pulse ||
        oldDelegate.winningPot != winningPot ||
        oldDelegate.multipliers != multipliers ||
        oldDelegate.sectors != sectors;
  }
}

Color _potColor(String pot) => switch (pot) {
  'A' => const Color(0xFF5AA7FF),
  'B' => const Color(0xFFFF7A45),
  'C' => const Color(0xFF5ED68A),
  _ => const Color(0xFFE95BFF),
};

String _formatGreedyCoins(int value) {
  if (value >= 1000000 && value % 1000000 == 0) {
    return '${value ~/ 1000000}M';
  }
  if (value >= 1000 && value % 1000 == 0) {
    return '${value ~/ 1000}K';
  }
  return value.toString();
}
