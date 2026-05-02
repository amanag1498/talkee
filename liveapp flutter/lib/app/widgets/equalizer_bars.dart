import 'dart:math' as math;
import 'package:flutter/material.dart';
import '../theme/brand.dart';

class EqualizerBars extends StatefulWidget {
  final int barCount;
  const EqualizerBars({super.key, this.barCount = 7});

  @override
  State<EqualizerBars> createState() => _EqualizerBarsState();
}

class _EqualizerBarsState extends State<EqualizerBars> with TickerProviderStateMixin {
  late final AnimationController _c;

  @override
  void initState() {
    super.initState();
    _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1200))..repeat();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    const baseHeight = 10.0;
    const maxHeight = 46.0;

    return SizedBox(
      height: 56,
      child: AnimatedBuilder(
        animation: _c,
        builder: (_, __) {
          return Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: List.generate(widget.barCount, (i) {
              final phase = (i / widget.barCount) * 2 * math.pi;
              final v = (math.sin((_c.value * 2 * math.pi) + phase) + 1) / 2;
              final h = baseHeight + (maxHeight - baseHeight) * v;

              return Container(
                width: 6,
                height: h,
                margin: const EdgeInsets.symmetric(horizontal: 4),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(6),
                  gradient: const LinearGradient(
                    begin: Alignment.bottomCenter,
                    end: Alignment.topCenter,
                    colors: [kTalkeePrimary, Color(0xFF3E2374)],
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: kTalkeePrimary.withOpacity(.35),
                      blurRadius: 12,
                      spreadRadius: 1,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
              );
            }),
          );
        },
      ),
    );
  }
}
