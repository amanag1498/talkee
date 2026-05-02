// lib/modules/live/widgets/lower_third_overlay.dart
import 'package:flutter/material.dart';

class LowerThirdOverlay extends StatefulWidget {
  const LowerThirdOverlay({super.key});
  @override
  LowerThirdOverlayState createState() => LowerThirdOverlayState();
}

// ⬇️ Public state so it can be referenced by GlobalKey<LowerThirdOverlayState>
class LowerThirdOverlayState extends State<LowerThirdOverlay> with TickerProviderStateMixin {
  String? _title;
  String? _subtitle;
  late final AnimationController _c =
  AnimationController(vsync: this, duration: const Duration(milliseconds: 380));

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  /// Call this from outside via GlobalKey to show a lower-third.
  Future<void> show({
    required String title,
    String? subtitle,
    Duration duration = const Duration(seconds: 5),
  }) async {
    setState(() {
      _title = title;
      _subtitle = subtitle;
    });
    await _c.forward();
    await Future.delayed(duration);
    if (!mounted) return;
    await _c.reverse();
    if (!mounted) return;
    setState(() {
      _title = null;
      _subtitle = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_title == null) return const SizedBox.shrink();
    return Positioned(
      left: 16,
      bottom: 16,
      child: FadeTransition(
        opacity: _c,
        child: SlideTransition(
          position: _c.drive(
            Tween(begin: const Offset(-.06, .1), end: Offset.zero).chain(
              CurveTween(curve: Curves.easeOutCubic),
            ),
          ),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              color: Colors.black.withOpacity(.55),
              border: Border.all(color: Colors.white.withOpacity(.15)),
            ),
            child: DefaultTextStyle(
              style: const TextStyle(color: Colors.white),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(_title!, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 14)),
                  if (_subtitle != null)
                    Text(_subtitle!, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12, color: Colors.white70)),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
