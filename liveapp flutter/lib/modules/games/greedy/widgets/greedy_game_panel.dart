import 'dart:async';
import 'dart:math';
import 'dart:ui' show lerpDouble;

import 'package:audioplayers/audioplayers.dart';
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
  static const List<int> _chipValues = <int>[100, 200, 500, 1000, 5000];
  static const List<String> _pots = <String>['A', 'B', 'C', 'D'];
  static const Map<int, String> _gemAssets = <int, String>{
    100: 'assets/games/teen_patti/gems_1.png',
    200: 'assets/games/teen_patti/gems_2.png',
    500: 'assets/games/teen_patti/gems_3.png',
    1000: 'assets/games/teen_patti/gems_4.png',
    5000: 'assets/games/teen_patti/gems_5.png',
  };

  final GreedyApi _api = Get.find<GreedyApi>();
  final GreedySocketService _socket = Get.find<GreedySocketService>();
  final GlobalKey _panelKey = GlobalKey();
  final List<GlobalKey> _potKeys = List<GlobalKey>.generate(4, (_) => GlobalKey());
  final Map<int, GlobalKey> _chipKeys = <int, GlobalKey>{
    for (final value in _chipValues) value: GlobalKey(),
  };
  final Random _random = Random();

  late final AnimationController _wheelController;
  late final AnimationController _pulseController;
  late final AnimationController _pointerController;
  late final AnimationController _flashController;
  late final AnimationController _idleController;
  late final AudioPlayer _effectPlayer;

  StreamSubscription<Map<String, dynamic>>? _snapshotSub;
  StreamSubscription<Map<String, dynamic>>? _eventSub;
  Timer? _timer;
  Timer? _wheelTickTimer;

  GreedySnapshot? _snapshot;
  bool _loading = true;
  bool _placing = false;
  String? _error;
  String? _selectedPot;
  int _selectedAmount = 100;
  double _wheelStartTurns = 0;
  double _wheelEndTurns = 0;
  String? _lastSettledRoundKey;
  Map<String, int> _displayTotals = const <String, int>{};
  Map<String, int> _animatedTotals = const <String, int>{};
  Map<String, int> _localViewerPotTotals = <String, int>{};
  String? _localViewerRoundKey;
  String? _lastWinningPot;
  Map<String, List<_GreedyGemStackItem>> _landedGems = <String, List<_GreedyGemStackItem>>{
    for (final pot in _pots) pot: <_GreedyGemStackItem>[],
  };
  List<_GreedyFlyingGem> _flyingGems = const <_GreedyFlyingGem>[];
  DateTime _now = DateTime.now();
  _GreedyRevealStage _revealStage = _GreedyRevealStage.betting;
  bool _boundaryRefreshInFlight = false;
  DateTime? _lastAutoRefreshAt;

  @override
  void initState() {
    super.initState();
    _effectPlayer = AudioPlayer()..setReleaseMode(ReleaseMode.stop);
    _wheelController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2800),
    );
    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1400),
    )..repeat(reverse: true);
    _pointerController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 180),
    );
    _flashController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 560),
    );
    _idleController = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 10),
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
    _pointerController.dispose();
    _flashController.dispose();
    _idleController.dispose();
    _effectPlayer.dispose();
    _socket.stop();
    _wheelTickTimer?.cancel();
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
    final previousPhase = previous?.round.phase;
    if (_localViewerRoundKey != next.round.roundKey) {
      _localViewerRoundKey = next.round.roundKey;
      _localViewerPotTotals = <String, int>{};
      _animatedTotals = const <String, int>{};
      _lastSettledRoundKey = next.round.phase == 'result' ? next.round.roundKey : null;
      _lastWinningPot = next.round.phase == 'result' ? next.round.winningPot : null;
      _landedGems = <String, List<_GreedyGemStackItem>>{
        for (final pot in _pots) pot: <_GreedyGemStackItem>[],
      };
      if (next.round.phase != 'result') {
        _animateWheelReset();
      }
    }
    if (syncViewerBets) {
      _syncLocalViewerBets(next.round);
    }
    _syncDisplayTotals(next, previousRound: previous?.round);
    final displayRound = _displayRound(next.round);
    _updateRevealStage(
      next.round,
      displayPhase: displayRound.phase,
      previousPhase: previousPhase,
    );
    _maybeSpinForResult(
      next.round,
      previous?.round,
      displayPhase: displayRound.phase,
    );

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
    _timer ??= Timer.periodic(const Duration(milliseconds: 70), (_) {
      if (!mounted || _snapshot == null) return;
      _now = DateTime.now();
      _pruneFinishedGems();
      _syncDisplayTotals(_snapshot!, previousRound: _snapshot!.round);
      _maybeRefreshForPhaseBoundary();
      setState(() {});
    });
  }

  void _maybeRefreshForPhaseBoundary() {
    final snapshot = _snapshot;
    if (snapshot == null || _loading || _placing || _boundaryRefreshInFlight) {
      return;
    }

    final displayRound = _displayRound(snapshot.round);
    final shouldRefresh =
        displayRound.roundChanged ||
        displayRound.phase == 'settling' ||
        (displayRound.phase == 'locked' && snapshot.round.phase == 'betting');
    if (!shouldRefresh) {
      return;
    }

    final now = DateTime.now();
    final minGap =
        displayRound.phase == 'settling'
            ? const Duration(milliseconds: 450)
            : const Duration(milliseconds: 900);
    final last = _lastAutoRefreshAt;
    if (last != null && now.difference(last) < minGap) {
      return;
    }

    _lastAutoRefreshAt = now;
    _boundaryRefreshInFlight = true;
    unawaited(_refreshBoundarySnapshot());
  }

  Future<void> _refreshBoundarySnapshot() async {
    try {
      final next = await _api.fetchSnapshot();
      if (!mounted) return;
      _applySnapshot(next, syncViewerBets: true);
    } catch (_) {
    } finally {
      _boundaryRefreshInFlight = false;
    }
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

  void _syncDisplayTotals(GreedySnapshot snapshot, {GreedyRound? previousRound}) {
    final round = snapshot.round;
    final ratio = _roundProgressRatio(round);
    final nextTotals = <String, int>{
      for (final pot in _pots)
        pot:
            (round.realTotals[pot] ?? 0) +
            ((round.fakeTotals[pot] ?? 0) * ratio).round(),
    };
    final nextAnimatedTotals = <String, int>{
      for (final pot in _pots)
        pot:
            (round.realTotals[pot] ?? 0) +
            _quantizedFakeTotal(
              totalFake: round.fakeTotals[pot] ?? 0,
              ratio: ratio,
            ),
    };
    _spawnDiffGems(previous: _animatedTotals, next: nextAnimatedTotals, round: round);
    _animatedTotals = nextAnimatedTotals;
    _displayTotals = nextTotals;
  }

  int _quantizedFakeTotal({
    required int totalFake,
    required double ratio,
  }) {
    if (totalFake <= 0) return 0;
    if (ratio >= 1) return totalFake;
    final raw = (totalFake * ratio).round();
    if (raw <= 0) return 0;
    final step = _fakeAnimationStep(totalFake);
    return min(totalFake, (raw ~/ step) * step);
  }

  int _fakeAnimationStep(int totalFake) {
    if (totalFake <= 500) return 100;
    if (totalFake <= 2000) return 200;
    if (totalFake <= 5000) return 500;
    return 1000;
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

  void _maybeSpinForResult(
    GreedyRound round,
    GreedyRound? previous, {
    required String displayPhase,
  }) {
    if (displayPhase != 'result' || round.winningPot == null) {
      return;
    }
    if (_lastSettledRoundKey == round.roundKey) {
      return;
    }
    _lastSettledRoundKey = round.roundKey;
    _lastWinningPot = round.winningPot;

    final targetPot = round.winningPot!;
    final baseTurn = 4.5;
    final targetOffset = _targetWheelOffset(round, targetPot);
    final currentNormalized = _normalizeTurn(_wheelEndTurns);
    var delta = targetOffset - currentNormalized;
    if (delta < 0) {
      delta += 1;
    }
    _wheelStartTurns = _wheelEndTurns;
    _wheelEndTurns = _wheelEndTurns + baseTurn + delta;
    _playWheelSpinTicks();
    unawaited(_playEffect('assets/games/teen_patti/coin_dropped.mp3'));
    _pointerNudge();
    _wheelController
      ..reset()
      ..forward();

    if (previous?.roundKey != round.roundKey) {
      Future<void>.delayed(const Duration(milliseconds: 2150), () async {
        if (!mounted || _snapshot?.round.roundKey != round.roundKey) return;
        _revealStage = _GreedyRevealStage.flash;
        _lastWinningPot = round.winningPot;
        await _flashController.forward(from: 0);
        _pointerNudge(strong: true);
        await _playEffect('assets/games/teen_patti/mystery-sound.mp3');
        Haptics.medium();
        if (!mounted || _snapshot?.round.roundKey != round.roundKey) return;
        _revealStage = _GreedyRevealStage.payout;
        _showResultDialog(round);
        setState(() {});
      });
    }
  }

  void _animateWheelReset() {
    final current = _normalizeTurn(_wheelEndTurns);
    _wheelStartTurns = _wheelEndTurns;
    _wheelEndTurns = _wheelEndTurns - current + 0.02;
    _wheelController.duration = const Duration(milliseconds: 700);
    _wheelController
      ..reset()
      ..forward().whenCompleteOrCancel(() {
        if (!mounted) return;
        _wheelStartTurns = 0.02;
        _wheelEndTurns = 0.02;
        _wheelController.reset();
        setState(() {});
      });
  }

  double _normalizeTurn(double turns) {
    final normalized = turns % 1;
    return normalized < 0 ? normalized + 1 : normalized;
  }

  double _targetWheelOffset(GreedyRound round, String pot) {
    final totalSectors = round.potSectors.values.fold<int>(0, (sum, value) => sum + value);
    if (totalSectors <= 0) {
      return 0;
    }

    var traversed = 0;
    for (final currentPot in _pots) {
      final sectorCount = round.potSectors[currentPot] ?? 0;
      if (currentPot == pot) {
        final midpoint = traversed + (sectorCount / 2);
        final normalizedMidpoint = midpoint / totalSectors;
        return _normalizeTurn(1 - normalizedMidpoint);
      }
      traversed += sectorCount;
    }

    return 0;
  }

  void _updateRevealStage(
    GreedyRound round, {
    required String displayPhase,
    String? previousPhase,
  }) {
    switch (displayPhase) {
      case 'betting':
        if (_revealStage != _GreedyRevealStage.betting) {
          _revealStage = _GreedyRevealStage.betting;
        }
        break;
      case 'locked':
        if (previousPhase != 'locked') {
          _revealStage = _GreedyRevealStage.locked;
          _pointerNudge();
          Haptics.light();
        }
        break;
      case 'settling':
        if (_revealStage.index < _GreedyRevealStage.locked.index) {
          _revealStage = _GreedyRevealStage.locked;
        }
        break;
      case 'result':
        if (_revealStage.index < _GreedyRevealStage.spin.index) {
          _revealStage = _GreedyRevealStage.spin;
        }
        break;
      default:
        _revealStage = _GreedyRevealStage.betting;
    }
  }

  void _spawnDiffGems({
    required Map<String, int> previous,
    required Map<String, int> next,
    required GreedyRound round,
  }) {
    for (final pot in _pots) {
      final before = previous[pot] ?? 0;
      final after = next[pot] ?? 0;
      final delta = after - before;
      if (delta <= 0) continue;
      final burstAmounts = _splitFakeGemBurst(delta);
      for (var i = 0; i < burstAmounts.length; i++) {
        _launchGemToPot(
          pot: pot,
          amount: burstAmounts[i],
          fromUserAction: false,
          staggerMs: i * 80,
        );
      }
    }
  }

  List<int> _splitFakeGemBurst(int delta) {
    final remaining = delta;
    if (remaining <= 100) {
      return const <int>[100];
    }

    final chips = <int>[];
    var left = remaining;
    final maxTokens = left >= 2000 ? 3 : left >= 500 ? 2 : 1;
    final options = _chipValues.reversed.toList(growable: false);

    while (left > 0 && chips.length < maxTokens) {
      final tokensLeft = maxTokens - chips.length;
      final reserve = tokensLeft > 1 ? 100 * (tokensLeft - 1) : 0;
      final candidate = options.firstWhere(
        (chip) => chip <= max(100, left - reserve),
        orElse: () => 100,
      );
      chips.add(candidate);
      left -= candidate;
      if (left < 100) {
        break;
      }
    }

    if (left > 0) {
      final nextValue = (chips.isEmpty ? 0 : chips.removeLast()) + left;
      chips.add(nextValue);
    }

    return chips.where((value) => value > 0).toList(growable: false);
  }

  void _pruneFinishedGems() {
    final hadGems = _flyingGems.isNotEmpty;
    if (!hadGems) return;
    final remaining = <_GreedyFlyingGem>[];
    for (final gem in _flyingGems) {
      final elapsed = _now.difference(gem.startedAt).inMilliseconds;
      if (elapsed < gem.durationMs + 220) {
        remaining.add(gem);
        continue;
      }
      final pile = List<_GreedyGemStackItem>.from(_landedGems[gem.pot] ?? const <_GreedyGemStackItem>[]);
      pile.add(
        _GreedyGemStackItem(
          amount: gem.amount,
          accent: _potColor(gem.pot),
          offsetSeed: gem.seed,
        ),
      );
      if (pile.length > 18) {
        pile.removeRange(0, pile.length - 18);
      }
      _landedGems[gem.pot] = pile;
    }
    if (remaining.length != _flyingGems.length) {
      _flyingGems = remaining;
    }
  }

  void _launchGemToPot({
    required String pot,
    required int amount,
    required bool fromUserAction,
    int staggerMs = 0,
  }) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final panelBox = _panelKey.currentContext?.findRenderObject() as RenderBox?;
      final potIndex = _pots.indexOf(pot);
      final potBox = potIndex >= 0
          ? _potKeys[potIndex].currentContext?.findRenderObject() as RenderBox?
          : null;
      if (panelBox == null || potBox == null) return;
      final chipBox = _chipKeys[_selectedAmount]?.currentContext?.findRenderObject() as RenderBox?;
      final startGlobal = fromUserAction && chipBox != null
          ? chipBox.localToGlobal(chipBox.size.center(Offset.zero))
          : panelBox.localToGlobal(
              Offset(
                panelBox.size.width * (.2 + _random.nextDouble() * .6),
                panelBox.size.height * .78,
              ),
            );
      final targetGlobal = potBox.localToGlobal(
        Offset(
          potBox.size.width * (.22 + _random.nextDouble() * .56),
          potBox.size.height * (.48 + _random.nextDouble() * .22),
        ),
      );
      final entry = _GreedyFlyingGem(
        id: '${DateTime.now().microsecondsSinceEpoch}_${pot}_${amount}_${_random.nextInt(9999)}',
        pot: pot,
        amount: amount,
        start: panelBox.globalToLocal(startGlobal),
        end: panelBox.globalToLocal(targetGlobal),
        startedAt: DateTime.now().add(Duration(milliseconds: staggerMs)),
        durationMs: fromUserAction ? 700 : 920,
        seed: _random.nextDouble(),
      );
      _flyingGems = <_GreedyFlyingGem>[..._flyingGems, entry];
      unawaited(_playEffect('assets/games/teen_patti/coin_dropped.mp3'));
      if (fromUserAction) {
        Haptics.selection();
      }
      setState(() {});
    });
  }

  void _pointerNudge({bool strong = false}) {
    _pointerController
      ..stop()
      ..forward(from: 0);
    if (strong) {
      Haptics.medium();
      SystemSound.play(SystemSoundType.click);
    }
  }

  void _playWheelSpinTicks() {
    _wheelTickTimer?.cancel();
    var tick = 0;
    _wheelTickTimer = Timer.periodic(const Duration(milliseconds: 120), (timer) {
      if (!_wheelController.isAnimating) {
        timer.cancel();
        SystemSound.play(SystemSoundType.click);
        return;
      }
      tick++;
      _pointerNudge();
      if (tick % 2 == 0) {
        SystemSound.play(SystemSoundType.click);
      }
      if (_wheelController.value > .72) {
        timer.cancel();
      }
    });
  }

  Future<void> _playEffect(String assetPath) async {
    try {
      await _effectPlayer.stop();
      await _effectPlayer.play(AssetSource(assetPath));
    } catch (_) {}
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
    if (_displayRound(snapshot.round).phase != 'betting') {
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
      _launchGemToPot(
        pot: selectedPot,
        amount: _selectedAmount,
        fromUserAction: true,
      );
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
    final displayRound = _displayRound(round);

    return Container(
      key: _panelKey,
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [Color(0xFF0B1020), Color(0xFF1A1026), Color(0xFF090D18)],
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ),
      ),
      child: Stack(
        children: [
          Positioned.fill(
            child: IgnorePointer(
              child: AnimatedBuilder(
                animation: _idleController,
                builder: (context, _) {
                  return CustomPaint(
                    painter: _GreedyAtmospherePainter(progress: _idleController.value),
                  );
                },
              ),
            ),
          ),
          Positioned(
            top: -70,
            left: -40,
            child: IgnorePointer(
              child: AnimatedBuilder(
                animation: Listenable.merge([_pulseController, _idleController]),
                builder: (context, _) {
                  return Transform.scale(
                    scale: 1 + (_pulseController.value * .08) + (_idleController.value * .02),
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
                animation: Listenable.merge([_pulseController, _idleController]),
                builder: (context, _) {
                  return Transform.scale(
                    scale: 1 + (_pulseController.value * .05) + ((1 - _idleController.value) * .02),
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
          Positioned.fill(
            child: IgnorePointer(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: RadialGradient(
                    center: const Alignment(0, -0.12),
                    radius: .72,
                    colors: [
                      const Color(0x22FFD36D),
                      Colors.transparent,
                    ],
                  ),
                ),
              ),
            ),
          ),
          ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 22),
            children: [
              _GreedyHeader(
                countdownSeconds: displayRound.countdownSeconds,
                walletBalance: snapshot.walletBalance,
                phaseLabel: _phaseLabel(displayRound.phase),
              ),
              _buildWheel(round, phase: displayRound.phase),
              const SizedBox(height: 12),
              _buildPotRail(round, phase: displayRound.phase),
              const SizedBox(height: 12),
              _GreedyBetConsole(
                selectedPot: _selectedPot,
                selectedAmount: _selectedAmount,
                phase: displayRound.phase,
                placing: _placing,
                chipValues: _chipValues,
                onSelectChip: (value) {
                  Haptics.selection();
                  SystemSound.play(SystemSoundType.click);
                  setState(() => _selectedAmount = value);
                },
                onPlaceBet: _placeBet,
                chipKeyFor: (value) => _chipKeys[value],
              ),
              const SizedBox(height: 14),
              _GreedyHistoryStrip(history: snapshot.history),
            ],
          ),
          IgnorePointer(
            child: AnimatedBuilder(
              animation: Listenable.merge([_pulseController, _wheelController, _pointerController]),
              builder: (context, _) {
                return Stack(
                  children: _flyingGems
                      .map((gem) => _buildFlyingGem(gem))
                      .whereType<Widget>()
                      .toList(growable: false),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  _GreedyLiveRoundView _displayRound(GreedyRound round) {
    final now = _now;
    final displayUntil = round.displayUntil;
    final locksAt = round.locksAt;
    final endsAt = round.endsAt;

    if (displayUntil != null && now.isAfter(displayUntil)) {
      return _GreedyLiveRoundView(
        source: round,
        phase: 'restarting',
        countdownSeconds: 0,
        roundChanged: true,
      );
    }

    if (round.status == 'settled' || round.status == 'cancelled') {
      final remaining =
          displayUntil == null ? 0 : displayUntil.difference(now).inSeconds;
      return _GreedyLiveRoundView(
        source: round,
        phase: round.status == 'cancelled' ? 'cancelled' : 'result',
        countdownSeconds: max(0, remaining),
        roundChanged: remaining <= 0,
      );
    }

    if (endsAt != null && !now.isBefore(endsAt)) {
      final remaining =
          displayUntil == null ? 0 : displayUntil.difference(now).inSeconds;
      return _GreedyLiveRoundView(
        source: round,
        phase: 'settling',
        countdownSeconds: max(0, remaining),
        roundChanged: false,
      );
    }

    if (locksAt != null && !now.isBefore(locksAt)) {
      final remaining = endsAt == null ? 0 : endsAt.difference(now).inSeconds;
      return _GreedyLiveRoundView(
        source: round,
        phase: 'locked',
        countdownSeconds: max(0, remaining),
        roundChanged: false,
      );
    }

    final remaining =
        locksAt == null ? round.countdownSeconds : locksAt.difference(now).inSeconds;
    return _GreedyLiveRoundView(
      source: round,
      phase: 'betting',
      countdownSeconds: max(0, remaining),
      roundChanged: false,
    );
  }

  Widget? _buildFlyingGem(_GreedyFlyingGem gem) {
    final elapsed = _now.difference(gem.startedAt).inMilliseconds;
    if (elapsed < 0) return null;
    final t = (elapsed / gem.durationMs).clamp(0.0, 1.0);
    final eased = Curves.easeOutCubic.transform(t);
    final position = Offset.lerp(gem.start, gem.end, eased)!;
    final arc = sin(eased * pi) * (34 + (gem.seed * 22));
    final bounce = t >= .82 ? sin(((t - .82) / .18) * pi) * 10 : 0.0;
    final scale = t < .8 ? lerpDouble(.7, 1.0, eased)! : lerpDouble(1.0, .92, (t - .8) / .2)!;
    return Positioned(
      left: position.dx - 14,
      top: position.dy - 14 - arc - bounce,
      child: Transform.scale(
        scale: scale,
        child: Opacity(
          opacity: t < .92 ? 1 : (1 - ((t - .92) / .08)).clamp(0.0, 1.0),
          child: _GreedyGemToken(
            amount: gem.amount,
            accent: _potColor(gem.pot),
            elevated: true,
          ),
        ),
      ),
    );
  }

  Widget _buildWheel(GreedyRound round, {required String phase}) {
    _wheelController.duration =
        phase == 'result'
            ? const Duration(milliseconds: 2800)
            : const Duration(milliseconds: 900);
    final turns = Tween<double>(begin: _wheelStartTurns, end: _wheelEndTurns).animate(
      CurvedAnimation(parent: _wheelController, curve: Curves.easeOutQuart),
    );
    final accent =
        phase == 'result' && round.winningPot != null
            ? _potColor(round.winningPot!)
            : const Color(0xFFFFD56A);

    return SizedBox(
      height: 252,
      child: Stack(
        alignment: Alignment.center,
        children: [
          IgnorePointer(
            child: AnimatedBuilder(
              animation: Listenable.merge([_pulseController, _idleController, _flashController]),
              builder: (context, _) {
                return Transform.scale(
                  scale: 1 + (_pulseController.value * .04) + (_idleController.value * .02),
                  child: Container(
                    width: 236,
                    height: 236,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      gradient: RadialGradient(
                        colors: [
                          accent.withValues(alpha: .20 + (_flashController.value * .15)),
                          const Color(0x225A7BFF),
                          Colors.transparent,
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
          Positioned(
            top: 4,
            child: AnimatedBuilder(
              animation: _pointerController,
              builder: (context, _) {
                final kick = sin(_pointerController.value * pi) * 12;
                return Transform.translate(
                  offset: Offset(0, kick),
                  child: Transform.rotate(
                    angle: (-7 * pi / 180) * sin(_pointerController.value * pi),
                    child: _GreedyWheelPointer(
                      accent: accent,
                      bounce: _pointerController.value,
                    ),
                  ),
                );
              },
            ),
          ),
          SizedBox(
            width: 228,
            height: 228,
            child: Stack(
              alignment: Alignment.center,
              children: [
                Container(
                  width: 228,
                  height: 228,
                  decoration: const BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: SweepGradient(
                      colors: [
                        Color(0xFF7E5A21),
                        Color(0xFFE5C174),
                        Color(0xFF4A3212),
                        Color(0xFFF6D88C),
                        Color(0xFF7E5A21),
                      ],
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black87,
                        blurRadius: 22,
                        offset: Offset(0, 16),
                      ),
                    ],
                  ),
                ),
                Container(
                  width: 214,
                  height: 214,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: const RadialGradient(
                      colors: [Color(0xFF25202C), Color(0xFF120F16)],
                    ),
                    border: Border.all(color: Colors.white10),
                  ),
                ),
                SizedBox(
                  width: 202,
                  height: 202,
                  child: AnimatedBuilder(
                    animation: Listenable.merge([_wheelController, _pulseController, _flashController]),
                    builder: (context, child) {
                      return Transform.rotate(
                        angle: turns.value * 2 * pi,
                        child: CustomPaint(
                          painter: _GreedyWheelPainter(
                            multipliers: round.potMultipliers,
                            sectors: round.potSectors,
                            pulse:
                                phase == 'betting'
                                    ? _pulseController.value
                                    : 0,
                            winningPot:
                                phase == 'result' ? round.winningPot : null,
                            flash: _flashController.value,
                          ),
                          child: const SizedBox.expand(),
                        ),
                      );
                    },
                  ),
                ),
              ],
            ),
          ),
          Container(
            width: 74,
            height: 74,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: const RadialGradient(
                colors: [Color(0xFFFFF0B0), Color(0xFFE6A11A)],
                stops: [0, .95],
              ),
              border: Border.all(color: const Color(0xAAFFF3C2), width: 2),
              boxShadow: [
                BoxShadow(
                  color: accent.withValues(alpha: .30),
                  blurRadius: 18,
                  spreadRadius: 1,
                  offset: Offset(0, 8),
                ),
                const BoxShadow(
                  color: Colors.black54,
                  blurRadius: 10,
                  offset: Offset(0, 5),
                ),
              ],
            ),
            child: Center(
              child: Text(
                phase == 'result'
                    ? (round.winningPot ?? '—')
                    : '${round.totalBetsCount}',
                style: const TextStyle(
                  color: Colors.black,
                  fontSize: 24,
                  fontWeight: FontWeight.w900,
                  letterSpacing: .4,
                ),
              ),
            ),
          ),
          Positioned(
            bottom: 6,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
              decoration: BoxDecoration(
                color: Colors.black.withValues(alpha: .26),
                borderRadius: BorderRadius.circular(999),
                border: Border.all(color: Colors.white12),
              ),
              child: Text(
                switch (_revealStage) {
                  _GreedyRevealStage.betting => 'OPEN',
                  _GreedyRevealStage.locked => 'LOCKED',
                  _GreedyRevealStage.spin => 'SPIN',
                  _GreedyRevealStage.flash => 'HIT',
                  _GreedyRevealStage.payout => 'PAYOUT',
                },
                style: TextStyle(
                  color: accent,
                  fontWeight: FontWeight.w900,
                  fontSize: 11,
                  letterSpacing: 1.0,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildPotRail(GreedyRound round, {required String phase}) {
    return SizedBox(
      height: 164,
      child: Row(
        children:
            _pots.map((pot) {
              final selected = _selectedPot == pot;
              final winning = phase == 'result' && round.winningPot == pot;
              final yourAmount = _localViewerPotTotals[pot] ?? 0;
              return Expanded(
                child: Padding(
                  padding: EdgeInsets.only(right: pot == _pots.last ? 0 : 8),
                  child: GestureDetector(
                    onTap:
                        phase == 'betting'
                            ? () {
                              Haptics.selection();
                              SystemSound.play(SystemSoundType.click);
                              setState(() => _selectedPot = pot);
                            }
                            : null,
                    child: _GreedyPotCard(
                      key: _potKeys[_pots.indexOf(pot)],
                      pot: pot,
                      selected: selected,
                      winning: winning,
                      pulse: phase == 'betting' ? _pulseController.value : 0,
                      flash: _flashController.value,
                      totalAmount: _displayTotals[pot] ?? 0,
                      yourAmount: yourAmount,
                      multiplier: round.potMultipliers[pot] ?? 0,
                      landedGems: _landedGems[pot] ?? const <_GreedyGemStackItem>[],
                    ),
                  ),
                ),
              );
            }).toList(),
      ),
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

    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      barrierColor: const Color(0xE8110C14),
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

  String _phaseLabel(String phase) => switch (phase) {
    'betting' => 'BETTING',
    'locked' => 'LOCKED',
    'settling' => 'SETTLING',
    'result' => 'RESULT',
    'restarting' => 'NEXT ROUND',
    'cancelled' => 'Cancelled',
    _ => 'Greedy',
  };

}

class _GreedyLiveRoundView {
  const _GreedyLiveRoundView({
    required this.source,
    required this.phase,
    required this.countdownSeconds,
    required this.roundChanged,
  });

  final GreedyRound source;
  final String phase;
  final int countdownSeconds;
  final bool roundChanged;
}

class _GreedyHeader extends StatelessWidget {
  const _GreedyHeader({
    required this.countdownSeconds,
    required this.walletBalance,
    required this.phaseLabel,
  });

  final int countdownSeconds;
  final int walletBalance;
  final String phaseLabel;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 84,
      child: Column(
        children: [
          SizedBox(
            height: 42,
            child: Stack(
              alignment: Alignment.center,
              children: [
                Row(
                  children: [
                    const SizedBox(width: 40),
                    const Spacer(),
                    _GreedyCoinBalancePill(balance: walletBalance),
                  ],
                ),
                _HeaderTimePill(seconds: countdownSeconds),
              ],
            ),
          ),
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
            decoration: BoxDecoration(
              color: Colors.black.withValues(alpha: .22),
              borderRadius: BorderRadius.circular(999),
              border: Border.all(color: Colors.white10),
            ),
            child: Text(
              phaseLabel,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 11,
                fontWeight: FontWeight.w800,
                letterSpacing: .4,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _HeaderTimePill extends StatelessWidget {
  const _HeaderTimePill({required this.seconds});

  final int seconds;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 9),
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
          fontSize: 16,
        ),
      ),
    );
  }
}

class _GreedyCoinBalancePill extends StatelessWidget {
  const _GreedyCoinBalancePill({required this.balance});

  final int balance;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
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
            width: 22,
            height: 22,
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
          Flexible(
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                '$balance',
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: 13,
                ),
              ),
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
    required this.phase,
    required this.placing,
    required this.chipValues,
    required this.onSelectChip,
    required this.onPlaceBet,
    required this.chipKeyFor,
  });

  final String? selectedPot;
  final int selectedAmount;
  final String phase;
  final bool placing;
  final List<int> chipValues;
  final ValueChanged<int> onSelectChip;
  final VoidCallback onPlaceBet;
  final GlobalKey? Function(int value) chipKeyFor;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            const Color(0xCC1A1421),
            const Color(0xCC0E0A12),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: const Color(0x55D4AF37)),
        boxShadow: const [
          BoxShadow(
            color: Colors.black54,
            blurRadius: 24,
            offset: Offset(0, 14),
          ),
        ],
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    Expanded(
                      child: Wrap(
                        spacing: 8,
                        runSpacing: 6,
                        children: [
                          const _ConsolePill(label: 'PLACE BET'),
                          if (selectedPot != null)
                            _ConsolePill(label: 'POT $selectedPot', premium: true),
                          _ConsolePill(
                            label: _formatGreedyCoins(selectedAmount),
                            premium: true,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Row(
                  children: List.generate(chipValues.length, (index) {
                    final value = chipValues[index];
                    final selected = value == selectedAmount;
                    return Expanded(
                      child: Padding(
                        padding: EdgeInsets.only(right: index == chipValues.length - 1 ? 0 : 8),
                        child: GestureDetector(
                          key: chipKeyFor(value),
                          onTap: phase == 'betting' && !placing ? () => onSelectChip(value) : null,
                          child: AnimatedContainer(
                            duration: const Duration(milliseconds: 180),
                            curve: Curves.easeOut,
                            height: 48,
                            alignment: Alignment.center,
                            padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 10),
                            transform: Matrix4.identity()..translate(0.0, selected ? -3.0 : 0.0),
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(999),
                              gradient:
                                  selected
                                      ? const LinearGradient(
                                        colors: [Color(0xFFFFF1C2), Color(0xFFE3A31C)],
                                        begin: Alignment.topCenter,
                                        end: Alignment.bottomCenter,
                                      )
                                      : LinearGradient(
                                        colors: [
                                          Colors.white.withValues(alpha: .10),
                                          const Color(0xFF110E16).withValues(alpha: .72),
                                        ],
                                        begin: Alignment.topCenter,
                                        end: Alignment.bottomCenter,
                                      ),
                              border: Border.all(
                                color: selected ? const Color(0xFFFFF3C2) : Colors.white12,
                                width: selected ? 1.4 : 1,
                              ),
                              boxShadow: selected
                                  ? const [
                                    BoxShadow(
                                      color: Color(0x4DE3A31C),
                                      blurRadius: 14,
                                      offset: Offset(0, 7),
                                    ),
                                    BoxShadow(
                                      color: Color(0x26FFF5D1),
                                      blurRadius: 2,
                                      offset: Offset(0, -1),
                                    ),
                                  ]
                                  : null,
                            ),
                            child: FittedBox(
                              fit: BoxFit.scaleDown,
                              child: Text(
                                _formatGreedyCoins(value),
                                style: TextStyle(
                                  color: selected ? Colors.black : Colors.white,
                                  fontWeight: FontWeight.w900,
                                  fontSize: 12.5,
                                  letterSpacing: .35,
                                ),
                              ),
                            ),
                          ),
                        ),
                      ),
                    );
                  }),
                ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          GestureDetector(
            onTap: selectedPot == null || placing || phase != 'betting' ? null : onPlaceBet,
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 160),
              padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(18),
                gradient: selectedPot == null || phase != 'betting'
                    ? const LinearGradient(
                        colors: [Color(0xFF423B3A), Color(0xFF262024)],
                      )
                    : const LinearGradient(
                        colors: [Color(0xFFFFE6A7), Color(0xFFE29A17), Color(0xFF9E6203)],
                        begin: Alignment.topCenter,
                        end: Alignment.bottomCenter,
                      ),
                border: Border.all(
                  color: selectedPot == null || phase != 'betting'
                      ? Colors.white12
                      : const Color(0x77FFF0BE),
                ),
                boxShadow: selectedPot == null || phase != 'betting'
                    ? null
                    : const [
                        BoxShadow(
                          color: Color(0x52E39A15),
                          blurRadius: 16,
                          offset: Offset(0, 8),
                        ),
                        BoxShadow(
                          color: Colors.black45,
                          blurRadius: 7,
                          offset: Offset(0, 5),
                        ),
                      ],
              ),
              child: SizedBox(
                width: 84,
                child: Center(
                  child: placing
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : Text(
                          selectedPot == null ? 'PICK' : 'DROP',
                          style: TextStyle(
                            color: selectedPot == null || phase != 'betting'
                                ? Colors.white70
                                : Colors.black,
                            fontWeight: FontWeight.w900,
                            fontSize: 14,
                            letterSpacing: 1.1,
                          ),
                        ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ConsolePill extends StatelessWidget {
  const _ConsolePill({required this.label, this.premium = false});

  final String label;
  final bool premium;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        gradient: premium
            ? const LinearGradient(
                colors: [Color(0x33FFF0BE), Color(0x18110E15)],
              )
            : null,
        color: premium ? null : Colors.white.withValues(alpha: .08),
        border: Border.all(color: premium ? const Color(0x44D4AF37) : Colors.white10),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: premium ? const Color(0xFFFFE39A) : Colors.white,
          fontWeight: FontWeight.w800,
          fontSize: 11,
          letterSpacing: .5,
        ),
      ),
    );
  }
}

class _GreedyPotCard extends StatelessWidget {
  const _GreedyPotCard({
    super.key,
    required this.pot,
    required this.selected,
    required this.winning,
    required this.pulse,
    required this.flash,
    required this.totalAmount,
    required this.yourAmount,
    required this.multiplier,
    required this.landedGems,
  });

  final String pot;
  final bool selected;
  final bool winning;
  final double pulse;
  final double flash;
  final int totalAmount;
  final int yourAmount;
  final int multiplier;
  final List<_GreedyGemStackItem> landedGems;

  Color get accent => _potColor(pot);

  @override
  Widget build(BuildContext context) {
    return AnimatedContainer(
      duration: const Duration(milliseconds: 220),
      curve: Curves.easeOut,
      padding: const EdgeInsets.fromLTRB(10, 10, 10, 8),
      transform: Matrix4.identity()..scale(selected ? 1.03 : 1.0),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        gradient: LinearGradient(
          colors:
              winning
                  ? [
                    accent.withValues(alpha: .62 + (flash * .12)),
                    const Color(0xFF24161A),
                    const Color(0xFF120F16),
                  ]
                  : [
                    accent.withValues(alpha: .14 + (pulse * .10)),
                    const Color(0xFF18131D),
                    const Color(0xFF120F16),
                  ],
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
                    color: accent.withValues(alpha: winning ? .44 : (.24 + pulse * .12)),
                    blurRadius: winning ? 24 + (flash * 10) : 16 + (pulse * 8),
                    offset: const Offset(0, 8),
                  ),
                ]
                : [
                    BoxShadow(
                      color: accent.withValues(alpha: .12 + (pulse * .06)),
                      blurRadius: 12 + (pulse * 8),
                      offset: const Offset(0, 8),
                    ),
                  ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'POT $pot',
                    style: TextStyle(
                      color: accent,
                      fontWeight: FontWeight.w900,
                      fontSize: 10,
                      letterSpacing: .7,
                    ),
                  ),
                  const SizedBox(height: 1),
                  Text(
                    '${multiplier}X',
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w900,
                      fontSize: 16,
                      letterSpacing: .3,
                    ),
                  ),
                ],
              ),
              const Spacer(),
              if (winning || selected)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(999),
                    color: winning
                        ? accent.withValues(alpha: .22)
                        : Colors.white.withValues(alpha: .08),
                    border: Border.all(
                      color: winning ? accent.withValues(alpha: .5) : Colors.white12,
                    ),
                  ),
                  child: Text(
                    winning ? 'WIN' : 'HOT',
                    style: TextStyle(
                      color: winning ? accent : Colors.white,
                      fontWeight: FontWeight.w900,
                      fontSize: 9,
                      letterSpacing: .5,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 8),
          Container(
            height: 6,
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
          const SizedBox(height: 4),
          SizedBox(
            height: 22,
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                Positioned(
                  left: 4,
                  right: 4,
                  bottom: -2,
                  child: Container(
                    height: 10,
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(999),
                      color: Colors.black.withValues(alpha: .30),
                      boxShadow: [
                        BoxShadow(
                          color: accent.withValues(alpha: .22),
                          blurRadius: 10,
                        ),
                      ],
                    ),
                  ),
                ),
                ..._buildGemPile(),
              ],
            ),
          ),
          const Spacer(),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(
              _formatGreedyCoins(totalAmount),
              style: const TextStyle(
                color: Colors.white,
                fontSize: 20,
                fontWeight: FontWeight.w900,
                letterSpacing: .2,
              ),
            ),
          ),
          const SizedBox(height: 4),
          Row(
            children: [
              Expanded(
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 5),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(12),
                    color: Colors.white.withValues(alpha: .06),
                    border: Border.all(color: Colors.white10),
                  ),
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: Text(
                      'YOU ${_formatGreedyCoins(yourAmount)}',
                      style: TextStyle(
                        color: yourAmount > 0 ? Colors.white : Colors.white70,
                        fontWeight: FontWeight.w900,
                        fontSize: 9,
                        letterSpacing: .4,
                      ),
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

  List<Widget> _buildGemPile() {
    final visible = landedGems.length > 8 ? landedGems.sublist(landedGems.length - 8) : landedGems;
    return List<Widget>.generate(visible.length, (index) {
      final item = visible[index];
      final x = 4.0 + ((index * 11) % 62);
      final y = (index % 2) * 4.0 + (item.offsetSeed * 2);
      return Positioned(
        left: x + ((index % 3) * 2),
        top: y,
        child: Transform.rotate(
          angle: (-0.16 + (item.offsetSeed * 0.28)),
          child: _GreedyGemToken(
            amount: item.amount,
            accent: accent,
          ),
        ),
      );
    });
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
            fontSize: 15,
            fontWeight: FontWeight.w900,
          ),
        ),
        const SizedBox(height: 8),
        SizedBox(
          height: 82,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            itemCount: history.length,
            separatorBuilder: (_, _) => const SizedBox(width: 10),
            itemBuilder: (context, index) {
              final round = history[index];
              final accent =
                  round.winningPot == null
                      ? Colors.white54
                      : _potColor(round.winningPot!);
              return Container(
                width: 118,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(16),
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
                    const Spacer(),
                    Text(
                      round.winningPot ?? '—',
                      style: TextStyle(
                        color: round.winningPot == null ? Colors.white60 : accent,
                        fontWeight: FontWeight.w800,
                        fontSize: 18,
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
  bool _badgeVisible = false;
  bool _centerVisible = false;
  bool _payoutVisible = false;

  @override
  void initState() {
    super.initState();
    Future<void>.delayed(const Duration(milliseconds: 100), () {
      if (!mounted) return;
      setState(() => _badgeVisible = true);
      SystemSound.play(SystemSoundType.click);
    });
    Future<void>.delayed(const Duration(milliseconds: 260), () {
      if (!mounted) return;
      setState(() => _centerVisible = true);
    });
    Future<void>.delayed(const Duration(milliseconds: 460), () {
      if (!mounted) return;
      setState(() => _payoutVisible = true);
      Haptics.medium();
    });
  }

  @override
  Widget build(BuildContext context) {
    final accent = _potColor(widget.winningPot);
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
        child: Container(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 20),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [Color(0xFF1A111F), Color(0xFF0D0912)],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
            borderRadius: BorderRadius.circular(28),
            border: Border.all(
              color: widget.won ? const Color(0xFFE1B12C) : Colors.white24,
              width: 1.4,
            ),
            boxShadow: [
              BoxShadow(
                color: accent.withValues(alpha: .24),
                blurRadius: 30,
                spreadRadius: 2,
              ),
            ],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 44,
                height: 4,
                margin: const EdgeInsets.only(bottom: 14),
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .24),
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
            AnimatedScale(
              scale: _badgeVisible ? 1 : .92,
              duration: const Duration(milliseconds: 240),
              curve: Curves.easeOutBack,
              child: AnimatedOpacity(
                opacity: _badgeVisible ? 1 : .4,
                duration: const Duration(milliseconds: 220),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors:
                          widget.won
                              ? [
                                accent.withValues(alpha: .20),
                                const Color(0xFFFFC107).withValues(alpha: .16),
                              ]
                              : [
                                accent.withValues(alpha: .14),
                                Colors.white.withValues(alpha: .08),
                              ],
                    ),
                    borderRadius: BorderRadius.circular(18),
                    border: Border.all(
                      color: widget.won ? const Color(0xFFFFC107) : accent.withValues(alpha: .42),
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: accent.withValues(alpha: .16),
                        blurRadius: 16,
                        offset: const Offset(0, 8),
                      ),
                    ],
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(
                        widget.won ? Icons.workspace_premium_rounded : Icons.stars_rounded,
                        color: widget.won ? const Color(0xFFFFC107) : accent,
                      ),
                      const SizedBox(width: 10),
                      Text(
                        'Winning Pot ${widget.winningPot}',
                        style: TextStyle(
                          color: widget.won ? const Color(0xFFFFC107) : Colors.white,
                          fontSize: 18,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
            const SizedBox(height: 18),
            AnimatedOpacity(
              opacity: _centerVisible ? 1 : 0,
              duration: const Duration(milliseconds: 220),
              child: AnimatedSlide(
                offset: _centerVisible ? Offset.zero : const Offset(0, .08),
                duration: const Duration(milliseconds: 240),
                curve: Curves.easeOutCubic,
                child: Container(
                  height: 142,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(22),
                    gradient: LinearGradient(
                      colors: [
                        accent.withValues(alpha: .16),
                        Colors.black.withValues(alpha: .22),
                      ],
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                    ),
                    border: Border.all(color: accent.withValues(alpha: .34)),
                  ),
                  child: Stack(
                    alignment: Alignment.center,
                    children: [
                      Container(
                        width: 164,
                        height: 114,
                        decoration: BoxDecoration(
                          gradient: RadialGradient(
                            colors: [accent.withValues(alpha: .24), accent.withValues(alpha: 0)],
                          ),
                        ),
                      ),
                      Container(
                        width: 114,
                        height: 114,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          gradient: LinearGradient(
                            colors:
                                widget.won
                                    ? [const Color(0xFFFFE39A), const Color(0xFFE39517)]
                                    : [accent.withValues(alpha: .88), accent.withValues(alpha: .52)],
                            begin: Alignment.topCenter,
                            end: Alignment.bottomCenter,
                          ),
                          boxShadow: [
                            BoxShadow(
                              color: accent.withValues(alpha: .32),
                              blurRadius: 24,
                              offset: const Offset(0, 12),
                            ),
                          ],
                        ),
                        child: Center(
                          child: Text(
                            widget.winningPot,
                            style: const TextStyle(
                              color: Colors.black,
                              fontWeight: FontWeight.w900,
                              fontSize: 36,
                              letterSpacing: .8,
                            ),
                          ),
                        ),
                      ),
                      Positioned(
                        bottom: 16,
                        child: ShaderMask(
                          shaderCallback: (rect) => LinearGradient(
                            colors: [accent, Colors.white],
                          ).createShader(rect),
                          child: Text(
                            '${widget.winningMultiplier}X RETURN',
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w900,
                              fontSize: 16,
                              letterSpacing: .6,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
            const SizedBox(height: 18),
            AnimatedOpacity(
              opacity: _payoutVisible ? 1 : 0,
              duration: const Duration(milliseconds: 260),
              child: _GreedyResultInfoTile(
                title: 'Your Bet on Winning Pot',
                value: widget.yourBet,
                color: const Color(0xFFFFA726),
                icon: Icons.local_atm_rounded,
              ),
            ),
            const SizedBox(height: 10),
            AnimatedOpacity(
              opacity: _payoutVisible ? 1 : 0,
              duration: const Duration(milliseconds: 320),
              child: _GreedyResultInfoTile(
                title: widget.won ? 'Your Winning Amount' : 'Round Outcome',
                value: widget.won ? widget.payout : 0,
                color: widget.won ? const Color(0xFF66BB6A) : accent,
                icon: widget.won ? Icons.workspace_premium_rounded : Icons.info_outline_rounded,
                emptyLabel: widget.won ? null : 'Missed this round',
              ),
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
      ),
    );
  }
}

class _GreedyResultInfoTile extends StatelessWidget {
  const _GreedyResultInfoTile({
    required this.title,
    required this.value,
    required this.color,
    required this.icon,
    this.emptyLabel,
  });

  final String title;
  final int value;
  final Color color;
  final IconData icon;
  final String? emptyLabel;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(18),
        gradient: LinearGradient(
          colors: [color.withValues(alpha: .18), Colors.black.withValues(alpha: .20)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(color: color.withValues(alpha: .34)),
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: color.withValues(alpha: .16),
            ),
            child: Icon(icon, color: color),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: .78),
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    letterSpacing: .5,
                  ),
                ),
                const SizedBox(height: 4),
                emptyLabel != null && value == 0
                    ? Text(
                        emptyLabel!,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 18,
                          fontWeight: FontWeight.w900,
                          letterSpacing: .4,
                        ),
                      )
                    : TweenAnimationBuilder<int>(
                        tween: IntTween(begin: 0, end: value),
                        duration: const Duration(milliseconds: 1200),
                        curve: Curves.easeOutCubic,
                        builder: (context, animatedValue, _) {
                          return Text(
                            _formatGreedyCoins(animatedValue),
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 22,
                              fontWeight: FontWeight.w900,
                              letterSpacing: .3,
                            ),
                          );
                        },
                      ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

enum _GreedyRevealStage { betting, locked, spin, flash, payout }

class _GreedyAtmospherePainter extends CustomPainter {
  const _GreedyAtmospherePainter({required this.progress});

  final double progress;

  @override
  void paint(Canvas canvas, Size size) {
    final vignette = Paint()
      ..shader = RadialGradient(
        center: const Alignment(0, -.12),
        radius: .95,
        colors: [
          Colors.transparent,
          const Color(0x22000000),
          const Color(0x66000000),
        ],
        stops: const [0.55, 0.82, 1],
      ).createShader(Offset.zero & size);
    canvas.drawRect(Offset.zero & size, vignette);

    final emberPaint = Paint()..style = PaintingStyle.fill;
    for (var i = 0; i < 14; i++) {
      final seed = i / 14;
      final dx = (size.width * (.08 + seed * .84)) + sin((progress + seed) * pi * 2) * 10;
      final dy = size.height * (.18 + ((seed * 37) % 1) * .72);
      final radius = 1.4 + ((i % 3) * .7);
      emberPaint.shader = RadialGradient(
        colors: [
          const Color(0xFFFFD36D).withValues(alpha: .30),
          const Color(0xFFFF9B22).withValues(alpha: .12),
          Colors.transparent,
        ],
      ).createShader(Rect.fromCircle(center: Offset(dx, dy), radius: radius * 3));
      canvas.drawCircle(Offset(dx, dy), radius * 3, emberPaint);
    }
  }

  @override
  bool shouldRepaint(covariant _GreedyAtmospherePainter oldDelegate) {
    return oldDelegate.progress != progress;
  }
}

class _GreedyPointerHeadPainter extends CustomPainter {
  const _GreedyPointerHeadPainter({required this.accent});

  final Color accent;

  @override
  void paint(Canvas canvas, Size size) {
    final path = Path()
      ..moveTo(size.width / 2, size.height)
      ..lineTo(0, 0)
      ..lineTo(size.width, 0)
      ..close();
    canvas.drawShadow(path, Colors.black, 6, false);
    canvas.drawPath(
      path,
      Paint()
        ..shader = const LinearGradient(
          colors: [Color(0xFFFFF0B6), Color(0xFFD8931C), Color(0xFF77480B)],
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ).createShader(Offset.zero & size),
    );
    canvas.drawPath(
      path,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.2
        ..color = Colors.white.withValues(alpha: .35),
    );
    canvas.drawCircle(
      Offset(size.width / 2, 7),
      4.8,
      Paint()
        ..shader = RadialGradient(
          colors: [
            Colors.white,
            accent.withValues(alpha: .95),
            accent.withValues(alpha: .45),
          ],
        ).createShader(Rect.fromCircle(center: Offset(size.width / 2, 7), radius: 4.8)),
    );
  }

  @override
  bool shouldRepaint(covariant _GreedyPointerHeadPainter oldDelegate) {
    return oldDelegate.accent != accent;
  }
}

class _GreedyFlyingGem {
  const _GreedyFlyingGem({
    required this.id,
    required this.pot,
    required this.amount,
    required this.start,
    required this.end,
    required this.startedAt,
    required this.durationMs,
    required this.seed,
  });

  final String id;
  final String pot;
  final int amount;
  final Offset start;
  final Offset end;
  final DateTime startedAt;
  final int durationMs;
  final double seed;
}

class _GreedyGemStackItem {
  const _GreedyGemStackItem({
    required this.amount,
    required this.accent,
    required this.offsetSeed,
  });

  final int amount;
  final Color accent;
  final double offsetSeed;
}

class _GreedyGemToken extends StatelessWidget {
  const _GreedyGemToken({
    required this.amount,
    required this.accent,
    this.elevated = false,
  });

  final int amount;
  final Color accent;
  final bool elevated;

  @override
  Widget build(BuildContext context) {
    final assetPath = _assetForGreedyGem(amount);
    final size = elevated ? 30.0 : 26.0;
    return SizedBox(
      width: size,
      height: size,
      child: Stack(
        alignment: Alignment.center,
        children: [
          Positioned(
            bottom: 0,
            child: Container(
              width: size * .78,
              height: 7,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                color: Colors.black.withValues(alpha: .26),
                boxShadow: [
                  BoxShadow(
                    color: accent.withValues(alpha: .24),
                    blurRadius: 10,
                  ),
                ],
              ),
            ),
          ),
          DecoratedBox(
            decoration: BoxDecoration(
              boxShadow: [
                BoxShadow(
                  color: accent.withValues(alpha: elevated ? .34 : .20),
                  blurRadius: elevated ? 14 : 10,
                  offset: Offset(0, elevated ? 6 : 4),
                ),
              ],
            ),
            child: Stack(
              alignment: Alignment.center,
              children: [
                Image.asset(
                  assetPath,
                  width: size,
                  height: size,
                  fit: BoxFit.contain,
                ),
                Positioned(
                  top: 3,
                  left: 6,
                  right: 6,
                  child: Container(
                    height: 5,
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(999),
                      gradient: LinearGradient(
                        colors: [
                          Colors.white.withValues(alpha: .55),
                          Colors.white.withValues(alpha: 0),
                        ],
                      ),
                    ),
                  ),
                ),
                Text(
                  _formatGreedyCoins(amount),
                  style: TextStyle(
                    color: Colors.black,
                    fontWeight: FontWeight.w900,
                    fontSize: elevated ? 7 : 6,
                    letterSpacing: .1,
                    shadows: const [
                      Shadow(
                        color: Colors.white70,
                        blurRadius: 2,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _GreedyWheelPointer extends StatelessWidget {
  const _GreedyWheelPointer({
    required this.accent,
    required this.bounce,
  });

  final Color accent;
  final double bounce;

  @override
  Widget build(BuildContext context) {
    final glow = .18 + (sin(bounce * pi) * .20);
    return SizedBox(
      width: 76,
      height: 58,
      child: Stack(
        alignment: Alignment.topCenter,
        children: [
          Positioned(
            top: 0,
            child: Container(
              width: 60,
              height: 28,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                gradient: const LinearGradient(
                  colors: [Color(0xFFFFE8A2), Color(0xFFD58C1B), Color(0xFF7A4C08)],
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                ),
                boxShadow: [
                  BoxShadow(
                    color: accent.withValues(alpha: glow),
                    blurRadius: 14,
                    offset: const Offset(0, 8),
                  ),
                ],
                border: Border.all(color: const Color(0x88FFF3C1)),
              ),
              child: Center(
                child: Container(
                  width: 16,
                  height: 16,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: RadialGradient(
                      colors: [
                        Colors.white,
                        accent.withValues(alpha: .95),
                        accent.withValues(alpha: .65),
                      ],
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: accent.withValues(alpha: .42),
                        blurRadius: 10,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
          Positioned(
            top: 22,
            child: CustomPaint(
              size: const Size(34, 30),
              painter: _GreedyPointerHeadPainter(accent: accent),
            ),
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
    required this.flash,
  });

  final Map<String, int> multipliers;
  final Map<String, int> sectors;
  final double pulse;
  final String? winningPot;
  final double flash;

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

    final engravedPaint =
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.1
          ..color = Colors.white.withValues(alpha: .08);

    for (final pot in _pots) {
      final sweep = ((sectors[pot] ?? 0) / max(1, totalSectors)) * 2 * pi;
      final color = _potColor(pot);
      final isWinner = winningPot == pot;
      final paint =
          Paint()
            ..style = PaintingStyle.fill
            ..shader = RadialGradient(
              colors: [
                color.withValues(alpha: isWinner ? (.92 + flash * .06) : (.66 + pulse * .18)),
                color.withValues(alpha: isWinner ? (.46 + flash * .10) : .28),
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
            ..strokeWidth = isWinner ? (4 + flash * 2) : 2
            ..color = isWinner ? Colors.white : Colors.white24;
      canvas.drawArc(
        Rect.fromCircle(center: center, radius: radius),
        startAngle,
        sweep,
        true,
        border,
      );

      canvas.drawArc(
        Rect.fromCircle(center: center, radius: radius * .78),
        startAngle,
        sweep,
        true,
        engravedPaint,
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
            fontSize: 15,
            letterSpacing: .4,
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
          ..color = Colors.white.withValues(alpha: .28)
          ..strokeWidth = 2
          ..strokeCap = StrokeCap.round;
    final microTickPaint =
        Paint()
          ..color = Colors.white.withValues(alpha: .12)
          ..strokeWidth = 1
          ..strokeCap = StrokeCap.round;
    final separatorPaint =
        Paint()
          ..color = Colors.white.withValues(alpha: .18)
          ..strokeWidth = 1.2;
    final majorTicks = max(1, totalSectors);
    final microTicks = max(majorTicks * 2, 24);
    for (var i = 0; i < majorTicks; i++) {
      final angle = (-pi / 2) + ((i / majorTicks) * 2 * pi);
      final outer = Offset(
        center.dx + cos(angle) * radius,
        center.dy + sin(angle) * radius,
      );
      final inner = Offset(
        center.dx + cos(angle) * (radius - 10),
        center.dy + sin(angle) * (radius - 10),
      );
      canvas.drawLine(inner, outer, tickPaint);
      final sepInner = Offset(
        center.dx + cos(angle) * (radius * .34),
        center.dy + sin(angle) * (radius * .34),
      );
      canvas.drawLine(sepInner, inner, separatorPaint);
    }
    for (var i = 0; i < microTicks; i++) {
      final angle = (-pi / 2) + ((i / microTicks) * 2 * pi);
      final outer = Offset(
        center.dx + cos(angle) * radius,
        center.dy + sin(angle) * radius,
      );
      final inner = Offset(
        center.dx + cos(angle) * (radius - 5),
        center.dy + sin(angle) * (radius - 5),
      );
      canvas.drawLine(inner, outer, microTickPaint);
    }

    canvas.drawCircle(
      center,
      radius,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 6
        ..color = Colors.white24,
    );

    canvas.drawCircle(
      center,
      radius * .24,
      Paint()
        ..shader = const RadialGradient(
          colors: [Color(0xFFFFF1BC), Color(0xFFD5941E), Color(0xFF704309)],
        ).createShader(Rect.fromCircle(center: center, radius: radius * .24)),
    );
    canvas.drawCircle(
      center,
      radius * .15,
      Paint()
        ..shader = RadialGradient(
          colors: [
            Colors.white,
            const Color(0xFFFFE3A0),
            const Color(0xFF9C5D0A).withValues(alpha: .95),
          ],
        ).createShader(Rect.fromCircle(center: center, radius: radius * .15)),
    );
    canvas.drawCircle(
      center,
      radius * .10,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.5
        ..color = Colors.white.withValues(alpha: .22),
    );
  }

  @override
  bool shouldRepaint(covariant _GreedyWheelPainter oldDelegate) {
    return oldDelegate.pulse != pulse ||
        oldDelegate.flash != flash ||
        oldDelegate.winningPot != winningPot ||
        oldDelegate.multipliers != multipliers ||
        oldDelegate.sectors != sectors;
  }
}

Color _potColor(String pot) => switch (pot) {
  'A' => const Color(0xFF5AA7FF),
  'B' => const Color(0xFFFF6B47),
  'C' => const Color(0xFF39D08F),
  _ => const Color(0xFFFFC34A),
};

String _assetForGreedyGem(int amount) {
  if (amount >= 5000) return _GreedyGamePanelState._gemAssets[5000]!;
  if (amount >= 1000) return _GreedyGamePanelState._gemAssets[1000]!;
  if (amount >= 500) return _GreedyGamePanelState._gemAssets[500]!;
  if (amount >= 200) return _GreedyGamePanelState._gemAssets[200]!;
  return _GreedyGamePanelState._gemAssets[100]!;
}

String _formatGreedyCoins(int value) {
  if (value >= 1000000 && value % 1000000 == 0) {
    return '${value ~/ 1000000}M';
  }
  if (value >= 1000 && value % 1000 == 0) {
    return '${value ~/ 1000}K';
  }
  return value.toString();
}
