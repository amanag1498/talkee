import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../routes/app_routes.dart';
import '../theme/brand.dart';
import '../../services/app_settings_service.dart';

class ThemedEnvironment extends StatefulWidget {
  const ThemedEnvironment({super.key, required this.child});

  final Widget child;

  @override
  State<ThemedEnvironment> createState() => _ThemedEnvironmentState();
}

class _ThemedEnvironmentState extends State<ThemedEnvironment>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(seconds: 18),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final settings = Get.find<AppSettingsService>();
    final reduceMotion =
        MediaQuery.maybeOf(context)?.disableAnimations == true ||
        MediaQuery.maybeOf(context)?.accessibleNavigation == true;

    return Obx(() {
      final variant = settings.activePremiumThemeVariant;
      final tokens = getPremiumThemeTokens(variant);
      final enabled = settings.themeEnvironmentEffectsEnabled && !reduceMotion;
      final route = Get.currentRoute;
      final isLiveRoute = route == Routes.liveVideo ||
          route == Routes.liveAudio ||
          route == Routes.activeCall ||
          route == Routes.incomingCall ||
          route == Routes.outgoingCall;
      final intensity = isLiveRoute ? 0.22 : 0.42;

      return Stack(
        fit: StackFit.expand,
        children: [
          if (enabled)
            Positioned.fill(
              child: IgnorePointer(
                ignoring: true,
                child: RepaintBoundary(
                  child: AnimatedBuilder(
                    animation: _controller,
                    builder: (context, _) {
                      return CustomPaint(
                        painter: _ThemeEnvironmentPainter(
                          progress: _controller.value,
                          routeIntensity: intensity,
                          spec: _specForTheme(variant, tokens),
                        ),
                      );
                    },
                  ),
                ),
              ),
            ),
          widget.child,
        ],
      );
    });
  }
}

class _ThemeEnvironmentSpec {
  const _ThemeEnvironmentSpec({
    required this.primary,
    required this.secondary,
    required this.tertiary,
    required this.baseOpacity,
    required this.blurScale,
    required this.particleCount,
    required this.scanlines,
    required this.shimmer,
  });

  final Color primary;
  final Color secondary;
  final Color tertiary;
  final double baseOpacity;
  final double blurScale;
  final int particleCount;
  final bool scanlines;
  final bool shimmer;
}

_ThemeEnvironmentSpec _specForTheme(String variant, PremiumThemeTokens tokens) {
  switch (variant.trim().toLowerCase()) {
    case 'aurora':
      return _ThemeEnvironmentSpec(
        primary: tokens.primaryButtonGradient.first,
        secondary: tokens.primaryButtonGradient.last,
        tertiary: tokens.glowColor,
        baseOpacity: 0.18,
        blurScale: 1.15,
        particleCount: 0,
        scanlines: false,
        shimmer: true,
      );
    case 'gold':
    case 'gold_black':
      return _ThemeEnvironmentSpec(
        primary: tokens.primaryButtonGradient.first,
        secondary: tokens.primaryButtonGradient.last,
        tertiary: tokens.glowColor,
        baseOpacity: 0.16,
        blurScale: 1.0,
        particleCount: 22,
        scanlines: false,
        shimmer: false,
      );
    case 'ocean':
      return _ThemeEnvironmentSpec(
        primary: tokens.primaryButtonGradient.first,
        secondary: tokens.cardGradient.last,
        tertiary: tokens.glowColor,
        baseOpacity: 0.16,
        blurScale: 1.05,
        particleCount: 0,
        scanlines: false,
        shimmer: true,
      );
    case 'inferno':
    case 'crimson_velvet':
      return _ThemeEnvironmentSpec(
        primary: tokens.primaryButtonGradient.first,
        secondary: tokens.primaryButtonGradient.last,
        tertiary: tokens.dangerColor,
        baseOpacity: 0.16,
        blurScale: 1.0,
        particleCount: 18,
        scanlines: false,
        shimmer: false,
      );
    case 'emerald':
    case 'imperial_jade':
      return _ThemeEnvironmentSpec(
        primary: tokens.primaryButtonGradient.first,
        secondary: tokens.primaryButtonGradient.last,
        tertiary: tokens.successColor,
        baseOpacity: 0.16,
        blurScale: 1.0,
        particleCount: 0,
        scanlines: false,
        shimmer: false,
      );
    case 'ice':
    case 'molten_pearl':
    case 'royal_sapphire':
    case 'noir_opal':
    case 'amethyst_chrome':
      return _ThemeEnvironmentSpec(
        primary: tokens.primaryButtonGradient.first,
        secondary: tokens.primaryButtonGradient.last,
        tertiary: tokens.textSecondary,
        baseOpacity: 0.14,
        blurScale: 1.1,
        particleCount: 0,
        scanlines: false,
        shimmer: true,
      );
    case 'cyberpunk':
      return _ThemeEnvironmentSpec(
        primary: tokens.primaryButtonGradient.first,
        secondary: tokens.primaryButtonGradient.last,
        tertiary: tokens.glowColor,
        baseOpacity: 0.18,
        blurScale: 0.95,
        particleCount: 0,
        scanlines: true,
        shimmer: false,
      );
    case 'ruby_sky':
    case 'teal_rose':
    case 'violet_lime':
    case 'sunset_pop':
    case 'obsidian_rose':
      return _ThemeEnvironmentSpec(
        primary: tokens.primaryButtonGradient.first,
        secondary: tokens.primaryButtonGradient.last,
        tertiary: tokens.glowColor,
        baseOpacity: 0.16,
        blurScale: 1.0,
        particleCount: 0,
        scanlines: false,
        shimmer: false,
      );
    case 'midnight':
    default:
      return _ThemeEnvironmentSpec(
        primary: tokens.primaryButtonGradient.first,
        secondary: tokens.primaryButtonGradient.last,
        tertiary: tokens.glowColor,
        baseOpacity: 0.15,
        blurScale: 1.0,
        particleCount: 0,
        scanlines: false,
        shimmer: false,
      );
  }
}

class _ThemeEnvironmentPainter extends CustomPainter {
  const _ThemeEnvironmentPainter({
    required this.progress,
    required this.routeIntensity,
    required this.spec,
  });

  final double progress;
  final double routeIntensity;
  final _ThemeEnvironmentSpec spec;

  @override
  void paint(Canvas canvas, Size size) {
    final opacity = spec.baseOpacity * routeIntensity;

    _paintAmbientOrb(
      canvas,
      size,
      center: Offset(
        size.width * (0.18 + 0.1 * math.sin(progress * math.pi * 2)),
        size.height * (0.16 + 0.05 * math.cos(progress * math.pi * 2)),
      ),
      color: spec.primary.withValues(alpha: opacity),
      radius: size.shortestSide * 0.34 * spec.blurScale,
    );

    _paintAmbientOrb(
      canvas,
      size,
      center: Offset(
        size.width * (0.82 + 0.08 * math.cos(progress * math.pi * 2)),
        size.height * (0.26 + 0.06 * math.sin(progress * math.pi * 2)),
      ),
      color: spec.secondary.withValues(alpha: opacity * 0.92),
      radius: size.shortestSide * 0.28 * spec.blurScale,
    );

    _paintAmbientOrb(
      canvas,
      size,
      center: Offset(
        size.width * (0.52 + 0.05 * math.sin(progress * math.pi * 4)),
        size.height * (0.88 + 0.03 * math.cos(progress * math.pi * 4)),
      ),
      color: spec.tertiary.withValues(alpha: opacity * 0.6),
      radius: size.shortestSide * 0.24 * spec.blurScale,
    );

    if (spec.shimmer) {
      final shimmerPaint = Paint()
        ..shader = LinearGradient(
          begin: Alignment(-1 + (progress * 2.4), -1),
          end: Alignment(1 + (progress * 2.4), 1),
          colors: [
            Colors.transparent,
            spec.secondary.withValues(alpha: opacity * 0.35),
            Colors.transparent,
          ],
          stops: const [0.2, 0.5, 0.8],
        ).createShader(Offset.zero & size);
      canvas.drawRect(Offset.zero & size, shimmerPaint);
    }

    if (spec.scanlines) {
      final scanPaint = Paint()
        ..color = spec.primary.withValues(alpha: opacity * 0.24)
        ..strokeWidth = 1;
      const gap = 6.0;
      for (double y = 0; y < size.height; y += gap) {
        canvas.drawLine(Offset(0, y), Offset(size.width, y), scanPaint);
      }
    }

    if (spec.particleCount > 0) {
      final particlePaint = Paint()..style = PaintingStyle.fill;
      for (var i = 0; i < spec.particleCount; i++) {
        final t = (progress + (i / spec.particleCount)) % 1.0;
        final dx = (size.width / spec.particleCount) * i +
            14 * math.sin((t * math.pi * 2) + i);
        final dy = size.height * (0.15 + 0.7 * t);
        final radius = 1.2 + (i % 3) * 0.7;
        particlePaint.color = Color.lerp(
              spec.primary,
              spec.secondary,
              (i % 5) / 5,
            )!
            .withValues(alpha: opacity * 0.55);
        canvas.drawCircle(Offset(dx, dy), radius, particlePaint);
      }
    }
  }

  void _paintAmbientOrb(
    Canvas canvas,
    Size size, {
    required Offset center,
    required Color color,
    required double radius,
  }) {
    final rect = Rect.fromCircle(center: center, radius: radius);
      final paint = Paint()
      ..shader = RadialGradient(
        colors: [
          color,
          color.withValues(alpha: color.opacity * 0.24),
          Colors.transparent,
        ],
        stops: const [0.0, 0.42, 1.0],
      ).createShader(rect);
    canvas.drawCircle(center, radius, paint);
  }

  @override
  bool shouldRepaint(covariant _ThemeEnvironmentPainter oldDelegate) {
    return oldDelegate.progress != progress ||
        oldDelegate.routeIntensity != routeIntensity ||
        oldDelegate.spec != spec;
  }
}
