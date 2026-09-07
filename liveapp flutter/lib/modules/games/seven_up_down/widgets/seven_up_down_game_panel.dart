import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../../app/routes/app_urls.dart';
import '../../../../app/widgets/haptics.dart';
import '../../../../services/api_client.dart';
import '../../../../services/storage_service.dart';
import '../../../wallet/widgets/recharge_bottom_sheet.dart';
import '../models/seven_up_down_models.dart';
import '../services/seven_up_down_api.dart';
import '../services/seven_up_down_socket_service.dart';

class SevenUpDownGamePanel extends StatefulWidget {
  const SevenUpDownGamePanel({super.key});

  @override
  State<SevenUpDownGamePanel> createState() => _SevenUpDownGamePanelState();
}

class _SevenUpDownGamePanelState extends State<SevenUpDownGamePanel>
    with TickerProviderStateMixin {
  static const _chips = [50, 200, 500, 1000, 5000];
  static const _potColors = {
    'DOWN': Color(0xFF4BD5FF),
    'SEVEN': Color(0xFFFFD45E),
    'UP': Color(0xFFFF6FA8),
  };
  static const _chipAssets = {
    50: 'assets/games/teen_patti/gems_1.png',
    200: 'assets/games/teen_patti/gems_2.png',
    500: 'assets/games/teen_patti/gems_3.png',
    1000: 'assets/games/teen_patti/gems_4.png',
    5000: 'assets/games/teen_patti/gems_5.png',
  };

  final _socket = SevenUpDownSocketService();
  final _random = Random();
  final _panelKey = GlobalKey();
  final _chipRowKey = GlobalKey();
  final _potKeys = List<GlobalKey>.generate(3, (_) => GlobalKey());
  late final SevenUpDownApi _api;
  late final AnimationController _idleController;
  late final AnimationController _diceController;
  late final AnimationController _lockController;
  late final AnimationController _resultController;
  late final AnimationController _roundIntroController;
  late final AnimationController _celebrationController;
  StreamSubscription<Map<String, dynamic>>? _snapshotSub;
  StreamSubscription<Map<String, dynamic>>? _eventSub;
  Timer? _clock;
  Timer? _feedbackTimer;
  SevenUpDownSnapshot? _snapshot;
  SevenUpDownRound? _pendingDiceResult;
  DateTime _now = DateTime.now();
  int _selectedChip = 50;
  String? _selectedPot;
  int _shownDiceOne = 1;
  int _shownDiceTwo = 6;
  int _diceFrame = -1;
  bool _loading = true;
  bool _placingBet = false;
  bool _rolling = false;
  String? _revealedRoundKey;
  String? _animatingRoundKey;
  String? _lastRoundKey;
  String? _lastPhase;
  String? _lastVisualPhase;
  List<_FlyingLuckyCoin> _flyingCoins = const [];
  Map<String, int> _displayTotals = const {'DOWN': 0, 'SEVEN': 0, 'UP': 0};
  List<_ScheduledLuckyBet> _scheduledFakeBets = const [];
  Set<String> _appliedFakeBetIds = const {};
  String? _activeFakeRoundKey;
  String? _betPulsePot;
  String? _betFeedback;
  String? _error;

  @override
  void initState() {
    super.initState();
    _api = SevenUpDownApi(Get.find<ApiClient>());
    _idleController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2800),
    )..repeat();
    _diceController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1850),
    )..addListener(_updateRollingFaces);
    _lockController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 520),
    );
    _resultController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 520),
    );
    _roundIntroController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 560),
    );
    _celebrationController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1500),
    );
    _clock = Timer.periodic(
      const Duration(milliseconds: 250),
      (_) => _tickClock(),
    );
    unawaited(_start());
  }

  Future<void> _start() async {
    await _load();
    final token = Get.find<StorageService>().token;
    if (!mounted || token == null || token.isEmpty) return;
    _snapshotSub = _socket.snapshots.listen((payload) {
      if (!mounted) return;
      final data =
          payload['data'] is Map
              ? Map<String, dynamic>.from(payload['data'] as Map)
              : Map<String, dynamic>.from(payload);
      final next = SevenUpDownSnapshot.fromJson(data);
      _apply(
        _snapshot == null || data.containsKey('wallet_balance')
            ? next
            : _snapshot!.mergePublic(next),
      );
    });
    _eventSub = _socket.events.listen((event) {
      if (!mounted) return;
      if (event['event'] == 'feature:error') {
        setState(
          () => _error = (event['message'] ?? 'Game unavailable.').toString(),
        );
      } else if (event['event'] == 'seven_up_down:round_settled' ||
          event['event'] == 'seven_up_down:bet_refunded') {
        // Public room snapshots intentionally omit private wallet and bet state.
        // Refresh the authenticated snapshot when settlement changes either.
        unawaited(_load());
      }
    });
    await _socket.start(url: AppUrls.wsGames, token: token);
  }

  Future<void> _load() async {
    try {
      final next = await _api.fetchSnapshot();
      if (mounted) _apply(next);
    } catch (error) {
      if (mounted) {
        setState(
          () => _error = error.toString().replaceFirst('Exception: ', ''),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _apply(SevenUpDownSnapshot next) {
    final round = next.round;
    final isNewRound = _lastRoundKey != round.roundKey;
    final phaseChanged = isNewRound || _lastPhase != round.phase;
    final visualPhase = _visualPhaseFor(round, DateTime.now());
    final visualPhaseChanged = isNewRound || _lastVisualPhase != visualPhase;
    final allowed =
        _chips
            .where(
              (chip) =>
                  chip >= next.settings.minBet && chip <= next.settings.maxBet,
            )
            .toList();

    if (isNewRound) {
      _pendingDiceResult = null;
      _animatingRoundKey = null;
      _revealedRoundKey = null;
      _rolling = false;
      _shownDiceOne = 1;
      _shownDiceTwo = 6;
      _diceController.reset();
      _lockController.reset();
      _resultController.reset();
      _roundIntroController.reset();
      _celebrationController.reset();
    }

    setState(() {
      _snapshot = next;
      _selectedChip =
          allowed.contains(_selectedChip)
              ? _selectedChip
              : (allowed.isEmpty ? next.settings.minBet : allowed.first);
      _lastRoundKey = round.roundKey;
      _lastPhase = round.phase;
      _lastVisualPhase = visualPhase;
      _error = null;
      _loading = false;
    });
    _syncDisplayTotals(next);

    if (isNewRound) {
      unawaited(_roundIntroController.forward(from: 0));
    }
    if ((phaseChanged || visualPhaseChanged) && visualPhase == 'locked') {
      unawaited(_lockController.forward(from: 0));
      unawaited(Haptics.warning());
    }
    if (round.phase == 'result' &&
        round.diceOne != null &&
        round.diceTwo != null &&
        _revealedRoundKey != round.roundKey &&
        _animatingRoundKey != round.roundKey) {
      unawaited(_animateResult(round));
    }
  }

  void _updateRollingFaces() {
    if (!_rolling || !mounted) return;
    final frame = (_diceController.value * 18).floor();
    if (frame == _diceFrame) return;
    _diceFrame = frame;
    setState(() {
      _shownDiceOne = 1 + _random.nextInt(6);
      _shownDiceTwo = 1 + _random.nextInt(6);
    });
  }

  Future<void> _animateResult(SevenUpDownRound round) async {
    _pendingDiceResult = round;
    _animatingRoundKey = round.roundKey;
    _diceFrame = -1;
    _resultController.reset();
    _lockController.value = 1;
    setState(() => _rolling = true);
    try {
      await _diceController.forward(from: 0).orCancel;
    } on TickerCanceled {
      return;
    }
    if (!mounted || _pendingDiceResult?.roundKey != round.roundKey) return;
    setState(() {
      _shownDiceOne = round.diceOne!;
      _shownDiceTwo = round.diceTwo!;
      _rolling = false;
      _revealedRoundKey = round.roundKey;
      _animatingRoundKey = null;
    });
    final currentRound = _snapshot?.round;
    if (currentRound != null && _viewerPayout(currentRound) > 0) {
      unawaited(Haptics.success());
      unawaited(_celebrationController.forward(from: 0));
    } else {
      unawaited(Haptics.light());
    }
    await _resultController.forward(from: 0);
  }

  void _tickClock() {
    if (!mounted) return;
    final nextNow = DateTime.now();
    final round = _snapshot?.round;
    if (round == null) {
      setState(() => _now = nextNow);
      return;
    }
    final visualPhase = _visualPhaseFor(round, nextNow);
    final changed = _lastVisualPhase != visualPhase;
    setState(() {
      _now = nextNow;
      _lastVisualPhase = visualPhase;
    });
    if (changed && visualPhase == 'locked') {
      unawaited(_lockController.forward(from: 0));
      unawaited(Haptics.warning());
    }
    _processDueFakeBets();
  }

  void _syncDisplayTotals(SevenUpDownSnapshot snapshot) {
    final round = snapshot.round;
    final isNewRound = _activeFakeRoundKey != round.roundKey;
    final schedule =
        isNewRound
            ? (snapshot.settings.fakeBetsEnabled
                ? _buildFakeBetSchedule(round)
                : const <_ScheduledLuckyBet>[])
            : _scheduledFakeBets;
    final appliedIds = <String>{
      if (!isNewRound) ..._appliedFakeBetIds,
      if (isNewRound)
        for (final event in schedule)
          if (!event.dueAt.isAfter(_now)) event.id,
    };
    final appliedFake = <String, int>{'DOWN': 0, 'SEVEN': 0, 'UP': 0};
    for (final event in schedule) {
      if (appliedIds.contains(event.id)) {
        appliedFake[event.pot] = (appliedFake[event.pot] ?? 0) + event.amount;
      }
    }

    setState(() {
      _activeFakeRoundKey = round.roundKey;
      _scheduledFakeBets = schedule;
      _appliedFakeBetIds = appliedIds;
      _displayTotals = {
        for (final pot in const ['DOWN', 'SEVEN', 'UP'])
          pot:
              snapshot.settings.fakeBetsEnabled
                  ? (round.realTotals[pot] ?? 0) + (appliedFake[pot] ?? 0)
                  : round.totals[pot] ?? 0,
      };
    });
  }

  List<_ScheduledLuckyBet> _buildFakeBetSchedule(SevenUpDownRound round) {
    final startsAt = round.startsAt;
    final locksAt = round.locksAt;
    if (startsAt == null || locksAt == null || !locksAt.isAfter(startsAt)) {
      return const [];
    }
    final durationMs = max(1000, locksAt.difference(startsAt).inMilliseconds);
    final minStep = max(1, _snapshot?.settings.minBet ?? 50);
    final scheduled = <_ScheduledLuckyBet>[];
    for (final pot in const ['DOWN', 'SEVEN', 'UP']) {
      final total = _snapToBetStep(round.fakeTotals[pot] ?? 0, minStep);
      if (total <= 0) continue;
      final random = Random(
        Object.hash(round.roundKey, pot, total, durationMs, minStep),
      );
      final count = _fakeBetChunkCount(total);
      final chunks = _splitFakeBetTotal(total, count, random, minStep);
      final minOffset = min(700, max(0, durationMs - 300));
      final maxOffset = max(minOffset + 1, durationMs - 550);
      for (var index = 0; index < chunks.length; index++) {
        final slotStart =
            minOffset +
            (((maxOffset - minOffset) * index) ~/ max(1, chunks.length));
        final slotEnd = min(
          maxOffset,
          minOffset +
              (((maxOffset - minOffset) * (index + 1)) ~/
                  max(1, chunks.length)),
        );
        final offset = slotStart + random.nextInt(max(1, slotEnd - slotStart));
        scheduled.add(
          _ScheduledLuckyBet(
            id: '${round.roundKey}-$pot-$index-${chunks[index]}',
            pot: pot,
            amount: chunks[index],
            dueAt: startsAt.add(Duration(milliseconds: offset)),
          ),
        );
      }
    }
    scheduled.sort((a, b) => a.dueAt.compareTo(b.dueAt));
    return scheduled;
  }

  int _fakeBetChunkCount(int total) {
    if (total <= 200) return 1;
    if (total <= 1000) return 2;
    if (total <= 3000) return 3;
    if (total <= 8000) return 4;
    return 5;
  }

  List<int> _splitFakeBetTotal(
    int total,
    int count,
    Random random,
    int minStep,
  ) {
    total = _snapToBetStep(total, minStep);
    if (count <= 1) return [total];
    final totalUnits = max(1, total ~/ minStep);
    final minimumUnits = max(1, total <= 500 ? 1 : (total <= 2000 ? 4 : 10));
    count = min(count, max(1, totalUnits ~/ minimumUnits));
    var remainingUnits = totalUnits;
    final chunks = <int>[];
    for (var index = 0; index < count; index++) {
      final left = count - index;
      if (left == 1) {
        chunks.add(remainingUnits * minStep);
        break;
      }
      final reserve = minimumUnits * (left - 1);
      final average = ((remainingUnits - reserve) / left).round();
      final jitter = max(1, average ~/ 3);
      final amountUnits =
          (average + random.nextInt((jitter * 2) + 1) - jitter)
              .clamp(minimumUnits, remainingUnits - reserve)
              .toInt();
      chunks.add(amountUnits * minStep);
      remainingUnits -= amountUnits;
    }
    return chunks.where((value) => value > 0).toList(growable: false);
  }

  int _snapToBetStep(int amount, int step) {
    if (amount <= 0) return 0;
    final safeStep = max(1, step);
    return max(safeStep, (amount / safeStep).round() * safeStep);
  }

  void _processDueFakeBets() {
    final snapshot = _snapshot;
    if (snapshot == null || !snapshot.settings.fakeBetsEnabled) return;
    final due = _scheduledFakeBets
        .where(
          (event) =>
              !_appliedFakeBetIds.contains(event.id) &&
              !event.dueAt.isAfter(_now),
        )
        .toList(growable: false);
    if (due.isEmpty) return;

    setState(() {
      final totals = Map<String, int>.from(_displayTotals);
      final applied = <String>{..._appliedFakeBetIds};
      for (final event in due) {
        applied.add(event.id);
        totals[event.pot] = (totals[event.pot] ?? 0) + event.amount;
      }
      _displayTotals = totals;
      _appliedFakeBetIds = applied;
    });
    for (final event in due) {
      _launchAmbientCoin(event);
    }
  }

  String _visualPhaseFor(SevenUpDownRound round, DateTime now) {
    if (round.phase == 'result' || round.phase == 'cancelled') {
      return round.phase;
    }
    if (round.endsAt != null && !now.isBefore(round.endsAt!)) {
      return 'settling';
    }
    if (round.locksAt != null && !now.isBefore(round.locksAt!)) {
      return 'locked';
    }
    return round.phase;
  }

  String _visualPhase(SevenUpDownRound round) => _visualPhaseFor(round, _now);

  Future<void> _placeBet(
    String pot, {
    required String imagePath,
    required Offset startGlobalPosition,
  }) async {
    final snapshot = _snapshot;
    if (_placingBet ||
        snapshot == null ||
        _visualPhase(snapshot.round) != 'betting' ||
        _countdown(snapshot.round) <= 0) {
      return;
    }
    if (_selectedChip > snapshot.walletBalance) {
      await showRechargeWalletSheet(
        reasonMessage: 'You need more coins to place this Lucky 7 bet.',
      );
      return;
    }

    _feedbackTimer?.cancel();
    setState(() {
      _placingBet = true;
      _betPulsePot = pot;
      _betFeedback = null;
      _error = null;
    });
    unawaited(Haptics.medium());

    try {
      final next = await _api.placeBet(
        pot: pot,
        amount: _selectedChip,
        idempotencyKey: 'sud_${DateTime.now().microsecondsSinceEpoch}_$pot',
      );
      if (!mounted) return;
      _apply(next);
      _launchFlyingCoin(
        pot: pot,
        imagePath: imagePath,
        startGlobalPosition: startGlobalPosition,
      );
      unawaited(Haptics.success());
      setState(() => _betFeedback = '$_selectedChip coins placed on $pot');
      _feedbackTimer = Timer(const Duration(milliseconds: 1800), () {
        if (mounted) setState(() => _betFeedback = null);
      });
    } catch (error) {
      if (mounted) {
        unawaited(Haptics.error());
        setState(() {
          _betPulsePot = null;
          _betFeedback = null;
          _error = error.toString().replaceFirst('Exception: ', '');
        });
      }
    } finally {
      if (mounted) setState(() => _placingBet = false);
    }
  }

  void _launchAmbientCoin(_ScheduledLuckyBet event) {
    final panelBox = _panelKey.currentContext?.findRenderObject() as RenderBox?;
    if (panelBox == null) return;
    final start = panelBox.localToGlobal(
      Offset(
        panelBox.size.width * (.18 + (_random.nextDouble() * .64)),
        panelBox.size.height * (.78 + (_random.nextDouble() * .12)),
      ),
    );
    _launchFlyingCoin(
      pot: event.pot,
      imagePath: _assetForAmount(event.amount),
      startGlobalPosition: start,
    );
  }

  String _assetForAmount(int amount) {
    if (amount >= 5000) return _chipAssets[5000]!;
    if (amount >= 1000) return _chipAssets[1000]!;
    if (amount >= 500) return _chipAssets[500]!;
    if (amount >= 200) return _chipAssets[200]!;
    return _chipAssets[50]!;
  }

  void _launchFlyingCoin({
    required String pot,
    required String imagePath,
    required Offset startGlobalPosition,
  }) {
    final potIndex = const ['DOWN', 'SEVEN', 'UP'].indexOf(pot);
    final panelBox = _panelKey.currentContext?.findRenderObject() as RenderBox?;
    final potBox =
        potIndex < 0
            ? null
            : _potKeys[potIndex].currentContext?.findRenderObject()
                as RenderBox?;
    if (panelBox == null || potBox == null) return;

    final start = panelBox.globalToLocal(startGlobalPosition);
    final potOrigin = panelBox.globalToLocal(potBox.localToGlobal(Offset.zero));
    final id = DateTime.now().microsecondsSinceEpoch + _random.nextInt(999);
    final coin = _FlyingLuckyCoin(
      id: id,
      imagePath: imagePath,
      left: start.dx - 20,
      top: start.dy - 20,
      targetLeft: potOrigin.dx + 12 + _random.nextDouble() * 38,
      targetTop: potOrigin.dy + 68 + _random.nextDouble() * 34,
    );
    setState(() => _flyingCoins = [..._flyingCoins, coin]);
    Future<void>.delayed(const Duration(milliseconds: 16), () {
      if (!mounted) return;
      setState(() {
        _flyingCoins = _flyingCoins
            .map(
              (item) =>
                  item.id == id
                      ? item.copyWith(
                        left: item.targetLeft,
                        top: item.targetTop,
                      )
                      : item,
            )
            .toList(growable: false);
      });
    });
    Future<void>.delayed(const Duration(milliseconds: 620), () {
      if (!mounted) return;
      setState(() {
        _flyingCoins = _flyingCoins
            .where((item) => item.id != id)
            .toList(growable: false);
      });
    });
  }

  int _countdown(SevenUpDownRound round) {
    final target = switch (_visualPhase(round)) {
      'betting' => round.locksAt,
      'locked' || 'settling' => round.endsAt,
      'result' => round.displayUntil,
      _ => null,
    };
    if (target == null) return 0;
    return max(0, target.difference(_now).inSeconds);
  }

  double _phaseProgress(SevenUpDownRound round) {
    final phase = _visualPhase(round);
    final (start, end) = switch (phase) {
      'betting' => (round.startsAt, round.locksAt),
      'locked' || 'settling' => (round.locksAt, round.endsAt),
      'result' => (round.endsAt, round.displayUntil),
      _ => (null, null),
    };
    if (start == null || end == null || !end.isAfter(start)) return 0;
    final total = end.difference(start).inMilliseconds;
    final remaining = end.difference(_now).inMilliseconds;
    return (remaining / total).clamp(0.0, 1.0).toDouble();
  }

  int _viewerAmount(SevenUpDownRound round, String pot) => round.viewerBets
      .where((bet) => bet.pot == pot && bet.status != 'refunded')
      .fold(0, (sum, bet) => sum + bet.amount);

  int _viewerPayout(SevenUpDownRound round) => round.viewerBets
      .where((bet) => bet.status == 'won')
      .fold(0, (sum, bet) => sum + bet.payoutCoins);

  @override
  void dispose() {
    _clock?.cancel();
    _feedbackTimer?.cancel();
    _snapshotSub?.cancel();
    _eventSub?.cancel();
    _idleController.dispose();
    _diceController.dispose();
    _lockController.dispose();
    _resultController.dispose();
    _roundIntroController.dispose();
    _celebrationController.dispose();
    _socket.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const ColoredBox(
        color: Color(0xFF090B22),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    final snapshot = _snapshot;
    if (snapshot == null) return _message(_error ?? 'Unable to load game.');
    final round = snapshot.round;
    final revealed = _revealedRoundKey == round.roundKey && !_rolling;
    final won = _viewerPayout(round);
    final chips = _chips.where(
      (chip) =>
          chip >= snapshot.settings.minBet && chip <= snapshot.settings.maxBet,
    );

    return LayoutBuilder(
      builder: (context, constraints) {
        final compactHeight = constraints.maxHeight < 720;
        final tightHeight = constraints.maxHeight < 640;
        final sectionGap = compactHeight ? 8.0 : 14.0;
        return Stack(
          key: _panelKey,
          clipBehavior: Clip.hardEdge,
          children: [
            Positioned.fill(
              child: Image.asset(
                'assets/games/seven_up_down/table_background.png',
                fit: BoxFit.cover,
                alignment: Alignment.topCenter,
              ),
            ),
            const Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [Color(0x33000000), Color(0xAA07091E)],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    stops: [0.2, 1],
                  ),
                ),
              ),
            ),
            Positioned.fill(
              child: IgnorePointer(
                child: RepaintBoundary(
                  child: AnimatedBuilder(
                    animation: _idleController,
                    builder:
                        (context, _) => CustomPaint(
                          painter: _AmbientParticlePainter(
                            _idleController.value,
                          ),
                        ),
                  ),
                ),
              ),
            ),
            if (revealed && won > 0)
              Positioned.fill(
                child: IgnorePointer(
                  child: RepaintBoundary(
                    child: AnimatedBuilder(
                      animation: _celebrationController,
                      builder:
                          (context, _) => CustomPaint(
                            painter: _CelebrationPainter(
                              progress: _celebrationController.value,
                              color:
                                  _potColors[round.winningPot] ?? Colors.white,
                            ),
                          ),
                    ),
                  ),
                ),
              ),
            AnimatedBuilder(
              animation: _roundIntroController,
              builder: (context, child) {
                final progress = Curves.easeOutCubic.transform(
                  _roundIntroController.value,
                );
                return Opacity(
                  opacity: .25 + (.75 * progress),
                  child: Transform.translate(
                    offset: Offset(0, 18 * (1 - progress)),
                    child: child,
                  ),
                );
              },
              child: SafeArea(
                top: false,
                child: ListView(
                  padding: EdgeInsets.fromLTRB(
                    compactHeight ? 12 : 14,
                    compactHeight ? 4 : 8,
                    compactHeight ? 12 : 14,
                    compactHeight ? 16 : 28,
                  ),
                  children: [
                    _header(snapshot.walletBalance, compact: compactHeight),
                    SizedBox(height: compactHeight ? 5 : 8),
                    _phaseBanner(round, revealed),
                    SizedBox(height: compactHeight ? 6 : 10),
                    _diceStage(round, revealed, compact: compactHeight),
                    if (revealed) ...[
                      SizedBox(height: compactHeight ? 6 : 8),
                      _resultBanner(round),
                    ],
                    SizedBox(height: sectionGap),
                    _bettingArena(round, revealed, compact: compactHeight),
                    SizedBox(height: sectionGap),
                    _chipTray(chips, round, compact: compactHeight),
                    if (_betFeedback != null)
                      Padding(
                        padding: EdgeInsets.only(top: compactHeight ? 6 : 10),
                        child: Text(
                          _betFeedback!,
                          textAlign: TextAlign.center,
                          style: const TextStyle(
                            color: Color(0xFF83F3BB),
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                    if (_error != null)
                      Padding(
                        padding: EdgeInsets.only(top: compactHeight ? 6 : 10),
                        child: Text(
                          _error!,
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: Color(0xFFFF9B9B)),
                        ),
                      ),
                    if (snapshot.history.isNotEmpty) ...[
                      SizedBox(
                        height: tightHeight ? 8 : (compactHeight ? 10 : 18),
                      ),
                      _history(snapshot.history, compact: compactHeight),
                    ],
                  ],
                ),
              ),
            ),
            ..._flyingCoins.map(
              (coin) => AnimatedPositioned(
                key: ValueKey(coin.id),
                duration: const Duration(milliseconds: 600),
                curve: Curves.easeOutBack,
                left: coin.left,
                top: coin.top,
                child: IgnorePointer(
                  child: Image.asset(
                    coin.imagePath,
                    width: 42,
                    height: 42,
                    filterQuality: FilterQuality.high,
                  ),
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _header(int walletBalance, {required bool compact}) => SizedBox(
    height: compact ? 56 : 68,
    child: Stack(
      alignment: Alignment.center,
      children: [
        Image.asset(
          'assets/games/seven_up_down/lucky_7_logo.png',
          width: compact ? 148 : 176,
          height: compact ? 54 : 66,
          fit: BoxFit.contain,
          filterQuality: FilterQuality.high,
        ),
        Align(
          alignment: Alignment.centerRight,
          child: _pill(
            '$walletBalance',
            const Color(0xFFFFD45E),
            Icons.paid_rounded,
          ),
        ),
      ],
    ),
  );

  Widget _phaseBanner(SevenUpDownRound round, bool revealed) {
    final countdown = _countdown(round);
    final phase = _visualPhase(round);
    final (label, color, icon) = switch (phase) {
      'betting' => (
        'PLACE YOUR BET · ${countdown}s',
        const Color(0xFF72F1C4),
        Icons.touch_app_rounded,
      ),
      'locked' => (
        'BETS LOCKED · ${countdown}s',
        const Color(0xFFFFD45E),
        Icons.lock_rounded,
      ),
      'settling' => (
        'RESULT INCOMING',
        const Color(0xFFFFD45E),
        Icons.hourglass_top_rounded,
      ),
      'result' when _rolling => (
        'DICE ROLLING…',
        const Color(0xFFFFD45E),
        Icons.casino_rounded,
      ),
      'result' when revealed => (
        'RESULT · ${round.diceTotal} · ${countdown}s',
        _potColors[round.winningPot] ?? Colors.white,
        Icons.emoji_events_rounded,
      ),
      _ => ('WAITING FOR ROUND', Colors.white70, Icons.schedule_rounded),
    };
    final urgent = phase == 'betting' && countdown <= 5;
    return AnimatedBuilder(
      animation: _idleController,
      builder: (context, _) {
        final pulse =
            urgent
                ? .92 + (.08 * sin(_idleController.value * pi * 4).abs())
                : 1.0;
        return Center(
          child: Transform.scale(
            scale: pulse,
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 260),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              decoration: BoxDecoration(
                color: color.withValues(alpha: urgent ? .2 : .12),
                border: Border.all(color: color.withValues(alpha: .65)),
                borderRadius: BorderRadius.circular(30),
                boxShadow: [
                  BoxShadow(
                    color: color.withValues(alpha: urgent ? .28 : .12),
                    blurRadius: urgent ? 22 : 16,
                  ),
                ],
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  SizedBox(
                    width: 20,
                    height: 20,
                    child: Stack(
                      alignment: Alignment.center,
                      children: [
                        CircularProgressIndicator(
                          value: _phaseProgress(round),
                          strokeWidth: 2.2,
                          color: color,
                          backgroundColor: Colors.white12,
                        ),
                        Icon(icon, color: color, size: 11),
                      ],
                    ),
                  ),
                  const SizedBox(width: 7),
                  Text(
                    label,
                    style: TextStyle(
                      color: color,
                      fontSize: 12,
                      fontWeight: FontWeight.w900,
                      letterSpacing: .5,
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _diceStage(
    SevenUpDownRound round,
    bool revealed, {
    required bool compact,
  }) {
    return AnimatedBuilder(
      animation: Listenable.merge([
        _idleController,
        _diceController,
        _lockController,
        _resultController,
      ]),
      builder: (context, _) {
        final phase = _visualPhase(round);
        final roll = Curves.easeInOutCubic.transform(_diceController.value);
        final idle = sin(_idleController.value * pi * 2);
        final lock = Curves.easeOutBack.transform(_lockController.value);
        final resultPulse = sin(_resultController.value * pi);
        final diceScale = 1 + (resultPulse * .12);
        final stageHeight = compact ? 136.0 : 170.0;
        final dieSize = compact ? 66.0 : 82.0;
        return SizedBox(
          height: stageHeight,
          child: Stack(
            alignment: Alignment.center,
            children: [
              Positioned(
                bottom: 17,
                child: Container(
                  width: 230,
                  height: 34,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(50),
                    boxShadow: const [
                      BoxShadow(
                        color: Color(0xAA050617),
                        blurRadius: 24,
                        spreadRadius: 7,
                      ),
                    ],
                  ),
                ),
              ),
              Transform.translate(
                offset: Offset(
                  phase == 'settling' ? idle * 5 : 0,
                  _rolling ? -40 * sin(roll * pi) : idle * 2,
                ),
                child: Transform.scale(
                  scale: diceScale,
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      _animatedDie(_shownDiceOne, roll, idle, false, dieSize),
                      SizedBox(width: compact ? 17 : 22),
                      _animatedDie(_shownDiceTwo, roll, -idle, true, dieSize),
                    ],
                  ),
                ),
              ),
              if (phase == 'locked' || phase == 'settling')
                Opacity(
                  opacity: lock.clamp(0, 1),
                  child: Transform.scale(
                    scale: .7 + (.3 * lock),
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 15,
                        vertical: 8,
                      ),
                      decoration: BoxDecoration(
                        color: const Color(0xDD11132B),
                        border: Border.all(color: const Color(0xFFFFD45E)),
                        borderRadius: BorderRadius.circular(30),
                        boxShadow: const [
                          BoxShadow(color: Color(0x88FFD45E), blurRadius: 20),
                        ],
                      ),
                      child: const Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.lock_rounded,
                            size: 16,
                            color: Color(0xFFFFD45E),
                          ),
                          SizedBox(width: 6),
                          Text(
                            'LOCKED',
                            style: TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w900,
                              letterSpacing: 1.4,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              if (_rolling)
                Positioned(
                  bottom: 1,
                  child: Text(
                    'ROLLING BACKEND RESULT',
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: .7),
                      fontSize: 9,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 1.1,
                    ),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }

  Widget _animatedDie(
    int value,
    double roll,
    double idle,
    bool second,
    double size,
  ) {
    final direction = second ? -1.0 : 1.0;
    final spinning = _rolling;
    final settling = _lastVisualPhase == 'settling';
    final rotation =
        spinning ? direction * roll * pi * 7 : idle * (settling ? .13 : .035);
    final tilt =
        spinning ? roll * pi * 5 : -.06 + (idle * (settling ? .07 : .025));
    return Transform(
      alignment: Alignment.center,
      transform:
          Matrix4.identity()
            ..setEntry(3, 2, .0018)
            ..rotateX(tilt)
            ..rotateY(rotation * .8)
            ..rotateZ(rotation),
      child: _ThreeDDice(value: value, size: size),
    );
  }

  Widget _resultBanner(SevenUpDownRound round) {
    final won = _viewerPayout(round);
    final userHadBet = round.viewerBets.any((bet) => bet.status != 'refunded');
    final color = _potColors[round.winningPot] ?? Colors.white;
    return FadeTransition(
      opacity: CurvedAnimation(
        parent: _resultController,
        curve: Curves.easeOut,
      ),
      child: ScaleTransition(
        scale: Tween(begin: .88, end: 1.0).animate(
          CurvedAnimation(parent: _resultController, curve: Curves.easeOutBack),
        ),
        child: AnimatedBuilder(
          animation: Listenable.merge([
            _idleController,
            _celebrationController,
          ]),
          builder: (context, _) {
            final shine = sin(_idleController.value * pi * 2).abs();
            return Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [
                    color.withValues(alpha: won > 0 ? .42 : .26),
                    const Color(0xEE11132B),
                    const Color(0xDD07091E),
                  ],
                ),
                border: Border.all(
                  color: color.withValues(alpha: .75 + (.2 * shine)),
                  width: won > 0 ? 1.8 : 1.2,
                ),
                borderRadius: BorderRadius.circular(18),
                boxShadow: [
                  BoxShadow(
                    color: color.withValues(alpha: won > 0 ? .36 : .18),
                    blurRadius: won > 0 ? 26 + (shine * 10) : 18,
                    spreadRadius: won > 0 ? 1 : 0,
                  ),
                ],
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Container(
                    width: 34,
                    height: 34,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: color.withValues(alpha: .16),
                      border: Border.all(color: color.withValues(alpha: .7)),
                    ),
                    child: Icon(
                      won > 0
                          ? Icons.emoji_events_rounded
                          : Icons.casino_rounded,
                      color: color,
                      size: 19,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Flexible(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          won > 0
                              ? 'YOU WON $won COINS'
                              : userHadBet
                              ? 'BETTER LUCK NEXT ROUND'
                              : '${round.winningPot} WINS',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 13,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          '${round.winningPot} · ${round.diceOne} + ${round.diceTwo} = ${round.diceTotal}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: color,
                            fontSize: 10,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  Widget _bettingArena(
    SevenUpDownRound round,
    bool revealed, {
    required bool compact,
  }) {
    return Container(
      padding: EdgeInsets.fromLTRB(9, compact ? 8 : 11, 9, compact ? 9 : 12),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Color(0xD4141834), Color(0xEE07091C)],
        ),
        border: Border.all(color: const Color(0x66FFD45E), width: 1.1),
        borderRadius: BorderRadius.circular(22),
        boxShadow: const [
          BoxShadow(
            color: Color(0x99000000),
            blurRadius: 24,
            offset: Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        children: [
          AnimatedBuilder(
            animation: _idleController,
            builder:
                (context, _) => SizedBox(
                  height: 18,
                  child: CustomPaint(
                    painter: _ArenaRailPainter(_idleController.value),
                    child: const SizedBox.expand(),
                  ),
                ),
          ),
          SizedBox(height: compact ? 3 : 6),
          Row(
            children: [
              Expanded(
                child: _pot(
                  _potKeys[0],
                  round,
                  'DOWN',
                  '7 DOWN',
                  'TOTAL 2 - 6',
                  revealed,
                ),
              ),
              const SizedBox(width: 7),
              Expanded(
                child: _pot(
                  _potKeys[1],
                  round,
                  'SEVEN',
                  'EXACT 7',
                  'TOTAL 7',
                  revealed,
                ),
              ),
              const SizedBox(width: 7),
              Expanded(
                child: _pot(
                  _potKeys[2],
                  round,
                  'UP',
                  '7 UP',
                  'TOTAL 8 - 12',
                  revealed,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _pot(
    GlobalKey potKey,
    SevenUpDownRound round,
    String pot,
    String title,
    String range,
    bool revealed,
  ) {
    final color = _potColors[pot]!;
    final winner = revealed && round.winningPot == pot;
    final selected = _selectedPot == pot;
    final bettingOpen =
        _visualPhase(round) == 'betting' &&
        _countdown(round) > 0 &&
        !_placingBet;
    final yourAmount = _viewerAmount(round, pot);
    final pulsing = _betPulsePot == pot && _placingBet;
    return AnimatedBuilder(
      animation: Listenable.merge([_idleController, _resultController]),
      builder: (context, _) {
        final glow =
            winner
                ? .72 + (.28 * sin(_idleController.value * pi * 4).abs())
                : selected
                ? .45 + (.15 * sin(_idleController.value * pi * 2).abs())
                : .0;
        return GestureDetector(
          key: potKey,
          onTap:
              bettingOpen
                  ? () {
                    unawaited(Haptics.selection());
                    setState(() => _selectedPot = selected ? null : pot);
                  }
                  : null,
          child: AnimatedScale(
            scale:
                pulsing
                    ? .96
                    : winner
                    ? 1.04
                    : selected
                    ? 1.015
                    : 1,
            duration: const Duration(milliseconds: 180),
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 280),
              padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 10),
              decoration: BoxDecoration(
                gradient: RadialGradient(
                  center: Alignment.topCenter,
                  radius: 1.12,
                  colors:
                      winner
                          ? [
                            color.withValues(alpha: .58),
                            color.withValues(alpha: .2),
                            const Color(0xEE090B22),
                          ]
                          : selected
                          ? [
                            color.withValues(alpha: .28),
                            const Color(0xDD11142E),
                            const Color(0xF0060819),
                          ]
                          : [
                            Colors.white.withValues(alpha: .1),
                            const Color(0xDD11142E),
                            const Color(0xEE060817),
                          ],
                ),
                border: Border.all(
                  color:
                      winner || selected ? color : color.withValues(alpha: .42),
                  width: winner ? 2.4 : (selected ? 1.8 : 1),
                ),
                borderRadius: BorderRadius.circular(18),
                boxShadow: [
                  BoxShadow(
                    color: color.withValues(alpha: .12 + (.32 * glow)),
                    blurRadius: 12 + (22 * glow),
                    spreadRadius: winner ? 1.5 : 0,
                  ),
                ],
              ),
              child: Column(
                children: [
                  Container(
                    height: 4,
                    width: 34,
                    decoration: BoxDecoration(
                      color: color.withValues(
                        alpha: winner || selected ? .95 : .45,
                      ),
                      borderRadius: BorderRadius.circular(20),
                    ),
                  ),
                  const SizedBox(height: 7),
                  Text(
                    title,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: color,
                      fontWeight: FontWeight.w900,
                      fontSize: 13,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    range,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: Colors.white60,
                      fontSize: 8,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 7),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 9,
                      vertical: 3,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: .25),
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(color: color.withValues(alpha: .34)),
                    ),
                    child: Text(
                      '${round.multipliers[pot] ?? 0}x',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 19,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
                  const SizedBox(height: 6),
                  _potCoinStack(_displayTotals[pot] ?? 0, pot),
                  const SizedBox(height: 5),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(
                        Icons.paid_rounded,
                        color: Color(0xFFFFD45E),
                        size: 12,
                      ),
                      const SizedBox(width: 3),
                      Flexible(
                        child: Text(
                          '${_displayTotals[pot] ?? 0}',
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: Colors.white70,
                            fontSize: 10,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                    ],
                  ),
                  if (yourAmount > 0) ...[
                    const SizedBox(height: 5),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 6,
                        vertical: 3,
                      ),
                      decoration: BoxDecoration(
                        color: color.withValues(alpha: .18),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: color.withValues(alpha: .3)),
                      ),
                      child: Text(
                        'YOU $yourAmount',
                        style: TextStyle(
                          color: color,
                          fontSize: 9,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ),
                  ],
                  if (selected && bettingOpen) ...[
                    const SizedBox(height: 5),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 7,
                        vertical: 3,
                      ),
                      decoration: BoxDecoration(
                        color: color.withValues(alpha: .16),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        'SELECTED',
                        style: TextStyle(
                          color: color,
                          fontSize: 8,
                          fontWeight: FontWeight.w900,
                          letterSpacing: .7,
                        ),
                      ),
                    ),
                  ],
                  if (!bettingOpen && !winner) ...[
                    const SizedBox(height: 5),
                    const Icon(
                      Icons.lock_outline_rounded,
                      color: Colors.white38,
                      size: 13,
                    ),
                  ],
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _chipTray(
    Iterable<int> chips,
    SevenUpDownRound round, {
    required bool compact,
  }) {
    final enabled = _visualPhase(round) == 'betting' && _countdown(round) > 0;
    return AnimatedOpacity(
      opacity: enabled ? 1 : .5,
      duration: const Duration(milliseconds: 220),
      child: Container(
        padding: EdgeInsets.fromLTRB(10, compact ? 7 : 9, 10, compact ? 7 : 10),
        decoration: BoxDecoration(
          color: const Color(0xCC090B21),
          border: Border.all(color: Colors.white.withValues(alpha: .12)),
          borderRadius: BorderRadius.circular(19),
        ),
        child: Column(
          children: [
            Text(
              enabled
                  ? (_selectedPot == null
                      ? 'SELECT A POT FIRST'
                      : '$_selectedPot SELECTED · TAP A COIN TO BET')
                  : 'CHIPS LOCKED FOR THIS ROUND',
              style: const TextStyle(
                color: Colors.white60,
                fontSize: 9,
                fontWeight: FontWeight.w800,
                letterSpacing: 1,
              ),
            ),
            SizedBox(height: compact ? 5 : 8),
            Row(
              key: _chipRowKey,
              mainAxisAlignment: MainAxisAlignment.spaceEvenly,
              children: [
                for (final chip in chips)
                  GestureDetector(
                    onTapDown:
                        !enabled || _placingBet
                            ? null
                            : (details) {
                              unawaited(Haptics.selection());
                              setState(() => _selectedChip = chip);
                              final pot = _selectedPot;
                              if (pot != null) {
                                unawaited(
                                  _placeBet(
                                    pot,
                                    imagePath: _chipAssets[chip]!,
                                    startGlobalPosition: details.globalPosition,
                                  ),
                                );
                              }
                            },
                    child: AnimatedScale(
                      scale: chip == _selectedChip ? 1.12 : .94,
                      duration: const Duration(milliseconds: 180),
                      child: _GameChip(
                        value: chip,
                        selected: chip == _selectedChip,
                        imagePath: _chipAssets[chip]!,
                        compact: compact,
                      ),
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _potCoinStack(int total, String pot) {
    final count =
        total <= 0 ? 0 : min(5, 2 + (log(total + 1) / log(9)).floor());
    return SizedBox(
      height: 31,
      child:
          count == 0
              ? Icon(
                Icons.add_circle_outline_rounded,
                color: Colors.white.withValues(alpha: .22),
                size: 22,
              )
              : Stack(
                alignment: Alignment.center,
                children: [
                  for (var index = 0; index < count; index++)
                    Transform.translate(
                      offset: Offset(
                        (index - ((count - 1) / 2)) * 9,
                        index.isEven ? 0 : 3,
                      ),
                      child: Transform.rotate(
                        angle: (index.isEven ? -1 : 1) * (.07 + index * .015),
                        child: Image.asset(
                          _assetForStackIndex(total, pot, index),
                          width: 29,
                          height: 29,
                          filterQuality: FilterQuality.high,
                        ),
                      ),
                    ),
                ],
              ),
    );
  }

  String _assetForStackIndex(int total, String pot, int index) {
    final rotation = switch (pot) {
      'DOWN' => [50, 500, 200, 1000, 5000],
      'SEVEN' => [500, 50, 1000, 200, 5000],
      _ => [200, 1000, 50, 5000, 500],
    };
    if (total >= 5000 && index == rotation.length - 1) {
      return _chipAssets[5000]!;
    }
    return _chipAssets[rotation[index % rotation.length]]!;
  }

  Widget _history(List<SevenUpDownRound> rounds, {required bool compact}) {
    final visibleRounds = rounds.take(compact ? 6 : 8);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'RECENT RESULTS',
          style: TextStyle(
            color: Colors.white60,
            fontSize: 9,
            fontWeight: FontWeight.w800,
            letterSpacing: 1,
          ),
        ),
        const SizedBox(height: 7),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              for (final item in visibleRounds)
                Container(
                  margin: const EdgeInsets.only(right: 7),
                  padding: const EdgeInsets.symmetric(
                    horizontal: 9,
                    vertical: 6,
                  ),
                  decoration: BoxDecoration(
                    color: (_potColors[item.winningPot] ?? Colors.white)
                        .withValues(alpha: .12),
                    border: Border.all(
                      color: (_potColors[item.winningPot] ?? Colors.white)
                          .withValues(alpha: .35),
                    ),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text(
                    '${item.diceOne ?? '—'} + ${item.diceTwo ?? '—'} = ${item.diceTotal ?? '—'} · ${item.winningPot ?? '—'}',
                    style: const TextStyle(
                      color: Colors.white70,
                      fontSize: 10,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _pill(String text, Color color, IconData icon) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 7),
    decoration: BoxDecoration(
      color: const Color(0xDD11132B),
      border: Border.all(color: color.withValues(alpha: .6)),
      borderRadius: BorderRadius.circular(30),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, color: color, size: 15),
        const SizedBox(width: 4),
        Text(
          text,
          style: const TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.w900,
          ),
        ),
      ],
    ),
  );

  Widget _message(String value) => ColoredBox(
    color: const Color(0xFF090B22),
    child: Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          value,
          textAlign: TextAlign.center,
          style: const TextStyle(color: Colors.white),
        ),
      ),
    ),
  );
}

class _ScheduledLuckyBet {
  const _ScheduledLuckyBet({
    required this.id,
    required this.pot,
    required this.amount,
    required this.dueAt,
  });

  final String id;
  final String pot;
  final int amount;
  final DateTime dueAt;
}

class _FlyingLuckyCoin {
  const _FlyingLuckyCoin({
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

  _FlyingLuckyCoin copyWith({double? left, double? top}) => _FlyingLuckyCoin(
    id: id,
    imagePath: imagePath,
    left: left ?? this.left,
    top: top ?? this.top,
    targetLeft: targetLeft,
    targetTop: targetTop,
  );
}

class _GameChip extends StatelessWidget {
  const _GameChip({
    required this.value,
    required this.selected,
    required this.imagePath,
    this.compact = false,
    this.large = false,
  });

  final int value;
  final bool selected;
  final String imagePath;
  final bool compact;
  final bool large;

  @override
  Widget build(BuildContext context) {
    final size =
        large
            ? 50.0
            : compact
            ? 37.0
            : 43.0;
    return SizedBox(
      width: size,
      height: size + (compact ? 11 : 14),
      child: Stack(
        clipBehavior: Clip.none,
        alignment: Alignment.topCenter,
        children: [
          DecoratedBox(
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              boxShadow:
                  selected
                      ? const [
                        BoxShadow(color: Color(0xAAFFD45E), blurRadius: 14),
                      ]
                      : const [
                        BoxShadow(color: Color(0x55000000), blurRadius: 8),
                      ],
            ),
            child: Image.asset(
              imagePath,
              width: size,
              height: size,
              filterQuality: FilterQuality.high,
            ),
          ),
          Positioned(
            bottom: 0,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
              decoration: BoxDecoration(
                color:
                    selected
                        ? const Color(0xFFFFD45E)
                        : const Color(0xDD17152D),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: Colors.white.withValues(alpha: .65)),
              ),
              child: Text(
                _compact(value),
                style: TextStyle(
                  color: selected ? const Color(0xFF2E1800) : Colors.white,
                  fontSize: compact ? 8 : (value >= 1000 ? 9 : 10),
                  fontWeight: FontWeight.w900,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  static String _compact(int value) =>
      value >= 1000 && value % 1000 == 0 ? '${value ~/ 1000}K' : '$value';
}

class _ArenaRailPainter extends CustomPainter {
  const _ArenaRailPainter(this.progress);

  final double progress;

  @override
  void paint(Canvas canvas, Size size) {
    final centerY = size.height * .55;
    final railPaint =
        Paint()
          ..strokeWidth = 1.2
          ..strokeCap = StrokeCap.round
          ..shader = const LinearGradient(
            colors: [Color(0x00FFD45E), Color(0xAAFFD45E), Color(0x00FFD45E)],
          ).createShader(Offset.zero & size);
    canvas.drawLine(
      Offset(8, centerY),
      Offset(size.width - 8, centerY),
      railPaint,
    );

    for (var index = 0; index < 3; index++) {
      final x = size.width * (.18 + index * .32);
      final pulse = .45 + (.35 * sin((progress * pi * 2) + index).abs());
      canvas.drawCircle(
        Offset(x, centerY),
        2.2,
        Paint()
          ..color = const Color(0xFFFFD45E).withValues(alpha: pulse)
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 2.5),
      );
    }
  }

  @override
  bool shouldRepaint(covariant _ArenaRailPainter oldDelegate) =>
      oldDelegate.progress != progress;
}

class _ThreeDDice extends StatelessWidget {
  const _ThreeDDice({required this.value, required this.size});

  final int value;
  final double size;

  @override
  Widget build(BuildContext context) => RepaintBoundary(
    child: CustomPaint(
      size: Size(size, size + 9),
      painter: _DicePainter(value),
    ),
  );
}

class _DicePainter extends CustomPainter {
  const _DicePainter(this.value);

  final int value;

  @override
  void paint(Canvas canvas, Size size) {
    final front = RRect.fromRectAndRadius(
      Rect.fromLTRB(4, 10, size.width - 11, size.height - 3),
      const Radius.circular(15),
    );
    final shadow =
        Paint()
          ..color = const Color(0x77000000)
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 8);
    canvas.drawRRect(front.shift(const Offset(3, 5)), shadow);

    final top =
        Path()
          ..moveTo(8, 10)
          ..lineTo(17, 2)
          ..lineTo(size.width - 3, 2)
          ..lineTo(size.width - 11, 10)
          ..close();
    canvas.drawPath(
      top,
      Paint()
        ..shader = const LinearGradient(
          colors: [Color(0xFFFFFFFF), Color(0xFFBFC5DE)],
        ).createShader(Offset.zero & size),
    );
    final side =
        Path()
          ..moveTo(size.width - 11, 10)
          ..lineTo(size.width - 3, 2)
          ..lineTo(size.width - 3, size.height - 12)
          ..lineTo(size.width - 11, size.height - 3)
          ..close();
    canvas.drawPath(
      side,
      Paint()
        ..shader = const LinearGradient(
          colors: [Color(0xFFCED3E7), Color(0xFF888FAA)],
        ).createShader(Offset.zero & size),
    );
    canvas.drawRRect(
      front,
      Paint()
        ..shader = const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Colors.white, Color(0xFFE8EBF5), Color(0xFFC9CEE1)],
        ).createShader(front.outerRect),
    );
    canvas.drawRRect(
      front,
      Paint()
        ..color = const Color(0x669A7AD4)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.3,
    );

    final positions =
        <int, List<Offset>>{
          1: const [Offset(.5, .5)],
          2: const [Offset(.28, .28), Offset(.72, .72)],
          3: const [Offset(.28, .28), Offset(.5, .5), Offset(.72, .72)],
          4: const [
            Offset(.28, .28),
            Offset(.72, .28),
            Offset(.28, .72),
            Offset(.72, .72),
          ],
          5: const [
            Offset(.28, .28),
            Offset(.72, .28),
            Offset(.5, .5),
            Offset(.28, .72),
            Offset(.72, .72),
          ],
          6: const [
            Offset(.28, .25),
            Offset(.72, .25),
            Offset(.28, .5),
            Offset(.72, .5),
            Offset(.28, .75),
            Offset(.72, .75),
          ],
        }[value.clamp(1, 6)]!;
    final pipPaint =
        Paint()
          ..shader = const RadialGradient(
            colors: [Color(0xFF6A35AA), Color(0xFF25123E)],
          ).createShader(front.outerRect);
    for (final point in positions) {
      canvas.drawCircle(
        Offset(
          front.left + (front.width * point.dx),
          front.top + (front.height * point.dy),
        ),
        size.width * .065,
        pipPaint,
      );
    }
  }

  @override
  bool shouldRepaint(covariant _DicePainter oldDelegate) =>
      oldDelegate.value != value;
}

class _AmbientParticlePainter extends CustomPainter {
  const _AmbientParticlePainter(this.progress);

  final double progress;

  @override
  void paint(Canvas canvas, Size size) {
    for (var index = 0; index < 14; index++) {
      final seed = index + 1.0;
      final x = (.08 + ((sin(seed * 9.73) + 1) * .42)) * size.width;
      final baseY = ((cos(seed * 5.17) + 1) * .5) * size.height;
      final y = (baseY - (progress * (28 + (index % 4) * 11))) % size.height;
      final shimmer = .25 + (.35 * sin((progress * pi * 2) + seed).abs());
      final color =
          index.isEven ? const Color(0xFFB985FF) : const Color(0xFFFFD45E);
      canvas.drawCircle(
        Offset(x, y),
        1.1 + (index % 3) * .55,
        Paint()
          ..color = color.withValues(alpha: shimmer)
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 2.5),
      );
    }
  }

  @override
  bool shouldRepaint(covariant _AmbientParticlePainter oldDelegate) =>
      oldDelegate.progress != progress;
}

class _CelebrationPainter extends CustomPainter {
  const _CelebrationPainter({required this.progress, required this.color});

  final double progress;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    if (progress <= 0 || progress >= 1) return;
    final eased = Curves.easeOutCubic.transform(progress);
    final fade =
        (1 - Curves.easeIn.transform(progress)).clamp(0.0, 1.0).toDouble();
    final origin = Offset(size.width * .5, min(size.height * .32, 230.0));
    for (var index = 0; index < 24; index++) {
      final angle = (-pi * .92) + ((pi * 1.84 / 23) * index);
      final distance = (70 + (index % 6) * 18) * eased;
      final gravity = 95 * progress * progress;
      final point = Offset(
        origin.dx + (cos(angle) * distance),
        origin.dy + (sin(angle) * distance) + gravity,
      );
      final particleColor = switch (index % 3) {
        0 => color,
        1 => const Color(0xFFFFD45E),
        _ => const Color(0xFFFFFFFF),
      };
      final paint = Paint()..color = particleColor.withValues(alpha: fade);
      if (index.isEven) {
        canvas.drawCircle(point, 2.5 + (index % 4), paint);
      } else {
        canvas.save();
        canvas.translate(point.dx, point.dy);
        canvas.rotate(progress * pi * 4 + index);
        canvas.drawRRect(
          RRect.fromRectAndRadius(
            const Rect.fromLTWH(-2.5, -5, 5, 10),
            const Radius.circular(1.5),
          ),
          paint,
        );
        canvas.restore();
      }
    }
  }

  @override
  bool shouldRepaint(covariant _CelebrationPainter oldDelegate) =>
      oldDelegate.progress != progress || oldDelegate.color != color;
}
