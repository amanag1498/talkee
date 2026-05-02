import 'dart:math' as math;
import 'package:flutter/material.dart';

class Shake extends StatefulWidget {
  final Widget child;
  final bool trigger;
  const Shake({super.key, required this.child, required this.trigger});

  @override
  State<Shake> createState() => _ShakeState();
}

class _ShakeState extends State<Shake> with SingleTickerProviderStateMixin {
  late final AnimationController _c;

  @override
  void initState() {
    super.initState();
    _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 420));
  }

  @override
  void didUpdateWidget(covariant Shake old) {
    super.didUpdateWidget(old);
    if (widget.trigger && !old.trigger) _c.forward(from: 0);
  }

  @override
  void dispose() { _c.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _c,
      builder: (_, child) {
        final t = _c.value;
        final offset = math.sin(t * math.pi * 6) * 6; // 3 shakes
        return Transform.translate(offset: Offset(offset, 0), child: child);
      },
      child: widget.child,
    );
  }
}
