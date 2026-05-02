import 'package:flutter/material.dart';

class EntranceFader extends StatefulWidget {
  final Widget child;
  final Duration duration;
  final double offsetY;
  const EntranceFader({
    super.key,
    required this.child,
    this.duration = const Duration(milliseconds: 650),
    this.offsetY = 20,
  });

  @override
  State<EntranceFader> createState() => _EntranceFaderState();
}

class _EntranceFaderState extends State<EntranceFader> with SingleTickerProviderStateMixin {
  late final AnimationController _c;
  late final Animation<double> _opacity;
  late final Animation<Offset> _offset;

  @override
  void initState() {
    super.initState();
    _c = AnimationController(vsync: this, duration: widget.duration)..forward();
    _opacity = CurvedAnimation(parent: _c, curve: Curves.easeOutCubic);
    _offset  = Tween(begin: Offset(0, widget.offsetY / 100), end: Offset.zero)
        .animate(CurvedAnimation(parent: _c, curve: Curves.easeOutCubic));
  }

  @override
  void dispose() { _c.dispose(); super.dispose(); }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _opacity,
      child: SlideTransition(position: _offset, child: widget.child),
    );
  }
}
