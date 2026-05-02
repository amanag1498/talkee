// lib/app/widgets/live_eq_badge.dart (or live_pill.dart)
import 'dart:ui' show ImageFilter;
import 'package:flutter/material.dart';
import 'dart:math' as math;

/// LiveEqBadge — tiny premium LIVE badge with animated EQ bars.
/// Fixes "RenderBox was not laid out" by sizing BackdropFilter from the core.
class LiveEqBadge extends StatefulWidget {
  final double height;        // 16–28 recommended
  final String label;         // usually "LIVE"
  final Color color;          // accent (bars/glow)
  final bool dense;           // tighter kerning
  final bool glass;           // frosted background
  final bool shine;           // diagonal shimmer
  final double intensity;     // 0..1 bar energy
  final double glassSigma;    // blur if glass

  const LiveEqBadge({
    super.key,
    this.height = 18,
    this.label = 'LIVE',
    this.color = const Color(0xFFFF2D55),
    this.dense = true,
    this.glass = true,
    this.shine = true,
    this.intensity = .9,
    this.glassSigma = 8,
  });

  factory LiveEqBadge.solid({
    Key? key,
    double height = 20,
    String label = 'LIVE',
    Color color = const Color(0xFFFF2D55),
    bool dense = true,
    bool shine = false,
    double intensity = 1.0,
  }) {
    return LiveEqBadge(
      key: key,
      height: height,
      label: label,
      color: color,
      dense: dense,
      glass: false,
      shine: shine,
      intensity: intensity,
    );
  }

  @override
  State<LiveEqBadge> createState() => _LiveEqBadgeState();
}

class _LiveEqBadgeState extends State<LiveEqBadge>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c;

  @override
  void initState() {
    super.initState();
    _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1200))
      ..repeat();
  }

  @override
  void dispose() { _c.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) {
    final h   = widget.height.clamp(16.0, 28.0);
    final fs  = h * 0.56;
    final pad = h * 0.46;
    final br  = BorderRadius.circular(999);

    final bg     = widget.glass ? Colors.white.withOpacity(.16) : Colors.black.withOpacity(.46);
    final stroke = widget.glass ? Colors.white.withOpacity(.42) : Colors.white.withOpacity(.28);

    // --- core badge (defines the size) ---
    final core = Container(
      height: h,
      padding: EdgeInsets.symmetric(horizontal: pad),
      decoration: BoxDecoration(
        borderRadius: br,
        color: bg,
        border: Border.all(color: stroke, width: widget.glass ? 1.0 : 0.8),
        boxShadow: [
          BoxShadow(color: widget.color.withOpacity(.26), blurRadius: 10, offset: const Offset(0, 2)),
        ],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _EqBars(h: h, t: _c.value, color: widget.color, intensity: widget.intensity),
          SizedBox(width: widget.dense ? 6 : 8),
          Text(
            widget.label.toUpperCase(),
            maxLines: 1,
            style: TextStyle(
              color: Colors.white,
              fontSize: fs,
              height: 1.0,
              fontWeight: FontWeight.w900,
              letterSpacing: widget.dense ? .8 : 1.05,
            ),
          ),
        ],
      ),
    );

    if (!widget.glass && !widget.shine) {
      // no stack needed if no glass/shine
      return ClipRRect(borderRadius: br, child: core);
    }

    // --- We size the BackdropFilter BY the core (child) ---
    final frosted = widget.glass
        ? BackdropFilter(
      filter: ImageFilter.blur(sigmaX: widget.glassSigma, sigmaY: widget.glassSigma),
      child: core,
    )
        : core;

    // --- Shine overlay: now safe to fill, because Stack is sized by 'frosted' child ---
    final dx = widget.shine ? Tween(begin: -1.0, end: 2.0).transform(_c.value) : 5.0;

    return ClipRRect(
      borderRadius: br,
      child: Stack(
        clipBehavior: Clip.hardEdge,
        children: [
          frosted,
          if (widget.shine)
            Positioned.fill(
              child: IgnorePointer(
                child: Opacity(
                  opacity: .18,
                  child: FractionalTranslation(
                    translation: Offset(dx, 0),
                    child: const DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                          colors: [Colors.transparent, Colors.white, Colors.transparent],
                          stops: [0.25, 0.5, 0.75],
                        ),
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

class _EqBars extends StatelessWidget {
  final double h;
  final double t;       // 0..1
  final Color color;
  final double intensity;

  const _EqBars({
    required this.h,
    required this.t,
    required this.color,
    required this.intensity,
  });

  @override
  Widget build(BuildContext context) {
    final w    = h * 1.02;
    final barW = (h * 0.11).clamp(1.8, 3.4);
    final r    = BorderRadius.circular(barW * 1.15);

    double barHeight({
      required double phase,
      required double base,
      required double amp,
      double speed = 1.0,
    }) {
      final raw   = (math.sin(((t * speed) * 2 * math.pi) + phase) + 1) / 2; // 0..1
      final eased = Curves.easeInOut.transform(raw);
      return base + amp * (eased * intensity);
    }

    final b1 = barHeight(phase: 0.00, base: h * 0.20, amp: h * 0.46, speed: 1.05);
    final b2 = barHeight(phase: 1.35, base: h * 0.18, amp: h * 0.54, speed: 0.95);
    final b3 = barHeight(phase: 2.70, base: h * 0.22, amp: h * 0.40, speed: 1.12);
    final b4 = barHeight(phase: 4.05, base: h * 0.16, amp: h * 0.58, speed: 0.90);

    Color _mix(Color a, Color b, double t) => Color.fromARGB(
      (a.alpha + ((b.alpha - a.alpha) * t)).round(),
      (a.red   + ((b.red   - a.red  ) * t)).round(),
      (a.green + ((b.green - a.green) * t)).round(),
      (a.blue  + ((b.blue  - a.blue ) * t)).round(),
    );

    final top = _mix(color, Colors.white, .30);

    BoxDecoration deco() => BoxDecoration(
      borderRadius: r,
      gradient: LinearGradient(
        begin: Alignment.bottomCenter,
        end: Alignment.topCenter,
        colors: [color, top],
      ),
      boxShadow: [
        BoxShadow(color: color.withOpacity(.30), blurRadius: 6, offset: const Offset(0, 1)),
      ],
    );

    return SizedBox(
      width: w,
      height: h,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceEvenly,
        children: [
          Container(width: barW, height: b1, decoration: deco()),
          Container(width: barW, height: b2, decoration: deco()),
          Container(width: barW, height: b3, decoration: deco()),
          Container(width: barW, height: b4, decoration: deco()),
        ],
      ),
    );
  }
}
