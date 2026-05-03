import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../models/live_pk_battle_model.dart';

class PkBattleOverlay extends StatefulWidget {
  const PkBattleOverlay({
    super.key,
    required this.battle,
    required this.ownLabel,
    required this.opponentLabel,
    required this.ownScore,
    required this.opponentScore,
    required this.ownChild,
    required this.opponentChild,
    this.opponentUnavailable = false,
    this.onEnd,
    this.canEnd = false,
    this.showEmbeddedRail = true,
  });

  final LivePkBattleModel battle;
  final String ownLabel;
  final String opponentLabel;
  final int ownScore;
  final int opponentScore;
  final Widget ownChild;
  final Widget opponentChild;
  final bool opponentUnavailable;
  final VoidCallback? onEnd;
  final bool canEnd;
  final bool showEmbeddedRail;

  @override
  State<PkBattleOverlay> createState() => _PkBattleOverlayState();
}

class _PkBattleOverlayState extends State<PkBattleOverlay> with TickerProviderStateMixin {
  late final AnimationController _pulse;
  late final AnimationController _burst;
  late final AnimationController _intro;
  Timer? _ticker;
  int _remaining = 0;
  int _leadSide = 0;
  int _leadStreak = 0;
  int _scoreBurstSide = 0;
  int _scoreBurstValue = 0;
  int _burstSequence = 0;
  final List<_ScoreBurstEntry> _scoreBursts = <_ScoreBurstEntry>[];

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1400),
    )..repeat(reverse: true);
    _burst = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 760),
    );
    _intro = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 980),
    );
    _leadSide = _computeLeadSide(widget.ownScore, widget.opponentScore);
    _leadStreak = _leadSide == 0 ? 0 : 1;
    _syncRemaining();
    _playPkIntro();
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) => _syncRemaining());
  }

  @override
  void didUpdateWidget(covariant PkBattleOverlay oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.battle.battleId != widget.battle.battleId || oldWidget.battle.endsAt != widget.battle.endsAt) {
      _syncRemaining();
      _playPkIntro();
    }
    final ownDelta = widget.ownScore - oldWidget.ownScore;
    final opponentDelta = widget.opponentScore - oldWidget.opponentScore;
    if (ownDelta > 0) {
      _emitScoreBurst(side: 1, amount: ownDelta);
    }
    if (opponentDelta > 0) {
      _emitScoreBurst(side: -1, amount: opponentDelta);
    }
    final nextLeadSide = _computeLeadSide(widget.ownScore, widget.opponentScore);
    final hasScoreChange = ownDelta != 0 || opponentDelta != 0;
    if (nextLeadSide != _leadSide) {
      _leadSide = nextLeadSide;
      _leadStreak = _leadSide == 0 ? 0 : 1;
      if (_leadSide != 0) {
        _burst.forward(from: 0);
      }
    } else if (hasScoreChange && _leadSide != 0) {
      final scoringLeadSide =
          ownDelta > opponentDelta
              ? 1
              : opponentDelta > ownDelta
              ? -1
              : _leadSide;
      if (scoringLeadSide == _leadSide) {
        _leadStreak += 1;
      }
      _burst.forward(from: 0);
    }
  }

  void _syncRemaining() {
    if (!mounted) return;
    setState(() => _remaining = widget.battle.remainingSeconds);
  }

  void _playPkIntro() {
    if (widget.battle.remainingSeconds <= 0) return;
    _intro.forward(from: 0);
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _pulse.dispose();
    _burst.dispose();
    _intro.dispose();
    super.dispose();
  }

  int _computeLeadSide(int own, int opponent) {
    if (own == opponent) return 0;
    return own > opponent ? 1 : -1;
  }

  void _emitScoreBurst({required int side, required int amount}) {
    if (!mounted || amount <= 0) return;
    final id = ++_burstSequence;
    setState(() {
      _scoreBurstSide = side;
      _scoreBurstValue = amount;
      _scoreBursts.add(
        _ScoreBurstEntry(
          id: id,
          side: side,
          amount: amount,
        ),
      );
    });
    _burst.forward(from: 0);
    Timer(const Duration(milliseconds: 1100), () {
      if (!mounted) return;
      setState(() {
        _scoreBursts.removeWhere((entry) => entry.id == id);
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final total = math.max(1, widget.ownScore + widget.opponentScore);
    final ownFraction = widget.ownScore / total;
    final oppFraction = widget.opponentScore / total;
    final dangerMode = _remaining > 0 && _remaining <= 10;
    return IgnorePointer(
      ignoring: false,
      child: AnimatedBuilder(
        animation: _pulse,
        builder: (context, _) {
          final pulse = Curves.easeInOut.transform(_pulse.value);
          final burst = Curves.easeOutCubic.transform(_burst.value);
          return SafeArea(
            top: false,
            child: LayoutBuilder(
              builder: (context, constraints) {
                const sideGap = 0.0;
                final railHeight = math.min(76.0, constraints.maxHeight * .2);
                final timerHeight = constraints.maxWidth < 360 ? 56.0 : 64.0;
                final timerTop = 12.0;
                final railBottom = 0.0;
                final railTop = constraints.maxHeight - railHeight;
                const tilesTop = 0.0;
                const tilesBottom = 0.0;
                return Stack(
                  children: [
                    Positioned(
                      top: tilesTop,
                      left: 0,
                      right: 0,
                      bottom: tilesBottom,
                      child: Row(
                        children: [
                          Expanded(
                            child: _BattleStagePane(
                              label: widget.ownLabel,
                              score: widget.ownScore,
                              pulse: pulse,
                              showCrown: _leadSide == 1,
                              dimmed: false,
                              streakActive: _leadSide == 1 && _leadStreak >= 2,
                              borderRadius: BorderRadius.zero,
                              accent: const [Color(0xFFFF5C8A), Color(0xFFFFA63D)],
                              child: widget.ownChild,
                            ),
                          ),
                          SizedBox(width: sideGap),
                          Expanded(
                            child: Stack(
                              children: [
                                Positioned.fill(
                                  child: _BattleStagePane(
                                    label: widget.opponentLabel,
                                    score: widget.opponentScore,
                                    pulse: pulse,
                                    showCrown: _leadSide == -1,
                                    dimmed: false,
                                    streakActive: _leadSide == -1 && _leadStreak >= 2,
                                    borderRadius: BorderRadius.zero,
                                    accent: const [Color(0xFF5AB3FF), Color(0xFF8A63E8)],
                                    child: widget.opponentChild,
                                  ),
                                ),
                                if (widget.opponentUnavailable)
                                  Positioned.fill(
                                    child: DecoratedBox(
                                      decoration: BoxDecoration(
                                        color: Colors.black.withOpacity(.54),
                                      ),
                                      child: const Center(
                                        child: _UnavailableBadge(),
                                      ),
                                    ),
                                  ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    Positioned(
                      top: tilesTop,
                      bottom: tilesBottom,
                      left: (constraints.maxWidth / 2) - 34,
                      width: 68,
                      child: IgnorePointer(
                        ignoring: true,
                        child: _PkSeamDivider(
                          pulse: pulse,
                          burst: burst,
                          leadSide: _leadSide,
                          dangerMode: dangerMode,
                        ),
                      ),
                    ),
                    Positioned(
                      top: timerTop,
                      left: (constraints.maxWidth / 2) - 62,
                      width: 124,
                      child: IgnorePointer(
                        ignoring: true,
                        child: Center(
                          child: Transform.scale(
                            scale: 1 + (pulse * .03),
                            child: _SeamDockedTimer(
                              seconds: _remaining,
                              pulse: pulse,
                              leadSide: _leadSide,
                              dangerMode: dangerMode,
                            ),
                          ),
                        ),
                      ),
                    ),
                    Positioned.fill(
                      child: IgnorePointer(
                        child: Center(
                          child: _PkIntroText(
                            animation: _intro,
                            leadSide: _leadSide,
                          ),
                        ),
                      ),
                    ),
                    ..._scoreBursts.map(
                      (burstEntry) => _FloatingScoreBurst(
                        key: ValueKey('burst-${burstEntry.id}'),
                        entry: burstEntry,
                      ),
                    ),
                    if (widget.showEmbeddedRail)
                      Positioned(
                        left: 0,
                        right: 0,
                        bottom: railBottom,
                        top: railTop,
                        child: Center(
                          child: _CenterBattleRail(
                            ownScore: widget.ownScore,
                            opponentScore: widget.opponentScore,
                            ownFraction: ownFraction,
                            opponentFraction: oppFraction,
                            pulse: pulse,
                            burst: burst,
                            leadSide: _leadSide,
                            leadStreak: _leadStreak,
                            scoreBurstSide: _scoreBurstSide,
                            scoreBurstValue: _scoreBurstValue,
                            dangerMode: dangerMode,
                          ),
                        ),
                      ),
                  ],
                );
              },
            ),
          );
        },
      ),
    );
  }
}

class PkBattleRail extends StatelessWidget {
  const PkBattleRail({
    super.key,
    required this.ownScore,
    required this.opponentScore,
    required this.ownFraction,
    required this.opponentFraction,
    required this.leadSide,
    required this.leadStreak,
    required this.scoreBurstSide,
    required this.scoreBurstValue,
    required this.dangerMode,
    this.pulse = .5,
    this.burst = 0,
  });

  final int ownScore;
  final int opponentScore;
  final double ownFraction;
  final double opponentFraction;
  final int leadSide;
  final int leadStreak;
  final int scoreBurstSide;
  final int scoreBurstValue;
  final bool dangerMode;
  final double pulse;
  final double burst;

  @override
  Widget build(BuildContext context) {
    return _CenterBattleRail(
      ownScore: ownScore,
      opponentScore: opponentScore,
      ownFraction: ownFraction,
      opponentFraction: opponentFraction,
      pulse: pulse,
      burst: burst,
      leadSide: leadSide,
      leadStreak: leadStreak,
      scoreBurstSide: scoreBurstSide,
      scoreBurstValue: scoreBurstValue,
      dangerMode: dangerMode,
    );
  }
}

class _ScoreBurstEntry {
  const _ScoreBurstEntry({
    required this.id,
    required this.side,
    required this.amount,
  });

  final int id;
  final int side;
  final int amount;
}

class PkWinnerOverlay extends StatelessWidget {
  const PkWinnerOverlay({
    super.key,
    required this.title,
    required this.subtitle,
    this.winnerSide = 0,
  });

  final String title;
  final String subtitle;
  final int winnerSide;

  @override
  Widget build(BuildContext context) {
    final leftWinner = winnerSide == 1;
    final rightWinner = winnerSide == -1;
    return Positioned.fill(
      child: IgnorePointer(
        child: Stack(
          children: [
            Positioned.fill(
              child: Row(
                children: [
                  Expanded(
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.centerLeft,
                          end: Alignment.centerRight,
                          colors: [
                            const Color(0xCCFF5C8A).withOpacity(leftWinner ? .38 : .08),
                            Colors.transparent,
                          ],
                        ),
                        boxShadow: [
                          if (leftWinner)
                            BoxShadow(
                              color: const Color(0x66FF8A62).withOpacity(.26),
                              blurRadius: 24,
                              spreadRadius: 2,
                            ),
                        ],
                      ),
                      child: ColoredBox(
                        color: Colors.black.withOpacity(rightWinner ? .36 : .14),
                      ),
                    ),
                  ),
                  Expanded(
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.centerLeft,
                          end: Alignment.centerRight,
                          colors: [
                            Colors.transparent,
                            const Color(0xCC5AB3FF).withOpacity(rightWinner ? .38 : .08),
                          ],
                        ),
                        boxShadow: [
                          if (rightWinner)
                            BoxShadow(
                              color: const Color(0x665AB3FF).withOpacity(.26),
                              blurRadius: 24,
                              spreadRadius: 2,
                            ),
                        ],
                      ),
                      child: ColoredBox(
                        color: Colors.black.withOpacity(leftWinner ? .36 : .14),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            Positioned.fill(
              child: Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      title,
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w900,
                        fontSize: 26,
                        letterSpacing: 1.2,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      subtitle,
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Colors.white.withOpacity(.82),
                        fontWeight: FontWeight.w600,
                        fontSize: 13,
                      ),
                    ),
                    if (leftWinner || rightWinner) ...[
                      const SizedBox(height: 14),
                      Container(
                        width: 96,
                        height: 4,
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(999),
                          gradient: LinearGradient(
                            colors:
                                leftWinner
                                    ? const [Color(0xFFFF5C8A), Color(0xFFFFA63D)]
                                    : const [Color(0xFF5AB3FF), Color(0xFF8A63E8)],
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _BattleStagePane extends StatelessWidget {
  const _BattleStagePane({
    required this.label,
    required this.score,
    required this.child,
    required this.accent,
    required this.pulse,
    required this.showCrown,
    required this.dimmed,
    required this.streakActive,
    required this.borderRadius,
  });

  final String label;
  final int score;
  final Widget child;
  final List<Color> accent;
  final double pulse;
  final bool showCrown;
  final bool dimmed;
  final bool streakActive;
  final BorderRadius borderRadius;

  @override
  Widget build(BuildContext context) {
    final borderTint = Color.lerp(accent.first, Colors.white, .18) ?? Colors.white;
    return Container(
      decoration: BoxDecoration(
        borderRadius: borderRadius,
        color: const Color(0xFF0B1018),
        border: Border.all(
          color: borderTint.withOpacity(streakActive ? .42 : .22),
          width: streakActive ? 1.15 : .8,
        ),
      ),
      child: ClipRRect(
        borderRadius: borderRadius,
        child: Stack(
          fit: StackFit.expand,
          children: [
            Positioned.fill(child: child),
            if (dimmed)
              Positioned.fill(
                child: ColoredBox(color: Colors.black.withOpacity(.32)),
              ),
            Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [
                      accent.first.withOpacity(.08 + (pulse * .02)),
                      Colors.transparent,
                      accent.last.withOpacity(.06),
                    ],
                    stops: const [0.0, .42, 1.0],
                  ),
                ),
              ),
            ),
            Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [
                      Colors.black.withOpacity(.03),
                      Colors.transparent,
                      Colors.black.withOpacity(.16),
                    ],
                    stops: const [0.0, 0.48, 1.0],
                  ),
                ),
              ),
            ),
            if (streakActive)
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    border: Border.all(
                      color: accent.first.withOpacity(.26 + (pulse * .18)),
                      width: 1.4,
                    ),
                  ),
                ),
              ),
            if (showCrown && score > 0)
              Positioned(
                top: 18,
                left: 12,
                child: Transform.translate(
                  offset: Offset(0, -2 - (pulse * 5)),
                  child: _WinnerCrown(accent: accent),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _AnimatedTimerBadge extends StatelessWidget {
  const _AnimatedTimerBadge({
    required this.seconds,
    required this.pulse,
    required this.leadSide,
  });

  final int seconds;
  final double pulse;
  final int leadSide;

  @override
  Widget build(BuildContext context) {
    final accent = switch (leadSide) {
      1 => const [Color(0xFFFF5C8A), Color(0xFFFFA63D)],
      -1 => const [Color(0xFF5AB3FF), Color(0xFF8A63E8)],
      _ => const [Color(0xFFFF7A59), Color(0xFF8A63E8)],
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            const Color(0xF50A0D13),
            Color.lerp(accent.first, Colors.black, .68) ?? const Color(0xFF201226),
            Color.lerp(accent.last, Colors.black, .58) ?? const Color(0xFF151D2C),
          ],
        ),
        border: Border.all(color: Colors.white.withOpacity(.18)),
        boxShadow: [
          BoxShadow(
            color: accent.first.withOpacity(.24 + (pulse * .10)),
            blurRadius: 18 + (pulse * 14),
            spreadRadius: 1 + (pulse * 1.5),
          ),
          BoxShadow(
            color: Colors.black.withOpacity(.32),
            blurRadius: 18,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(999),
              color: Colors.white.withOpacity(.10),
            ),
            child: const Text(
              'PK',
              style: TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w900,
                fontSize: 11,
                letterSpacing: .8,
              ),
            ),
          ),
          const SizedBox(width: 10),
          _TimerPill(seconds: seconds, pulse: pulse),
          const SizedBox(width: 10),
          Icon(
            Icons.bolt_rounded,
            color: Colors.white.withOpacity(.94),
            size: 18 + (pulse * 2),
          ),
        ],
      ),
    );
  }
}

class _PkSeamDivider extends StatelessWidget {
  const _PkSeamDivider({
    required this.pulse,
    required this.burst,
    required this.leadSide,
    required this.dangerMode,
  });

  final double pulse;
  final double burst;
  final int leadSide;
  final bool dangerMode;

  @override
  Widget build(BuildContext context) {
    final accent = switch (leadSide) {
      1 => const [Color(0xFFFF5C8A), Color(0xFFFFA63D)],
      -1 => const [Color(0xFF5AB3FF), Color(0xFF8A63E8)],
      _ => const [Color(0xFFFF7A59), Color(0xFF8A63E8)],
    };

    return Stack(
      alignment: Alignment.center,
      children: [
        Container(
          width: 2,
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [
                Colors.white.withOpacity(.0),
                accent.first.withOpacity(dangerMode ? .96 : .82),
                accent.last.withOpacity(dangerMode ? .96 : .82),
                Colors.white.withOpacity(.0),
              ],
              stops: const [0.0, .18, .82, 1.0],
            ),
            boxShadow: [
              BoxShadow(
                color:
                    (dangerMode ? const Color(0xFFFF8A7A) : accent.first)
                        .withOpacity(.24 + (pulse * .18)),
                blurRadius: 12 + (pulse * 16) + (dangerMode ? 8 : 0),
                spreadRadius: 1 + (pulse * 1.2),
              ),
            ],
          ),
        ),
        Container(
          width: 22 + (burst * 10),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [
                accent.first.withOpacity(.0),
                accent.first.withOpacity(.12 + (pulse * .08)),
                accent.last.withOpacity(.16 + (pulse * .08)),
                accent.last.withOpacity(.0),
              ],
            ),
          ),
        ),
        Positioned.fill(
          child: Center(
            child: Icon(
              Icons.bolt_rounded,
              size: 22 + (pulse * 3) + (burst * 4),
              color: Colors.white.withOpacity(.92),
            ),
          ),
        ),
      ],
    );
  }
}

class _SeamDockedTimer extends StatelessWidget {
  const _SeamDockedTimer({
    required this.seconds,
    required this.pulse,
    required this.leadSide,
    required this.dangerMode,
  });

  final int seconds;
  final double pulse;
  final int leadSide;
  final bool dangerMode;

  @override
  Widget build(BuildContext context) {
    final accent = switch (leadSide) {
      1 => const [Color(0xFFFF5C8A), Color(0xFFFFA63D)],
      -1 => const [Color(0xFF5AB3FF), Color(0xFF8A63E8)],
      _ => const [Color(0xFFFF7A59), Color(0xFF8A63E8)],
    };
    final mins = (seconds ~/ 60).toString().padLeft(2, '0');
    final secs = (seconds % 60).toString().padLeft(2, '0');
    final urgent = dangerMode;
    final scale = urgent ? 1 + (pulse * .08) : 1 + (pulse * .035);
    final baseColor = urgent ? const Color(0xFFFFE1EC) : Colors.white;

    return Transform.scale(
      scale: scale,
      child: DecoratedBox(
        decoration: BoxDecoration(
          boxShadow: [
            BoxShadow(
              color: accent.first.withOpacity(.30 + (pulse * .14)),
              blurRadius: 22 + (pulse * 12),
              spreadRadius: pulse * 1.2,
            ),
            BoxShadow(
              color: accent.last.withOpacity(.18 + (pulse * .10)),
              blurRadius: 30 + (pulse * 10),
            ),
          ],
        ),
        child: ShaderMask(
          shaderCallback:
              (bounds) => LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  baseColor,
                  Color.lerp(baseColor, accent.first, .14) ?? baseColor,
                  Color.lerp(baseColor, accent.last, .22) ?? baseColor,
                ],
              ).createShader(bounds),
          child: Text(
            '$mins:$secs',
            textAlign: TextAlign.center,
            style: TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
              fontSize: urgent ? 24 : 22,
              letterSpacing: urgent ? 1.2 : 1.0,
              height: 1,
              shadows: [
                Shadow(
                  color: Colors.black.withOpacity(.55),
                  blurRadius: 16,
                ),
                Shadow(
                  color: accent.first.withOpacity(.55 + (pulse * .18)),
                  blurRadius: 18 + (pulse * 8),
                ),
                Shadow(
                  color: accent.last.withOpacity(.42 + (pulse * .16)),
                  blurRadius: 28 + (pulse * 10),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _BattleHud extends StatelessWidget {
  const _BattleHud({
    required this.ownLabel,
    required this.opponentLabel,
    required this.leadText,
    required this.remainingSeconds,
    required this.ownScore,
    required this.opponentScore,
    this.onEnd,
  });

  final String ownLabel;
  final String opponentLabel;
  final String leadText;
  final int remainingSeconds;
  final int ownScore;
  final int opponentScore;
  final VoidCallback? onEnd;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            const Color(0xE61D102F),
            const Color(0xE20C1324),
            Colors.black.withOpacity(.72),
          ],
        ),
        border: Border.all(color: Colors.white.withOpacity(.10)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(.22),
            blurRadius: 16,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                Flexible(
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const _MiniSideTag(
                        label: 'A',
                        accent: Color(0xFFFF5C8A),
                      ),
                      const SizedBox(width: 6),
                      Flexible(
                        child: Text(
                          ownLabel,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: Colors.white.withOpacity(.92),
                            fontWeight: FontWeight.w800,
                            fontSize: 12,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 6),
                _TimerPill(seconds: remainingSeconds, pulse: 0),
                const SizedBox(width: 8),
                Flexible(
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.end,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Flexible(
                        child: Text(
                          opponentLabel,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          textAlign: TextAlign.right,
                          style: TextStyle(
                            color: Colors.white.withOpacity(.92),
                            fontWeight: FontWeight.w800,
                            fontSize: 12,
                          ),
                        ),
                      ),
                      const SizedBox(width: 6),
                      const _MiniSideTag(
                        label: 'B',
                        accent: Color(0xFF5AB3FF),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: Text(
                    leadText,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: Colors.white.withOpacity(.86),
                      fontWeight: FontWeight.w800,
                      fontSize: 12,
                    ),
                  ),
                ),
                if (onEnd != null) ...[
                  const SizedBox(width: 8),
                  _EndPkButton(onTap: onEnd!),
                ],
              ],
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: _HudScoreLane(
                    score: ownScore,
                    accent: const [Color(0xFFFF5C8A), Color(0xFFFFA63D)],
                    alignStart: true,
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: _HudScoreLane(
                    score: opponentScore,
                    accent: const [Color(0xFF5AB3FF), Color(0xFF8A63E8)],
                    alignStart: false,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _CenterBattleRail extends StatelessWidget {
  const _CenterBattleRail({
    required this.ownScore,
    required this.opponentScore,
    required this.ownFraction,
    required this.opponentFraction,
    required this.pulse,
    required this.burst,
    required this.leadSide,
    required this.leadStreak,
    required this.scoreBurstSide,
    required this.scoreBurstValue,
    required this.dangerMode,
  });

  final int ownScore;
  final int opponentScore;
  final double ownFraction;
  final double opponentFraction;
  final double pulse;
  final double burst;
  final int leadSide;
  final int leadStreak;
  final int scoreBurstSide;
  final int scoreBurstValue;
  final bool dangerMode;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final compact = constraints.maxHeight < 72;
        final showChip = !compact && ((leadStreak >= 2 && leadSide != 0) || (scoreBurstValue > 0 && scoreBurstSide != 0));
        final leftActive = leadSide >= 0;
        final rightActive = leadSide <= 0;
        return SizedBox(
          width: constraints.maxWidth,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
                Container(
                  width: double.infinity,
                  padding: EdgeInsets.fromLTRB(compact ? 6 : 8, compact ? 6 : 8, compact ? 6 : 8, compact ? 6 : 8),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(24),
                    gradient: LinearGradient(
                      begin: Alignment.centerLeft,
                      end: Alignment.centerRight,
                      colors: [
                        const Color(0xFF15111A).withOpacity(dangerMode ? .98 : .94),
                        const Color(0xFF0F1520).withOpacity(.98),
                        const Color(0xFF13111C).withOpacity(dangerMode ? .98 : .94),
                      ],
                    ),
                    border: Border.all(
                      color:
                          dangerMode
                              ? const Color(0xFFFF8A7A).withOpacity(.30 + (pulse * .18))
                              : Colors.white.withOpacity(.10),
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withOpacity(.24),
                        blurRadius: 16,
                        offset: const Offset(0, 8),
                      ),
                      BoxShadow(
                        color:
                            leadSide == 1
                                ? const Color(0x66FF8A62).withOpacity(scoreBurstSide == 1 ? .28 : .12)
                                : leadSide == -1
                                ? const Color(0x665AB3FF).withOpacity(scoreBurstSide == -1 ? .28 : .12)
                                : Colors.white.withOpacity(.04),
                        blurRadius: 16 + (burst * 12),
                        spreadRadius: 1,
                      ),
                    ],
                  ),
                  child: Row(
                    children: [
                      _RailScore(
                        value: ownScore,
                        accent: const [Color(0xFFFF5C8A), Color(0xFFFFA63D)],
                        active: leftActive,
                        compact: compact,
                      ),
                      SizedBox(width: compact ? 6 : 8),
                      Expanded(
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(18),
                          child: Container(
                            height: compact ? 24 : 28,
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 5),
                            decoration: BoxDecoration(
                              gradient: LinearGradient(
                                begin: Alignment.topCenter,
                                end: Alignment.bottomCenter,
                                colors: [
                                  Colors.white.withOpacity(.08),
                                  Colors.white.withOpacity(.03),
                                ],
                              ),
                              border: Border.all(color: Colors.white.withOpacity(.06)),
                            ),
                            child: Stack(
                              children: [
                                Positioned.fill(
                                  child: DecoratedBox(
                                    decoration: BoxDecoration(
                                      color: Colors.white.withOpacity(.04),
                                      borderRadius: BorderRadius.circular(999),
                                    ),
                                  ),
                                ),
                                Align(
                                  alignment: Alignment.centerLeft,
                                  child: TweenAnimationBuilder<double>(
                                    tween: Tween<double>(
                                      begin: 0,
                                      end: ownFraction.clamp(0.0, 1.0),
                                    ),
                                    duration: const Duration(milliseconds: 340),
                                    curve: Curves.easeOutCubic,
                                    builder: (context, animatedFraction, _) {
                                      return FractionallySizedBox(
                                        widthFactor: animatedFraction,
                                        child: Container(
                                          decoration: BoxDecoration(
                                            borderRadius: BorderRadius.circular(999),
                                            gradient: const LinearGradient(
                                              begin: Alignment.centerLeft,
                                              end: Alignment.centerRight,
                                              colors: [Color(0xFFFF4F8F), Color(0xFFFFB03C)],
                                            ),
                                            boxShadow: [
                                              BoxShadow(
                                                color: const Color(0x88FF8B62).withOpacity(scoreBurstSide == 1 ? .36 + (burst * .22) : .12),
                                                blurRadius: 14 + (burst * 14),
                                              ),
                                            ],
                                          ),
                                        ),
                                      );
                                    },
                                  ),
                                ),
                                Align(
                                  alignment: Alignment.centerRight,
                                  child: TweenAnimationBuilder<double>(
                                    tween: Tween<double>(
                                      begin: 0,
                                      end: opponentFraction.clamp(0.0, 1.0),
                                    ),
                                    duration: const Duration(milliseconds: 340),
                                    curve: Curves.easeOutCubic,
                                    builder: (context, animatedFraction, _) {
                                      return FractionallySizedBox(
                                        widthFactor: animatedFraction,
                                        child: Container(
                                          decoration: BoxDecoration(
                                            borderRadius: BorderRadius.circular(999),
                                            gradient: const LinearGradient(
                                              begin: Alignment.centerLeft,
                                              end: Alignment.centerRight,
                                              colors: [Color(0xFF56B8FF), Color(0xFF8864FF)],
                                            ),
                                            boxShadow: [
                                              BoxShadow(
                                                color: const Color(0x885AB3FF).withOpacity(scoreBurstSide == -1 ? .36 + (burst * .22) : .12),
                                                blurRadius: 14 + (burst * 14),
                                              ),
                                            ],
                                          ),
                                        ),
                                      );
                                    },
                                  ),
                                ),
                                Align(
                                  alignment: Alignment.center,
                                  child: Container(
                                    width: 2,
                                    margin: const EdgeInsets.symmetric(vertical: 1),
                                    decoration: BoxDecoration(
                                      color: Colors.white.withOpacity(.58),
                                      borderRadius: BorderRadius.circular(999),
                                      boxShadow: [
                                        BoxShadow(
                                          color: Colors.white.withOpacity(.12 + (burst * .08)),
                                          blurRadius: 8 + (burst * 8),
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
                      SizedBox(width: compact ? 6 : 8),
                      _RailScore(
                        value: opponentScore,
                        accent: const [Color(0xFF56B8FF), Color(0xFF8864FF)],
                        active: rightActive,
                        compact: compact,
                      ),
                    ],
                  ),
                ),
              if (showChip) ...[
                const SizedBox(height: 4),
                _LeadBurstChip(
                  leadSide: leadStreak >= 2 && leadSide != 0 ? leadSide : scoreBurstSide,
                  label:
                      leadStreak >= 2 && leadSide != 0
                          ? (leadSide == 1 ? 'Left streak x$leadStreak' : 'Right streak x$leadStreak')
                          : (scoreBurstSide == 1 ? 'Left +$scoreBurstValue' : 'Right +$scoreBurstValue'),
                ),
              ],
            ],
          ),
        );
      },
    );
  }
}

class _TimerPill extends StatelessWidget {
  const _TimerPill({required this.seconds, required this.pulse});

  final int seconds;
  final double pulse;

  @override
  Widget build(BuildContext context) {
    final mins = (seconds ~/ 60).toString().padLeft(2, '0');
    final secs = (seconds % 60).toString().padLeft(2, '0');
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            const Color(0x44FFFFFF),
            const Color(0x22FFFFFF),
          ],
        ),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: Colors.white.withOpacity(.14)),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFFB86CFF).withOpacity(.08 + (pulse * .10)),
            blurRadius: 12 + (pulse * 6),
          ),
        ],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 7,
            height: 7,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: const Color(0xFFFF6B9A).withOpacity(.82 + (pulse * .18)),
            ),
          ),
          const SizedBox(width: 8),
          Text(
            '$mins:$secs',
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
              fontSize: 11.5,
              letterSpacing: .3,
            ),
          ),
        ],
      ),
    );
  }
}

class _SideChip extends StatelessWidget {
  const _SideChip({required this.label, required this.accent});

  final String label;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: accent.withOpacity(.16),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: accent.withOpacity(.28)),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: Colors.white.withOpacity(.92),
          fontWeight: FontWeight.w800,
          fontSize: 10.5,
          letterSpacing: .5,
        ),
      ),
    );
  }
}

class _MiniSideTag extends StatelessWidget {
  const _MiniSideTag({required this.label, required this.accent});

  final String label;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 18,
      height: 18,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: accent.withOpacity(.22),
        border: Border.all(color: accent.withOpacity(.44)),
      ),
      child: Center(
        child: Text(
          label,
          style: const TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.w900,
            fontSize: 10,
          ),
        ),
      ),
    );
  }
}

class _ScorePill extends StatelessWidget {
  const _ScorePill({required this.score, required this.accent});

  final int score;
  final List<Color> accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: accent),
        borderRadius: BorderRadius.circular(999),
        boxShadow: [
          BoxShadow(
            color: accent.last.withOpacity(.30),
            blurRadius: 12,
          ),
        ],
      ),
      child: Text(
        '$score',
        style: const TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w900,
          fontSize: 12.5,
        ),
      ),
    );
  }
}

class _HudScoreLane extends StatelessWidget {
  const _HudScoreLane({
    required this.score,
    required this.accent,
    required this.alignStart,
  });

  final int score;
  final List<Color> accent;
  final bool alignStart;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 7),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: LinearGradient(
          colors: [
            accent.first.withOpacity(.20),
            accent.last.withOpacity(.10),
          ],
        ),
        border: Border.all(color: Colors.white.withOpacity(.08)),
      ),
      child: Row(
        mainAxisAlignment: alignStart ? MainAxisAlignment.start : MainAxisAlignment.end,
        children: [
          Icon(
            Icons.workspace_premium_rounded,
            size: 12,
            color: accent.last.withOpacity(.94),
          ),
          const SizedBox(width: 4),
          Text(
            '$score',
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
              fontSize: 12,
            ),
          ),
        ],
      ),
    );
  }
}

class _RailScore extends StatelessWidget {
  const _RailScore({
    required this.value,
    required this.accent,
    required this.active,
    this.compact = false,
  });

  final int value;
  final List<Color> accent;
  final bool active;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: BoxConstraints(minWidth: compact ? 54 : 62),
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 8 : 10,
        vertical: compact ? 5 : 6,
      ),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: LinearGradient(
          colors: [
            accent.first.withOpacity(active ? .28 : .18),
            accent.last.withOpacity(active ? .22 : .12),
          ],
        ),
        border: Border.all(
          color: Colors.white.withOpacity(active ? .16 : .10),
        ),
        boxShadow: [
          BoxShadow(
            color: accent.first.withOpacity(active ? .20 : .08),
            blurRadius: active ? 12 : 8,
          ),
        ],
      ),
      child: Text(
        '${(value / 1000).toStringAsFixed(value >= 10000 ? 1 : 0)}K',
        textAlign: TextAlign.center,
        style: TextStyle(
          color: Colors.white.withOpacity(active ? .95 : .84),
          fontWeight: FontWeight.w900,
          fontSize: compact ? 9.6 : 10.8,
          letterSpacing: .2,
        ),
      ),
    );
  }
}

class _PkIntroText extends StatelessWidget {
  const _PkIntroText({
    required this.animation,
    required this.leadSide,
  });

  final Animation<double> animation;
  final int leadSide;

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: animation,
      builder: (context, _) {
        final t = animation.value.clamp(0.0, 1.0);
        final eased = Curves.easeOutBack.transform(t);
        final opacity = 1 - Curves.easeIn.transform(t);
        if (opacity <= 0.01) return const SizedBox.shrink();
        final colors =
            leadSide == -1
                ? const [Color(0xFF5AB3FF), Color(0xFF8A63E8)]
                : const [Color(0xFFFF5C8A), Color(0xFFFFA63D)];
        return Opacity(
          opacity: opacity,
          child: Transform.translate(
            offset: Offset(0, (1 - eased) * 22),
            child: Transform.scale(
              scale: .62 + (eased * .58),
              child: ShaderMask(
                shaderCallback: (bounds) => LinearGradient(colors: colors).createShader(bounds),
                child: Text(
                  'PK',
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w900,
                    letterSpacing: 2.8,
                    fontSize: 34,
                    shadows: [
                      Shadow(
                        color: colors.first.withOpacity(.28),
                        blurRadius: 16,
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _WinnerCrown extends StatelessWidget {
  const _WinnerCrown({required this.accent});

  final List<Color> accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        gradient: const LinearGradient(
          colors: [Color(0xFFFFE07A), Color(0xFFFFA83D)],
        ),
        boxShadow: [
          BoxShadow(
            color: accent.last.withOpacity(.24),
            blurRadius: 18,
          ),
        ],
      ),
      child: const Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.workspace_premium_rounded, size: 15, color: Color(0xFF402100)),
          SizedBox(width: 6),
          Text(
            'LEADING',
            style: TextStyle(
              color: Color(0xFF402100),
              fontWeight: FontWeight.w900,
              fontSize: 11.5,
            ),
          ),
        ],
      ),
    );
  }
}

class _LeadBurstChip extends StatelessWidget {
  const _LeadBurstChip({required this.leadSide, required this.label});

  final int leadSide;
  final String label;

  @override
  Widget build(BuildContext context) {
    final ownLead = leadSide > 0;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        gradient: LinearGradient(
          colors: ownLead
              ? const [Color(0xFFFF5C8A), Color(0xFFFFA63D)]
              : const [Color(0xFF5AB3FF), Color(0xFF8A63E8)],
        ),
        boxShadow: [
          BoxShadow(
            color: (ownLead ? const Color(0xFFFF7A79) : const Color(0xFF76A8FF)).withOpacity(.30),
            blurRadius: 16,
          ),
        ],
      ),
      child: Text(
        label,
        style: const TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w900,
          fontSize: 12,
        ),
      ),
    );
  }
}

class _FloatingScoreBurst extends StatelessWidget {
  const _FloatingScoreBurst({
    super.key,
    required this.entry,
  });

  final _ScoreBurstEntry entry;

  @override
  Widget build(BuildContext context) {
    final leftSide = entry.side == 1;
    final accent =
        leftSide
            ? const [Color(0xFFFF5C8A), Color(0xFFFFA63D)]
            : const [Color(0xFF5AB3FF), Color(0xFF8A63E8)];
    return Positioned(
      left: leftSide ? 28 : null,
      right: leftSide ? null : 28,
      top: 78,
      child: TweenAnimationBuilder<double>(
        tween: Tween<double>(begin: 0, end: 1),
        duration: const Duration(milliseconds: 950),
        curve: Curves.easeOutCubic,
        builder: (context, progress, child) {
          return Opacity(
            opacity: (1 - (progress * .16)).clamp(0.0, 1.0),
            child: Transform.translate(
              offset: Offset(leftSide ? progress * 10 : -(progress * 10), -(progress * 42)),
              child: Transform.scale(
                scale: 0.9 + (progress * .18),
                child: child,
              ),
            ),
          );
        },
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(999),
            gradient: LinearGradient(colors: accent),
            boxShadow: [
              BoxShadow(
                color: accent.first.withOpacity(.30),
                blurRadius: 14,
              ),
            ],
          ),
          child: Text(
            '+${entry.amount}',
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
              fontSize: 13,
            ),
          ),
        ),
      ),
    );
  }
}

class _LightningRailPainter extends CustomPainter {
  _LightningRailPainter({required this.progress});

  final double progress;

  @override
  void paint(Canvas canvas, Size size) {
    final path = Path();
    final mid = size.width / 2;
    path.moveTo(mid, 10);
    path.lineTo(mid + 6, 36 + (progress * 4));
    path.lineTo(mid - 5, 74 + (progress * 8));
    path.lineTo(mid + 7, 112 + (progress * 2));
    path.lineTo(mid - 4, size.height - 26);
    path.lineTo(mid, size.height - 10);

    final glow = Paint()
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..strokeWidth = 5
      ..color = const Color(0xFF8D68FF).withOpacity(.16 + (progress * .12))
      ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 8);
    final stroke = Paint()
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..strokeWidth = 1.8
      ..color = Colors.white.withOpacity(.30 + (progress * .18));

    canvas.drawPath(path, glow);
    canvas.drawPath(path, stroke);
  }

  @override
  bool shouldRepaint(covariant _LightningRailPainter oldDelegate) {
    return oldDelegate.progress != progress;
  }
}

class _UnavailableBadge extends StatelessWidget {
  const _UnavailableBadge();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.black.withOpacity(.42),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withOpacity(.12)),
      ),
      child: const Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.sync_problem_rounded, color: Colors.white, size: 18),
          SizedBox(width: 8),
          Text(
            'Reconnecting',
            textAlign: TextAlign.center,
            style: TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w800,
              fontSize: 12,
            ),
          ),
        ],
      ),
    );
  }
}

class _EndPkButton extends StatelessWidget {
  const _EndPkButton({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return TextButton.icon(
      onPressed: onTap,
      icon: const Icon(Icons.stop_circle_outlined, size: 18),
      label: const Text('End PK'),
      style: TextButton.styleFrom(
        foregroundColor: Colors.white,
        backgroundColor: Colors.white.withOpacity(.08),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
      ),
    );
  }
}
