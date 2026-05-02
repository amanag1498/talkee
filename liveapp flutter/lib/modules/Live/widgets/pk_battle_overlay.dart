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

  @override
  State<PkBattleOverlay> createState() => _PkBattleOverlayState();
}

class _PkBattleOverlayState extends State<PkBattleOverlay> with TickerProviderStateMixin {
  late final AnimationController _pulse;
  late final AnimationController _burst;
  Timer? _ticker;
  int _remaining = 0;
  int _leadSide = 0;

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
    _leadSide = _computeLeadSide(widget.ownScore, widget.opponentScore);
    _syncRemaining();
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) => _syncRemaining());
  }

  @override
  void didUpdateWidget(covariant PkBattleOverlay oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.battle.battleId != widget.battle.battleId || oldWidget.battle.endsAt != widget.battle.endsAt) {
      _syncRemaining();
    }
    final nextLeadSide = _computeLeadSide(widget.ownScore, widget.opponentScore);
    if (nextLeadSide != _leadSide) {
      _leadSide = nextLeadSide;
      if (_leadSide != 0) {
        _burst.forward(from: 0);
      }
    }
  }

  void _syncRemaining() {
    if (!mounted) return;
    setState(() => _remaining = widget.battle.remainingSeconds);
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _pulse.dispose();
    _burst.dispose();
    super.dispose();
  }

  int _computeLeadSide(int own, int opponent) {
    if (own == opponent) return 0;
    return own > opponent ? 1 : -1;
  }

  @override
  Widget build(BuildContext context) {
    final total = math.max(1, widget.ownScore + widget.opponentScore);
    final ownFraction = widget.ownScore / total;
    final oppFraction = widget.opponentScore / total;
    return IgnorePointer(
      ignoring: false,
      child: AnimatedBuilder(
        animation: _pulse,
        builder: (context, _) {
          final pulse = Curves.easeInOut.transform(_pulse.value);
          final burst = Curves.easeOutCubic.transform(_burst.value);
          return SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
              child: LayoutBuilder(
                builder: (context, constraints) {
                  final sideGap = constraints.maxWidth < 360 ? 6.0 : 10.0;
                  final railHeight = 76.0;
                  final timerHeight = 32.0;
                  final timerTop = 12.0;
                  final railBottom = 0.0;
                  final railTop = constraints.maxHeight - railHeight;
                  final tilesTop = timerTop + timerHeight + 10;
                  final tilesBottom = railHeight + 10;
                  return Stack(
                    children: [
                      Positioned(
                        top: timerTop,
                        left: 0,
                        right: 0,
                        child: Center(child: _TimerPill(seconds: _remaining, pulse: pulse)),
                      ),
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
                                showCrown: false,
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
                                      showCrown: false,
                                      accent: const [Color(0xFF5AB3FF), Color(0xFF8A63E8)],
                                      child: widget.opponentChild,
                                    ),
                                  ),
                                  if (widget.opponentUnavailable)
                                    Positioned.fill(
                                      child: DecoratedBox(
                                        decoration: BoxDecoration(
                                          color: Colors.black.withOpacity(.54),
                                          borderRadius: BorderRadius.circular(24),
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
                          ),
                        ),
                      ),
                    ],
                  );
                },
              ),
            ),
          );
        },
      ),
    );
  }
}

class PkWinnerOverlay extends StatelessWidget {
  const PkWinnerOverlay({
    super.key,
    required this.title,
    required this.subtitle,
  });

  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Positioned.fill(
      child: IgnorePointer(
        child: ColoredBox(
          color: const Color(0x99070B12),
          child: Center(
            child: Container(
              padding: const EdgeInsets.fromLTRB(22, 18, 22, 18),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(28),
                gradient: const LinearGradient(colors: [Color(0xFF2D153D), Color(0xFF0E1321)]),
                border: Border.all(color: Colors.white.withOpacity(.14)),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.emoji_events_rounded, color: Color(0xFFFFD86B), size: 34),
                  const SizedBox(height: 10),
                  Text(title, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 22)),
                  const SizedBox(height: 6),
                  Text(subtitle, textAlign: TextAlign.center, style: TextStyle(color: Colors.white.withOpacity(.78), fontWeight: FontWeight.w600)),
                ],
              ),
            ),
          ),
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
  });

  final String label;
  final int score;
  final Widget child;
  final List<Color> accent;
  final double pulse;
  final bool showCrown;

  @override
  Widget build(BuildContext context) {
    final borderTint = Color.lerp(accent.first, Colors.white, .18) ?? Colors.white;
    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        color: const Color(0xFF10141D),
        border: Border.all(color: borderTint.withOpacity(.42), width: 1.1),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(.18),
            blurRadius: 14 + (pulse * 4),
          ),
          BoxShadow(
            color: Colors.black.withOpacity(.24),
            blurRadius: 14,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(24),
        child: Stack(
          fit: StackFit.expand,
          children: [
            Positioned.fill(child: child),
            Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [
                      Colors.black.withOpacity(.08),
                      Colors.transparent,
                      Colors.black.withOpacity(.22),
                    ],
                    stops: const [0.0, 0.48, 1.0],
                  ),
                ),
              ),
            ),
            if (showCrown && score > 0)
              Positioned(
                top: 44,
                right: 12,
                child: Transform.translate(
                  offset: Offset(0, -2 - (pulse * 5)),
                  child: _WinnerCrown(accent: accent),
                ),
              ),
            Positioned(
              left: 10,
              right: 10,
              bottom: 10,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: const Color(0xCC0A0D13),
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: borderTint.withOpacity(.18)),
                ),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          label,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 12.5,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
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
  });

  final int ownScore;
  final int opponentScore;
  final double ownFraction;
  final double opponentFraction;
  final double pulse;

  @override
  Widget build(BuildContext context) {
    return ConstrainedBox(
      constraints: const BoxConstraints(maxWidth: 320),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Transform.scale(
            scale: 0.92 + (pulse * .05),
            child: _VsBadge(pulse: pulse),
          ),
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.fromLTRB(10, 8, 10, 8),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(999),
              color: const Color(0xCC11131A),
              border: Border.all(color: Colors.white.withOpacity(.10)),
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withOpacity(.22),
                  blurRadius: 10,
                  offset: const Offset(0, 4),
                ),
              ],
            ),
            child: Row(
              children: [
                _RailScore(value: ownScore, accent: const Color(0xFFFF8B62)),
                const SizedBox(width: 8),
                Expanded(
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(999),
                    child: SizedBox(
                      height: 10,
                      child: Stack(
                        children: [
                          Positioned.fill(
                            child: DecoratedBox(
                              decoration: BoxDecoration(
                                color: Colors.white.withOpacity(.08),
                              ),
                            ),
                          ),
                          Align(
                            alignment: Alignment.centerLeft,
                            child: FractionallySizedBox(
                              widthFactor: ownFraction.clamp(0.0, 1.0),
                              child: Container(
                                decoration: const BoxDecoration(
                                  gradient: LinearGradient(
                                    begin: Alignment.centerLeft,
                                    end: Alignment.centerRight,
                                    colors: [Color(0xFFFF5C8A), Color(0xFFFFA63D)],
                                  ),
                                ),
                              ),
                            ),
                          ),
                          Align(
                            alignment: Alignment.centerRight,
                            child: FractionallySizedBox(
                              widthFactor: opponentFraction.clamp(0.0, 1.0),
                              child: Container(
                                decoration: const BoxDecoration(
                                  gradient: LinearGradient(
                                    begin: Alignment.centerLeft,
                                    end: Alignment.centerRight,
                                    colors: [Color(0xFF5AB3FF), Color(0xFF8A63E8)],
                                  ),
                                ),
                              ),
                            ),
                          ),
                          Align(
                            alignment: Alignment.center,
                            child: Container(
                              width: 2,
                              margin: const EdgeInsets.symmetric(vertical: 1),
                              decoration: BoxDecoration(
                                color: Colors.white.withOpacity(.50),
                                borderRadius: BorderRadius.circular(999),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                _RailScore(value: opponentScore, accent: const Color(0xFF78B8FF)),
              ],
            ),
          ),
        ],
      ),
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
  const _RailScore({required this.value, required this.accent});

  final int value;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Text(
      '${(value / 1000).toStringAsFixed(value >= 10000 ? 1 : 0)}K',
      style: TextStyle(
        color: accent,
        fontWeight: FontWeight.w900,
        fontSize: 10.5,
      ),
    );
  }
}

class _VsBadge extends StatelessWidget {
  const _VsBadge({required this.pulse});

  final double pulse;

  @override
  Widget build(BuildContext context) {
    return Transform.scale(
      scale: 1 + (pulse * .08),
      child: Container(
        width: 28,
        height: 28,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: const LinearGradient(colors: [Color(0xFFFF6C8C), Color(0xFF7B50C5)]),
          boxShadow: [
            BoxShadow(
              color: const Color(0xFFB86CFF).withOpacity(.22 + (pulse * .14)),
              blurRadius: 8,
            ),
          ],
        ),
        child: const Center(
          child: Text('VS', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 9.5)),
        ),
      ),
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
  const _LeadBurstChip({required this.leadSide});

  final int leadSide;

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
        ownLead ? 'Lead Swing' : 'Opponent Surge',
        style: const TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w900,
          fontSize: 12,
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
