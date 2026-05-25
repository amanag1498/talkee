import 'dart:async';
import 'dart:math';

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
  static const _chipOptions = <int>[50, 200, 500, 1000, 5000];
  static const _chipAssets = <int, String>{
    50: 'assets/games/teen_patti/gems_1.png',
    200: 'assets/games/teen_patti/gems_2.png',
    500: 'assets/games/teen_patti/gems_3.png',
    1000: 'assets/games/teen_patti/gems_4.png',
    5000: 'assets/games/teen_patti/gems_5.png',
  };

  final TeenPattiApi _api = Get.find<TeenPattiApi>();
  final TeenPattiSocketService _socket = Get.find<TeenPattiSocketService>();
  final GlobalKey _panelKey = GlobalKey();
  final GlobalKey _chipRowKey = GlobalKey();
  final List<GlobalKey> _potKeys = List.generate(3, (_) => GlobalKey());
  final Map<int, GlobalKey> _chipKeys = {
    50: GlobalKey(),
    200: GlobalKey(),
    500: GlobalKey(),
    1000: GlobalKey(),
    5000: GlobalKey(),
  };
  final Random _random = Random();

  TeenPattiSnapshot? _snapshot;
  StreamSubscription<Map<String, dynamic>>? _snapshotSub;
  StreamSubscription<Map<String, dynamic>>? _eventSub;
  Timer? _ticker;
  bool _loading = true;
  bool _placingBet = false;
  String? _error;
  int _selectedChip = 50;
  int _selectedPotIndex = -1;
  int? _shownResultRoundId;
  List<_FlyingGem> _flyingGems = const [];
  DateTime _now = DateTime.now();
  DateTime? _lastAutoRefreshAt;

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  @override
  void dispose() {
    _snapshotSub?.cancel();
    _eventSub?.cancel();
    _ticker?.cancel();
    unawaited(_socket.stop());
    super.dispose();
  }

  Future<void> _bootstrap() async {
    await _loadSnapshot();

    final token = Get.find<StorageService>().token;
    if (token == null || token.isEmpty) return;

    _snapshotSub?.cancel();
    _ticker ??= Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      setState(() => _now = DateTime.now());
      _maybeRefreshForPhaseBoundary();
    });

    _snapshotSub = _socket.snapshotEvents.listen((payload) {
      final data =
          payload['data'] is Map
              ? Map<String, dynamic>.from(payload['data'] as Map)
              : Map<String, dynamic>.from(payload);
      if (data.isEmpty || !mounted) return;
      _applySnapshot(TeenPattiSnapshot.fromJson(data));
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
      _applySnapshot(snapshot, loading: false);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString().replaceFirst('Exception: ', '');
        _loading = false;
      });
    }
  }

  Future<void> _placeBet(
    String pot, {
    required String imagePath,
    Offset? startGlobalPosition,
  }) async {
    final snapshot = _snapshot;
    if (_placingBet || snapshot == null) return;
    if (_selectedChip > snapshot.walletBalance) {
      _showRecharge();
      return;
    }
    final displayRound = _displayRound(snapshot.round);
    if (displayRound.phase != 'betting') {
      setState(() {
        _error = displayRound.phase == 'locked'
            ? 'Bets are locked. Wait for the next game.'
            : 'This round is not accepting bets right now.';
      });
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
      if (startGlobalPosition != null) {
        _launchFlyingGem(
          imagePath: imagePath,
          potIndex: ['A', 'B', 'C'].indexOf(pot),
          startGlobalPosition: startGlobalPosition,
        );
      }
      _applySnapshot(next);
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

  void _maybeRefreshForPhaseBoundary() {
    final snapshot = _snapshot;
    if (snapshot == null || _loading || _placingBet) return;
    final liveRound = _displayRound(snapshot.round);
    if (liveRound.roundChanged) {
      final last = _lastAutoRefreshAt;
      if (last != null &&
          DateTime.now().difference(last) < const Duration(seconds: 2)) {
        return;
      }
      _lastAutoRefreshAt = DateTime.now();
      unawaited(_loadSnapshot());
    }
  }

  void _applySnapshot(TeenPattiSnapshot next, {bool loading = false}) {
    final previous = _snapshot?.round;
    final allowedChips =
        _chipOptions
            .where(
              (chip) =>
                  chip >= next.settings.minBet && chip <= next.settings.maxBet,
            )
            .toList(growable: false);
    final nextSelectedChip =
        allowedChips.contains(_selectedChip)
            ? _selectedChip
            : (allowedChips.isNotEmpty ? allowedChips.first : next.settings.minBet);

    setState(() {
      _snapshot = next;
      _selectedChip = nextSelectedChip;
      _loading = loading;
      _error = null;
      if (_selectedPotIndex > 2) {
        _selectedPotIndex = -1;
      }
    });

    _animateRemoteBetDeltas(previous, next.round);
    _showResultDialogIfNeeded(next.round);
  }

  void _animateRemoteBetDeltas(TeenPattiRound? previous, TeenPattiRound next) {
    if (!mounted || previous == null) return;
    for (var index = 0; index < 3; index++) {
      final pot = ['A', 'B', 'C'][index];
      final oldTotal = previous.totals[pot] ?? 0;
      final newTotal = next.totals[pot] ?? 0;
      if (newTotal <= oldTotal) continue;
      final delta = newTotal - oldTotal;
      final imagePath = _assetForCoinAmount(delta);
      final panelBox = _panelKey.currentContext?.findRenderObject() as RenderBox?;
      if (panelBox == null) continue;
      final startGlobal = panelBox.localToGlobal(
        Offset(
          panelBox.size.width * (.18 + (.24 * index)),
          panelBox.size.height * .28,
        ),
      );
      _launchFlyingGem(
        imagePath: imagePath,
        potIndex: index,
        startGlobalPosition: startGlobal,
      );
    }
  }

  void _showResultDialogIfNeeded(TeenPattiRound round) {
    if (!mounted || round.phase != 'result' || round.id <= 0) return;
    if (_shownResultRoundId == round.id) return;
    _shownResultRoundId = round.id;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      showDialog<void>(
        context: context,
        barrierDismissible: true,
        builder: (context) => _TeenPattiResultDialog(round: round),
      );
    });
  }

  String _assetForCoinAmount(int amount) {
    if (amount >= 5000) return _chipAssets[5000]!;
    if (amount >= 1000) return _chipAssets[1000]!;
    if (amount >= 500) return _chipAssets[500]!;
    if (amount >= 200) return _chipAssets[200]!;
    return _chipAssets[50]!;
  }

  void _launchFlyingGem({
    required String imagePath,
    required int potIndex,
    required Offset startGlobalPosition,
  }) {
    if (potIndex < 0 || potIndex > 2) return;
    final panelBox = _panelKey.currentContext?.findRenderObject() as RenderBox?;
    final potBox =
        _potKeys[potIndex].currentContext?.findRenderObject() as RenderBox?;
    if (panelBox == null || potBox == null) return;

    final startLocal = panelBox.globalToLocal(startGlobalPosition);
    final potOrigin = panelBox.globalToLocal(potBox.localToGlobal(Offset.zero));
    final targetLeft = potOrigin.dx + 12 + _random.nextDouble() * 46;
    final targetTop = potOrigin.dy + 58 + _random.nextDouble() * 56;
    final id = DateTime.now().microsecondsSinceEpoch;

    setState(() {
      _flyingGems = [
        ..._flyingGems,
        _FlyingGem(
          id: id,
          imagePath: imagePath,
          left: startLocal.dx - 16,
          top: startLocal.dy - 16,
          targetLeft: startLocal.dx - 16,
          targetTop: startLocal.dy - 16,
        ),
      ];
    });

    Future<void>.delayed(const Duration(milliseconds: 16), () {
      if (!mounted) return;
      setState(() {
        _flyingGems =
            _flyingGems
                .map(
                  (gem) => gem.id == id
                      ? gem.copyWith(
                          targetLeft: targetLeft,
                          targetTop: targetTop,
                        )
                      : gem,
                )
                .toList();
      });
    });

    Future<void>.delayed(const Duration(milliseconds: 520), () {
      if (!mounted) return;
      setState(() {
        _flyingGems = _flyingGems.where((gem) => gem.id != id).toList();
      });
    });
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
    final round = _displayRound(snapshot.round);

    return Stack(
      key: _panelKey,
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
                Colors.black.withValues(alpha: .18),
                Colors.black.withValues(alpha: .44),
                Colors.black.withValues(alpha: .62),
              ],
            ),
          ),
        ),
        ListView(
          padding: const EdgeInsets.fromLTRB(12, 6, 12, 24),
          children: [
            _TeenPattiHeader(
              countdownSeconds: round.countdownSeconds,
              walletBalance: snapshot.walletBalance,
              gameStateLabel: _gameStateLabel(round),
            ),
            const SizedBox(height: 12),
            LayoutBuilder(
              builder: (context, constraints) {
                const gap = 8.0;
                final cardWidth = (constraints.maxWidth - (gap * 2)) / 3;
                final cardHeight = cardWidth * 1.9;
                return Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: List.generate(3, (index) {
                    final pot = ['A', 'B', 'C'][index];
                    return _BoardPotCard(
                      potKey: _potKeys[index],
                      pot: pot,
                      width: cardWidth,
                      height: cardHeight,
                      potCoins: round.totals[pot] ?? 0,
                      myCoins: _myPotCoins(round, pot),
                      cards: _cardsForPot(round, pot),
                      selected: _selectedPotIndex == index,
                      revealedWinner:
                          round.phase == 'result' && round.winningPot == pot,
                      bettingOpen: round.phase == 'betting',
                      onTap: () {
                        if (round.phase != 'betting') return;
                        setState(() {
                          _selectedPotIndex =
                              _selectedPotIndex == index ? -1 : index;
                        });
                      },
                    );
                  }),
                );
              },
            ),
            const SizedBox(height: 16),
            _RecentWinnersStrip(history: snapshot.history.take(5).toList()),
            const SizedBox(height: 18),
            Row(
              children: [
                const Expanded(
                  child: Text(
                    'Place Your Bet',
                    style: TextStyle(
                      fontSize: 24,
                      fontWeight: FontWeight.w800,
                      color: Colors.white,
                    ),
                  ),
                ),
                _CoinBalancePill(balance: snapshot.walletBalance),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              _selectedPotIndex == -1
                  ? 'Please select the pot to place bet'
                  : 'Selected pot ${['A', 'B', 'C'][_selectedPotIndex]}',
              style: TextStyle(
                color: Colors.white.withValues(alpha: .82),
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 10),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              key: _chipRowKey,
              children:
                  _chipOptions.map((chip) {
                    final enabled =
                        chip >= snapshot.settings.minBet &&
                        chip <= snapshot.settings.maxBet &&
                        round.phase == 'betting';
                    return _BetGemButton(
                      chipKey: _chipKeys[chip],
                      value: chip,
                      imagePath: _chipAssets[chip]!,
                      active: chip == _selectedChip,
                      enabled: enabled && !_placingBet,
                      onTapDown: (details) {
                        if (!enabled) return;
                        setState(() => _selectedChip = chip);
                        if (_selectedPotIndex != -1) {
                          _placeBet(
                            ['A', 'B', 'C'][_selectedPotIndex],
                            imagePath: _chipAssets[chip]!,
                            startGlobalPosition: details.globalPosition,
                          );
                        }
                      },
                    );
                  }).toList(),
            ),
            const SizedBox(height: 16),
            _PhaseBanner(
              phase: round.phase,
              winningPot: round.winningPot,
              error: _error,
            ),
            const SizedBox(height: 8),
          ],
        ),
        ..._flyingGems.map(
          (gem) => AnimatedPositioned(
            key: ValueKey('gem-${gem.id}'),
            duration: const Duration(milliseconds: 460),
            curve: Curves.easeOutCubic,
            left: gem.targetLeft,
            top: gem.targetTop,
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: 1, end: .68),
              duration: const Duration(milliseconds: 460),
              builder: (context, scale, child) {
                return Transform.scale(scale: scale, child: child);
              },
              child: IgnorePointer(
                child: Image.asset(
                  gem.imagePath,
                  width: 34,
                  height: 34,
                  fit: BoxFit.contain,
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }

  List<String> _cardsForPot(_LiveRoundView round, String pot) {
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

  int _myPotCoins(_LiveRoundView round, String pot) {
    return round.viewerBets
        .where((bet) => bet.pot == pot)
        .fold<int>(0, (sum, bet) => sum + bet.amount);
  }

  String _gameStateLabel(_LiveRoundView round) {
    return switch (round.phase) {
      'betting' => 'Game in Progress',
      'locked' => 'Bet Pots are Locked...',
      'settling' => 'Calculating Winner...',
      'result' => round.winningPot == null
          ? 'Winner Declared'
          : 'Winner: Pot ${round.winningPot}',
      'restarting' => 'Next Game Restart...',
      'cancelled' => 'Round Cancelled',
      _ => 'Waiting...',
    };
  }

  _LiveRoundView _displayRound(TeenPattiRound round) {
    final now = _now;
    final displayUntil = round.displayUntil;
    final locksAt = round.locksAt;
    final endsAt = round.endsAt;

    if (displayUntil != null && now.isAfter(displayUntil)) {
      return _LiveRoundView(
        source: round,
        phase: 'restarting',
        countdownSeconds: 0,
        roundChanged: true,
      );
    }

    if (round.status == 'settled' || round.status == 'cancelled') {
      final remaining =
          displayUntil == null ? 0 : displayUntil.difference(now).inSeconds;
      return _LiveRoundView(
        source: round,
        phase: round.status == 'cancelled' ? 'cancelled' : 'result',
        countdownSeconds: max(0, remaining),
        roundChanged: remaining <= 0,
      );
    }

    if (endsAt != null && !now.isBefore(endsAt)) {
      final remaining =
          displayUntil == null ? 0 : displayUntil.difference(now).inSeconds;
      return _LiveRoundView(
        source: round,
        phase: 'settling',
        countdownSeconds: max(0, remaining),
        roundChanged: false,
      );
    }

    if (locksAt != null && !now.isBefore(locksAt)) {
      final remaining =
          endsAt == null ? 0 : endsAt.difference(now).inSeconds;
      return _LiveRoundView(
        source: round,
        phase: 'locked',
        countdownSeconds: max(0, remaining),
        roundChanged: false,
      );
    }

    final remaining =
        locksAt == null ? round.countdownSeconds : locksAt.difference(now).inSeconds;
    return _LiveRoundView(
      source: round,
      phase: 'betting',
      countdownSeconds: max(0, remaining),
      roundChanged: false,
    );
  }
}

class _TeenPattiHeader extends StatelessWidget {
  const _TeenPattiHeader({
    required this.countdownSeconds,
    required this.walletBalance,
    required this.gameStateLabel,
  });

  final int countdownSeconds;
  final int walletBalance;
  final String gameStateLabel;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 136,
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Icon(Icons.help_outline, color: Colors.white, size: 22),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 8),
                decoration: BoxDecoration(
                  color: Colors.redAccent.withValues(alpha: .86),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(
                  '00:${countdownSeconds.toString().padLeft(2, '0')}s',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              _CoinBalancePill(balance: walletBalance, compact: true),
            ],
          ),
          Expanded(
            child: Center(
              child: Image.asset(
                'assets/games/teen_patti/logo_teenpatti.png',
                width: MediaQuery.of(context).size.width * .58,
                fit: BoxFit.contain,
              ),
            ),
          ),
          Text(
            gameStateLabel,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 18,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

class _CoinBalancePill extends StatelessWidget {
  const _CoinBalancePill({required this.balance, this.compact = false});

  final int balance;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 10 : 12,
        vertical: compact ? 8 : 10,
      ),
      decoration: BoxDecoration(
        color: Colors.black12,
        borderRadius: BorderRadius.circular(12),
        boxShadow: const [
          BoxShadow(
            color: Colors.black26,
            blurRadius: 4,
            offset: Offset(0, 2),
          ),
        ],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: compact ? 22 : 28,
            height: compact ? 22 : 28,
            decoration: const BoxDecoration(
              shape: BoxShape.circle,
              gradient: LinearGradient(
                colors: [Color(0xFFFFE082), Color(0xFFFFB300)],
              ),
            ),
            child: const Icon(
              Icons.monetization_on,
              color: Colors.white,
              size: 16,
            ),
          ),
          const SizedBox(width: 6),
          Text(
            '$balance',
            style: TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w800,
              fontSize: compact ? 13 : 14,
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

class _RecentWinnersStrip extends StatelessWidget {
  const _RecentWinnersStrip({required this.history});

  final List<TeenPattiRound> history;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: BoxDecoration(
        color: Colors.deepPurple.shade800.withValues(alpha: .8),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.symmetric(horizontal: 12),
            child: Text(
              'Last 5 Winners',
              style: TextStyle(
                color: Colors.white,
                fontSize: 14,
                fontWeight: FontWeight.bold,
              ),
            ),
          ),
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            child: Row(
              children: history.isEmpty
                  ? const [
                      _WinnerBadge(label: 'No results yet', winnerPot: null),
                    ]
                  : history
                      .map(
                        (round) => _WinnerBadge(
                          label: round.roundKey.split('_').last.substring(0, 4),
                          winnerPot: round.winningPot,
                        ),
                      )
                      .toList(),
            ),
          ),
        ],
      ),
    );
  }
}

class _WinnerBadge extends StatelessWidget {
  const _WinnerBadge({required this.label, required this.winnerPot});

  final String label;
  final String? winnerPot;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(right: 10),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.black.withValues(alpha: .28),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.white12),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label,
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w800,
              fontSize: 12,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            winnerPot == null ? '—' : 'Pot $winnerPot',
            style: const TextStyle(
              color: Color(0xFFFFD54F),
              fontWeight: FontWeight.w700,
              fontSize: 11,
            ),
          ),
        ],
      ),
    );
  }
}

class _BoardPotCard extends StatelessWidget {
  const _BoardPotCard({
    required this.potKey,
    required this.pot,
    required this.width,
    required this.height,
    required this.potCoins,
    required this.myCoins,
    required this.selected,
    required this.revealedWinner,
    required this.bettingOpen,
    required this.cards,
    this.onTap,
  });

  final GlobalKey potKey;
  final String pot;
  final double width;
  final double height;
  final int potCoins;
  final int myCoins;
  final bool selected;
  final bool revealedWinner;
  final bool bettingOpen;
  final List<String> cards;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: width,
        height: height,
        padding: EdgeInsets.all(width * .05),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: .1),
          borderRadius: BorderRadius.circular(width * .10),
          boxShadow: [
            BoxShadow(
              color: Colors.black26,
              blurRadius: width * .03,
              offset: Offset(0, height * .01),
            ),
          ],
          border:
              selected
                  ? Border.all(color: Colors.greenAccent, width: width * .03)
                  : null,
        ),
        child: Column(
          children: [
            SizedBox(
              key: potKey,
              height: height * .34,
              child: Stack(
                clipBehavior: Clip.none,
                children: [
                  Positioned(
                    left: 0,
                    child: _CardImage(path: cards[0], width: width * .46, opacity: .7),
                  ),
                  Positioned(
                    left: width * .12,
                    child: _CardImage(path: cards[1], width: width * .46, opacity: .85),
                  ),
                  Positioned(
                    left: width * .24,
                    child: _CardImage(path: cards[2], width: width * .46),
                  ),
                ],
              ),
            ),
            SizedBox(height: height * .025),
            Text(
              'Pot: $potCoins',
              style: TextStyle(
                fontSize: width * .10,
                fontWeight: FontWeight.bold,
                color: Colors.white,
              ),
            ),
            Text(
              'Me: $myCoins',
              style: TextStyle(
                fontSize: width * .082,
                color: Colors.white70,
              ),
            ),
            SizedBox(height: height * .02),
            Text(
              revealedWinner
                  ? 'Winner'
                  : (bettingOpen ? 'Tap pot $pot' : 'Waiting'),
              style: TextStyle(
                color: revealedWinner ? Colors.greenAccent : Colors.white70,
                fontWeight: FontWeight.w700,
                fontSize: width * .074,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CardImage extends StatelessWidget {
  const _CardImage({
    required this.path,
    required this.width,
    this.opacity = 1,
  });

  final String path;
  final double width;
  final double opacity;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: opacity,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: Image.asset(
          path,
          width: width,
          height: width * 1.34,
          fit: BoxFit.contain,
        ),
      ),
    );
  }
}

class _BetGemButton extends StatelessWidget {
  const _BetGemButton({
    required this.chipKey,
    required this.value,
    required this.imagePath,
    required this.active,
    required this.enabled,
    required this.onTapDown,
  });

  final GlobalKey? chipKey;
  final int value;
  final String imagePath;
  final bool active;
  final bool enabled;
  final ValueChanged<TapDownDetails> onTapDown;

  @override
  Widget build(BuildContext context) {
    final shortLabel = switch (value) {
      1000 => '1K',
      5000 => '5K',
      _ => '$value',
    };
    return Opacity(
      opacity: enabled ? 1 : .42,
      child: GestureDetector(
        onTapDown: enabled ? onTapDown : null,
        child: AnimatedContainer(
          key: chipKey,
          duration: const Duration(milliseconds: 180),
          padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border:
                active
                    ? Border.all(color: Colors.greenAccent, width: 2)
                    : null,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              _RotatingGem(imagePath: imagePath),
              const SizedBox(height: 4),
              Text(
                shortLabel,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 12,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _RotatingGem extends StatefulWidget {
  const _RotatingGem({required this.imagePath});

  final String imagePath;

  @override
  State<_RotatingGem> createState() => _RotatingGemState();
}

class _RotatingGemState extends State<_RotatingGem>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 3),
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, child) {
        return Transform.rotate(
          angle: _controller.value * 2 * pi,
          child: child,
        );
      },
      child: Stack(
        alignment: Alignment.center,
        children: [
          Container(
            width: 50,
            height: 50,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: const RadialGradient(
                colors: [Color(0x66FFFFFF), Color(0x33FFD54F)],
                center: Alignment.center,
                radius: .82,
              ),
              boxShadow: const [
                BoxShadow(
                  color: Color(0x66FFD54F),
                  blurRadius: 12,
                  spreadRadius: 3,
                ),
              ],
            ),
          ),
          Image.asset(
            widget.imagePath,
            width: 40,
            height: 40,
            fit: BoxFit.contain,
          ),
        ],
      ),
    );
  }
}

class _TeenPattiResultDialog extends StatelessWidget {
  const _TeenPattiResultDialog({required this.round});

  final TeenPattiRound round;

  @override
  Widget build(BuildContext context) {
    final winningPot = round.winningPot ?? '—';
    final winningCards = [
      if (round.winningHand.isNotEmpty)
        round.winningHand[0]
      else
        'assets/games/teen_patti/card_back_1.jpeg',
      if (round.winningHand.length > 1)
        round.winningHand[1]
      else
        'assets/games/teen_patti/card_back_1.jpeg',
      if (round.winningHand.length > 2)
        round.winningHand[2]
      else
        'assets/games/teen_patti/card_back_1.jpeg',
    ];
    final totalBetOnWinningPot = round.viewerBets
        .where((bet) => bet.pot == winningPot)
        .fold<int>(0, (sum, bet) => sum + bet.amount);
    final winningAmount = round.viewerBets
        .where((bet) => bet.payoutCoins > 0)
        .fold<int>(0, (sum, bet) => sum + bet.payoutCoins);

    return Dialog(
      backgroundColor: Colors.transparent,
      child: Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          color: Colors.black.withValues(alpha: .72),
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: const Color(0xFFE1B12C), width: 1.5),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              decoration: BoxDecoration(
                color: Colors.black.withValues(alpha: .48),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: const Color(0xFFFFC107)),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(Icons.stars_rounded, color: Color(0xFFFFC107)),
                  const SizedBox(width: 10),
                  Text(
                    'Winning Pot $winningPot',
                    style: const TextStyle(
                      color: Color(0xFFFFC107),
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            SizedBox(
              height: 110,
              child: Stack(
                alignment: Alignment.center,
                children: [
                  Positioned(
                    left: 44,
                    child: _CardImage(
                      path: winningCards[0].contains('/')
                          ? winningCards[0]
                          : 'assets/games/teen_patti/cards/${winningCards[0]}',
                      width: 72,
                      opacity: .78,
                    ),
                  ),
                  Positioned(
                    left: 72,
                    child: _CardImage(
                      path: winningCards[1].contains('/')
                          ? winningCards[1]
                          : 'assets/games/teen_patti/cards/${winningCards[1]}',
                      width: 72,
                      opacity: .88,
                    ),
                  ),
                  Positioned(
                    left: 100,
                    child: _CardImage(
                      path: winningCards[2].contains('/')
                          ? winningCards[2]
                          : 'assets/games/teen_patti/cards/${winningCards[2]}',
                      width: 72,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            _ResultInfoTile(
              title: 'Your Bet on Winning Pot',
              value: '$totalBetOnWinningPot',
              color: const Color(0xFFFFA726),
            ),
            const SizedBox(height: 10),
            _ResultInfoTile(
              title: 'Your Winning Amount',
              value: '$winningAmount',
              color: const Color(0xFF66BB6A),
            ),
            const SizedBox(height: 14),
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text(
                'Close',
                style: TextStyle(
                  color: Colors.white,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ResultInfoTile extends StatelessWidget {
  const _ResultInfoTile({
    required this.title,
    required this.value,
    required this.color,
  });

  final String title;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .18),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          Expanded(
            child: Text(
              title,
              style: const TextStyle(color: Colors.white70, fontSize: 13),
            ),
          ),
          Text(
            value,
            style: TextStyle(
              color: color,
              fontWeight: FontWeight.w800,
              fontSize: 16,
            ),
          ),
        ],
      ),
    );
  }
}

class _LiveRoundView {
  const _LiveRoundView({
    required this.source,
    required this.phase,
    required this.countdownSeconds,
    required this.roundChanged,
  });

  final TeenPattiRound source;
  final String phase;
  final int countdownSeconds;
  final bool roundChanged;

  int get id => source.id;
  String get roundKey => source.roundKey;
  String get status => source.status;
  DateTime? get startsAt => source.startsAt;
  DateTime? get locksAt => source.locksAt;
  DateTime? get endsAt => source.endsAt;
  DateTime? get settledAt => source.settledAt;
  DateTime? get displayUntil => source.displayUntil;
  String? get winningPot => source.winningPot;
  List<String> get winningHand => source.winningHand;
  List<String> get losingHandOne => source.losingHandOne;
  List<String> get losingHandTwo => source.losingHandTwo;
  Map<String, int> get totals => source.totals;
  int get totalBetsCount => source.totalBetsCount;
  int get participantCount => source.participantCount;
  int get payoutMultiplier => source.payoutMultiplier;
  List<TeenPattiBet> get viewerBets => source.viewerBets;
}

class _FlyingGem {
  const _FlyingGem({
    required this.id,
    required this.imagePath,
    required this.left,
    required this.top,
    required this.targetLeft,
    required this.targetTop,
  });

  final int id;
  final String imagePath;
  final double left;
  final double top;
  final double targetLeft;
  final double targetTop;

  _FlyingGem copyWith({
    double? left,
    double? top,
    double? targetLeft,
    double? targetTop,
  }) {
    return _FlyingGem(
      id: id,
      imagePath: imagePath,
      left: left ?? this.left,
      top: top ?? this.top,
      targetLeft: targetLeft ?? this.targetLeft,
      targetTop: targetTop ?? this.targetTop,
    );
  }
}
