import 'dart:async';
import 'dart:math';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import '../../../../app/routes/app_urls.dart';
import '../../../../app/widgets/haptics.dart';
import '../../../../services/app_settings_service.dart';
import '../../../../services/storage_service.dart';
import '../../fortune_wheel/services/fortune_wheel_preload_service.dart';
import '../../greedy/widgets/greedy_game_panel.dart';
import '../../../wallet/widgets/recharge_bottom_sheet.dart';
import '../models/teen_patti_models.dart';
import '../services/teen_patti_api.dart';
import '../services/teen_patti_socket_service.dart';

enum LiveRoomGamesSheetResult { fortuneWheel }

class TeenPattiGamesSheet extends StatefulWidget {
  const TeenPattiGamesSheet({super.key});

  @override
  State<TeenPattiGamesSheet> createState() => _TeenPattiGamesSheetState();
}

class _TeenPattiGamesSheetState extends State<TeenPattiGamesSheet> {
  String? _selectedGame;

  @override
  Widget build(BuildContext context) {
    final title = switch (_selectedGame) {
      'teen_patti' => 'Teen Patti',
      'greedy' => 'Greedy',
      _ => 'Games',
    };
    final subtitle = switch (_selectedGame) {
      'teen_patti' => 'High-tempo card betting inside the live room',
      'greedy' => 'Weighted spinner pots with premium reveal pacing',
      _ => 'Choose a room game without leaving the current live session',
    };

    return SafeArea(
      top: false,
      child: FractionallySizedBox(
        heightFactor: 0.94,
        child: DecoratedBox(
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              colors: [Color(0xFF0F0A18), Color(0xFF191028), Color(0xFF0A0A12)],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
            borderRadius: BorderRadius.vertical(top: Radius.circular(34)),
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
                padding: const EdgeInsets.fromLTRB(18, 14, 18, 12),
                child: Stack(
                  children: [
                    Positioned(
                      left: 18,
                      top: 10,
                      child: Container(
                        width: 86,
                        height: 86,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: const Color(0xFFFFD966).withValues(alpha: .10),
                        ),
                      ),
                    ),
                    Positioned(
                      right: -6,
                      top: -8,
                      child: Container(
                        width: 92,
                        height: 92,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: const Color(0xFF67A5FF).withValues(alpha: .10),
                        ),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(28),
                        gradient: LinearGradient(
                          colors: [
                            Colors.white.withValues(alpha: .10),
                            Colors.white.withValues(alpha: .04),
                          ],
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                        ),
                        border: Border.all(color: Colors.white.withValues(alpha: .12)),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withValues(alpha: .24),
                            blurRadius: 24,
                            offset: const Offset(0, 14),
                          ),
                        ],
                      ),
                      child: Column(
                        children: [
                          Row(
                            children: [
                              SizedBox(
                                width: 42,
                                child:
                                    _selectedGame != null
                                        ? IconButton(
                                          onPressed:
                                              () => setState(() => _selectedGame = null),
                                          icon: const Icon(Icons.arrow_back_rounded),
                                          color: Colors.white,
                                          splashRadius: 20,
                                        )
                                        : const SizedBox.shrink(),
                              ),
                              Expanded(
                                child: Column(
                                  children: [
                                    Container(
                                      padding: const EdgeInsets.symmetric(
                                        horizontal: 10,
                                        vertical: 4,
                                      ),
                                      decoration: BoxDecoration(
                                        borderRadius: BorderRadius.circular(999),
                                        color: const Color(0xFFFFD966).withValues(alpha: .12),
                                        border: Border.all(
                                          color: const Color(0xFFFFD966).withValues(alpha: .24),
                                        ),
                                      ),
                                      child: const Text(
                                        'ROOM GAMES',
                                        style: TextStyle(
                                          color: Color(0xFFFFE8A3),
                                          fontSize: 10,
                                          fontWeight: FontWeight.w900,
                                          letterSpacing: 1.1,
                                        ),
                                      ),
                                    ),
                                    const SizedBox(height: 10),
                                    Text(
                                      title,
                                      textAlign: TextAlign.center,
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 24,
                                        fontWeight: FontWeight.w900,
                                        letterSpacing: .2,
                                      ),
                                    ),
                                    const SizedBox(height: 6),
                                    Text(
                                      subtitle,
                                      textAlign: TextAlign.center,
                                      style: TextStyle(
                                        color: Colors.white.withValues(alpha: .68),
                                        fontSize: 12.5,
                                        fontWeight: FontWeight.w600,
                                        height: 1.35,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              SizedBox(
                                width: 42,
                                child: IconButton(
                                  onPressed: () => Navigator.of(context).maybePop(),
                                  icon: const Icon(Icons.close_rounded),
                                  color: Colors.white70,
                                  splashRadius: 20,
                                ),
                              ),
                            ],
                          ),
                          if (_selectedGame == null) ...[
                            const SizedBox(height: 14),
                            Row(
                              children: [
                                Expanded(
                                  child: _SheetFactPill(
                                    icon: Icons.flash_on_rounded,
                                    label: 'Instant room play',
                                    accent: const Color(0xFFFFD966),
                                  ),
                                ),
                                const SizedBox(width: 10),
                                Expanded(
                                  child: _SheetFactPill(
                                    icon: Icons.stacked_line_chart_rounded,
                                    label: 'Live pot motion',
                                    accent: const Color(0xFF67A5FF),
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              Expanded(
                child:
                    _selectedGame == 'teen_patti'
                        ? const TeenPattiGamePanel()
                        : _selectedGame == 'greedy'
                        ? const GreedyGamePanel()
                        : _GamesList(
                          onOpenTeenPatti: () {
                            setState(() => _selectedGame = 'teen_patti');
                          },
                          onOpenGreedy: () {
                            setState(() => _selectedGame = 'greedy');
                          },
                          onOpenFortuneWheel: () {
                            Navigator.of(context).pop(
                              LiveRoomGamesSheetResult.fortuneWheel,
                            );
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
  const _GamesList({
    required this.onOpenTeenPatti,
    required this.onOpenGreedy,
    required this.onOpenFortuneWheel,
  });

  final VoidCallback onOpenTeenPatti;
  final VoidCallback onOpenGreedy;
  final VoidCallback onOpenFortuneWheel;

  @override
  Widget build(BuildContext context) {
    final settings = Get.find<AppSettingsService>();
    final fortune =
        Get.isRegistered<FortuneWheelPreloadService>()
            ? Get.find<FortuneWheelPreloadService>()
            : null;

    return Obx(() {
      settings.payload.value;
      final showTeenPatti = settings.teenPattiEnabled;
      final showGreedy = settings.greedyEnabled;
      final showFortuneWheel =
          settings.fortuneWheelEnabled &&
          settings.fortuneWheelVisibleInVideoRoomStrip;
      final snapshot = fortune?.snapshot.value;
      final freeSpins = snapshot?.freeSpinsRemaining ?? 0;
      final paidCost = snapshot?.settings.paidSpinCostCoins;
      final paidSpinsEnabled = snapshot?.settings.paidSpinsEnabled ?? true;

      return ListView(
      padding: const EdgeInsets.fromLTRB(18, 4, 18, 28),
      children: [
        if (showTeenPatti)
          _GameEntryCard(
            title: 'Teen Patti',
            description:
                'Live round-based betting across A, B, and C pots with result reveals inside the room.',
            chip: 'CARD TABLE',
            accent: const Color(0xFFFFD966),
            icon: ClipRRect(
              borderRadius: BorderRadius.circular(18),
              child: Image.asset(
                'assets/games/teen_patti/logo_teenpatti.png',
                width: 76,
                height: 76,
                fit: BoxFit.cover,
              ),
            ),
            onTap: onOpenTeenPatti,
          ),
        if (showTeenPatti && showGreedy) const SizedBox(height: 14),
        if (showGreedy)
          _GameEntryCard(
            title: 'Greedy',
            description:
                'Weighted spinner pots with sharper multipliers, wheel drama, and layered result moments.',
            chip: 'SPINNER TABLE',
            accent: const Color(0xFF67A5FF),
            icon: Container(
              width: 76,
              height: 76,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(18),
                gradient: const LinearGradient(
                  colors: [Color(0xFF5AA7FF), Color(0xFFE95BFF)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
              ),
              child: const Icon(
                Icons.blur_circular_rounded,
                color: Colors.white,
                size: 38,
              ),
            ),
            onTap: onOpenGreedy,
          ),
        if ((showTeenPatti || showGreedy) && showFortuneWheel)
          const SizedBox(height: 14),
        if (showFortuneWheel)
          _GameEntryCard(
            title: 'Fortune Wheel',
            description:
                freeSpins > 0
                    ? '$freeSpins free spin ready. Win coins, entry packs, subscriptions, or 0 coins.'
                    : paidSpinsEnabled
                    ? 'Spin the reward wheel for ${paidCost ?? 'coins'} and collect instant prizes.'
                    : 'Today\'s free spins are used. Come back after the daily reset.',
            chip:
                freeSpins > 0
                    ? 'FREE SPIN READY'
                    : paidSpinsEnabled
                    ? 'DAILY REWARD'
                    : 'COME BACK TOMORROW',
            accent: const Color(0xFFFFB84D),
            icon: Container(
              width: 76,
              height: 76,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: const SweepGradient(
                  colors: [
                    Color(0xFFFFD76B),
                    Color(0xFFFF5FD2),
                    Color(0xFF67E8F9),
                    Color(0xFFFFD76B),
                  ],
                ),
                boxShadow: [
                  BoxShadow(
                    color: const Color(0xFFFFD76B).withValues(alpha: .28),
                    blurRadius: 18,
                    offset: const Offset(0, 8),
                  ),
                ],
              ),
              child: const Icon(
                Icons.stars_rounded,
                color: Colors.white,
                size: 38,
              ),
            ),
            onTap: onOpenFortuneWheel,
          ),
        if (!showTeenPatti && !showGreedy && !showFortuneWheel)
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(24),
              gradient: LinearGradient(
                colors: [
                  Colors.white.withValues(alpha: .06),
                  Colors.white.withValues(alpha: .03),
                ],
              ),
              border: Border.all(color: Colors.white12),
            ),
            child: const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'No room games are enabled right now.',
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                    fontSize: 16,
                  ),
                ),
                SizedBox(height: 6),
                Text(
                  'Enable Teen Patti, Greedy, or Fortune Wheel for this user to make games available in the live room.',
                  style: TextStyle(
                    color: Colors.white70,
                    fontWeight: FontWeight.w600,
                    height: 1.35,
                  ),
                ),
              ],
            ),
          ),
      ],
      );
    });
  }
}

class _SheetFactPill extends StatelessWidget {
  const _SheetFactPill({
    required this.icon,
    required this.label,
    required this.accent,
  });

  final IconData icon;
  final String label;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(18),
        color: Colors.white.withValues(alpha: .05),
        border: Border.all(color: Colors.white.withValues(alpha: .08)),
      ),
      child: Row(
        children: [
          Icon(icon, color: accent, size: 16),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              label,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 12,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _GameEntryCard extends StatelessWidget {
  const _GameEntryCard({
    required this.title,
    required this.description,
    required this.chip,
    required this.accent,
    required this.icon,
    required this.onTap,
  });

  final String title;
  final String description;
  final String chip;
  final Color accent;
  final Widget icon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(26),
      child: Ink(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(26),
          gradient: LinearGradient(
            colors: [
              Colors.white.withValues(alpha: .08),
              Colors.white.withValues(alpha: .04),
            ],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          border: Border.all(color: Colors.white.withValues(alpha: .10)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: .20),
              blurRadius: 18,
              offset: const Offset(0, 10),
            ),
          ],
        ),
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              icon,
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(999),
                        color: accent.withValues(alpha: .12),
                        border: Border.all(color: accent.withValues(alpha: .24)),
                      ),
                      child: Text(
                        chip,
                        style: TextStyle(
                          color: accent,
                          fontSize: 10.5,
                          fontWeight: FontWeight.w900,
                          letterSpacing: .9,
                        ),
                      ),
                    ),
                    const SizedBox(height: 10),
                    Text(
                      title,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 7),
                    Text(
                      description,
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: .72),
                        height: 1.4,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Container(
                width: 38,
                height: 38,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: Colors.white.withValues(alpha: .06),
                  border: Border.all(color: Colors.white.withValues(alpha: .10)),
                ),
                child: const Icon(
                  Icons.arrow_forward_rounded,
                  color: Colors.white70,
                  size: 20,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class TeenPattiGamePanel extends StatefulWidget {
  const TeenPattiGamePanel({super.key});

  @override
  State<TeenPattiGamePanel> createState() => _TeenPattiGamePanelState();
}

class _TeenPattiGamePanelState extends State<TeenPattiGamePanel>
    with TickerProviderStateMixin {
  static const String _cardBackAsset = 'assets/games/teen_patti/card_back_1.jpeg';
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
  late final AnimationController _idleController;
  late final AudioPlayer _effectPlayer;

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
  String? _localViewerRoundKey;
  Map<String, int> _localViewerPotTotals = const {'A': 0, 'B': 0, 'C': 0};
  String? _activeFakeRoundKey;
  Map<String, int> _displayTotals = const {'A': 0, 'B': 0, 'C': 0};
  List<_ScheduledFakeBet> _scheduledFakeBets = const [];
  Set<String> _appliedFakeBetIds = <String>{};
  List<_FlyingGem> _flyingGems = const [];
  DateTime _now = DateTime.now();
  DateTime? _lastAutoRefreshAt;
  String? _countdownAnchorRoundKey;
  int _countdownAnchorSeconds = 0;
  DateTime? _countdownAnchorAt;

  @override
  void initState() {
    super.initState();
    _effectPlayer = AudioPlayer()..setReleaseMode(ReleaseMode.stop);
    _idleController = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 8),
    )..repeat(reverse: true);
    _bootstrap();
  }

  @override
  void dispose() {
    _snapshotSub?.cancel();
    _eventSub?.cancel();
    _ticker?.cancel();
    _idleController.dispose();
    _effectPlayer.dispose();
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
        final publicRoundData =
            data['round'] is Map
                ? Map<String, dynamic>.from(data['round'] as Map)
                : const <String, dynamic>{};
        final mergedRound = TeenPattiRound(
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
          winningHand: next.round.winningHand,
          losingHandOne: next.round.losingHandOne,
          losingHandTwo: next.round.losingHandTwo,
          countdownSeconds: next.round.countdownSeconds,
          totals: next.round.totals,
          realTotals: next.round.realTotals,
          fakeTotals: next.round.fakeTotals,
          totalBetsCount: next.round.totalBetsCount,
          participantCount: next.round.participantCount,
          payoutMultiplier: next.round.payoutMultiplier,
          viewerBets:
              publicRoundData.containsKey('viewer_bets')
                  ? next.round.viewerBets
                  : current.round.viewerBets,
        );
        _applySnapshot(
          TeenPattiSnapshot(
            settings: next.settings,
            walletBalance: current.walletBalance,
            round: mergedRound,
            history: next.history,
          ),
          syncViewerBets: false,
        );
        return;
      }
      _applySnapshot(next, syncViewerBets: false);
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

  Future<void> _loadSnapshot({bool silent = false}) async {
    if (!silent || _snapshot == null) {
      setState(() {
        _loading = true;
        _error = null;
      });
    } else if (_error != null) {
      setState(() {
        _error = null;
      });
    }
    try {
      final snapshot = await _api.fetchSnapshot();
      if (!mounted) return;
      _applySnapshot(snapshot, loading: false, syncViewerBets: true);
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
        Haptics.selection();
        SystemSound.play(SystemSoundType.click);
        unawaited(_playEffect('games/teen_patti/coin_dropped.mp3'));
        _launchFlyingGem(
          imagePath: imagePath,
          potIndex: ['A', 'B', 'C'].indexOf(pot),
          startGlobalPosition: startGlobalPosition,
        );
      }
      _applyLocalViewerBet(next.round.roundKey, pot, _selectedChip);
      _applySnapshot(next, syncViewerBets: true);
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
      unawaited(_loadSnapshot(silent: true));
    }
  }

  void _applySnapshot(
    TeenPattiSnapshot next, {
    bool loading = false,
    bool syncViewerBets = true,
  }) {
    final previousSnapshot = _snapshot;
    final previous = previousSnapshot?.round;
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
      _countdownAnchorRoundKey = next.round.roundKey;
      _countdownAnchorSeconds = next.round.countdownSeconds;
      _countdownAnchorAt = _now;
      if (_selectedPotIndex > 2) {
        _selectedPotIndex = -1;
      }
    });

    if (syncViewerBets) {
      _syncLocalViewerBets(next.round);
    }
    _syncDisplayTotals(next);
    _animateRemoteBetDeltas(previous, next.round);
    _showResultDialogIfNeeded(next, previousSnapshot);
  }

  void _applyLocalViewerBet(String roundKey, String pot, int amount) {
    final nextTotals =
        _localViewerRoundKey == roundKey
            ? Map<String, int>.from(_localViewerPotTotals)
            : <String, int>{'A': 0, 'B': 0, 'C': 0};
    nextTotals[pot] = (nextTotals[pot] ?? 0) + amount;
    setState(() {
      _localViewerRoundKey = roundKey;
      _localViewerPotTotals = nextTotals;
    });
  }

  void _syncLocalViewerBets(TeenPattiRound round) {
    final serverTotals = <String, int>{
      'A': round.viewerBets.where((bet) => bet.pot == 'A').fold<int>(0, (sum, bet) => sum + bet.amount),
      'B': round.viewerBets.where((bet) => bet.pot == 'B').fold<int>(0, (sum, bet) => sum + bet.amount),
      'C': round.viewerBets.where((bet) => bet.pot == 'C').fold<int>(0, (sum, bet) => sum + bet.amount),
    };

    if (_localViewerRoundKey != round.roundKey) {
      setState(() {
        _localViewerRoundKey = round.roundKey;
        _localViewerPotTotals = serverTotals;
      });
      return;
    }

    final merged = <String, int>{
      'A': max(_localViewerPotTotals['A'] ?? 0, serverTotals['A'] ?? 0),
      'B': max(_localViewerPotTotals['B'] ?? 0, serverTotals['B'] ?? 0),
      'C': max(_localViewerPotTotals['C'] ?? 0, serverTotals['C'] ?? 0),
    };

    if (merged['A'] == _localViewerPotTotals['A'] &&
        merged['B'] == _localViewerPotTotals['B'] &&
        merged['C'] == _localViewerPotTotals['C']) {
      return;
    }

    setState(() {
      _localViewerPotTotals = merged;
    });
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
      final totalFake = round.fakeTotals[pot] ?? 0;
      if (totalFake <= 0) continue;

      final seededRandom = Random(
        Object.hash(round.roundKey, pot, totalFake, durationMs),
      );
      final chunkCount = _fakeBetChunkCount(totalFake);
      final chunks = _splitFakeBetTotal(
        total: totalFake,
        chunkCount: chunkCount,
        random: seededRandom,
      );
      final minOffset = min(900, max(0, durationMs - 300));
      final maxOffset = max(minOffset + 1, durationMs - 650);
      for (var index = 0; index < chunks.length; index++) {
        final slotStart = minOffset + (((maxOffset - minOffset) * index) ~/ max(1, chunks.length));
        final slotEnd = min(
          maxOffset,
          minOffset + (((maxOffset - minOffset) * (index + 1)) ~/ max(1, chunks.length)),
        );
        final jitterWindow = max(1, slotEnd - slotStart);
        final offsetMs = slotStart + seededRandom.nextInt(jitterWindow);
        final amount = chunks[index];
        scheduled.add(
          _ScheduledFakeBet(
            id: '${round.roundKey}-$pot-$index-$amount',
            pot: pot,
            amount: amount,
            dueAt: startsAt.add(Duration(milliseconds: offsetMs)),
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

  List<int> _splitFakeBetTotal({
    required int total,
    required int chunkCount,
    required Random random,
  }) {
    if (chunkCount <= 1 || total <= 0) {
      return <int>[total];
    }

    final minimumChunk = _minimumFakeBetChunk(total);
    var remaining = total;
    final chunks = <int>[];

    for (var index = 0; index < chunkCount; index++) {
      final chunksLeft = chunkCount - index;
      if (chunksLeft == 1) {
        chunks.add(remaining);
        break;
      }

      final reserve = minimumChunk * (chunksLeft - 1);
      final average = ((remaining - reserve) / chunksLeft).round();
      final jitter = max(1, average ~/ 3);
      final proposed = average + random.nextInt((jitter * 2) + 1) - jitter;
      final amount = proposed.clamp(minimumChunk, remaining - reserve);
      chunks.add(amount);
      remaining -= amount;
    }

    if (chunks.isEmpty) {
      return <int>[total];
    }

    final summed = chunks.fold<int>(0, (sum, value) => sum + value);
    if (summed != total) {
      chunks[chunks.length - 1] += total - summed;
    }
    return chunks.where((value) => value > 0).toList(growable: false);
  }

  int _minimumFakeBetChunk(int total) {
    if (total <= 500) return 50;
    if (total <= 2000) return 200;
    if (total <= 5000) return 500;
    return 1000;
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

  void _showResultDialogIfNeeded(
    TeenPattiSnapshot snapshot,
    TeenPattiSnapshot? previousSnapshot,
  ) {
    final round = snapshot.round;
    final historyHead =
        snapshot.history.isEmpty ? null : snapshot.history.first;
    final previousRound = previousSnapshot?.round;

    if (previousRound != null &&
        previousRound.id > 0 &&
        previousRound.id != round.id &&
        previousRound.phase != 'result' &&
        historyHead != null &&
        historyHead.id == previousRound.id &&
        historyHead.phase == 'result') {
      _presentResultDialog(historyHead);
      return;
    }

    _presentResultDialog(round);
  }

  void _presentResultDialog(TeenPattiRound round) {
    if (!mounted || round.phase != 'result' || round.id <= 0) return;
    if (_shownResultRoundId == round.id) return;
    _shownResultRoundId = round.id;
    Haptics.success();
    SystemSound.play(SystemSoundType.alert);
    final winningPot = round.winningPot;
    final localWinningBet =
        winningPot == null ? 0 : (_localViewerRoundKey == round.roundKey ? (_localViewerPotTotals[winningPot] ?? 0) : 0);
    final serverWinningBet = round.viewerBets
        .where((bet) => bet.pot == winningPot)
        .fold<int>(0, (sum, bet) => sum + bet.amount);
    final resolvedWinningBet = max(localWinningBet, serverWinningBet);
    final serverWinningAmount = round.viewerBets
        .where((bet) => bet.payoutCoins > 0)
        .fold<int>(0, (sum, bet) => sum + bet.payoutCoins);
    final resolvedWinningAmount =
        serverWinningAmount > 0
            ? serverWinningAmount
            : (winningPot == null ? 0 : resolvedWinningBet * round.payoutMultiplier);

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      showDialog<void>(
        context: context,
        barrierDismissible: true,
        builder: (context) => _TeenPattiResultDialog(
          round: round,
          winningBetAmount: resolvedWinningBet,
          winningPayoutAmount: resolvedWinningAmount,
        ),
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

  Future<void> _playEffect(String assetPath) async {
    try {
      await _effectPlayer.stop();
      await _effectPlayer.play(AssetSource(assetPath));
    } catch (_) {}
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
          accent: _potAccentColor(const ['A', 'B', 'C'][potIndex]),
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
        AnimatedBuilder(
          animation: _idleController,
          builder: (context, child) {
            final drift = sin(_idleController.value * 2 * pi);
            return Stack(
              children: [
                Positioned(
                  top: -40 + (drift * 8),
                  left: -30 + (drift * 10),
                  child: IgnorePointer(
                    child: Container(
                      width: 180,
                      height: 180,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: RadialGradient(
                          colors: [Color(0x5547C8FF), Color(0x0047C8FF)],
                        ),
                      ),
                    ),
                  ),
                ),
                Positioned(
                  top: 36 - (drift * 6),
                  right: -20 + (drift * 7),
                  child: IgnorePointer(
                    child: Container(
                      width: 160,
                      height: 160,
                      decoration: const BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: RadialGradient(
                          colors: [Color(0x44FFD54F), Color(0x00FFD54F)],
                        ),
                      ),
                    ),
                  ),
                ),
                Positioned(
                  top: 112 + (drift * 6),
                  left: 0,
                  right: 0,
                  child: IgnorePointer(
                    child: Center(
                      child: Container(
                        width: 240 + (drift.abs() * 24),
                        height: 130,
                        decoration: const BoxDecoration(
                          gradient: RadialGradient(
                            colors: [Color(0x559B59FF), Color(0x009B59FF)],
                            radius: .92,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            );
          },
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
                      gemStack: _potGemStackForTotal(_displayTotals[pot] ?? 0),
                      cards: _cardsForPot(round, pot),
                      selected: _selectedPotIndex == index,
                      revealedWinner:
                          round.phase == 'result' && round.winningPot == pot,
                      bettingOpen: round.phase == 'betting',
                      pulseSeed: index,
                      onTap: () {
                        if (round.phase != 'betting') return;
                        Haptics.selection();
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
            _BettingConsole(
              chipRowKey: _chipRowKey,
              walletBalance: snapshot.walletBalance,
              selectedPot:
                  _selectedPotIndex == -1 ? null : ['A', 'B', 'C'][_selectedPotIndex],
              selectedChip: _selectedChip,
              phase: round.phase,
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
            _RecentWinnersStrip(history: snapshot.history.take(5).toList()),
            const SizedBox(height: 8),
          ],
        ),
        ..._flyingGems.map(
          (gem) => AnimatedPositioned(
            key: ValueKey('gem-${gem.id}'),
            duration: const Duration(milliseconds: 520),
            curve: Curves.easeOutBack,
            left: gem.targetLeft,
            top: gem.targetTop,
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: 1.08, end: .82),
              duration: const Duration(milliseconds: 520),
              curve: Curves.elasticOut,
              builder: (context, scale, child) {
                return Transform.scale(scale: scale, child: child);
              },
              child: IgnorePointer(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    boxShadow: [
                      BoxShadow(
                        color: gem.accent.withValues(alpha: .24),
                        blurRadius: 10,
                        spreadRadius: 2,
                      ),
                    ],
                  ),
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
    if (_localViewerRoundKey == round.roundKey) {
      return _localViewerPotTotals[pot] ?? 0;
    }
    return round.viewerBets
        .where((bet) => bet.pot == pot)
        .fold<int>(0, (sum, bet) => sum + bet.amount);
  }

  List<String> _potGemStackForTotal(int total) {
    if (total <= 0) return const [];
    var remaining = total;
    final chips = <String>[];
    for (final option in const [5000, 1000, 500, 200, 50]) {
      while (remaining >= option && chips.length < 4) {
        chips.add(_chipAssets[option]!);
        remaining -= option;
      }
      if (chips.length >= 4) break;
    }
    if (chips.isEmpty) {
      chips.add(_chipAssets[50]!);
    }
    return chips;
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
    final remaining = _syncedCountdownSeconds(round);
    final phase =
        round.status == 'cancelled'
            ? 'cancelled'
            : (round.phase.isEmpty ? 'betting' : round.phase);
    return _LiveRoundView(
      source: round,
      phase: phase,
      countdownSeconds: max(0, remaining),
      roundChanged: remaining <= 0,
    );
  }

  int _syncedCountdownSeconds(TeenPattiRound round) {
    if (_countdownAnchorRoundKey != round.roundKey || _countdownAnchorAt == null) {
      return max(0, round.countdownSeconds);
    }

    final elapsed = _now.difference(_countdownAnchorAt!).inSeconds;
    return max(0, _countdownAnchorSeconds - elapsed);
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
      height: 164,
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
                constraints: const BoxConstraints(minWidth: 124),
                padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 10),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    colors: [Color(0xFFFF7B54), Color(0xFFE63E6D)],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.circular(18),
                  border: Border.all(color: const Color(0x66FFFFFF)),
                  boxShadow: const [
                    BoxShadow(
                      color: Color(0x66E63E6D),
                      blurRadius: 18,
                      spreadRadius: 2,
                      offset: Offset(0, 6),
                    ),
                  ],
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(
                      Icons.timer_rounded,
                      color: Colors.white,
                      size: 16,
                    ),
                    const SizedBox(width: 8),
                    Text(
                      '00:${countdownSeconds.toString().padLeft(2, '0')}',
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 18,
                        fontWeight: FontWeight.w900,
                        letterSpacing: .4,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Expanded(
            child: Center(
              child: Stack(
                alignment: Alignment.center,
                children: [
                  Container(
                    width: MediaQuery.of(context).size.width * .52,
                    height: 88,
                    decoration: const BoxDecoration(
                      gradient: RadialGradient(
                        colors: [Color(0x44FFD54F), Color(0x00FFD54F)],
                      ),
                    ),
                  ),
                  Image.asset(
                    'assets/games/teen_patti/logo_teenpatti.png',
                    width: MediaQuery.of(context).size.width * .54,
                    fit: BoxFit.contain,
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
            decoration: BoxDecoration(
              color: Colors.black.withValues(alpha: .28),
              borderRadius: BorderRadius.circular(999),
              border: Border.all(color: Colors.white12),
            ),
            child: Text(
              gameStateLabel,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 15,
                fontWeight: FontWeight.w800,
                letterSpacing: .2,
              ),
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
        mainAxisSize: MainAxisSize.min,
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
                        winnerPot: null,
                      ),
                    ]
                  : history
                      .map(
                        (round) => _WinnerBadge(
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
  const _WinnerBadge({
    required this.winnerPot,
  });

  final String? winnerPot;

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
              const Spacer(),
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
          const SizedBox(height: 18),
          Text(
            winnerPot == null ? 'Waiting' : 'Winning pot',
            style: TextStyle(
              color: winnerColor,
              fontWeight: FontWeight.w800,
              fontSize: 12,
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
    required this.gemStack,
    required this.selected,
    required this.revealedWinner,
    required this.bettingOpen,
    required this.pulseSeed,
    required this.cards,
    this.onTap,
  });

  final GlobalKey potKey;
  final String pot;
  final double width;
  final double height;
  final int potCoins;
  final int myCoins;
  final List<String> gemStack;
  final bool selected;
  final bool revealedWinner;
  final bool bettingOpen;
  final int pulseSeed;
  final List<String> cards;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final accent = _potAccentColor(pot);
    final softAccent = accent.withValues(alpha: .18);
    final pulse = bettingOpen ? ((sin((DateTime.now().millisecondsSinceEpoch / 700) + pulseSeed) + 1) / 2) : 0.0;
    return GestureDetector(
      onTap: onTap,
      child: TweenAnimationBuilder<double>(
        tween: Tween(begin: 0, end: selected || revealedWinner || bettingOpen ? 1 : .45),
        duration: const Duration(milliseconds: 320),
        builder: (context, glow, child) {
          return Container(
            width: width,
            height: height,
            padding: EdgeInsets.all(width * .05),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  softAccent.withValues(alpha: .20 + (.05 * glow)),
                  Colors.white.withValues(alpha: selected ? .14 : .10),
                  Colors.black.withValues(alpha: .20),
                ],
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              ),
              borderRadius: BorderRadius.circular(width * .10),
              boxShadow: [
                BoxShadow(
                  color: accent.withValues(alpha: (.12 + (.14 * pulse)) * glow),
                  blurRadius: width * (.12 + (.06 * pulse)),
                  spreadRadius: width * (.012 + (.012 * pulse)),
                ),
                BoxShadow(
                  color: Colors.black38,
                  blurRadius: width * .06,
                  offset: Offset(0, height * .012),
                ),
              ],
              border: Border.all(
                color:
                    revealedWinner
                        ? accent.withValues(alpha: .88)
                        : selected
                            ? accent.withValues(alpha: .68)
                            : Colors.white10,
                width: selected || revealedWinner ? width * .024 : 1.1,
              ),
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
                            ? accent.withValues(alpha: .18)
                            : selected
                                ? accent.withValues(alpha: .14)
                                : Colors.black.withValues(alpha: .18),
                    borderRadius: BorderRadius.circular(999),
                    border: Border.all(
                      color:
                          revealedWinner
                              ? accent.withValues(alpha: .82)
                              : selected
                                  ? accent.withValues(alpha: .58)
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
                      color: revealedWinner ? accent : Colors.white,
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
                  child: _CardFan(
                    paths: cards,
                    cardWidth: width * .40,
                    sideOffset: width * .13,
                    centerLift: 4,
                    sideOpacity: .78,
                  ),
                ),
                SizedBox(height: height * .01),
                _PotGemPile(gems: gemStack, width: width, accent: accent),
                SizedBox(height: height * .018),
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
                    color: selected ? accent : Colors.white70,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const Spacer(),
                if (bettingOpen)
                  Text(
                    revealedWinner
                        ? 'Winner Locked'
                        : selected
                            ? 'Ready for gem bet'
                            : 'Tap to target',
                    style: TextStyle(
                      color: selected ? accent : Colors.white54,
                      fontWeight: FontWeight.w700,
                      fontSize: width * .064,
                    ),
                  ),
              ],
            ),
          );
        },
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
          errorBuilder: (context, error, stackTrace) {
            return Image.asset(
              _TeenPattiGamePanelState._cardBackAsset,
              width: width,
              height: width * 1.34,
              fit: BoxFit.contain,
              errorBuilder: (context, nestedError, nestedStackTrace) {
                return Container(
                  width: width,
                  height: width * 1.34,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(12),
                    gradient: const LinearGradient(
                      colors: [Color(0xFF3E2A56), Color(0xFF1C132B)],
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                    ),
                    border: Border.all(color: Colors.white24),
                  ),
                );
              },
            );
          },
        ),
      ),
    );
  }
}

class _CardFan extends StatelessWidget {
  const _CardFan({
    required this.paths,
    required this.cardWidth,
    required this.sideOffset,
    required this.centerLift,
    this.sideOpacity = .8,
  });

  final List<String> paths;
  final double cardWidth;
  final double sideOffset;
  final double centerLift;
  final double sideOpacity;

  @override
  Widget build(BuildContext context) {
    final resolved = paths.length >= 3
        ? paths
        : const [
            _TeenPattiGamePanelState._cardBackAsset,
            _TeenPattiGamePanelState._cardBackAsset,
            _TeenPattiGamePanelState._cardBackAsset,
          ];

    return SizedBox(
      width: (cardWidth * 2) + sideOffset,
      height: (cardWidth * 1.34) + centerLift + 2,
      child: Stack(
        alignment: Alignment.bottomCenter,
        clipBehavior: Clip.none,
        children: [
          Transform.translate(
            offset: Offset(-sideOffset, 0),
            child: _CardImage(
              path: resolved[0],
              width: cardWidth,
              opacity: sideOpacity,
            ),
          ),
          Transform.translate(
            offset: Offset(0, -centerLift),
            child: _CardImage(
              path: resolved[1],
              width: cardWidth,
              opacity: .94,
            ),
          ),
          Transform.translate(
            offset: Offset(sideOffset, 0),
            child: _CardImage(
              path: resolved[2],
              width: cardWidth,
              opacity: sideOpacity,
            ),
          ),
        ],
      ),
    );
  }
}

class _PotGemPile extends StatelessWidget {
  const _PotGemPile({
    required this.gems,
    required this.width,
    required this.accent,
  });

  final List<String> gems;
  final double width;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    if (gems.isEmpty) {
      return SizedBox(height: width * .12);
    }

    return SizedBox(
      height: width * .18,
      child: Center(
        child: SizedBox(
          width: width * .48,
          child: Stack(
            clipBehavior: Clip.none,
            children: List.generate(gems.length, (index) {
              final left = (width * .09) * index;
              final top = index.isEven ? 4.0 : 0.0;
              return Positioned(
                left: left,
                top: top,
                child: Transform.rotate(
                  angle: (index.isEven ? -.08 : .08),
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      boxShadow: [
                        BoxShadow(
                          color: accent.withValues(alpha: .18),
                          blurRadius: 8,
                          spreadRadius: 1,
                        ),
                      ],
                    ),
                    child: Image.asset(
                      gems[index],
                      width: width * .16,
                      height: width * .16,
                      fit: BoxFit.contain,
                    ),
                  ),
                ),
              );
            }),
          ),
        ),
      ),
    );
  }
}

class _BetGemButton extends StatefulWidget {
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
  State<_BetGemButton> createState() => _BetGemButtonState();
}

class _BetGemButtonState extends State<_BetGemButton> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final shortLabel = switch (widget.value) {
      1000 => '1K',
      5000 => '5K',
      _ => '${widget.value}',
    };
    return Opacity(
      opacity: widget.enabled ? 1 : .42,
      child: GestureDetector(
        onTapDown: widget.enabled
            ? (details) {
                setState(() => _pressed = true);
                Haptics.selection();
                widget.onTapDown(details);
              }
            : null,
        onTapUp: (_) => setState(() => _pressed = false),
        onTapCancel: () => setState(() => _pressed = false),
        child: AnimatedScale(
          duration: const Duration(milliseconds: 120),
          scale: _pressed ? .92 : 1,
          child: AnimatedContainer(
            key: widget.chipKey,
            duration: const Duration(milliseconds: 180),
          padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            gradient: LinearGradient(
              colors: [
                Colors.white.withValues(alpha: widget.active ? .16 : .06),
                Colors.black.withValues(alpha: .14),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
            border: Border.all(
              color: widget.active
                  ? const Color(0xFFFFE082)
                  : Colors.white12,
              width: widget.active ? 2 : 1,
            ),
            boxShadow: [
              if (widget.active)
                const BoxShadow(
                  color: Color(0x66FFD54F),
                  blurRadius: 12,
                  spreadRadius: 1,
                ),
              const BoxShadow(
                color: Colors.black38,
                blurRadius: 8,
                offset: Offset(0, 4),
              ),
            ],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              _RotatingGem(imagePath: widget.imagePath, active: widget.active),
              const SizedBox(height: 4),
              Text(
                shortLabel,
                style: TextStyle(
                  color: widget.active ? const Color(0xFFFFF2B0) : Colors.white,
                  fontSize: 12,
                  fontWeight: FontWeight.w900,
                  letterSpacing: .3,
                ),
              ),
            ],
          ),
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
            Colors.white.withValues(alpha: .08),
            Colors.black.withValues(alpha: .18),
          ],
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: const Color(0x33D4AF37)),
        boxShadow: const [
          BoxShadow(
            color: Colors.black45,
            blurRadius: 18,
            offset: Offset(0, 10),
          ),
        ],
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
                    fontSize: 23,
                    fontWeight: FontWeight.w900,
                    color: Color(0xFFFFF2B0),
                    letterSpacing: .4,
                    shadows: [
                      Shadow(
                        color: Color(0x66000000),
                        blurRadius: 12,
                        offset: Offset(0, 3),
                      ),
                    ],
                  ),
                ),
              ),
              if (selectedPot != null) ...[
                _SelectionChip(
                  label: 'Pot $selectedPot',
                  active: true,
                ),
                const SizedBox(width: 8),
              ],
              _SelectionChip(
                label:
                    'Gem ${selectedChip >= 1000 ? '${selectedChip ~/ 1000}K' : selectedChip}',
                active: true,
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            selectedPot == null
                ? 'Pick a pot, then drop a gem.'
                : phase == 'betting'
                    ? 'Pot $selectedPot armed. Drop your gem.'
                    : 'Round closed.',
            style: TextStyle(
              color: Colors.white.withValues(alpha: .80),
              fontWeight: FontWeight.w700,
              fontSize: 11.5,
              height: 1.35,
            ),
          ),
          const SizedBox(height: 14),
          Row(
            key: chipRowKey,
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children:
                chipOptions.map((chip) {
                  final enabled = phase == 'betting';
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
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: active
              ? [
                  const Color(0xFFFFD54F).withValues(alpha: .22),
                  const Color(0xFFFF8A5B).withValues(alpha: .16),
                ]
              : [
                  Colors.white.withValues(alpha: .10),
                  Colors.white.withValues(alpha: .04),
                ],
        ),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(
          color:
              active
                  ? const Color(0xFFFFD54F).withValues(alpha: .44)
                  : Colors.white12,
        ),
        boxShadow: active
            ? const [
                BoxShadow(
                  color: Color(0x44FFD54F),
                  blurRadius: 10,
                  spreadRadius: 1,
                ),
              ]
            : null,
      ),
      child: Text(
        label,
        style: TextStyle(
          color: active ? const Color(0xFFFFF2B0) : Colors.white70,
          fontWeight: FontWeight.w900,
          fontSize: 11,
          letterSpacing: .25,
        ),
      ),
    );
  }
}

Color _potAccentColor(String pot) {
  return switch (pot) {
    'A' => const Color(0xFF66B8FF),
    'B' => const Color(0xFFFF8A5B),
    'C' => const Color(0xFF68D391),
    _ => const Color(0xFFE8C76A),
  };
}

class _RotatingGem extends StatefulWidget {
  const _RotatingGem({required this.imagePath, required this.active});

  final String imagePath;
  final bool active;

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
            width: widget.active ? 54 : 50,
            height: widget.active ? 54 : 50,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: RadialGradient(
                colors: widget.active
                    ? const [Color(0x88FFF7CC), Color(0x44FFD54F)]
                    : const [Color(0x66FFFFFF), Color(0x33FFD54F)],
                center: Alignment.center,
                radius: .82,
              ),
              boxShadow: [
                BoxShadow(
                  color: widget.active
                      ? const Color(0x88FFD54F)
                      : const Color(0x66FFD54F),
                  blurRadius: widget.active ? 16 : 12,
                  spreadRadius: widget.active ? 5 : 3,
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

class _TeenPattiResultDialog extends StatefulWidget {
  const _TeenPattiResultDialog({
    required this.round,
    required this.winningBetAmount,
    required this.winningPayoutAmount,
  });

  final TeenPattiRound round;
  final int winningBetAmount;
  final int winningPayoutAmount;

  @override
  State<_TeenPattiResultDialog> createState() => _TeenPattiResultDialogState();
}

class _TeenPattiResultDialogState extends State<_TeenPattiResultDialog> {
  bool _cardsVisible = false;
  bool _highlightVisible = false;
  bool _payoutVisible = false;

  @override
  void initState() {
    super.initState();
    Future<void>.delayed(const Duration(milliseconds: 120), () {
      if (!mounted) return;
      setState(() => _cardsVisible = true);
      SystemSound.play(SystemSoundType.click);
    });
    Future<void>.delayed(const Duration(milliseconds: 300), () {
      if (!mounted) return;
      setState(() => _highlightVisible = true);
    });
    Future<void>.delayed(const Duration(milliseconds: 520), () {
      if (!mounted) return;
      setState(() => _payoutVisible = true);
      Haptics.medium();
    });
  }

  @override
  Widget build(BuildContext context) {
    final winningPot = widget.round.winningPot ?? '—';
    final accent = _potAccentColor(winningPot);
    final winningCards = [
      if (widget.round.winningHand.isNotEmpty)
        widget.round.winningHand[0]
      else
        'assets/games/teen_patti/card_back_1.jpeg',
      if (widget.round.winningHand.length > 1)
        widget.round.winningHand[1]
      else
        'assets/games/teen_patti/card_back_1.jpeg',
      if (widget.round.winningHand.length > 2)
        widget.round.winningHand[2]
      else
        'assets/games/teen_patti/card_back_1.jpeg',
    ];
    return Dialog(
      backgroundColor: Colors.transparent,
      child: Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [Color(0xFF1C0F2B), Color(0xFF0C0814)],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
          borderRadius: BorderRadius.circular(24),
          border: Border.all(color: const Color(0xFFE1B12C), width: 1.5),
          boxShadow: [
            BoxShadow(
              color: accent.withValues(alpha: .22),
              blurRadius: 30,
              spreadRadius: 2,
            ),
          ],
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            AnimatedScale(
              scale: _highlightVisible ? 1 : .92,
              duration: const Duration(milliseconds: 260),
              curve: Curves.easeOutBack,
              child: AnimatedOpacity(
                opacity: _highlightVisible ? 1 : .45,
                duration: const Duration(milliseconds: 220),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: [
                    accent.withValues(alpha: .18),
                    const Color(0xFFFFC107).withValues(alpha: .16),
                  ],
                ),
                borderRadius: BorderRadius.circular(18),
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
              ),
            ),
            const SizedBox(height: 18),
            AnimatedOpacity(
              opacity: _cardsVisible ? 1 : 0,
              duration: const Duration(milliseconds: 220),
              child: AnimatedSlide(
                offset: _cardsVisible ? Offset.zero : const Offset(0, .08),
                duration: const Duration(milliseconds: 240),
                curve: Curves.easeOutCubic,
                child: Container(
                  height: 138,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(22),
                    gradient: LinearGradient(
                      colors: [
                        accent.withValues(alpha: .14),
                        Colors.black.withValues(alpha: .22),
                      ],
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                    ),
                    border: Border.all(color: accent.withValues(alpha: .36)),
                  ),
                  child: Stack(
                    alignment: Alignment.center,
                    children: [
                      Container(
                        width: 160,
                        height: 110,
                        decoration: BoxDecoration(
                          gradient: RadialGradient(
                            colors: [accent.withValues(alpha: .22), accent.withValues(alpha: 0)],
                          ),
                        ),
                      ),
                      _CardFan(
                        paths: [
                          winningCards[0].contains('/')
                              ? winningCards[0]
                              : 'assets/games/teen_patti/cards/${winningCards[0]}',
                          winningCards[1].contains('/')
                              ? winningCards[1]
                              : 'assets/games/teen_patti/cards/${winningCards[1]}',
                          winningCards[2].contains('/')
                              ? winningCards[2]
                              : 'assets/games/teen_patti/cards/${winningCards[2]}',
                        ],
                        cardWidth: 80,
                        sideOffset: 34,
                        centerLift: 6,
                        sideOpacity: .80,
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
              child: _ResultInfoTile(
                title: 'Your Bet on Winning Pot',
                value: widget.winningBetAmount,
                color: const Color(0xFFFFA726),
                icon: Icons.local_atm_rounded,
              ),
            ),
            const SizedBox(height: 10),
            AnimatedOpacity(
              opacity: _payoutVisible ? 1 : 0,
              duration: const Duration(milliseconds: 320),
              child: _ResultInfoTile(
                title: 'Your Winning Amount',
                value: widget.winningPayoutAmount,
                color: const Color(0xFF66BB6A),
                icon: Icons.workspace_premium_rounded,
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
    );
  }
}

class _ResultInfoTile extends StatelessWidget {
  const _ResultInfoTile({
    required this.title,
    required this.value,
    required this.color,
    required this.icon,
  });

  final String title;
  final int value;
  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            color.withValues(alpha: .22),
            color.withValues(alpha: .08),
          ],
        ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: color.withValues(alpha: .28)),
      ),
      child: Row(
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              color: color.withValues(alpha: .18),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: color, size: 18),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              title,
              style: const TextStyle(
                color: Colors.white70,
                fontSize: 13,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          TweenAnimationBuilder<double>(
            tween: Tween(begin: 0, end: value.toDouble()),
            duration: const Duration(milliseconds: 850),
            curve: Curves.easeOutCubic,
            builder: (context, animatedValue, child) {
              return Text(
                animatedValue.round().toString(),
                style: TextStyle(
                  color: color,
                  fontWeight: FontWeight.w900,
                  fontSize: 20,
                ),
              );
            },
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
    required this.accent,
    required this.left,
    required this.top,
    required this.targetLeft,
    required this.targetTop,
  });

  final int id;
  final String imagePath;
  final Color accent;
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
      accent: accent,
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
