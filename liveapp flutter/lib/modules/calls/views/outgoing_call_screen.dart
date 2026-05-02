import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/theme/brand.dart';
import '../../../app/widgets/haptics.dart';
import '../../../services/app_settings_service.dart';
import '../controllers/call_controller.dart';
import 'call_ui.dart';

class OutgoingCallScreen extends StatefulWidget {
  const OutgoingCallScreen({super.key});

  @override
  State<OutgoingCallScreen> createState() => _OutgoingCallScreenState();
}

class _OutgoingCallScreenState extends State<OutgoingCallScreen>
    with TickerProviderStateMixin {
  final AppCallController controller = Get.find<AppCallController>();

  late final AnimationController _pulseController;
  late final AnimationController _floatController;

  @override
  void initState() {
    super.initState();
    Haptics.medium();

    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1800),
    )..repeat();

    _floatController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2600),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pulseController.dispose();
    _floatController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return CallScaffold(
      child: Obx(() {
        final call = controller.activeCall.value ?? <String, dynamic>{};
        final type = (call['type'] ?? 'audio').toString();
        final displayName = controller.remoteDisplayName;
        final avatarUrl = controller.remoteAvatarUrl;
        final secondsLeft = controller.ringingSecondsLeft.value;
        final isVideo = type == 'video';
        final accent = isVideo ? const Color(0xFF4F8CFF) : const Color(0xFF7C5CFF);

        final statusText = controller.reconnecting.value
            ? 'Reconnecting...'
            : controller.callState.value == 'outgoing_ringing'
                ? 'Ringing'
                : 'Calling';

        return LayoutBuilder(
          builder: (context, constraints) {
            final h = constraints.maxHeight;
            final w = constraints.maxWidth;
            final compact = h < 760 || w < 370;
            final heroGap = compact ? 18.0 : 30.0;
            final nameGap = compact ? 18.0 : 24.0;
            final statusGap = compact ? 18.0 : 24.0;
            final waveformGap = compact ? 14.0 : 18.0;
            final chipGap = compact ? 16.0 : 22.0;
            final bottomGap = compact ? 16.0 : 24.0;
            return Stack(
              children: [
                Positioned.fill(
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        colors: [
                          const Color(0xFF07111F),
                          isVideo ? const Color(0xFF102A58) : const Color(0xFF1D1248),
                          const Color(0xFF050812),
                        ],
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                      ),
                    ),
                  ),
                ),
                Positioned(
                  top: -80,
                  right: -70,
                  child: _GlowBlob(color: accent.withValues(alpha: .28), size: 220),
                ),
                Positioned(
                  bottom: 110,
                  left: -90,
                  child: _GlowBlob(color: Colors.pink.withValues(alpha: .18), size: 230),
                ),
                SafeArea(
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(22, compact ? 10 : 14, 22, compact ? 18 : 28),
                    child: Column(
                      children: [
                        _TopPill(
                          compact: compact,
                          icon: isVideo ? Icons.videocam_rounded : Icons.call_rounded,
                          text: isVideo ? 'Outgoing Video Call' : 'Outgoing Audio Call',
                        ),
                        SizedBox(height: heroGap),
                        Expanded(
                          child: Column(
                            children: [
                              const Spacer(flex: 2),
                              AnimatedBuilder(
                                animation: _floatController,
                                builder: (_, child) {
                                  final dy = math.sin(_floatController.value * math.pi) * -10;
                                  return Transform.translate(offset: Offset(0, dy), child: child);
                                },
                                child: _PremiumAvatar(
                                  compact: compact,
                                  name: displayName,
                                  avatarUrl: avatarUrl,
                                  accent: accent,
                                  icon: isVideo ? Icons.videocam_rounded : Icons.call_rounded,
                                  pulseController: _pulseController,
                                ),
                              ),
                              SizedBox(height: nameGap),
                              FittedBox(
                                fit: BoxFit.scaleDown,
                                child: Text(
                                  displayName,
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: compact ? 24 : 30,
                                    fontWeight: FontWeight.w900,
                                    letterSpacing: -.4,
                                  ),
                                ),
                              ),
                              const SizedBox(height: 8),
                              Text(
                                isVideo ? 'Video call request sent' : 'Audio call request sent',
                                textAlign: TextAlign.center,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                  color: Colors.white.withValues(alpha: .68),
                                  fontSize: compact ? 12 : 14,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                              SizedBox(height: statusGap),
                              _GlassStatusCard(
                                compact: compact,
                                title: statusText,
                                subtitle: secondsLeft > 0
                                    ? 'Auto cancel in ${secondsLeft}s'
                                    : 'Timeout controlled by server',
                              ),
                              SizedBox(height: waveformGap),
                              AudioWaveform(accent: accent),
                              SizedBox(height: chipGap),
                              Container(
                                padding: EdgeInsets.symmetric(
                                  horizontal: compact ? 14 : 18,
                                  vertical: compact ? 9 : 11,
                                ),
                                decoration: BoxDecoration(
                                  color: Colors.white.withValues(alpha: .10),
                                  borderRadius: BorderRadius.circular(100),
                                  border: Border.all(color: Colors.white.withValues(alpha: .12)),
                                ),
                                child: FittedBox(
                                  fit: BoxFit.scaleDown,
                                  child: Text(
                                    '${controller.ratePerMinute} coins/min',
                                    style: TextStyle(
                                      color: Colors.white,
                                      fontSize: compact ? 16 : 18,
                                      fontWeight: FontWeight.w900,
                                    ),
                                  ),
                                ),
                              ),
                              const Spacer(),
                            ],
                          ),
                        ),
                        SizedBox(height: bottomGap),
                        _PremiumEndButton(
                          compact: compact,
                          label: 'Cancel',
                          onTap: controller.busy.value
                              ? null
                              : () {
                                  Haptics.medium();
                                  controller.cancelOutgoing();
                                },
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            );
          },
        );
      }),
    );
  }
}

class _TopPill extends StatelessWidget {
  const _TopPill({
    required this.icon,
    required this.text,
    this.compact = false,
  });

  final IconData icon;
  final String text;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: compact ? 14 : 16, vertical: compact ? 8 : 9),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .10),
        borderRadius: BorderRadius.circular(100),
        border: Border.all(color: Colors.white.withValues(alpha: .12)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: Colors.white, size: compact ? 16 : 17),
          const SizedBox(width: 8),
          Text(
            text,
            style: TextStyle(
              color: Colors.white,
              fontSize: compact ? 12 : 13,
              fontWeight: FontWeight.w900,
            ),
          ),
        ],
      ),
    );
  }
}

class _PremiumAvatar extends StatelessWidget {
  const _PremiumAvatar({
    required this.name,
    required this.avatarUrl,
    required this.accent,
    required this.icon,
    required this.pulseController,
    this.compact = false,
  });

  final String name;
  final String avatarUrl;
  final Color accent;
  final IconData icon;
  final AnimationController pulseController;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final outerSize = compact ? 160.0 : 190.0;
    final innerSize = compact ? 106.0 : 122.0;
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return SizedBox(
      width: outerSize,
      height: outerSize,
      child: AnimatedBuilder(
        animation: pulseController,
        builder: (_, child) {
          return Stack(
            alignment: Alignment.center,
            children: [
              _PulseRing(
                size: (compact ? 126 : 150) + pulseController.value * (compact ? 35 : 45),
                opacity: 1 - pulseController.value,
                color: accent,
              ),
              _PulseRing(
                size: (compact ? 108 : 125) + pulseController.value * (compact ? 26 : 35),
                opacity: .7 - (pulseController.value * .7),
                color: accent,
              ),
              child!,
            ],
          );
        },
        child: Stack(
          alignment: Alignment.center,
          children: [
            Container(
              width: innerSize,
              height: innerSize,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: LinearGradient(
                  colors: [accent.withValues(alpha: .95), Colors.white.withValues(alpha: .20)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                boxShadow: [
                  BoxShadow(
                    color: accent.withValues(alpha: .35),
                    blurRadius: 34,
                    offset: const Offset(0, 18),
                  ),
                ],
              ),
              padding: const EdgeInsets.all(4),
              child: CircleAvatar(
                backgroundColor: tokens.cardGradient.last,
                backgroundImage: avatarUrl.isNotEmpty ? NetworkImage(avatarUrl) : null,
                child: avatarUrl.isEmpty
                    ? Text(
                        name.isNotEmpty ? name[0].toUpperCase() : '?',
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 36,
                          fontWeight: FontWeight.w900,
                        ),
                      )
                    : null,
              ),
            ),
            Positioned(
              bottom: compact ? 24 : 28,
              right: compact ? 24 : 28,
              child: Container(
                width: compact ? 38 : 42,
                height: compact ? 38 : 42,
                decoration: BoxDecoration(
                  color: accent,
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white, width: 3),
                ),
                child: Icon(icon, color: Colors.white, size: compact ? 18 : 21),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PulseRing extends StatelessWidget {
  const _PulseRing({
    required this.size,
    required this.opacity,
    required this.color,
  });

  final double size;
  final double opacity;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(
          color: color.withValues(alpha: opacity.clamp(0.0, 1.0) * .45),
          width: 2,
        ),
      ),
    );
  }
}

class _GlassStatusCard extends StatelessWidget {
  const _GlassStatusCard({
    required this.title,
    required this.subtitle,
    this.compact = false,
  });

  final String title;
  final String subtitle;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: EdgeInsets.symmetric(horizontal: compact ? 14 : 18, vertical: compact ? 12 : 16),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .10),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: Colors.white.withValues(alpha: .12)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: .18),
            blurRadius: 24,
            offset: const Offset(0, 14),
          ),
        ],
      ),
      child: Column(
        children: [
          Text(
            title,
            style: TextStyle(
              color: Colors.white,
              fontSize: compact ? 16 : 18,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            subtitle,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: Colors.white.withValues(alpha: .62),
              fontSize: compact ? 12 : 13,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

class _PremiumEndButton extends StatelessWidget {
  const _PremiumEndButton({
    required this.label,
    required this.onTap,
    this.compact = false,
  });

  final String label;
  final VoidCallback? onTap;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: compact ? 76 : 86,
      child: Column(
        children: [
          GestureDetector(
            onTap: onTap,
            child: Container(
              width: compact ? 64 : 72,
              height: compact ? 64 : 72,
              decoration: BoxDecoration(
                color: const Color(0xFFFF4D67),
                shape: BoxShape.circle,
                boxShadow: [
                  BoxShadow(
                    color: const Color(0xFFFF4D67).withValues(alpha: .34),
                    blurRadius: 24,
                    offset: const Offset(0, 12),
                  ),
                ],
              ),
              child: const Icon(Icons.call_end_rounded, color: Colors.white, size: 30),
            ),
          ),
          SizedBox(height: compact ? 8 : 10),
          Text(
            label,
            style: TextStyle(
              color: Colors.white.withValues(alpha: .78),
              fontSize: compact ? 12 : 13,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}

class _GlowBlob extends StatelessWidget {
  const _GlowBlob({
    required this.color,
    required this.size,
  });

  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: color,
          boxShadow: [
            BoxShadow(
              color: color,
              blurRadius: 90,
              spreadRadius: 50,
            ),
          ],
        ),
      ),
    );
  }
}
