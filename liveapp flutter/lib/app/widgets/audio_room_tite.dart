import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../theme/brand.dart';
import '../../services/app_settings_service.dart';

class AudioRoomTile extends StatefulWidget {
  final String title;
  final List<String> speakers; // names
  final int listeners;
  final VoidCallback? onTap;

  const AudioRoomTile({
    super.key,
    required this.title,
    required this.speakers,
    required this.listeners,
    this.onTap,
  });

  @override
  State<AudioRoomTile> createState() => _AudioRoomTileState();
}

class _AudioRoomTileState extends State<AudioRoomTile>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c;
  @override
  void initState() {
    super.initState();
    _c = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1200),
    )..repeat();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return ListTile(
      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 2),
      onTap: widget.onTap,
      leading: Container(
        width: 44,
        height: 44,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: LinearGradient(
            colors: [
              tokens.primaryButtonGradient.first,
              tokens.primaryButtonGradient.last,
            ],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          boxShadow: [
            BoxShadow(
              color: tokens.glowColor.withOpacity(.25),
              blurRadius: 14,
              spreadRadius: 1,
            ),
          ],
        ),
        child: const Icon(Icons.mic_rounded, color: Colors.white),
      ),
      title: Text(
        widget.title,
        style: const TextStyle(fontWeight: FontWeight.w800),
      ),
      subtitle: Text(
        '${widget.speakers.take(3).join(', ')} • ${widget.listeners} listening',
      ),
      trailing: _MiniEq(controller: _c),
    );
  }
}

class _MiniEq extends StatelessWidget {
  final AnimationController controller;
  const _MiniEq({required this.controller});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return AnimatedBuilder(
      animation: controller,
      builder: (_, __) {
        final t = controller.value;
        final bars = List.generate(4, (i) {
          final ph = (i / 4) * math.pi * 2;
          final v = (math.sin(t * math.pi * 2 + ph) + 1) / 2; // 0..1
          final h = 10 + v * 16;
          return Container(
            width: 3,
            height: h,
            margin: const EdgeInsets.symmetric(horizontal: 2),
            decoration: BoxDecoration(
              color: tokens.primaryButtonGradient.first,
              borderRadius: BorderRadius.circular(2),
            ),
          );
        });
        return Row(mainAxisSize: MainAxisSize.min, children: bars);
      },
    );
  }
}
