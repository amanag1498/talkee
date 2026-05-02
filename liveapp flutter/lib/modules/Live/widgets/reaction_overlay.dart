// lib/modules/live/widgets/reaction_overlay.dart
import 'dart:math' as math;
import 'package:flutter/material.dart';

class ReactionOverlay extends StatefulWidget {
  const ReactionOverlay({super.key});

  @override
  ReactionOverlayState createState() => ReactionOverlayState();
}

// ⬇️ Public state so it can be referenced by GlobalKey<ReactionOverlayState>
class ReactionOverlayState extends State<ReactionOverlay> with TickerProviderStateMixin {
  final _items = <_Reaction>[];
  int _counter = 0;

  /// Call this from outside via GlobalKey to add a reaction bubble.
  void add(String emoji) {
    final id = _counter++;
    final startX = .15 + math.Random().nextDouble() * .7; // 15%..85%
    setState(() => _items.add(_Reaction(emoji, startX, id)));
    Future.delayed(const Duration(milliseconds: 1600), () {
      if (!mounted) return;
      setState(() => _items.removeWhere((e) => e.id == id));
    });
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: _items.map((e) => _Floaty(emoji: e.emoji, startX: e.startX, key: ValueKey(e.id))).toList(),
    );
  }
}

class _Reaction {
  final String emoji;
  final double startX;
  final int id;
  _Reaction(this.emoji, this.startX, this.id);
}

class _Floaty extends StatefulWidget {
  final String emoji;
  final double startX; // 0..1
  const _Floaty({super.key, required this.emoji, required this.startX});
  @override
  State<_Floaty> createState() => _FloatyState();
}

class _FloatyState extends State<_Floaty> with SingleTickerProviderStateMixin {
  late final AnimationController _c =
  AnimationController(vsync: this, duration: const Duration(milliseconds: 1600))..forward();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _c,
      builder: (_, __) {
        final t = _c.value; // 0..1
        final dy = (1 - t) * 160 + (math.sin(t * 8) * 6);
        final dx = (widget.startX * MediaQuery.of(context).size.width) + math.sin(t * 3.14) * 12;
        final scale = 0.9 + 0.2 * t;
        final opacity = (1 - (t * .7)).clamp(0.0, 1.0);
        return Positioned(
          bottom: dy,
          left: dx,
          child: Opacity(
            opacity: opacity,
            child: Transform.scale(scale: scale, child: Text(widget.emoji, style: const TextStyle(fontSize: 24))),
          ),
        );
      },
    );
  }
}
