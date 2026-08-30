import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:audioplayers/audioplayers.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../../../../app/widgets/coin_lottie.dart';
import '../../../../app/widgets/haptics.dart';
import '../../../wallet/widgets/recharge_bottom_sheet.dart';
import '../models/fortune_wheel_models.dart';
import '../services/fortune_wheel_preload_service.dart';

const _fortuneBgAsset = 'assets/games/fortune_wheel/fortune_bg.png';
const _fortuneWheelFrameAsset = 'assets/games/fortune_wheel/wheel_frame.png';
const _fortuneSpinButtonAsset = 'assets/games/fortune_wheel/spin_button.png';
const _fortunePremiumSpinButtonAsset =
    'assets/games/fortune_wheel/spin_button_premium.png';
const _fortunePremiumRewardAsset =
    'assets/games/fortune_wheel/reward_treasure_premium.png';
const _fortuneTitleAsset = 'assets/games/fortune_wheel/spin_and_win_title.png';
const _fortuneSpinSoundAsset = 'games/fortune_wheel/wheel_spin.mp3';

enum FortuneWheelDialogResult { recharge }

Future<void> showFortuneWheelDialog(
  BuildContext context, {
  bool freeSpinOnly = false,
  bool playSounds = true,
}) async {
  final result = await showGeneralDialog<FortuneWheelDialogResult>(
    context: context,
    useRootNavigator: true,
    barrierDismissible: false,
    barrierLabel: 'Close Fortune Wheel',
    barrierColor: Colors.black.withValues(alpha: .16),
    transitionDuration: const Duration(milliseconds: 360),
    pageBuilder: (dialogContext, _, __) {
      final size = MediaQuery.sizeOf(dialogContext);
      final width = math.min(size.width - 24, 480.0);
      final height = math.min(size.height - 48, 720.0);
      return SafeArea(
        minimum: const EdgeInsets.all(12),
        child: Center(
          child: SizedBox(
            width: width,
            height: height,
            child: RepaintBoundary(
              child: FortuneWheelPanel(
                showCloseButton: true,
                freeSpinOnly: freeSpinOnly,
                playSounds: playSounds,
                onClose:
                    () =>
                        Navigator.of(dialogContext, rootNavigator: true).pop(),
                onRechargeRequired:
                    () => Navigator.of(
                      dialogContext,
                      rootNavigator: true,
                    ).pop(FortuneWheelDialogResult.recharge),
              ),
            ),
          ),
        ),
      );
    },
    transitionBuilder: (context, animation, secondaryAnimation, child) {
      final curved = CurvedAnimation(
        parent: animation,
        curve: Curves.easeOutCubic,
        reverseCurve: Curves.easeInCubic,
      );
      return FadeTransition(
        opacity: animation,
        child: ScaleTransition(
          scale: Tween<double>(begin: .94, end: 1).animate(curved),
          child: child,
        ),
      );
    },
  );

  if (result == FortuneWheelDialogResult.recharge && context.mounted) {
    await showModalBottomSheet<void>(
      context: context,
      useRootNavigator: true,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const RechargeBottomSheet(),
    );
  }
}

class FortuneWheelPanel extends StatefulWidget {
  const FortuneWheelPanel({
    super.key,
    this.showCloseButton = false,
    this.freeSpinOnly = false,
    this.playSounds = true,
    this.onClose,
    this.onRechargeRequired,
  });

  final bool showCloseButton;
  final bool freeSpinOnly;
  final bool playSounds;
  final VoidCallback? onClose;
  final VoidCallback? onRechargeRequired;

  @override
  State<FortuneWheelPanel> createState() => _FortuneWheelPanelState();
}

class _FortuneWheelPanelState extends State<FortuneWheelPanel>
    with TickerProviderStateMixin {
  late final AnimationController _spinController;
  late final AnimationController _ambientController;
  late final AnimationController _entranceController;
  late Animation<double> _spinAnimation;
  AudioPlayer? _spinAudioPlayer;

  bool _spinning = false;
  String? _error;
  double _rotation = 0;
  FortuneWheelSpin? _visibleReward;
  Completer<void>? _rewardDismissed;

  FortuneWheelPreloadService get _service =>
      Get.find<FortuneWheelPreloadService>();

  @override
  void initState() {
    super.initState();
    if (widget.playSounds) {
      _spinAudioPlayer = AudioPlayer()..setReleaseMode(ReleaseMode.stop);
    }
    _spinController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 3680),
      animationBehavior: AnimationBehavior.preserve,
    );
    _spinAnimation = AlwaysStoppedAnimation<double>(_rotation);
    _spinController.addListener(() {
      setState(() => _rotation = _spinAnimation.value);
    });
    _ambientController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 6200),
    )..repeat();
    _entranceController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 850),
    )..forward();
    unawaited(_service.maybePreload(reason: 'panel_open'));
  }

  @override
  void dispose() {
    final rewardDismissed = _rewardDismissed;
    _rewardDismissed = null;
    if (rewardDismissed != null && !rewardDismissed.isCompleted) {
      rewardDismissed.complete();
    }
    final spinAudioPlayer = _spinAudioPlayer;
    _spinAudioPlayer = null;
    if (spinAudioPlayer != null) {
      unawaited(spinAudioPlayer.stop());
      unawaited(spinAudioPlayer.dispose());
    }
    _spinController.dispose();
    _ambientController.dispose();
    _entranceController.dispose();
    super.dispose();
  }

  Future<void> _spin(FortuneWheelSnapshot snapshot) async {
    if (_spinning || snapshot.segments.isEmpty) return;
    if (widget.freeSpinOnly && !snapshot.canFreeSpin) {
      widget.onClose?.call();
      return;
    }
    if (!snapshot.canFreeSpin &&
        snapshot.settings.paidSpinsEnabled &&
        snapshot.walletBalance < snapshot.settings.paidSpinCostCoins) {
      _showRechargePrompt();
      return;
    }
    if (!snapshot.canFreeSpin && !snapshot.settings.paidSpinsEnabled) {
      setState(() => _error = 'Paid spins are disabled right now.');
      return;
    }

    setState(() {
      _spinning = true;
      _error = null;
    });

    try {
      HapticFeedback.mediumImpact();
      final result = await _service.spin();
      final resultSegments =
          result.segments.isEmpty ? snapshot.segments : result.segments;
      final targetIndex = _targetIndex(resultSegments, result.spin);
      final targetRotation = _targetRotation(
        resultSegments.length,
        targetIndex,
      );
      _spinAnimation = Tween<double>(
        begin: _rotation,
        end: targetRotation,
      ).animate(
        CurvedAnimation(parent: _spinController, curve: Curves.easeOutCubic),
      );
      unawaited(_playSpinSound());
      await _spinController.forward(from: 0);
      if (!mounted) return;
      // Animation controllers complete on wall-clock time. Explicitly paint
      // the winning position before the reward is allowed to cover the wheel,
      // which keeps low-frame-rate and reduced-animation devices in sequence.
      setState(() {
        _rotation = targetRotation;
        _spinning = false;
      });
      await WidgetsBinding.instance.endOfFrame;
      if (!mounted) return;
      await Future<void>.delayed(const Duration(milliseconds: 280));
      if (!mounted) return;
      Haptics.success();
      await _showReward(result.spin);
      if (mounted && widget.freeSpinOnly) {
        widget.onClose?.call();
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _spinning = false;
        _error = e.toString().replaceFirst('Exception: ', '');
      });
      Haptics.warning();
    }
  }

  Future<void> _playSpinSound() async {
    final spinAudioPlayer = _spinAudioPlayer;
    if (spinAudioPlayer == null) return;
    try {
      await spinAudioPlayer.stop();
      await spinAudioPlayer.play(AssetSource(_fortuneSpinSoundAsset));
    } catch (_) {
      // Audio must never block or invalidate a server-authoritative spin.
    }
  }

  int _targetIndex(List<FortuneWheelSegment> segments, FortuneWheelSpin spin) {
    final segmentId = spin.segment?.id;
    final index = segments.indexWhere((segment) => segment.id == segmentId);
    return index >= 0 ? index : 0;
  }

  double _targetRotation(int count, int targetIndex) {
    final slice = (math.pi * 2) / math.max(1, count);
    final targetCenter = (targetIndex * slice) + (slice / 2);
    // The painter already starts segment zero at -pi/2 (the top pointer), so
    // only the segment's offset from that origin must be reversed here.
    final desired = -targetCenter;
    final fullTurns = 6 + math.Random().nextInt(3);
    final base = (math.pi * 2 * fullTurns) + desired;
    final currentTurns = (_rotation / (math.pi * 2)).floor();
    var target = base + (math.pi * 2 * currentTurns);
    while (target <= _rotation + (math.pi * 2 * 4)) {
      target += math.pi * 2;
    }
    return target;
  }

  Future<void> _showReward(FortuneWheelSpin spin) {
    if (!mounted) return Future<void>.value();
    final dismissed = Completer<void>();
    setState(() {
      _visibleReward = spin;
      _rewardDismissed = dismissed;
    });
    return dismissed.future;
  }

  void _dismissReward() {
    final dismissed = _rewardDismissed;
    if (_visibleReward == null && dismissed == null) return;
    setState(() {
      _visibleReward = null;
      _rewardDismissed = null;
    });
    if (dismissed != null && !dismissed.isCompleted) {
      dismissed.complete();
    }
  }

  void _showRechargePrompt() {
    widget.onRechargeRequired?.call();
  }

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      final snapshot = _service.snapshot.value;
      final loading = _service.loading.value;
      final error = _error ?? _service.error.value;

      return AnimatedBuilder(
        animation: Listenable.merge([_ambientController, _entranceController]),
        builder: (context, _) {
          final ambient = _ambientController.value;
          final entrance = Curves.easeOutCubic.transform(
            _entranceController.value,
          );

          return PopScope(
            canPop: !_spinning && _visibleReward == null,
            onPopInvoked: (didPop) {
              if (!didPop && _visibleReward != null) {
                _dismissReward();
              }
            },
            child: ColoredBox(
              color: Colors.transparent,
              child: Stack(
                children: [
                  SafeArea(
                    top: false,
                    child: Opacity(
                      opacity: _visibleReward == null ? entrance : 0,
                      child: Transform.translate(
                        offset: Offset(0, (1 - entrance) * 26),
                        child: ListView(
                          padding: const EdgeInsets.fromLTRB(18, 12, 18, 24),
                          children: [
                            _FortuneTopBar(
                              snapshot: snapshot,
                              showBalance: !widget.freeSpinOnly,
                              showCloseButton: widget.showCloseButton,
                              onClose:
                                  _spinning
                                      ? () {}
                                      : widget.onClose ??
                                          () =>
                                              Navigator.of(context).maybePop(),
                            ),
                            const SizedBox(height: 8),
                            if (loading && snapshot == null)
                              const _FortuneLoadingCard()
                            else if (error != null && snapshot == null)
                              _FortuneErrorCard(
                                message: error,
                                onRetry: () => unawaited(_service.refresh()),
                              )
                            else if (snapshot == null)
                              _FortuneErrorCard(
                                message:
                                    'Fortune Wheel is not available right now.',
                                onRetry: () => unawaited(_service.refresh()),
                              )
                            else ...[
                              if (snapshot.segments.isEmpty)
                                _NoSegmentsCard(
                                  onRefresh:
                                      () => unawaited(_service.refresh()),
                                )
                              else ...[
                                _WheelStage(
                                  snapshot: snapshot,
                                  rotation: _rotation,
                                  spinning: _spinning,
                                  ambient: ambient,
                                ),
                                const SizedBox(height: 4),
                                _SpinButton(
                                  snapshot: snapshot,
                                  spinning: _spinning,
                                  ambient: ambient,
                                  onPressed: () => unawaited(_spin(snapshot)),
                                ),
                              ],
                              if (error != null) ...[
                                const SizedBox(height: 12),
                                _InlineError(message: error),
                              ],
                            ],
                          ],
                        ),
                      ),
                    ),
                  ),
                  if (_visibleReward != null)
                    Positioned.fill(
                      child: GestureDetector(
                        behavior: HitTestBehavior.opaque,
                        onTap: () {},
                        child: Center(
                          child: Material(
                            color: Colors.transparent,
                            child: ConstrainedBox(
                              constraints: const BoxConstraints(maxWidth: 420),
                              child: _FortuneRewardSheet(
                                spin: _visibleReward!,
                                onClose: _dismissReward,
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          );
        },
      );
    });
  }
}

class _FortuneTopBar extends StatelessWidget {
  const _FortuneTopBar({
    required this.snapshot,
    required this.showBalance,
    required this.showCloseButton,
    required this.onClose,
  });

  final FortuneWheelSnapshot? snapshot;
  final bool showBalance;
  final bool showCloseButton;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 70,
      child: Row(
        children: [
          SizedBox(
            width: 86,
            child:
                snapshot == null || !showBalance
                    ? const SizedBox.shrink()
                    : _BalanceChip(balance: snapshot!.walletBalance),
          ),
          Expanded(
            child: Image.asset(
              _fortuneTitleAsset,
              height: 64,
              fit: BoxFit.contain,
              filterQuality: FilterQuality.high,
            ),
          ),
          SizedBox(
            width: 86,
            child:
                showCloseButton
                    ? Align(
                      alignment: Alignment.centerRight,
                      child: _FortuneCloseButton(onPressed: onClose),
                    )
                    : const SizedBox.shrink(),
          ),
        ],
      ),
    );
  }
}

class _BalanceChip extends StatelessWidget {
  const _BalanceChip({required this.balance});

  final int balance;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        gradient: const LinearGradient(
          colors: [Color(0xFFFFF0A6), Color(0xFFFFB414), Color(0xFF9A4700)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFFFFB414).withValues(alpha: .24),
            blurRadius: 16,
            spreadRadius: 1,
          ),
          BoxShadow(
            color: Colors.black.withValues(alpha: .48),
            blurRadius: 12,
            offset: const Offset(0, 7),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(2),
        child: DecoratedBox(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(999),
            gradient: const LinearGradient(
              colors: [Color(0xFF4A1268), Color(0xFF1B072A), Color(0xFF09030F)],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
            border: Border.all(color: const Color(0xFF8D4CAB), width: .8),
          ),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(3, 2, 10, 2),
            child: Row(
              children: [
                Container(
                  width: 31,
                  height: 31,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: const RadialGradient(
                      colors: [
                        Color(0xFFFFF4A8),
                        Color(0xFFFFB20F),
                        Color(0xFFC35B00),
                      ],
                      center: Alignment(-.32, -.38),
                    ),
                    border: Border.all(
                      color: const Color(0xFFFFF1A1),
                      width: 1.2,
                    ),
                    boxShadow: const [
                      BoxShadow(color: Color(0x99FFB414), blurRadius: 7),
                    ],
                  ),
                  child: const Center(child: CoinLottie(size: 24)),
                ),
                const SizedBox(width: 5),
                Expanded(
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '$balance',
                          maxLines: 1,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 14,
                            height: 1,
                            fontWeight: FontWeight.w900,
                            decoration: TextDecoration.none,
                            shadows: [
                              Shadow(color: Color(0xFFFFB414), blurRadius: 7),
                            ],
                          ),
                        ),
                        const Text(
                          'COINS',
                          style: TextStyle(
                            color: Color(0xFFFFD86A),
                            fontSize: 7,
                            height: 1.2,
                            fontWeight: FontWeight.w900,
                            letterSpacing: 1,
                            decoration: TextDecoration.none,
                          ),
                        ),
                      ],
                    ),
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

class _FortuneCloseButton extends StatelessWidget {
  const _FortuneCloseButton({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: const Color(0xFF090512).withValues(alpha: .78),
      shape: const CircleBorder(),
      elevation: 8,
      shadowColor: Colors.black,
      child: IconButton(
        tooltip: 'Close',
        onPressed: onPressed,
        icon: const Icon(Icons.close_rounded, size: 20),
        color: Colors.white,
        padding: EdgeInsets.zero,
        constraints: const BoxConstraints.tightFor(width: 38, height: 38),
      ),
    );
  }
}

class _WalletStrip extends StatelessWidget {
  const _WalletStrip({required this.snapshot});

  final FortuneWheelSnapshot snapshot;

  @override
  Widget build(BuildContext context) {
    final free = snapshot.canFreeSpin;
    return Wrap(
      alignment: WrapAlignment.center,
      spacing: 10,
      runSpacing: 8,
      children: [
        _MiniStat(
          label: '${snapshot.walletBalance}',
          icon: Icons.monetization_on_rounded,
          accent: const Color(0xFFFFD76B),
        ),
        _MiniStat(
          label:
              free
                  ? '${snapshot.freeSpinsRemaining} FREE'
                  : '${snapshot.settings.paidSpinCostCoins} COINS',
          icon: free ? Icons.card_giftcard_rounded : Icons.bolt_rounded,
          accent: free ? const Color(0xFF67E8F9) : const Color(0xFFFF5FD2),
        ),
      ],
    );
  }
}

class _MiniStat extends StatelessWidget {
  const _MiniStat({
    required this.label,
    required this.icon,
    required this.accent,
  });

  final String label;
  final IconData icon;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 8),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: Colors.white.withValues(alpha: .06),
        border: Border.all(color: Colors.white.withValues(alpha: .10)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: accent, size: 17),
          const SizedBox(width: 7),
          Text(
            label,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 12,
              fontWeight: FontWeight.w900,
              letterSpacing: .2,
            ),
          ),
        ],
      ),
    );
  }
}

class _WheelStage extends StatelessWidget {
  const _WheelStage({
    required this.snapshot,
    required this.rotation,
    required this.spinning,
    required this.ambient,
  });

  final FortuneWheelSnapshot snapshot;
  final double rotation;
  final bool spinning;
  final double ambient;

  @override
  Widget build(BuildContext context) {
    final size = math.min(MediaQuery.sizeOf(context).width - 44, 330.0);
    final wheelSize = size * .82;
    final hubSize = size * .27;
    final wheelCenterY = 44 + (wheelSize / 2);
    final glowPulse = .5 + (.5 * math.sin(ambient * math.pi * 2));
    final frameScale = 1 + (glowPulse * .018);
    return Center(
      child: SizedBox(
        width: size,
        height: size + 48,
        child: Stack(
          alignment: Alignment.center,
          children: [
            Positioned(
              top: 44,
              child: Container(
                width: wheelSize,
                height: wheelSize,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  boxShadow: [
                    BoxShadow(
                      color: const Color(
                        0xFFFFD76B,
                      ).withValues(alpha: .18 + glowPulse * .12),
                      blurRadius: 42 + glowPulse * 18,
                      spreadRadius: 4 + glowPulse * 4,
                    ),
                    BoxShadow(
                      color: Colors.black.withValues(alpha: .42),
                      blurRadius: 36,
                      offset: const Offset(0, 18),
                    ),
                  ],
                ),
                child: Transform.rotate(
                  angle: rotation,
                  child: Stack(
                    children: [
                      CustomPaint(
                        painter: _FortuneWheelPainter(snapshot.segments),
                        child: Container(),
                      ),
                      Positioned.fill(
                        child: _WheelSegmentIconLayer(
                          segments: snapshot.segments,
                        ),
                      ),
                      Positioned.fill(
                        child: CustomPaint(
                          painter: _WheelSweepPainter(ambient, spinning),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
            Positioned(
              top: 18,
              child: IgnorePointer(
                child: Transform.scale(
                  scale: frameScale,
                  child: Image.asset(
                    _fortuneWheelFrameAsset,
                    width: size,
                    height: size,
                    fit: BoxFit.contain,
                    filterQuality: FilterQuality.high,
                  ),
                ),
              ),
            ),
            Positioned(
              top: 42,
              child: IgnorePointer(
                child: AnimatedScale(
                  scale: spinning ? 1.08 : 1,
                  duration: const Duration(milliseconds: 300),
                  curve: Curves.easeOutBack,
                  child: const SizedBox(
                    width: 34,
                    height: 38,
                    child: CustomPaint(painter: _FortunePointerPainter()),
                  ),
                ),
              ),
            ),
            Positioned(
              top: wheelCenterY - (hubSize / 2),
              child: AnimatedScale(
                scale: spinning ? 1.12 : 1 + glowPulse * .025,
                duration: const Duration(milliseconds: 500),
                curve: Curves.easeOutBack,
                child: SizedBox(
                  width: hubSize,
                  height: hubSize,
                  child: Stack(
                    alignment: Alignment.center,
                    children: [
                      Image.asset(
                        _fortuneSpinButtonAsset,
                        fit: BoxFit.contain,
                        filterQuality: FilterQuality.high,
                      ),
                      Icon(
                        spinning
                            ? Icons.auto_awesome_rounded
                            : Icons.stars_rounded,
                        color: Colors.white,
                        size: size * .075,
                        shadows: const [
                          Shadow(color: Color(0xFF4A0045), blurRadius: 7),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _WheelSegmentIconLayer extends StatelessWidget {
  const _WheelSegmentIconLayer({required this.segments});

  final List<FortuneWheelSegment> segments;

  @override
  Widget build(BuildContext context) {
    if (!segments.any(
      (segment) => segment.iconUrl?.trim().isNotEmpty ?? false,
    )) {
      return const SizedBox.shrink();
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        final size = math.min(constraints.maxWidth, constraints.maxHeight);
        final center = Offset(
          constraints.maxWidth / 2,
          constraints.maxHeight / 2,
        );
        final slice = math.pi * 2 / math.max(1, segments.length);
        final iconSize = segments.length > 10 ? size * .066 : size * .078;
        final iconRadius = size * .19;

        return Stack(
          clipBehavior: Clip.none,
          children: [
            for (var index = 0; index < segments.length; index++)
              if (segments[index].iconUrl?.trim().isNotEmpty ?? false)
                _positionedIcon(
                  center: center,
                  angle: -math.pi / 2 + (index * slice) + (slice / 2),
                  radius: iconRadius,
                  size: iconSize,
                  url: segments[index].iconUrl!.trim(),
                ),
          ],
        );
      },
    );
  }

  Widget _positionedIcon({
    required Offset center,
    required double angle,
    required double radius,
    required double size,
    required String url,
  }) {
    final iconCenter =
        center + Offset(math.cos(angle), math.sin(angle)) * radius;
    final isSvg =
        Uri.tryParse(url)?.path.toLowerCase().endsWith('.svg') ?? false;

    return Positioned(
      left: iconCenter.dx - (size / 2),
      top: iconCenter.dy - (size / 2),
      width: size,
      height: size,
      child: Transform.rotate(
        angle: angle + math.pi / 2,
        child: DecoratedBox(
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: Colors.white.withValues(alpha: .16),
            boxShadow: const [
              BoxShadow(
                color: Colors.black38,
                blurRadius: 5,
                offset: Offset(0, 2),
              ),
            ],
          ),
          child: Padding(
            padding: EdgeInsets.all(size * .14),
            child:
                isSvg
                    ? SvgPicture.network(
                      url,
                      fit: BoxFit.contain,
                      placeholderBuilder: (_) => const SizedBox.shrink(),
                    )
                    : Image.network(
                      url,
                      fit: BoxFit.contain,
                      filterQuality: FilterQuality.high,
                      errorBuilder: (_, __, ___) => const SizedBox.shrink(),
                    ),
          ),
        ),
      ),
    );
  }
}

class _FortunePointerPainter extends CustomPainter {
  const _FortunePointerPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final path =
        Path()
          ..moveTo(2, 3)
          ..quadraticBezierTo(size.width / 2, -1, size.width - 2, 3)
          ..lineTo(size.width / 2, size.height - 2)
          ..close();
    canvas.drawShadow(path, Colors.black, 8, true);
    final paint =
        Paint()
          ..shader = const LinearGradient(
            colors: [Color(0xFFFFF2A8), Color(0xFFFFB21C), Color(0xFFB75A00)],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ).createShader(Offset.zero & size);
    canvas.drawPath(path, paint);
    canvas.drawPath(
      path,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.5
        ..color = Colors.white.withValues(alpha: .72),
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _FortuneWheelPainter extends CustomPainter {
  _FortuneWheelPainter(this.segments);

  final List<FortuneWheelSegment> segments;

  static const _fallbackColors = [
    Color(0xFF7C3AED),
    Color(0xFFDB2777),
    Color(0xFF0891B2),
    Color(0xFFEA580C),
    Color(0xFF059669),
    Color(0xFF9333EA),
  ];

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final radius = math.min(size.width, size.height) / 2;
    final slice = math.pi * 2 / math.max(1, segments.length);
    final rect = Rect.fromCircle(center: center, radius: radius);
    final textPainter = TextPainter(
      textDirection: TextDirection.ltr,
      textAlign: TextAlign.center,
      maxLines: 2,
    );

    for (var i = 0; i < segments.length; i++) {
      final start = -math.pi / 2 + (i * slice);
      final color = _segmentColor(segments[i], i);
      final paint =
          Paint()
            ..shader = RadialGradient(
              colors: [
                Color.lerp(color, Colors.white, .16)!,
                Color.lerp(color, Colors.black, .20)!,
              ],
            ).createShader(rect);
      canvas.drawArc(rect, start, slice, true, paint);

      final line =
          Paint()
            ..color = const Color(0xFFFFE7A3).withValues(alpha: .34)
            ..strokeWidth = 1.2;
      canvas.drawLine(
        center,
        center + Offset(math.cos(start), math.sin(start)) * radius,
        line,
      );

      final textAngle = start + (slice / 2);
      final labelOffset =
          center +
          Offset(math.cos(textAngle), math.sin(textAngle)) * (radius * .62);
      canvas.save();
      canvas.translate(labelOffset.dx, labelOffset.dy);
      canvas.rotate(textAngle + math.pi / 2);
      textPainter.text = TextSpan(
        text: _compactLabel(segments[i]),
        style: TextStyle(
          color: Colors.white,
          fontSize: segments.length > 10 ? 9.2 : 10.5,
          fontWeight: FontWeight.w900,
          letterSpacing: .25,
          height: 1.05,
          shadows: [Shadow(color: Colors.black87, blurRadius: 6)],
        ),
      );
      textPainter.layout(maxWidth: radius * .52);
      textPainter.paint(
        canvas,
        Offset(-textPainter.width / 2, -textPainter.height / 2),
      );
      canvas.restore();
    }

    canvas.drawCircle(
      center,
      radius,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 9
        ..color = Colors.white.withValues(alpha: .24),
    );
    canvas.drawCircle(
      center,
      radius - 7,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..color = const Color(0xFFFFD76B).withValues(alpha: .72),
    );
  }

  Color _segmentColor(FortuneWheelSegment segment, int index) {
    final raw = segment.colorHex?.replaceAll('#', '').trim();
    if (raw != null && (raw.length == 6 || raw.length == 8)) {
      final value = int.tryParse(raw.length == 6 ? 'FF$raw' : raw, radix: 16);
      if (value != null) return Color(value);
    }
    return _fallbackColors[index % _fallbackColors.length];
  }

  String _compactLabel(FortuneWheelSegment segment) {
    if (segment.rewardType == 'coins') {
      return '${segment.rewardValueCoins}\nCOINS';
    }
    if (segment.rewardType == 'entry_pack') {
      final name = _shortRewardName(segment.entryPackName, 'ENTRY');
      return '$name\nENTRY';
    }
    if (segment.rewardType == 'subscription') {
      final name = _shortRewardName(segment.subscriptionPlanName, 'VIP');
      return '$name\n1 DAY';
    }
    return segment.label;
  }

  String _shortRewardName(String? value, String fallback) {
    final name = value?.trim().toUpperCase();
    if (name == null || name.isEmpty) return fallback;
    return name.length <= 8 ? name : '${name.substring(0, 7)}.';
  }

  @override
  bool shouldRepaint(covariant _FortuneWheelPainter oldDelegate) {
    return oldDelegate.segments != segments;
  }
}

class _SpinButton extends StatefulWidget {
  const _SpinButton({
    required this.snapshot,
    required this.spinning,
    required this.ambient,
    required this.onPressed,
  });

  final FortuneWheelSnapshot snapshot;
  final bool spinning;
  final double ambient;
  final VoidCallback onPressed;

  @override
  State<_SpinButton> createState() => _PremiumSpinButtonState();
}

class _PremiumSpinButtonState extends State<_SpinButton> {
  bool _pressed = false;

  bool get _enabled => !widget.spinning && widget.snapshot.segments.isNotEmpty;

  @override
  Widget build(BuildContext context) {
    final isFree = widget.snapshot.canFreeSpin;
    final pulse = .5 + (.5 * math.sin(widget.ambient * math.pi * 2));
    final label =
        widget.spinning ? 'SPINNING' : (isFree ? 'FREE SPIN' : 'SPIN NOW');

    return Center(
      child: Semantics(
        button: true,
        enabled: _enabled,
        label: isFree ? 'Use free spin' : 'Spin for coins',
        child: GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTapDown: _enabled ? (_) => setState(() => _pressed = true) : null,
          onTapCancel: _enabled ? () => setState(() => _pressed = false) : null,
          onTapUp:
              _enabled
                  ? (_) {
                    setState(() => _pressed = false);
                    Haptics.selection();
                    widget.onPressed();
                  }
                  : null,
          child: AnimatedScale(
            duration: const Duration(milliseconds: 100),
            curve: Curves.easeOutBack,
            scale: _pressed ? .94 : 1 + (pulse * .025),
            child: SizedBox(
              width: 226,
              height: 92,
              child: Stack(
                alignment: Alignment.center,
                children: [
                  Positioned(
                    left: 15,
                    right: 15,
                    bottom: 5,
                    height: 30,
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(999),
                        boxShadow: [
                          BoxShadow(
                            color: const Color(
                              0xFFFF29C7,
                            ).withValues(alpha: .28 + pulse * .24),
                            blurRadius: 28 + pulse * 14,
                            spreadRadius: 4,
                          ),
                        ],
                      ),
                    ),
                  ),
                  AnimatedPositioned(
                    duration: const Duration(milliseconds: 100),
                    curve: Curves.easeOut,
                    left: 0,
                    right: 0,
                    top: _pressed ? 8 : 0,
                    bottom: _pressed ? 0 : 8,
                    child: Stack(
                      fit: StackFit.expand,
                      alignment: Alignment.center,
                      children: [
                        Image.asset(
                          _fortunePremiumSpinButtonAsset,
                          fit: BoxFit.fill,
                          filterQuality: FilterQuality.high,
                        ),
                        Positioned.fill(
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(999),
                            child: CustomPaint(
                              painter: _ButtonShimmerPainter(widget.ambient),
                            ),
                          ),
                        ),
                        Padding(
                          padding: const EdgeInsets.fromLTRB(42, 7, 42, 18),
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              if (widget.spinning)
                                const SizedBox(
                                  width: 20,
                                  height: 20,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2.4,
                                    color: Color(0xFFFFF2A3),
                                  ),
                                )
                              else
                                Icon(
                                  isFree
                                      ? Icons.auto_awesome_rounded
                                      : Icons.bolt_rounded,
                                  color: const Color(0xFFFFF09B),
                                  size: 22,
                                  shadows: const [
                                    Shadow(
                                      color: Color(0xFFFF8C00),
                                      blurRadius: 8,
                                    ),
                                  ],
                                ),
                              const SizedBox(width: 8),
                              Flexible(
                                child: FittedBox(
                                  fit: BoxFit.scaleDown,
                                  child: _ArcadeLabel(
                                    text: label,
                                    fontSize: 20,
                                    letterSpacing: 1.3,
                                    strokeWidth: 4.2,
                                  ),
                                ),
                              ),
                              if (!widget.spinning && !isFree) ...[
                                const SizedBox(width: 7),
                                CoinLottie(size: 19),
                                const SizedBox(width: 2),
                                _ArcadeLabel(
                                  text:
                                      '${widget.snapshot.settings.paidSpinCostCoins}',
                                  fontSize: 14,
                                  strokeWidth: 2.7,
                                  fillColors: const [
                                    Color(0xFFFFFFFF),
                                    Color(0xFFFFE470),
                                    Color(0xFFFFA800),
                                  ],
                                ),
                              ],
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ArcadeLabel extends StatelessWidget {
  const _ArcadeLabel({
    required this.text,
    required this.fontSize,
    this.letterSpacing = .8,
    this.strokeWidth = 3.5,
    this.strokeColor = const Color(0xFF4A0038),
    this.fillColors = const [
      Color(0xFFFFFFFF),
      Color(0xFFFFF0A2),
      Color(0xFFFFB000),
    ],
    this.maxLines = 1,
  });

  final String text;
  final double fontSize;
  final double letterSpacing;
  final double strokeWidth;
  final Color strokeColor;
  final List<Color> fillColors;
  final int maxLines;

  TextStyle _style({Paint? foreground, List<Shadow>? shadows}) => TextStyle(
    foreground: foreground,
    color: foreground == null ? Colors.white : null,
    fontSize: fontSize,
    height: .98,
    fontWeight: FontWeight.w900,
    letterSpacing: letterSpacing,
    decoration: TextDecoration.none,
    shadows: shadows,
  );

  @override
  Widget build(BuildContext context) {
    final label = Stack(
      alignment: Alignment.center,
      children: [
        Text(
          text,
          maxLines: maxLines,
          overflow: TextOverflow.visible,
          textAlign: TextAlign.center,
          style: _style(
            foreground:
                Paint()
                  ..style = PaintingStyle.stroke
                  ..strokeJoin = StrokeJoin.round
                  ..strokeWidth = strokeWidth
                  ..color = strokeColor,
            shadows: const [
              Shadow(
                color: Color(0xCC210015),
                blurRadius: 2,
                offset: Offset(0, 4),
              ),
            ],
          ),
        ),
        ShaderMask(
          blendMode: BlendMode.srcIn,
          shaderCallback:
              (bounds) => LinearGradient(
                colors: fillColors,
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              ).createShader(bounds),
          child: Text(
            text,
            maxLines: maxLines,
            overflow: TextOverflow.visible,
            textAlign: TextAlign.center,
            style: _style(
              shadows: const [
                Shadow(color: Color(0xFFFF2EC5), blurRadius: 12),
                Shadow(color: Colors.white54, blurRadius: 1),
              ],
            ),
          ),
        ),
      ],
    );

    return Transform(
      alignment: Alignment.center,
      transform: Matrix4.identity()..setEntry(0, 1, -.07),
      child: label,
    );
  }
}

class _LegacySpinButton extends StatefulWidget {
  const _LegacySpinButton({
    required this.snapshot,
    required this.spinning,
    required this.ambient,
    required this.onPressed,
  });

  final FortuneWheelSnapshot snapshot;
  final bool spinning;
  final double ambient;
  final VoidCallback onPressed;

  @override
  State<_LegacySpinButton> createState() => _SpinButtonState();
}

class _SpinButtonState extends State<_LegacySpinButton> {
  bool _pressed = false;

  bool get _enabled => !widget.spinning && widget.snapshot.segments.isNotEmpty;

  @override
  Widget build(BuildContext context) {
    final isFree = widget.snapshot.canFreeSpin;
    final pulse = .5 + (.5 * math.sin(widget.ambient * math.pi * 2));
    final label =
        widget.spinning
            ? 'SPINNING...'
            : isFree
            ? 'FREE SPIN'
            : 'SPIN';
    return Center(
      child: Transform.scale(
        scale: widget.spinning ? .98 : .98 + (pulse * .025),
        child: Semantics(
          button: true,
          enabled: _enabled,
          label: isFree ? 'Use free spin' : 'Spin for coins',
          child: GestureDetector(
            behavior: HitTestBehavior.opaque,
            onTapDown: _enabled ? (_) => setState(() => _pressed = true) : null,
            onTapCancel:
                _enabled ? () => setState(() => _pressed = false) : null,
            onTapUp:
                _enabled
                    ? (_) {
                      setState(() => _pressed = false);
                      widget.onPressed();
                    }
                    : null,
            child: SizedBox(
              width: 196,
              height: 68,
              child: Stack(
                alignment: Alignment.topCenter,
                children: [
                  Positioned(
                    left: 5,
                    right: 5,
                    top: 13,
                    bottom: 0,
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(24),
                        gradient: const LinearGradient(
                          colors: [Color(0xFF8E175F), Color(0xFF4A092F)],
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                        ),
                        border: Border.all(
                          color: const Color(0xFF2B041B),
                          width: 1.4,
                        ),
                        boxShadow: [
                          BoxShadow(
                            color: const Color(
                              0xFFFF36C8,
                            ).withValues(alpha: .22 + pulse * .18),
                            blurRadius: 24 + pulse * 10,
                            spreadRadius: pulse * 2,
                            offset: const Offset(0, 8),
                          ),
                        ],
                      ),
                    ),
                  ),
                  AnimatedPositioned(
                    duration: const Duration(milliseconds: 85),
                    curve: Curves.easeOut,
                    left: 0,
                    right: 0,
                    top: _pressed ? 8 : 1,
                    height: 54,
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(24),
                        gradient: const LinearGradient(
                          colors: [
                            Color(0xFFFFF0A3),
                            Color(0xFFFFB51D),
                            Color(0xFFB85A00),
                          ],
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                        ),
                      ),
                      child: Padding(
                        padding: const EdgeInsets.all(2.2),
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(21.5),
                          child: Stack(
                            fit: StackFit.expand,
                            children: [
                              const DecoratedBox(
                                decoration: BoxDecoration(
                                  gradient: LinearGradient(
                                    colors: [
                                      Color(0xFFFF75DF),
                                      Color(0xFFE52CB2),
                                      Color(0xFFA31178),
                                    ],
                                    begin: Alignment.topCenter,
                                    end: Alignment.bottomCenter,
                                  ),
                                ),
                              ),
                              Positioned(
                                left: 14,
                                right: 14,
                                top: 3,
                                height: 12,
                                child: DecoratedBox(
                                  decoration: BoxDecoration(
                                    borderRadius: BorderRadius.circular(99),
                                    gradient: LinearGradient(
                                      colors: [
                                        Colors.white.withValues(alpha: .40),
                                        Colors.white.withValues(alpha: .04),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                              Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  _SpinButtonMedallion(
                                    spinning: widget.spinning,
                                  ),
                                  const SizedBox(width: 8),
                                  Text(
                                    label,
                                    style: const TextStyle(
                                      color: Colors.white,
                                      fontSize: 16,
                                      fontWeight: FontWeight.w900,
                                      letterSpacing: .7,
                                      decoration: TextDecoration.none,
                                      shadows: [
                                        Shadow(
                                          color: Color(0xFF65054B),
                                          blurRadius: 4,
                                          offset: Offset(0, 2),
                                        ),
                                      ],
                                    ),
                                  ),
                                  if (!widget.spinning && !isFree) ...[
                                    const SizedBox(width: 8),
                                    _SpinCostPill(
                                      coins:
                                          widget
                                              .snapshot
                                              .settings
                                              .paidSpinCostCoins,
                                    ),
                                  ],
                                ],
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _SpinButtonMedallion extends StatelessWidget {
  const _SpinButtonMedallion({required this.spinning});

  final bool spinning;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 31,
      height: 31,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: const RadialGradient(
          colors: [Color(0xFFFFF8C9), Color(0xFFFFCB37), Color(0xFFC25A00)],
          center: Alignment(-.3, -.35),
        ),
        border: Border.all(
          color: Colors.white.withValues(alpha: .82),
          width: 1.1,
        ),
        boxShadow: const [BoxShadow(color: Color(0x88FFCB37), blurRadius: 8)],
      ),
      child:
          spinning
              ? const Padding(
                padding: EdgeInsets.all(7),
                child: CircularProgressIndicator(
                  strokeWidth: 2.2,
                  color: Color(0xFF7A1459),
                ),
              )
              : const Icon(
                Icons.auto_awesome_rounded,
                color: Color(0xFF8B155F),
                size: 18,
              ),
    );
  }
}

class _SpinCostPill extends StatelessWidget {
  const _SpinCostPill({required this.coins});

  final int coins;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(5, 3, 8, 3),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(99),
        color: const Color(0xFF5E0B48).withValues(alpha: .66),
        border: Border.all(
          color: const Color(0xFFFFD66A).withValues(alpha: .72),
        ),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const CoinLottie(size: 17),
          const SizedBox(width: 2),
          Text(
            '$coins',
            style: const TextStyle(
              color: Color(0xFFFFF4B4),
              fontSize: 13,
              fontWeight: FontWeight.w900,
              decoration: TextDecoration.none,
            ),
          ),
        ],
      ),
    );
  }
}

class _NoSegmentsCard extends StatelessWidget {
  const _NoSegmentsCard({required this.onRefresh});

  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(26),
        color: Colors.white.withValues(alpha: .06),
        border: Border.all(color: Colors.white.withValues(alpha: .10)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.tune_rounded, color: Color(0xFFFFD76B)),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Rewards are being configured',
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w900,
                    fontSize: 17,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            'Admin needs to add at least one active Fortune Wheel segment before users can spin.',
            style: TextStyle(
              color: Colors.white.withValues(alpha: .68),
              fontWeight: FontWeight.w700,
              height: 1.35,
            ),
          ),
          const SizedBox(height: 14),
          OutlinedButton.icon(
            onPressed: onRefresh,
            icon: const Icon(Icons.refresh_rounded),
            label: const Text('Refresh'),
          ),
        ],
      ),
    );
  }
}

class _RecentRewards extends StatelessWidget {
  const _RecentRewards({required this.spins});

  final List<FortuneWheelSpin> spins;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(26),
        color: Colors.white.withValues(alpha: .06),
        border: Border.all(color: Colors.white.withValues(alpha: .10)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Recent rewards',
            style: TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
              fontSize: 16,
            ),
          ),
          const SizedBox(height: 12),
          if (spins.isEmpty)
            Text(
              'Your Fortune Wheel rewards will appear here.',
              style: TextStyle(
                color: Colors.white.withValues(alpha: .62),
                fontWeight: FontWeight.w700,
              ),
            )
          else
            ...spins
                .take(5)
                .map(
                  (spin) => Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Row(
                      children: [
                        Icon(
                          _rewardIcon(spin.rewardType),
                          color: const Color(0xFFFFD76B),
                          size: 18,
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            _rewardText(spin),
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                        Text(
                          spin.spinType == 'free'
                              ? 'Free'
                              : '-${spin.spinCostCoins}',
                          style: TextStyle(
                            color: Colors.white.withValues(alpha: .55),
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
        ],
      ),
    );
  }
}

class _LatestWinCard extends StatelessWidget {
  const _LatestWinCard({required this.spin});

  final FortuneWheelSpin spin;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(22),
        gradient: LinearGradient(
          colors: [
            const Color(0xFFFFD76B).withValues(alpha: .18),
            Colors.white.withValues(alpha: .06),
          ],
        ),
        border: Border.all(
          color: const Color(0xFFFFD76B).withValues(alpha: .25),
        ),
      ),
      child: Row(
        children: [
          const Icon(Icons.emoji_events_rounded, color: Color(0xFFFFD76B)),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              _rewardText(spin),
              style: const TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _FortuneRewardSheet extends StatefulWidget {
  const _FortuneRewardSheet({required this.spin, required this.onClose});

  final FortuneWheelSpin spin;
  final VoidCallback onClose;

  @override
  State<_FortuneRewardSheet> createState() => _PremiumRewardDialogState();
}

class _PremiumRewardDialogState extends State<_FortuneRewardSheet>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2600),
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isZeroCoins =
        widget.spin.rewardType == 'coins' && widget.spin.rewardValueCoins == 0;
    final title = isZeroCoins ? 'SPIN COMPLETE' : 'YOU WON!';

    return SafeArea(
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, _) {
          final pulse = .5 + (.5 * math.sin(_controller.value * math.pi * 2));
          return SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(18, 20, 18, 20),
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: .76, end: 1),
              duration: const Duration(milliseconds: 700),
              curve: Curves.easeOutBack,
              builder:
                  (context, scale, child) =>
                      Transform.scale(scale: scale, child: child),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 360),
                child: Stack(
                  clipBehavior: Clip.none,
                  alignment: Alignment.topCenter,
                  children: [
                    Container(
                      margin: EdgeInsets.only(top: isZeroCoins ? 54 : 92),
                      padding: const EdgeInsets.all(2.5),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(30),
                        gradient: const LinearGradient(
                          colors: [
                            Color(0xFFFFF3A8),
                            Color(0xFFFFBD24),
                            Color(0xFF7B2B00),
                            Color(0xFFFFD966),
                          ],
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                        ),
                        boxShadow: [
                          BoxShadow(
                            color: const Color(
                              0xFFFFB51C,
                            ).withValues(alpha: .24 + pulse * .16),
                            blurRadius: 46 + pulse * 16,
                            spreadRadius: 2,
                          ),
                          BoxShadow(
                            color: Colors.black.withValues(alpha: .70),
                            blurRadius: 34,
                            offset: const Offset(0, 20),
                          ),
                        ],
                      ),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(27.5),
                        child: Stack(
                          children: [
                            const Positioned.fill(
                              child: DecoratedBox(
                                decoration: BoxDecoration(
                                  gradient: LinearGradient(
                                    colors: [
                                      Color(0xFF501064),
                                      Color(0xFF21062F),
                                      Color(0xFF08020D),
                                    ],
                                    begin: Alignment.topCenter,
                                    end: Alignment.bottomCenter,
                                  ),
                                ),
                              ),
                            ),
                            Positioned.fill(
                              child: CustomPaint(
                                painter: _RewardBurstPainter(_controller.value),
                              ),
                            ),
                            Padding(
                              padding: EdgeInsets.fromLTRB(
                                24,
                                isZeroCoins ? 56 : 66,
                                24,
                                14,
                              ),
                              child: Column(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  _PremiumRewardBadge(label: 'FORTUNE WHEEL'),
                                  const SizedBox(height: 10),
                                  Row(
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    children: [
                                      const _RewardTitleSparkle(),
                                      const SizedBox(width: 8),
                                      Flexible(
                                        child: FittedBox(
                                          fit: BoxFit.scaleDown,
                                          child: _ArcadeLabel(
                                            text: title,
                                            fontSize: isZeroCoins ? 27 : 32,
                                            letterSpacing: 1.5,
                                            strokeWidth: 5.5,
                                            strokeColor: const Color(
                                              0xFF6B2100,
                                            ),
                                          ),
                                        ),
                                      ),
                                      const SizedBox(width: 8),
                                      const _RewardTitleSparkle(),
                                    ],
                                  ),
                                  const SizedBox(height: 8),
                                  ConstrainedBox(
                                    constraints: const BoxConstraints(
                                      minHeight: 44,
                                    ),
                                    child: FittedBox(
                                      fit: BoxFit.scaleDown,
                                      child: _ArcadeLabel(
                                        text: _rewardText(widget.spin),
                                        fontSize: isZeroCoins ? 34 : 28,
                                        maxLines: 2,
                                        letterSpacing: .2,
                                        strokeWidth: 4.4,
                                        strokeColor: const Color(0xFF2A083A),
                                        fillColors:
                                            isZeroCoins
                                                ? const [
                                                  Color(0xFFFFFFFF),
                                                  Color(0xFFD8C9FF),
                                                  Color(0xFF9A73E8),
                                                ]
                                                : const [
                                                  Color(0xFFFFFFFF),
                                                  Color(0xFFFF8FE7),
                                                  Color(0xFFFF2EBE),
                                                ],
                                      ),
                                    ),
                                  ),
                                  const SizedBox(height: 10),
                                  _PremiumRewardStatus(spin: widget.spin),
                                  const SizedBox(height: 10),
                                  _PremiumImageActionButton(
                                    label: 'COLLECT',
                                    pulse: pulse,
                                    icon: Icons.card_giftcard_rounded,
                                    onPressed: () {
                                      Haptics.selection();
                                      widget.onClose();
                                    },
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    Positioned(
                      top: isZeroCoins ? 2 : -8,
                      child: IgnorePointer(
                        child: Transform.scale(
                          scale: 1 + pulse * .035,
                          child:
                              isZeroCoins
                                  ? _ZeroRewardEmblem(pulse: pulse)
                                  : Image.asset(
                                    _fortunePremiumRewardAsset,
                                    width: 148,
                                    height: 164,
                                    fit: BoxFit.contain,
                                    filterQuality: FilterQuality.high,
                                  ),
                        ),
                      ),
                    ),
                    Positioned(
                      right: 9,
                      top: isZeroCoins ? 61 : 99,
                      child: Material(
                        color: const Color(0xFF110519).withValues(alpha: .88),
                        shape: const CircleBorder(
                          side: BorderSide(color: Color(0xFFFFD968), width: 1),
                        ),
                        child: IconButton(
                          tooltip: 'Close reward',
                          onPressed: widget.onClose,
                          icon: const Icon(Icons.close_rounded, size: 18),
                          color: Colors.white,
                          constraints: const BoxConstraints.tightFor(
                            width: 34,
                            height: 34,
                          ),
                          padding: EdgeInsets.zero,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

class _ZeroRewardEmblem extends StatelessWidget {
  const _ZeroRewardEmblem({required this.pulse});

  final double pulse;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 88,
      height: 88,
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: const SweepGradient(
          colors: [
            Color(0xFFFFE987),
            Color(0xFF8F52DD),
            Color(0xFF39155F),
            Color(0xFFFFC238),
            Color(0xFFFFE987),
          ],
        ),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF9E5AF1).withValues(alpha: .26 + pulse * .18),
            blurRadius: 20 + pulse * 8,
            spreadRadius: 2,
          ),
        ],
      ),
      child: Container(
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: const RadialGradient(
            colors: [Color(0xFF4D2670), Color(0xFF16071F)],
          ),
          border: Border.all(color: const Color(0xFFFFE9A0), width: 1.2),
        ),
        child: const Icon(
          Icons.flare_rounded,
          color: Color(0xFFFFE58C),
          size: 38,
          shadows: [Shadow(color: Color(0xFFFFB226), blurRadius: 12)],
        ),
      ),
    );
  }
}

class _PremiumRewardBadge extends StatelessWidget {
  const _PremiumRewardBadge({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(99),
        gradient: const LinearGradient(
          colors: [Color(0xFF7A1A89), Color(0xFF330849)],
        ),
        border: Border.all(color: const Color(0xFFFFD968), width: 1.2),
        boxShadow: const [BoxShadow(color: Color(0x66FFBE27), blurRadius: 12)],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(
            Icons.auto_awesome_rounded,
            color: Color(0xFFFFE77A),
            size: 12,
          ),
          const SizedBox(width: 7),
          Text(
            label,
            style: const TextStyle(
              color: Color(0xFFFFED9C),
              fontSize: 9,
              fontWeight: FontWeight.w900,
              letterSpacing: 1.7,
              decoration: TextDecoration.none,
            ),
          ),
          const SizedBox(width: 7),
          const Icon(
            Icons.auto_awesome_rounded,
            color: Color(0xFFFFE77A),
            size: 12,
          ),
        ],
      ),
    );
  }
}

class _RewardTitleSparkle extends StatelessWidget {
  const _RewardTitleSparkle();

  @override
  Widget build(BuildContext context) {
    return Transform.rotate(
      angle: math.pi / 4,
      child: Container(
        width: 10,
        height: 10,
        decoration: BoxDecoration(
          gradient: const RadialGradient(
            colors: [Colors.white, Color(0xFFFFD43B)],
          ),
          borderRadius: BorderRadius.circular(2),
          boxShadow: const [
            BoxShadow(color: Color(0xFFFFC42D), blurRadius: 9, spreadRadius: 2),
          ],
        ),
      ),
    );
  }
}

class _PremiumRewardStatus extends StatelessWidget {
  const _PremiumRewardStatus({required this.spin});

  final FortuneWheelSpin spin;

  @override
  Widget build(BuildContext context) {
    final isZero = spin.rewardType == 'coins' && spin.rewardValueCoins == 0;
    final isCoin = spin.rewardType == 'coins';
    final label =
        isZero
            ? 'ROUND COMPLETE'
            : isCoin
            ? 'CREDITED TO YOUR WALLET'
            : 'ACTIVATED INSTANTLY';
    final icon =
        isZero
            ? Icons.auto_awesome_rounded
            : isCoin
            ? Icons.account_balance_wallet_rounded
            : Icons.verified_rounded;
    final accent = isZero ? const Color(0xFFD9B4FF) : const Color(0xFF75F1C0);
    final glow = isZero ? const Color(0xFF9F5DEA) : const Color(0xFF16B984);

    return Container(
      padding: const EdgeInsets.fromLTRB(8, 6, 14, 6),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(99),
        gradient: LinearGradient(
          colors: [
            accent.withValues(alpha: .16),
            (isZero ? const Color(0xFF21112F) : const Color(0xFF0B1E22))
                .withValues(alpha: .58),
          ],
        ),
        border: Border.all(color: accent.withValues(alpha: .36)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 25,
            height: 25,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: accent.withValues(alpha: .13),
              border: Border.all(color: accent.withValues(alpha: .28)),
            ),
            child: Icon(icon, color: accent, size: 14),
          ),
          const SizedBox(width: 8),
          Text(
            label,
            style: TextStyle(
              color: isZero ? const Color(0xFFF4E9FF) : const Color(0xFFE9FFF8),
              fontSize: 9.5,
              fontWeight: FontWeight.w900,
              letterSpacing: .7,
              decoration: TextDecoration.none,
              shadows: [Shadow(color: glow, blurRadius: 7)],
            ),
          ),
        ],
      ),
    );
  }
}

class _PremiumImageActionButton extends StatefulWidget {
  const _PremiumImageActionButton({
    required this.label,
    required this.pulse,
    required this.icon,
    required this.onPressed,
  });

  final String label;
  final double pulse;
  final IconData icon;
  final VoidCallback onPressed;

  @override
  State<_PremiumImageActionButton> createState() =>
      _PremiumImageActionButtonState();
}

class _PremiumImageActionButtonState extends State<_PremiumImageActionButton> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTapDown: (_) => setState(() => _pressed = true),
      onTapCancel: () => setState(() => _pressed = false),
      onTapUp: (_) {
        setState(() => _pressed = false);
        widget.onPressed();
      },
      child: Semantics(
        button: true,
        label: widget.label,
        child: AnimatedScale(
          duration: const Duration(milliseconds: 90),
          scale: _pressed ? .94 : 1 + widget.pulse * .018,
          child: SizedBox(
            width: 220,
            height: 82,
            child: Stack(
              fit: StackFit.expand,
              alignment: Alignment.center,
              children: [
                Image.asset(
                  _fortunePremiumSpinButtonAsset,
                  fit: BoxFit.fill,
                  filterQuality: FilterQuality.high,
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(34, 6, 34, 17),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(
                        widget.icon,
                        color: const Color(0xFFFFF1A4),
                        size: 20,
                        shadows: const [
                          Shadow(color: Color(0xFFFF9F00), blurRadius: 8),
                        ],
                      ),
                      const SizedBox(width: 8),
                      _ArcadeLabel(
                        text: widget.label,
                        fontSize: 18,
                        letterSpacing: 1.4,
                        strokeWidth: 4,
                      ),
                    ],
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

class _LegacyFortuneRewardSheet extends StatefulWidget {
  const _LegacyFortuneRewardSheet({required this.spin});

  final FortuneWheelSpin spin;

  @override
  State<_LegacyFortuneRewardSheet> createState() => _FortuneRewardSheetState();
}

class _FortuneRewardSheetState extends State<_LegacyFortuneRewardSheet>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1600),
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
      builder: (context, _) {
        final pulse = .5 + (.5 * math.sin(_controller.value * math.pi * 2));
        final rewardText = _rewardText(widget.spin);
        return SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(18, 70, 18, 18),
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: .82, end: 1),
              duration: const Duration(milliseconds: 620),
              curve: Curves.easeOutBack,
              builder:
                  (context, scale, child) =>
                      Transform.scale(scale: scale, child: child),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 350),
                child: Stack(
                  clipBehavior: Clip.none,
                  alignment: Alignment.topCenter,
                  children: [
                    Container(
                      margin: const EdgeInsets.only(top: 52),
                      padding: const EdgeInsets.all(2.2),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(36),
                        gradient: const LinearGradient(
                          colors: [
                            Color(0xFFFFF2A3),
                            Color(0xFFFFB71B),
                            Color(0xFF8D3D00),
                          ],
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                        ),
                        boxShadow: [
                          BoxShadow(
                            color: const Color(
                              0xFFFFC32E,
                            ).withValues(alpha: .20 + pulse * .16),
                            blurRadius: 44 + pulse * 22,
                            spreadRadius: 2 + pulse * 2,
                            offset: const Offset(0, 18),
                          ),
                          BoxShadow(
                            color: Colors.black.withValues(alpha: .56),
                            blurRadius: 28,
                            offset: const Offset(0, 18),
                          ),
                        ],
                      ),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(33.5),
                        child: DecoratedBox(
                          decoration: const BoxDecoration(
                            gradient: LinearGradient(
                              colors: [
                                Color(0xFF4D176D),
                                Color(0xFF1A0729),
                                Color(0xFF08030D),
                              ],
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                            ),
                          ),
                          child: Stack(
                            children: [
                              Positioned.fill(
                                child: CustomPaint(
                                  painter: _RewardBurstPainter(
                                    _controller.value,
                                  ),
                                ),
                              ),
                              Positioned(
                                left: -22,
                                top: 36,
                                child: Transform.rotate(
                                  angle: -.35,
                                  child: Image.asset(
                                    'assets/games/teen_patti/gems_3.png',
                                    width: 54,
                                    opacity: const AlwaysStoppedAnimation(.30),
                                  ),
                                ),
                              ),
                              Positioned(
                                right: -18,
                                bottom: 44,
                                child: Transform.rotate(
                                  angle: .32,
                                  child: Image.asset(
                                    'assets/games/teen_patti/gems_1.png',
                                    width: 48,
                                    opacity: const AlwaysStoppedAnimation(.25),
                                  ),
                                ),
                              ),
                              Padding(
                                padding: const EdgeInsets.fromLTRB(
                                  24,
                                  73,
                                  24,
                                  25,
                                ),
                                child: Column(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    const _RewardRibbon(),
                                    const SizedBox(height: 17),
                                    ShaderMask(
                                      shaderCallback:
                                          (bounds) => const LinearGradient(
                                            colors: [
                                              Color(0xFFFFF6C4),
                                              Color(0xFFFFC934),
                                              Color(0xFFFF8A00),
                                            ],
                                          ).createShader(bounds),
                                      child: const Text(
                                        'YOU WON!',
                                        textAlign: TextAlign.center,
                                        style: TextStyle(
                                          color: Colors.white,
                                          fontSize: 31,
                                          height: 1,
                                          fontWeight: FontWeight.w900,
                                          letterSpacing: 1.1,
                                          decoration: TextDecoration.none,
                                          shadows: [
                                            Shadow(
                                              color: Color(0xFF7B2B00),
                                              blurRadius: 8,
                                              offset: Offset(0, 3),
                                            ),
                                          ],
                                        ),
                                      ),
                                    ),
                                    const SizedBox(height: 12),
                                    Text(
                                      rewardText,
                                      textAlign: TextAlign.center,
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 24,
                                        height: 1.12,
                                        fontWeight: FontWeight.w900,
                                        letterSpacing: -.2,
                                        decoration: TextDecoration.none,
                                        shadows: [
                                          Shadow(
                                            color: Color(0xFFFF38C7),
                                            blurRadius: 14,
                                          ),
                                        ],
                                      ),
                                    ),
                                    const SizedBox(height: 12),
                                    _RewardStatusPill(spin: widget.spin),
                                    const SizedBox(height: 22),
                                    _PremiumCollectButton(
                                      pulse: pulse,
                                      onPressed:
                                          () =>
                                              Navigator.of(context).maybePop(),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                    Positioned(
                      top: -20,
                      child: Transform.scale(
                        scale: 1 + pulse * .045,
                        child: _RewardMedallion(spin: widget.spin),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _RewardMedallion extends StatelessWidget {
  const _RewardMedallion({required this.spin});

  final FortuneWheelSpin spin;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 128,
      height: 128,
      child: Stack(
        alignment: Alignment.center,
        children: [
          Image.asset(
            _fortuneSpinButtonAsset,
            fit: BoxFit.contain,
            filterQuality: FilterQuality.high,
          ),
          if (spin.rewardType == 'coins')
            Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const CoinLottie(size: 39),
                Text(
                  '${spin.rewardValueCoins}',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 17,
                    height: .9,
                    fontWeight: FontWeight.w900,
                    decoration: TextDecoration.none,
                    shadows: [Shadow(color: Color(0xFF4A0045), blurRadius: 7)],
                  ),
                ),
              ],
            )
          else
            Icon(
              _rewardIcon(spin.rewardType),
              color: Colors.white,
              size: 46,
              shadows: const [Shadow(color: Color(0xFF4A0045), blurRadius: 9)],
            ),
        ],
      ),
    );
  }
}

class _RewardRibbon extends StatelessWidget {
  const _RewardRibbon();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(99),
        gradient: const LinearGradient(
          colors: [Color(0xFF5F176E), Color(0xFF2B0A3F)],
        ),
        border: Border.all(
          color: const Color(0xFFFFD55E).withValues(alpha: .56),
        ),
      ),
      child: const Text(
        'FORTUNE REWARD',
        style: TextStyle(
          color: Color(0xFFFFE583),
          fontSize: 10,
          fontWeight: FontWeight.w900,
          letterSpacing: 1.8,
          decoration: TextDecoration.none,
        ),
      ),
    );
  }
}

class _RewardStatusPill extends StatelessWidget {
  const _RewardStatusPill({required this.spin});

  final FortuneWheelSpin spin;

  @override
  Widget build(BuildContext context) {
    final isCoin = spin.rewardType == 'coins';
    final label = isCoin ? 'CREDITED TO YOUR WALLET' : 'ACTIVATED INSTANTLY';
    final icon =
        isCoin ? Icons.account_balance_wallet_rounded : Icons.verified_rounded;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 8),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(99),
        color: const Color(0xFF0A0710).withValues(alpha: .58),
        border: Border.all(color: Colors.white.withValues(alpha: .12)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: const Color(0xFF74F0C0), size: 15),
          const SizedBox(width: 7),
          Text(
            label,
            style: const TextStyle(
              color: Colors.white70,
              fontSize: 9,
              fontWeight: FontWeight.w900,
              letterSpacing: .9,
              decoration: TextDecoration.none,
            ),
          ),
        ],
      ),
    );
  }
}

class _PremiumCollectButton extends StatefulWidget {
  const _PremiumCollectButton({required this.pulse, required this.onPressed});

  final double pulse;
  final VoidCallback onPressed;

  @override
  State<_PremiumCollectButton> createState() => _PremiumCollectButtonState();
}

class _PremiumCollectButtonState extends State<_PremiumCollectButton> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    return Transform.scale(
      scale: .98 + widget.pulse * .018,
      child: GestureDetector(
        onTapDown: (_) => setState(() => _pressed = true),
        onTapCancel: () => setState(() => _pressed = false),
        onTapUp: (_) {
          setState(() => _pressed = false);
          Haptics.selection();
          widget.onPressed();
        },
        child: Semantics(
          button: true,
          label: 'Collect reward',
          child: SizedBox(
            width: 188,
            height: 62,
            child: Stack(
              alignment: Alignment.topCenter,
              children: [
                Positioned(
                  left: 5,
                  right: 5,
                  top: 12,
                  bottom: 0,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(21),
                      gradient: const LinearGradient(
                        colors: [Color(0xFF9A4D00), Color(0xFF542000)],
                        begin: Alignment.topCenter,
                        end: Alignment.bottomCenter,
                      ),
                      border: Border.all(color: const Color(0xFF321100)),
                    ),
                  ),
                ),
                AnimatedPositioned(
                  duration: const Duration(milliseconds: 85),
                  left: 0,
                  right: 0,
                  top: _pressed ? 8 : 1,
                  height: 51,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(21),
                      gradient: const LinearGradient(
                        colors: [
                          Color(0xFFFFF5B4),
                          Color(0xFFFFCB37),
                          Color(0xFFE58200),
                        ],
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                      ),
                      boxShadow: [
                        BoxShadow(
                          color: const Color(
                            0xFFFFC32E,
                          ).withValues(alpha: .22 + widget.pulse * .18),
                          blurRadius: 18 + widget.pulse * 8,
                        ),
                      ],
                    ),
                    child: Padding(
                      padding: const EdgeInsets.all(2.2),
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(18.5),
                          gradient: const LinearGradient(
                            colors: [Color(0xFFFFF09D), Color(0xFFFFB51C)],
                            begin: Alignment.topCenter,
                            end: Alignment.bottomCenter,
                          ),
                        ),
                        child: const Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              Icons.card_giftcard_rounded,
                              color: Color(0xFF731653),
                              size: 21,
                            ),
                            SizedBox(width: 9),
                            Text(
                              'COLLECT',
                              style: TextStyle(
                                color: Color(0xFF681047),
                                fontSize: 16,
                                fontWeight: FontWeight.w900,
                                letterSpacing: 1,
                                decoration: TextDecoration.none,
                                shadows: [
                                  Shadow(
                                    color: Colors.white54,
                                    blurRadius: 2,
                                    offset: Offset(0, 1),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
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

class _FortuneLoadingCard extends StatelessWidget {
  const _FortuneLoadingCard();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(26),
        color: Colors.white.withValues(alpha: .06),
        border: Border.all(color: Colors.white.withValues(alpha: .10)),
      ),
      child: const Row(
        children: [
          CircularProgressIndicator(color: Color(0xFFFFD76B)),
          SizedBox(width: 16),
          Expanded(
            child: Text(
              'Loading your daily Fortune Wheel...',
              style: TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _FortuneErrorCard extends StatelessWidget {
  const _FortuneErrorCard({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(26),
        color: Colors.white.withValues(alpha: .06),
        border: Border.all(color: Colors.white.withValues(alpha: .10)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Fortune Wheel unavailable',
            style: TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
              fontSize: 17,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            message,
            style: TextStyle(
              color: Colors.white.withValues(alpha: .68),
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 14),
          OutlinedButton.icon(
            onPressed: onRetry,
            icon: const Icon(Icons.refresh_rounded),
            label: const Text('Retry'),
          ),
        ],
      ),
    );
  }
}

class _InlineError extends StatelessWidget {
  const _InlineError({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(13),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(18),
        color: Colors.redAccent.withValues(alpha: .14),
        border: Border.all(color: Colors.redAccent.withValues(alpha: .24)),
      ),
      child: Row(
        children: [
          const Icon(Icons.info_rounded, color: Colors.redAccent, size: 18),
          const SizedBox(width: 9),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _FortuneSparkleOverlay extends StatelessWidget {
  const _FortuneSparkleOverlay({required this.progress});

  final double progress;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: CustomPaint(painter: _FortuneSparklePainter(progress)),
    );
  }
}

class _FortuneSparklePainter extends CustomPainter {
  _FortuneSparklePainter(this.progress);

  final double progress;

  static const _stars = <Offset>[
    Offset(.14, .17),
    Offset(.78, .15),
    Offset(.28, .34),
    Offset(.90, .42),
    Offset(.11, .62),
    Offset(.62, .71),
    Offset(.35, .86),
    Offset(.82, .88),
  ];

  @override
  void paint(Canvas canvas, Size size) {
    for (var i = 0; i < _stars.length; i++) {
      final phase = (progress + i * .13) % 1;
      final sparkle = math.sin(phase * math.pi).abs();
      final point = Offset(
        _stars[i].dx * size.width,
        ((_stars[i].dy + phase * .035) % 1) * size.height,
      );
      final radius = 1.8 + sparkle * 3.2;
      final color = (i.isEven
              ? const Color(0xFFFFD76B)
              : const Color(0xFF67E8F9))
          .withValues(alpha: .12 + sparkle * .38);
      final fill = Paint()..color = color;
      final stroke =
          Paint()
            ..color = color.withValues(alpha: .82)
            ..strokeWidth = 1.1
            ..strokeCap = StrokeCap.round;

      canvas.drawCircle(point, radius, fill);
      canvas.drawLine(
        point + Offset(-radius * 2.4, 0),
        point + Offset(radius * 2.4, 0),
        stroke,
      );
      canvas.drawLine(
        point + Offset(0, -radius * 2.4),
        point + Offset(0, radius * 2.4),
        stroke,
      );
    }
  }

  @override
  bool shouldRepaint(covariant _FortuneSparklePainter oldDelegate) {
    return oldDelegate.progress != progress;
  }
}

class _ShimmerSweepPainter extends CustomPainter {
  _ShimmerSweepPainter(this.progress);

  final double progress;

  @override
  void paint(Canvas canvas, Size size) {
    final bandWidth = size.width * .22;
    final travel = size.width + bandWidth * 2;
    final x = (travel * progress) - bandWidth;
    final rect = Rect.fromLTWH(
      x,
      -size.height * .45,
      bandWidth,
      size.height * 1.9,
    );
    final paint =
        Paint()
          ..shader = LinearGradient(
            colors: [
              Colors.transparent,
              Colors.white.withValues(alpha: .18),
              Colors.transparent,
            ],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ).createShader(rect);

    canvas.save();
    canvas.translate(size.width / 2, size.height / 2);
    canvas.rotate(-.34);
    canvas.translate(-size.width / 2, -size.height / 2);
    canvas.drawRect(rect, paint);
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant _ShimmerSweepPainter oldDelegate) {
    return oldDelegate.progress != progress;
  }
}

class _WheelSweepPainter extends CustomPainter {
  _WheelSweepPainter(this.progress, this.spinning);

  final double progress;
  final bool spinning;

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final radius = math.min(size.width, size.height) / 2;
    final rect = Rect.fromCircle(center: center, radius: radius);
    final start = (progress * math.pi * 2) - math.pi / 8;
    final paint =
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = spinning ? 16 : 10
          ..strokeCap = StrokeCap.round
          ..shader = SweepGradient(
            startAngle: start,
            endAngle: start + math.pi / 2.7,
            colors: [
              Colors.transparent,
              const Color(0xFFFFF3B0).withValues(alpha: spinning ? .68 : .36),
              Colors.transparent,
            ],
          ).createShader(rect);

    canvas.drawArc(
      Rect.fromCircle(center: center, radius: radius * .82),
      start,
      math.pi / 2.7,
      false,
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant _WheelSweepPainter oldDelegate) {
    return oldDelegate.progress != progress || oldDelegate.spinning != spinning;
  }
}

class _ButtonShimmerPainter extends CustomPainter {
  _ButtonShimmerPainter(this.progress);

  final double progress;

  @override
  void paint(Canvas canvas, Size size) {
    final width = size.width * .24;
    final x = (size.width + width * 2) * progress - width;
    final rect = Rect.fromLTWH(x, -size.height, width, size.height * 3);
    final paint =
        Paint()
          ..shader = LinearGradient(
            colors: [
              Colors.transparent,
              Colors.white.withValues(alpha: .30),
              Colors.transparent,
            ],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ).createShader(rect);

    canvas.save();
    canvas.translate(size.width / 2, size.height / 2);
    canvas.rotate(-.46);
    canvas.translate(-size.width / 2, -size.height / 2);
    canvas.drawRect(rect, paint);
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant _ButtonShimmerPainter oldDelegate) {
    return oldDelegate.progress != progress;
  }
}

class _RewardBurstPainter extends CustomPainter {
  _RewardBurstPainter(this.progress);

  final double progress;

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height * .22);
    final radius = math.min(size.width, size.height) * .34;
    final ring =
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2
          ..color = const Color(0xFFFFD76B).withValues(alpha: .16);
    canvas.drawCircle(center, radius * (.72 + progress * .18), ring);

    final ray =
        Paint()
          ..strokeWidth = 2
          ..strokeCap = StrokeCap.round
          ..color = Colors.white.withValues(alpha: .14);
    for (var i = 0; i < 18; i++) {
      final angle = (i / 18 * math.pi * 2) + progress * math.pi * 2;
      final start =
          center + Offset(math.cos(angle), math.sin(angle)) * (radius * .38);
      final end =
          center + Offset(math.cos(angle), math.sin(angle)) * (radius * .82);
      canvas.drawLine(start, end, ray);
    }
  }

  @override
  bool shouldRepaint(covariant _RewardBurstPainter oldDelegate) {
    return oldDelegate.progress != progress;
  }
}

class _FortuneBackground extends StatelessWidget {
  const _FortuneBackground();

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        Opacity(
          opacity: .34,
          child: Image.asset(
            _fortuneBgAsset,
            fit: BoxFit.cover,
            alignment: Alignment.topCenter,
            filterQuality: FilterQuality.high,
          ),
        ),
        DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                const Color(0xFF160B2C).withValues(alpha: .30),
                const Color(0xFF07040E).withValues(alpha: .70),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
        ),
      ],
    );
  }
}

class _FortuneBackgroundPainter extends CustomPainter {
  const _FortuneBackgroundPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()..style = PaintingStyle.fill;
    final points = [
      Offset(size.width * .12, 80),
      Offset(size.width * .82, 130),
      Offset(size.width * .24, size.height * .56),
      Offset(size.width * .74, size.height * .72),
    ];
    for (final point in points) {
      paint.shader = RadialGradient(
        colors: [
          const Color(0xFFFFD76B).withValues(alpha: .16),
          Colors.transparent,
        ],
      ).createShader(Rect.fromCircle(center: point, radius: 120));
      canvas.drawCircle(point, 120, paint);
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

String _rewardText(FortuneWheelSpin spin) {
  if (spin.rewardType == 'coins') {
    return '${spin.rewardValueCoins} Coins';
  }
  if (spin.rewardType == 'entry_pack') {
    final name = spin.entryPackName ?? 'Entry Pack';
    return '$name for ${_durationText(spin.rewardDurationHours)}';
  }
  if (spin.rewardType == 'subscription') {
    final name = spin.subscriptionPlanName ?? 'Subscription';
    return '$name for ${_durationText(spin.rewardDurationHours)}';
  }
  return spin.segment?.label ?? 'Reward';
}

String _durationText(int? hours) {
  final value = hours ?? 24;
  if (value < 24) return '$value hours';
  final days = (value / 24).round();
  return days == 1 ? '1 day' : '$days days';
}

IconData _rewardIcon(String rewardType) {
  return switch (rewardType) {
    'entry_pack' => Icons.rocket_launch_rounded,
    'subscription' => Icons.workspace_premium_rounded,
    _ => Icons.monetization_on_rounded,
  };
}
