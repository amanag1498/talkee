import 'dart:math' as math;
import 'dart:ui' show ImageFilter;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';

import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';
import '../../../app/widgets/talkee_logo.dart';

PremiumThemeTokens _welcomeGiftTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class BackstageWelcomeDialog extends StatefulWidget {
  const BackstageWelcomeDialog({
    super.key,
    required this.planName,
    this.endsText,
  });

  final String planName;
  final String? endsText;

  @override
  State<BackstageWelcomeDialog> createState() => _BackstageWelcomeDialogState();
}

class _BackstageWelcomeDialogState extends State<BackstageWelcomeDialog>
    with TickerProviderStateMixin {
  late final AnimationController _pop;    // dialog pop-in
  late final AnimationController _ring;   // border sweep
  late final AnimationController _holo;   // hologram sweep
  late final AnimationController _nfc;    // nfc arcs pulse
  late final AnimationController _halo;   // logo halo pulse
  bool _pressing = false;

  @override
  void initState() {
    super.initState();
    HapticFeedback.lightImpact();

    _pop  = AnimationController(vsync: this, duration: const Duration(milliseconds: 240))..forward();
    _ring = AnimationController(vsync: this, duration: const Duration(seconds: 6))..repeat();
    _holo = AnimationController(vsync: this, duration: const Duration(seconds: 5))..repeat();
    _nfc  = AnimationController(vsync: this, duration: const Duration(milliseconds: 1800))..repeat(reverse: true);
    _halo = AnimationController(vsync: this, duration: const Duration(milliseconds: 2600))..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pop.dispose();
    _ring.dispose();
    _holo.dispose();
    _nfc.dispose();
    _halo.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _welcomeGiftTokens();
    final curve = CurvedAnimation(parent: _pop, curve: Curves.easeOutBack);

    return FadeTransition(
      opacity: curve,
      child: ScaleTransition(
        scale: Tween(begin: .94, end: 1.0).animate(curve),
        child: Dialog(
          backgroundColor: Colors.transparent,
          elevation: 0,
          insetPadding: const EdgeInsets.symmetric(horizontal: 22),
          child: _Frosted(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(18, 18, 18, 14),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // BACKSTAGE PASS CARD
                  AnimatedBuilder(
                    animation: Listenable.merge([_ring, _holo, _nfc]),
                    builder: (_, __) => _VipPassCard(
                      ringT: _ring.value,
                      holoT: _holo.value,
                      nfcT:  _nfc.value,
                    ),
                  ),
                  const SizedBox(height: 16),

                  // HEADLINE
                  ShaderMask(
                    shaderCallback: (r) => LinearGradient(
                      colors: tokens.primaryButtonGradient,
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ).createShader(r),
                    child: const Text(
                      "You're on the list",
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.w900,
                        letterSpacing: .2,
                      ),
                    ),
                  ),

                  const SizedBox(height: 8),

                  // BODY
                  Text(
                    'Subscription — ${widget.planName}. Enjoy on us.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: tokens.textPrimary.withOpacity(.92),
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  if (widget.endsText != null) ...[
                    const SizedBox(height: 6),
                    Text(
                      widget.endsText!,
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: tokens.textSecondary.withOpacity(.82),
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ],

                  const SizedBox(height: 18),

                  // CTA — matte, no gloss
                  AnimatedBuilder(
                    animation: _halo,
                    builder: (_, __) {
                      final subtleLift = 1 + (math.sin(_halo.value * 2 * math.pi) * 0.01);
                      return AnimatedScale(
                        duration: const Duration(milliseconds: 110),
                        scale: _pressing ? .985 : subtleLift,
                        child: GestureDetector(
                          onTapDown: (_) => setState(() => _pressing = true),
                          onTapCancel: () => setState(() => _pressing = false),
                          onTapUp: (_) => setState(() => _pressing = false),
                          onTap: () {
                            HapticFeedback.selectionClick();
                            Get.back();
                          },
                          child: Container(
                            width: double.infinity,
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(14),
                              gradient: LinearGradient(
                                colors: tokens.primaryButtonGradient,
                                begin: Alignment.centerLeft,
                                end: Alignment.centerRight,
                              ),
                              boxShadow: [
                                BoxShadow(
                                  color: Colors.black.withOpacity(.25),
                                  blurRadius: 14,
                                  offset: const Offset(0, 8),
                                ),
                              ],
                            ),
                            alignment: Alignment.center,
                            child: const Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(Icons.arrow_forward_rounded, color: Colors.white),
                                SizedBox(width: 10),
                                Text(
                                  'Enter now',
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.w900,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      );
                    },
                  ),

                  // Secondary

                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// ────────────────────────────────────────────
// Frosted container (glass, matte)
// ────────────────────────────────────────────
class _Frosted extends StatelessWidget {
  const _Frosted({required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final tokens = _welcomeGiftTokens();
    return ClipRRect(
      borderRadius: BorderRadius.circular(22),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 22, sigmaY: 22),
        child: Container(
          decoration: BoxDecoration(
            color: tokens.cardGradient.last.withOpacity(.58),
            border: Border.all(color: tokens.borderColor.withOpacity(.72)),
            gradient: LinearGradient(
              colors: tokens.cardGradient,
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withOpacity(.42),
                blurRadius: 26,
                offset: const Offset(0, 12),
              ),
            ],
          ),
          child: child,
        ),
      ),
    );
  }
}

// ────────────────────────────────────────────
// VIP pass card (matte, with animated ring + holo stripe + NFC arcs)
// ────────────────────────────────────────────
class _VipPassCard extends StatelessWidget {
  const _VipPassCard({required this.ringT, required this.holoT, required this.nfcT});
  final double ringT;
  final double holoT;
  final double nfcT;

  static const _brand1 = Color(0xFF7B50C5);
  static const _brand2 = Color(0xFF3E2374);

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(2.2), // animated ring thickness
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(18),
        gradient: SweepGradient(
          startAngle: 0,
          endAngle: math.pi * 2,
          transform: GradientRotation(ringT * 2 * math.pi),
          colors: [
            _brand1.withOpacity(.08),
            _brand2.withOpacity(.22),
            _brand1.withOpacity(.08),
          ],
        ),
      ),
      child: Container(
        height: 120,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          color: Colors.white.withOpacity(.06),
          border: Border.all(color: Colors.white.withOpacity(.16)),
          gradient: const LinearGradient(
            colors: [Color(0xFF23143F), Color(0xFF170E2E)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        clipBehavior: Clip.antiAlias,
        child: Stack(
          children: [
            // Hologram sweep (very subtle)
            Positioned.fill(
              child: IgnorePointer(
                child: CustomPaint(painter: _HoloPainter(t: holoT)),
              ),
            ),
            // Content
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              child: Row(
                children: [
                  // Left: labels
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Subscription',
                            style: TextStyle(
                              color: Colors.white.withOpacity(.7),
                              fontWeight: FontWeight.w800,
                              letterSpacing: 1.4,
                              fontSize: 12,
                            )),
                        const SizedBox(height: 6),
                        const Text(
                          'Live Stream',
                          style: TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w900,
                            fontSize: 20,
                            letterSpacing: .2,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'Access granted',
                          style: TextStyle(
                            color: Colors.white.withOpacity(.78),
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ],
                    ),
                  ),
                  // Right: logo + nfc waves
                  SizedBox(
                    height: double.infinity,
                    width: 120,
                    child: Stack(
                      alignment: Alignment.centerRight,
                      children: [
                        const Positioned(
                          right: 6,
                          child: TalkeeLogo(size: 46, showWordmark: false),
                        ),
                        Positioned(
                          right: 54,
                          top: 20,
                          child: IgnorePointer(
                            child: CustomPaint(
                              painter: _NfcPainter(t: nfcT),
                              size: const Size(36, 36),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            // Perforation hint (ticket feel)
            Align(
              alignment: Alignment.center,
              child: Container(
                height: 1,
                margin: const EdgeInsets.symmetric(horizontal: 12),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [
                      Colors.white.withOpacity(.10),
                      Colors.white.withOpacity(.02),
                      Colors.white.withOpacity(.10),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// Subtle moving “hologram” diagonal stripe
class _HoloPainter extends CustomPainter {
  final double t; // 0..1
  const _HoloPainter({required this.t});

  @override
  void paint(Canvas canvas, Size size) {
    final dx = -size.width * .4 + (size.width * 1.4) * t;
    final rect = Rect.fromLTWH(dx, -size.height * .3, size.width * .32, size.height * 1.6);
    final paint = Paint()
      ..shader = const LinearGradient(
        begin: Alignment.topLeft,
        end: Alignment.bottomRight,
        colors: [Colors.white10, Colors.white24, Colors.white10],
        stops: [0, .5, 1],
      ).createShader(rect);
    canvas.save();
    canvas.transform(Matrix4.rotationZ(-0.55).storage);
    canvas.drawRRect(RRect.fromRectAndRadius(rect, const Radius.circular(22)), paint);
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant _HoloPainter old) => old.t != t;
}

// NFC arcs pulsing near the logo
class _NfcPainter extends CustomPainter {
  final double t; // 0..1
  const _NfcPainter({required this.t});

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(0, size.height / 2);
    final base = Paint()
      ..color = Colors.white.withOpacity(.35 + .25 * (math.sin(t * math.pi) * .6 + .4))
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.6
      ..isAntiAlias = true;

    for (int i = 0; i < 3; i++) {
      final r = 8 + i * 6;
      final p = Path()
        ..addArc(Rect.fromCircle(center: center, radius: r.toDouble()), -math.pi / 3, math.pi * 2 / 3);
      canvas.drawPath(p, base..color = base.color.withOpacity(.35 - i * .08));
    }
  }

  @override
  bool shouldRepaint(covariant _NfcPainter old) => old.t != t;
}
