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
  String? _activeFakeRoundKey;
  Map<String, int> _displayTotals = const {'A': 0, 'B': 0, 'C': 0};
  List<_ScheduledFakeBet> _scheduledFakeBets = const [];
  Set<String> _appliedFakeBetIds = <String>{};
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
    _ticker ??= Timer.periodic(const Duration(milliseconds: 300), (_) {
      if (!mounted) return;
      setState(() => _now = DateTime.now());
      _processDueFakeBets();
      _maybeRefreshForPhaseBoundary();
    });

    _snapshotSub = _socket.snapshotEvents.listen((payload) {
      final data =
          payload['data'] is Map
              ? Map<String, dynamic>.from(payload['data'] as Map)
              : Map<String, dynamic>.from(payload);
      if (data.isEmpty || !mounted) return;
      final next = TeenPattiSnapshot.fromJson(data);
      final current = _snapshot;
      if (current != null && !data.containsKey('wallet_balance')) {
        _applySnapshot(
          TeenPattiSnapshot(
            settings: next.settings,
            walletBalance: current.walletBalance,
            round: next.round,
            history: next.history,
          ),
        );
        return;
      }
      _applySnapshot(next);
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

    _syncDisplayTotals(next);
    _animateRemoteBetDeltas(previous, next.round);
    _showResultDialogIfNeeded(next.round);
  }

  void _syncDisplayTotals(TeenPattiSnapshot snapshot) {
    final round = snapshot.round;
    final isNewRound = _activeFakeRoundKey != round.roundKey;
    final nextFakeEvents =
        snapshot.settings.fakeBetsEnabled
            ? _buildFakeBetSchedule(round)
            : const <_ScheduledFakeBet>[];

    final appliedIds = <String>{
      if (!isNewRound) ..._appliedFakeBetIds,
      for (final event in nextFakeEvents)
        if (!event.dueAt.isAfter(_now)) event.id,
    };

    final appliedFakeByPot = <String, int>{'A': 0, 'B': 0, 'C': 0};
    for (final event in nextFakeEvents) {
      if (!appliedIds.contains(event.id)) continue;
      appliedFakeByPot[event.pot] = (appliedFakeByPot[event.pot] ?? 0) + event.amount;
    }

    final nextDisplayTotals =
        snapshot.settings.fakeBetsEnabled
            ? <String, int>{
              'A': (round.realTotals['A'] ?? 0) + (appliedFakeByPot['A'] ?? 0),
              'B': (round.realTotals['B'] ?? 0) + (appliedFakeByPot['B'] ?? 0),
              'C': (round.realTotals['C'] ?? 0) + (appliedFakeByPot['C'] ?? 0),
            }
            : <String, int>{
              'A': round.totals['A'] ?? 0,
              'B': round.totals['B'] ?? 0,
              'C': round.totals['C'] ?? 0,
            };

    setState(() {
      _activeFakeRoundKey = round.roundKey;
      _scheduledFakeBets = nextFakeEvents;
      _appliedFakeBetIds = appliedIds;
      _displayTotals = nextDisplayTotals;
    });
  }

  List<_ScheduledFakeBet> _buildFakeBetSchedule(TeenPattiRound round) {
    final startsAt = round.startsAt;
    final locksAt = round.locksAt;
    if (startsAt == null || locksAt == null || !locksAt.isAfter(startsAt)) {
      return const [];
    }

    final durationMs = max(1000, locksAt.difference(startsAt).inMilliseconds);
    final scheduled = <_ScheduledFakeBet>[];
    for (final pot in const ['A', 'B', 'C']) {
      var remaining = round.fakeTotals[pot] ?? 0;
      if (remaining <= 0) continue;

      final seededRandom = Random(
        Object.hash(round.roundKey, pot, remaining, durationMs),
      );
      var index = 0;
      while (remaining > 0) {
        final choices = _chipOptions.where((chip) => chip <= remaining).toList(growable: false);
        final amount =
            choices.isEmpty
                ? remaining
                : choices[seededRandom.nextInt(choices.length)];
        remaining -= amount;

        final minOffset = min(700, max(0, durationMs - 200));
        final maxOffset = max(minOffset + 1, durationMs - 700);
        final offsetMs =
            maxOffset <= minOffset
                ? minOffset
                : minOffset + seededRandom.nextInt(maxOffset - minOffset);

        scheduled.add(
          _ScheduledFakeBet(
            id: '${round.roundKey}-$pot-$index-$amount',
            pot: pot,
            amount: amount,
            dueAt: startsAt.add(Duration(milliseconds: offsetMs)),
          ),
        );
        index += 1;
      }
    }

    scheduled.sort((a, b) => a.dueAt.compareTo(b.dueAt));
    return scheduled;
  }

  void _processDueFakeBets() {
    final snapshot = _snapshot;
    if (snapshot == null || !snapshot.settings.fakeBetsEnabled) return;
    if (_scheduledFakeBets.isEmpty) return;

    final dueEvents =
        _scheduledFakeBets
            .where(
              (event) =>
                  !_appliedFakeBetIds.contains(event.id) &&
                  !event.dueAt.isAfter(_now),
            )
            .toList(growable: false);
    if (dueEvents.isEmpty) return;

    final nextDisplay = Map<String, int>.from(_displayTotals);
    final nextApplied = <String>{..._appliedFakeBetIds};
    for (final event in dueEvents) {
      nextApplied.add(event.id);
      nextDisplay[event.pot] = (nextDisplay[event.pot] ?? 0) + event.amount;
    }

    setState(() {
      _displayTotals = nextDisplay;
      _appliedFakeBetIds = nextApplied;
    });

    for (final event in dueEvents) {
      _launchFakeBetAnimation(event);
    }
  }

  void _launchFakeBetAnimation(_ScheduledFakeBet event) {
    final panelBox = _panelKey.currentContext?.findRenderObject() as RenderBox?;
    if (panelBox == null) return;
    final rowBox = _chipRowKey.currentContext?.findRenderObject() as RenderBox?;
    final potIndex = const ['A', 'B', 'C'].indexOf(event.pot);
    final fallbackLocal = Offset(
      panelBox.size.width * (.28 + (_random.nextDouble() * .44)),
      panelBox.size.height * .82,
    );
    final baseLocal =
        rowBox == null
            ? fallbackLocal
            : panelBox.globalToLocal(
              rowBox.localToGlobal(
                Offset(
                  rowBox.size.width * (.20 + (_random.nextDouble() * .60)),
                  rowBox.size.height * .55,
                ),
              ),
            );
    final startGlobal = panelBox.localToGlobal(
      Offset(
        baseLocal.dx + ((_random.nextDouble() * 28) - 14),
        baseLocal.dy + ((_random.nextDouble() * 18) - 9),
      ),
    );

    _launchFlyingGem(
      imagePath: _assetForCoinAmount(event.amount),
      potIndex: potIndex,
      startGlobalPosition: startGlobal,
    );
  }

  void _animateRemoteBetDeltas(TeenPattiRound? previous, TeenPattiRound next) {
    if (!mounted || previous == null) return;
    for (var index = 0; index < 3; index++) {
      final pot = ['A', 'B', 'C'][index];
      final oldTotal = previous.realTotals[pot] ?? 0;
      final newTotal = next.realTotals[pot] ?? 0;
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
                      potCoins: _displayTotals[pot] ?? 0,
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
            _BettingConsole(
              chipRowKey: _chipRowKey,
              walletBalance: snapshot.walletBalance,
              selectedPot:
                  _selectedPotIndex == -1 ? null : ['A', 'B', 'C'][_selectedPotIndex],
              selectedChip: _selectedChip,
              phase: round.phase,
              minBet: snapshot.settings.minBet,
              maxBet: snapshot.settings.maxBet,
              placingBet: _placingBet,
              chipOptions: _chipOptions,
              chipAssets: _chipAssets,
              chipKeys: _chipKeys,
              onTapChip: (chip, details) {
                final enabled =
                    chip >= snapshot.settings.minBet &&
                    chip <= snapshot.settings.maxBet &&
                    round.phase == 'betting';
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
            ),
            const SizedBox(height: 14),
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
      height: 152,
      child: Column(
        children: [
          Stack(
            alignment: Alignment.center,
            children: [
              Row(
                children: [
                  const SizedBox(
                    width: 40,
                    child: Align(
                      alignment: Alignment.centerLeft,
                      child: Icon(Icons.help_outline, color: Colors.white, size: 22),
                    ),
                  ),
                  const Spacer(),
                  _CoinBalancePill(balance: walletBalance, compact: true),
                ],
              ),
              Container(
                constraints: const BoxConstraints(minWidth: 112),
                padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 8),
                decoration: BoxDecoration(
                  color: Colors.redAccent.withValues(alpha: .9),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: Colors.white24),
                  boxShadow: const [
                    BoxShadow(
                      color: Colors.black26,
                      blurRadius: 10,
                      offset: Offset(0, 4),
                    ),
                  ],
                ),
                child: Text(
                  '00:${countdownSeconds.toString().padLeft(2, '0')}s',
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Expanded(
            child: Center(
              child: Image.asset(
                'assets/games/teen_patti/logo_teenpatti.png',
                width: MediaQuery.of(context).size.width * .54,
                fit: BoxFit.contain,
              ),
            ),
          ),
          const SizedBox(height: 4),
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
      'settling' => 'Winner Calculation Running',
      'restarting' => 'Next Round Starting',
      'cancelled' => 'Round Cancelled',
      _ => 'Teen Patti',
    };
    final subtitle = switch (phase) {
      'betting' => 'Select a pot first, then tap a gem to place your bet.',
      'locked' => 'Current round is frozen. Watch the result and wait for the next deal.',
      'settling' => 'All bets are locked. Result animation will appear shortly.',
      'result' => winningPot == null ? 'Result is ready to broadcast.' : 'Pot $winningPot has won this round.',
      'restarting' => 'Board is preparing the next round.',
      'cancelled' => 'This round was cancelled. Accepted bets should be refunded.',
      _ => 'Teen Patti is active in this room.',
    };

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            Colors.white.withValues(alpha: .10),
            Colors.white.withValues(alpha: .04),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: Colors.white12),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: const Color(0xFFFFD966).withValues(alpha: .14),
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Icon(Icons.bolt_rounded, color: Color(0xFFFFD966)),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                    fontSize: 15,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  error?.isNotEmpty == true ? error! : subtitle,
                  style: TextStyle(
                    color:
                        error?.isNotEmpty == true
                            ? const Color(0xFFFFB4B4)
                            : Colors.white70,
                    fontWeight: FontWeight.w600,
                    height: 1.35,
                    fontSize: 12,
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

class _RecentWinnersStrip extends StatelessWidget {
  const _RecentWinnersStrip({required this.history});

  final List<TeenPattiRound> history;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            Colors.deepPurple.shade900.withValues(alpha: .92),
            const Color(0xFF251236).withValues(alpha: .92),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.white10),
        boxShadow: const [
          BoxShadow(
            color: Colors.black26,
            blurRadius: 14,
            offset: Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: const Color(0xFFFFD54F).withValues(alpha: .18),
                ),
                child: const Icon(
                  Icons.emoji_events_rounded,
                  color: Color(0xFFFFD54F),
                  size: 18,
                ),
              ),
              const SizedBox(width: 10),
              const Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Last 5 Winners',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    SizedBox(height: 2),
                    Text(
                      'Recent winning pots and round finishes',
                      style: TextStyle(
                        color: Colors.white70,
                        fontSize: 11,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ],
                ),
              ),
              Text(
                '${history.length}/5',
                style: const TextStyle(
                  color: Colors.white54,
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: history.isEmpty
                  ? const [
                      _WinnerBadge(
                        label: 'WAIT',
                        winnerPot: null,
                        subtitle: 'No results yet',
                      ),
                    ]
                  : history
                      .map(
                        (round) => _WinnerBadge(
                          label: round.roundKey.split('_').last.substring(0, 4),
                          winnerPot: round.winningPot,
                          subtitle: round.settledAt == null
                              ? 'Pending'
                              : '${round.settledAt!.hour.toString().padLeft(2, '0')}:${round.settledAt!.minute.toString().padLeft(2, '0')}',
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
  const _WinnerBadge({
    required this.label,
    required this.winnerPot,
    required this.subtitle,
  });

  final String label;
  final String? winnerPot;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    final winnerColor = switch (winnerPot) {
      'A' => const Color(0xFF64B5F6),
      'B' => const Color(0xFFFF8A65),
      'C' => const Color(0xFF81C784),
      _ => const Color(0xFFB0BEC5),
    };
    return Container(
      width: 124,
      margin: const EdgeInsets.only(right: 12),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 10),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            winnerColor.withValues(alpha: .26),
            Colors.black.withValues(alpha: .22),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: winnerColor.withValues(alpha: .34)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '#$label',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white54,
                    fontSize: 10,
                    fontWeight: FontWeight.w800,
                    letterSpacing: .6,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
                decoration: BoxDecoration(
                  color: winnerColor.withValues(alpha: .18),
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(color: winnerColor.withValues(alpha: .34)),
                ),
                child: Text(
                  winnerPot == null ? 'WAIT' : 'POT $winnerPot',
                  style: TextStyle(
                    color: winnerColor,
                    fontWeight: FontWeight.w900,
                    fontSize: 10,
                  ),
                ),
              ),
            ],
          ),
          const Spacer(),
          Text(
            winnerPot == null ? 'Waiting for result' : 'Winning side',
            style: TextStyle(
              color: winnerColor,
              fontWeight: FontWeight.w800,
              fontSize: 12,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            subtitle,
            style: const TextStyle(
              color: Colors.white70,
              fontWeight: FontWeight.w600,
              fontSize: 11,
            ),
          ),
          const SizedBox(height: 10),
          LinearProgressIndicator(
            value: winnerPot == null
                ? .28
                : switch (winnerPot) {
                  'A' => .36,
                  'B' => .66,
                  'C' => .92,
                  _ => .20,
                },
            minHeight: 6,
            borderRadius: BorderRadius.circular(999),
            backgroundColor: Colors.white10,
            valueColor: AlwaysStoppedAnimation<Color>(winnerColor),
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
          gradient: LinearGradient(
            colors: [
              Colors.white.withValues(alpha: selected ? .16 : .11),
              Colors.black.withValues(alpha: .12),
            ],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
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
            Container(
              padding: EdgeInsets.symmetric(
                horizontal: width * .085,
                vertical: height * .014,
              ),
              decoration: BoxDecoration(
                color:
                    revealedWinner
                        ? Colors.greenAccent.withValues(alpha: .14)
                        : selected
                            ? Colors.white.withValues(alpha: .16)
                            : Colors.black.withValues(alpha: .18),
                borderRadius: BorderRadius.circular(999),
                border: Border.all(
                  color:
                      revealedWinner
                          ? Colors.greenAccent.withValues(alpha: .44)
                          : selected
                              ? Colors.white24
                              : Colors.white10,
                ),
              ),
              child: Text(
                revealedWinner
                    ? 'Winner Pot $pot'
                    : selected
                        ? 'Pot $pot Selected'
                        : 'Pot $pot',
                style: TextStyle(
                  color: revealedWinner ? Colors.greenAccent : Colors.white,
                  fontWeight: FontWeight.w900,
                  fontSize: width * .078,
                ),
              ),
            ),
            SizedBox(height: height * .018),
            Container(
              key: potKey,
              height: height * .34,
              alignment: Alignment.center,
              child: SizedBox(
                width: width * .82,
                child: Stack(
                  alignment: Alignment.center,
                  clipBehavior: Clip.none,
                  children: [
                    Transform.translate(
                      offset: Offset(-width * .13, 0),
                      child: _CardImage(
                        path: cards[0],
                        width: width * .44,
                        opacity: .7,
                      ),
                    ),
                    Transform.translate(
                      offset: Offset(0, -2),
                      child: _CardImage(
                        path: cards[1],
                        width: width * .44,
                        opacity: .86,
                      ),
                    ),
                    Transform.translate(
                      offset: Offset(width * .13, 0),
                      child: _CardImage(
                        path: cards[2],
                        width: width * .44,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            SizedBox(height: height * .025),
            Text(
              '$potCoins',
              style: TextStyle(
                fontSize: width * .115,
                fontWeight: FontWeight.w900,
                color: Colors.white,
              ),
            ),
            Text(
              'Total Bet',
              style: TextStyle(
                fontSize: width * .065,
                color: Colors.white54,
                fontWeight: FontWeight.w700,
              ),
            ),
            SizedBox(height: height * .012),
            Text(
              'You: $myCoins',
              style: TextStyle(
                fontSize: width * .082,
                color: selected ? Colors.white : Colors.white70,
                fontWeight: FontWeight.w700,
              ),
            ),
            const Spacer(),
            if (bettingOpen)
              Text(
                selected ? 'Ready for gem bet' : 'Tap to target',
                style: TextStyle(
                  color: selected ? const Color(0xFFFFE082) : Colors.white54,
                  fontWeight: FontWeight.w700,
                  fontSize: width * .064,
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

class _BettingConsole extends StatelessWidget {
  const _BettingConsole({
    required this.chipRowKey,
    required this.walletBalance,
    required this.selectedPot,
    required this.selectedChip,
    required this.phase,
    required this.minBet,
    required this.maxBet,
    required this.placingBet,
    required this.chipOptions,
    required this.chipAssets,
    required this.chipKeys,
    required this.onTapChip,
  });

  final GlobalKey chipRowKey;
  final int walletBalance;
  final String? selectedPot;
  final int selectedChip;
  final String phase;
  final int minBet;
  final int maxBet;
  final bool placingBet;
  final List<int> chipOptions;
  final Map<int, String> chipAssets;
  final Map<int, GlobalKey> chipKeys;
  final void Function(int chip, TapDownDetails details) onTapChip;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 16),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            Colors.black.withValues(alpha: .24),
            Colors.black.withValues(alpha: .12),
          ],
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: Colors.white12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Expanded(
                child: Text(
                  'Bet Console',
                  style: TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.w900,
                    color: Colors.white,
                  ),
                ),
              ),
              _CoinBalancePill(balance: walletBalance),
            ],
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              _SelectionChip(
                label: selectedPot == null ? 'Select Pot' : 'Pot $selectedPot',
                active: selectedPot != null,
              ),
              const SizedBox(width: 8),
              _SelectionChip(
                label:
                    'Gem ${selectedChip >= 1000 ? '${selectedChip ~/ 1000}K' : selectedChip}',
                active: true,
              ),
              const Spacer(),
              Text(
                '$minBet-$maxBet',
                style: const TextStyle(
                  color: Colors.white54,
                  fontWeight: FontWeight.w700,
                  fontSize: 11,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            selectedPot == null
                ? 'Tap a pot board, then tap a gem to place a bet.'
                : phase == 'betting'
                    ? 'Pot $selectedPot is armed. Tap any enabled gem below.'
                    : 'Round is not accepting bets right now.',
            style: TextStyle(
              color: Colors.white.withValues(alpha: .80),
              fontWeight: FontWeight.w600,
              fontSize: 12,
              height: 1.35,
            ),
          ),
          const SizedBox(height: 14),
          Row(
            key: chipRowKey,
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children:
                chipOptions.map((chip) {
                  final enabled =
                      chip >= minBet && chip <= maxBet && phase == 'betting';
                  return _BetGemButton(
                    chipKey: chipKeys[chip],
                    value: chip,
                    imagePath: chipAssets[chip]!,
                    active: chip == selectedChip,
                    enabled: enabled && !placingBet,
                    onTapDown: (details) => onTapChip(chip, details),
                  );
                }).toList(),
          ),
        ],
      ),
    );
  }
}

class _SelectionChip extends StatelessWidget {
  const _SelectionChip({required this.label, required this.active});

  final String label;
  final bool active;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color:
            active
                ? const Color(0xFFFFD54F).withValues(alpha: .16)
                : Colors.white10,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(
          color:
              active
                  ? const Color(0xFFFFD54F).withValues(alpha: .44)
                  : Colors.white12,
        ),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: active ? const Color(0xFFFFE082) : Colors.white70,
          fontWeight: FontWeight.w800,
          fontSize: 11,
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

class _ScheduledFakeBet {
  const _ScheduledFakeBet({
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
