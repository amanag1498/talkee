import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../app/theme/brand.dart';

class CallScaffold extends StatelessWidget {
  const CallScaffold({
    super.key,
    required this.child,
  });

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
      backgroundColor: const Color(0xFF0F0B1C),
      body: Stack(
        children: [
          const _CallBackground(),
          SafeArea(
            child: child,
          ),
        ],
      ),
    ));
  }
}

class CallHero extends StatefulWidget {
  const CallHero({
    super.key,
    required this.name,
    this.subtitle,
    this.avatarUrl,
    this.accent = kTalkeePrimary,
    this.icon = Icons.call_rounded,
  });

  final String name;
  final String? subtitle;
  final String? avatarUrl;
  final Color accent;
  final IconData icon;

  @override
  State<CallHero> createState() => _CallHeroState();
}

class _CallHeroState extends State<CallHero> with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2200),
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final subtitle = widget.subtitle?.trim() ?? '';
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          width: 220,
          height: 220,
          child: AnimatedBuilder(
            animation: _controller,
            builder: (context, _) {
              final pulse = Curves.easeOut.transform(_controller.value);
              return Stack(
                alignment: Alignment.center,
                children: [
                  _PulseRing(
                    size: 150 + (pulse * 54),
                    color: widget.accent.withValues(alpha: 0.16 * (1 - pulse)),
                  ),
                  _PulseRing(
                    size: 118 + (pulse * 38),
                    color: Colors.white.withValues(alpha: 0.07 * (1 - pulse)),
                  ),
                  Container(
                    width: 126,
                    height: 126,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      gradient: LinearGradient(
                        colors: [
                          widget.accent.withValues(alpha: .34),
                          widget.accent.withValues(alpha: .12),
                        ],
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                      ),
                      border: Border.all(color: Colors.white.withValues(alpha: .16)),
                      boxShadow: [
                        BoxShadow(
                          color: widget.accent.withValues(alpha: .16),
                          blurRadius: 24,
                          offset: const Offset(0, 12),
                        ),
                      ],
                    ),
                  ),
                  CircleAvatar(
                    radius: 54,
                    backgroundColor: const Color(0xFF1B1430),
                    backgroundImage: (widget.avatarUrl != null && widget.avatarUrl!.isNotEmpty)
                        ? NetworkImage(widget.avatarUrl!)
                        : null,
                    child: (widget.avatarUrl != null && widget.avatarUrl!.isNotEmpty)
                        ? null
                        : Text(
                            _initials(widget.name),
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 26,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                  ),
                  Positioned(
                    right: 40,
                    bottom: 38,
                    child: Container(
                      width: 42,
                      height: 42,
                      decoration: BoxDecoration(
                        color: const Color(0xFF120F22),
                        shape: BoxShape.circle,
                        border: Border.all(color: Colors.white.withValues(alpha: .14)),
                      ),
                      child: Icon(widget.icon, color: Colors.white, size: 20),
                    ),
                  ),
                ],
              );
            },
          ),
        ),
        const SizedBox(height: 16),
        Text(
          widget.name,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          textAlign: TextAlign.center,
          style: const TextStyle(
            color: Colors.white,
            fontSize: 28,
            fontWeight: FontWeight.w800,
          ),
        ),
        if (subtitle.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text(
            subtitle,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: Colors.white.withValues(alpha: .68),
              fontSize: 15,
              fontWeight: FontWeight.w500,
            ),
          ),
        ],
      ],
    );
  }

  String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((part) => part.isNotEmpty).take(2).toList();
    if (parts.isEmpty) return '?';
    return parts.map((part) => part.substring(0, 1).toUpperCase()).join();
  }
}

class CallStatusText extends StatefulWidget {
  const CallStatusText({
    super.key,
    required this.text,
  });

  final String text;

  @override
  State<CallStatusText> createState() => _CallStatusTextState();
}

class _CallStatusTextState extends State<CallStatusText> with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1100),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: Tween<double>(begin: .45, end: 1).animate(
        CurvedAnimation(parent: _controller, curve: Curves.easeInOut),
      ),
      child: Text(
        widget.text,
        textAlign: TextAlign.center,
        style: TextStyle(
          color: Colors.white.withValues(alpha: .72),
          fontSize: 15,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

class CallTypingDots extends StatefulWidget {
  const CallTypingDots({super.key});

  @override
  State<CallTypingDots> createState() => _CallTypingDotsState();
}

class _CallTypingDotsState extends State<CallTypingDots> with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1200),
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 42,
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, _) {
          return Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: List.generate(3, (index) {
              final value = ((_controller.value + (index * .18)) % 1.0);
              final scale = .7 + (.5 * (1 - (value - .5).abs() * 2).clamp(0, 1));
              return Transform.scale(
                scale: scale,
                child: Container(
                  width: 8,
                  height: 8,
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: .80),
                    shape: BoxShape.circle,
                  ),
                ),
              );
            }),
          );
        },
      ),
    );
  }
}

class AudioWaveform extends StatefulWidget {
  const AudioWaveform({
    super.key,
    this.accent = kTalkeePrimary,
  });

  final Color accent;

  @override
  State<AudioWaveform> createState() => _AudioWaveformState();
}

class _AudioWaveformState extends State<AudioWaveform> with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1400),
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 42,
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, _) {
          return Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: List.generate(5, (index) {
              final offset = ((_controller.value + index * .13) % 1.0);
              final amp = (14 + (18 * (1 - (offset - .5).abs() * 2).clamp(0, 1))).toDouble();
              return Container(
                width: 7,
                height: amp,
                margin: const EdgeInsets.symmetric(horizontal: 4),
                decoration: BoxDecoration(
                  color: widget.accent.withValues(alpha: .85),
                  borderRadius: BorderRadius.circular(999),
                ),
              );
            }),
          );
        },
      ),
    );
  }
}

class CallGlassCard extends StatelessWidget {
  const CallGlassCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(18),
  });

  final Widget child;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(28),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: Container(
          padding: padding,
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                const Color(0xFF1B1430).withValues(alpha: .94),
                const Color(0xFF120F22).withValues(alpha: .96),
              ],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: Colors.white.withValues(alpha: .10)),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: .24),
                blurRadius: 30,
                offset: const Offset(0, 18),
              ),
            ],
          ),
          child: child,
        ),
      ),
    );
  }
}

class CallMetricTile extends StatelessWidget {
  const CallMetricTile({
    super.key,
    required this.label,
    required this.value,
    required this.icon,
    this.accent = kTalkeePrimary,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .05),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: Colors.white.withValues(alpha: .08)),
      ),
      child: Row(
        children: [
          Container(
            width: 34,
            height: 34,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: .18),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, size: 18, color: accent),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: .54),
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  value,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class CallPill extends StatelessWidget {
  const CallPill({
    super.key,
    required this.label,
    required this.color,
  });

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .16),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: .22)),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: color,
          fontSize: 12,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

class CallFabButton extends StatelessWidget {
  const CallFabButton({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    required this.color,
    this.filled = true,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final Color color;
  final bool filled;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          AnimatedContainer(
            duration: const Duration(milliseconds: 180),
            width: 72,
            height: 72,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: filled ? color : Colors.white.withValues(alpha: .06),
              border: Border.all(
                color: filled ? color : Colors.white.withValues(alpha: .12),
              ),
              boxShadow: filled
                  ? [
                      BoxShadow(
                        color: color.withValues(alpha: .24),
                        blurRadius: 22,
                        offset: const Offset(0, 12),
                      ),
                    ]
                  : null,
            ),
            child: Icon(
              icon,
              color: filled ? const Color(0xFF07111F) : Colors.white,
              size: 30,
            ),
          ),
          const SizedBox(height: 10),
          Text(
            label,
            style: TextStyle(
              color: Colors.white.withValues(alpha: .78),
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

class CallControlButton extends StatelessWidget {
  const CallControlButton({
    super.key,
    required this.icon,
    required this.label,
    required this.onTap,
    required this.active,
    this.disabled = false,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final bool active;
  final bool disabled;

  @override
  Widget build(BuildContext context) {
    final baseColor = disabled
        ? Colors.white.withValues(alpha: .06)
        : active
            ? kTalkeePrimary.withValues(alpha: .18)
            : Colors.white.withValues(alpha: .06);
    final iconColor = disabled
        ? Colors.white.withValues(alpha: .28)
        : active
            ? kTalkeePrimary
            : Colors.white;

    return InkWell(
      onTap: disabled ? null : onTap,
      borderRadius: BorderRadius.circular(20),
      child: Ink(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(20),
          color: baseColor,
          border: Border.all(color: Colors.white.withValues(alpha: .08)),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, color: iconColor, size: 20),
            const SizedBox(width: 10),
            Flexible(
              child: Text(
                label,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: iconColor,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CallBackground extends StatelessWidget {
  const _CallBackground();

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                const Color(0xFF0F0B1C),
                const Color(0xFF19112C),
                kTalkeePrimary.withValues(alpha: .18),
              ],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
          ),
        ),
        Positioned(
          top: -90,
          right: -70,
          child: _GlowBlob(
            size: 250,
            colors: [kTalkeePrimary.withValues(alpha: .26), Colors.transparent],
          ),
        ),
        Positioned(
          top: 240,
          left: -80,
          child: _GlowBlob(
            size: 220,
            colors: [const Color(0xFFB89CF8).withValues(alpha: .16), Colors.transparent],
          ),
        ),
        Positioned(
          bottom: -60,
          right: -40,
          child: _GlowBlob(
            size: 180,
            colors: [kTalkeeGold.withValues(alpha: .10), Colors.transparent],
          ),
        ),
      ],
    );
  }
}

class IncomingHeadsUpAvatar extends StatefulWidget {
  const IncomingHeadsUpAvatar({
    super.key,
    required this.name,
    required this.avatarUrl,
    required this.accent,
    required this.icon,
  });

  final String name;
  final String avatarUrl;
  final Color accent;
  final IconData icon;

  @override
  State<IncomingHeadsUpAvatar> createState() => _IncomingAvatarState();
}

class _IncomingAvatarState extends State<IncomingHeadsUpAvatar> with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1800),
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 62,
      height: 62,
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, _) {
          final pulse = Curves.easeOut.transform(_controller.value);
          return Stack(
            alignment: Alignment.center,
            children: [
              Container(
                width: 44 + (pulse * 16),
                height: 44 + (pulse * 16),
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: widget.accent.withValues(alpha: .16 * (1 - pulse)),
                ),
              ),
              CircleAvatar(
                radius: 24,
                backgroundColor: const Color(0xFF1B1430),
                backgroundImage: widget.avatarUrl.isNotEmpty ? NetworkImage(widget.avatarUrl) : null,
                child: widget.avatarUrl.isNotEmpty
                    ? null
                    : Text(
                        _initials(widget.name),
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 16,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
              ),
              Positioned(
                right: 0,
                bottom: 0,
                child: Container(
                  width: 22,
                  height: 22,
                  decoration: BoxDecoration(
                    color: const Color(0xFF120F22),
                    shape: BoxShape.circle,
                    border: Border.all(color: Colors.white.withValues(alpha: .10)),
                  ),
                  child: Icon(widget.icon, color: Colors.white, size: 12),
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((part) => part.isNotEmpty).take(2).toList();
    if (parts.isEmpty) return '?';
    return parts.map((part) => part.substring(0, 1).toUpperCase()).join();
  }
}

class IncomingPulseDot extends StatefulWidget {
  const IncomingPulseDot({super.key});

  @override
  State<IncomingPulseDot> createState() => _IncomingPulseDotState();
}

class _IncomingPulseDotState extends State<IncomingPulseDot> with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: Tween<double>(begin: .35, end: 1).animate(
        CurvedAnimation(parent: _controller, curve: Curves.easeInOut),
      ),
      child: Container(
        width: 8,
        height: 8,
        decoration: const BoxDecoration(
          color: Color(0xFF55D38A),
          shape: BoxShape.circle,
        ),
      ),
    );
  }
}

class _GlowBlob extends StatelessWidget {
  const _GlowBlob({
    required this.size,
    required this.colors,
  });

  final double size;
  final List<Color> colors;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(colors: colors),
        ),
      ),
    );
  }
}

class _PulseRing extends StatelessWidget {
  const _PulseRing({
    required this.size,
    required this.color,
  });

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(color: color, width: 1.4),
      ),
    );
  }
}
