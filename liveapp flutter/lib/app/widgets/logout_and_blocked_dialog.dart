import 'dart:ui' show ImageFilter;
import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:liveapp/app/widgets/talkee_logo.dart';
import 'package:liveapp/app/theme/brand.dart';
import 'package:liveapp/services/app_settings_service.dart';
// ⬇️ adjust this path if needed

class LoggedOutDialog extends StatefulWidget {
  const LoggedOutDialog({super.key});

  @override
  State<LoggedOutDialog> createState() => _LoggedOutDialogState();
}

class _LoggedOutDialogState extends State<LoggedOutDialog>
    with TickerProviderStateMixin {
  late final AnimationController _pop;
  late final AnimationController _glow; // logo halo
  late final AnimationController _btn;  // button pulse

  @override
  void initState() {
    super.initState();
    HapticFeedback.lightImpact();
    _pop  = AnimationController(vsync: this, duration: const Duration(milliseconds: 220))..forward();
    _glow = AnimationController(vsync: this, duration: const Duration(milliseconds: 2200))..repeat(reverse: true);
    _btn  = AnimationController(vsync: this, duration: const Duration(milliseconds: 1900))..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pop.dispose();
    _glow.dispose();
    _btn.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final curve = CurvedAnimation(parent: _pop, curve: Curves.easeOutBack);
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );

    return FadeTransition(
      opacity: curve,
      child: ScaleTransition(
        scale: Tween(begin: .94, end: 1.0).animate(curve),
        child: Dialog(
          elevation: 0,
          backgroundColor: Colors.transparent,
          insetPadding: const EdgeInsets.symmetric(horizontal: 22),
          child: _Frosted(
            tokens: tokens,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(18, 18, 18, 14),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // Brand header with animated halo
                  AnimatedBuilder(
                    animation: _glow,
                    builder: (_, __) {
                      final t = _glow.value;
                      return Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          boxShadow: [
                            BoxShadow(
                              color: tokens.glowColor.withOpacity(
                                .22 + .10 * math.sin(t * math.pi),
                              ),
                              blurRadius: 26,
                              spreadRadius: 2,
                            ),
                          ],
                        ),
                        child: const TalkeeLogo(size: 54, showWordmark: false),
                      );
                    },
                  ),
                  const SizedBox(height: 12),

                  // Title
                  ShaderMask(
                    shaderCallback: (r) => LinearGradient(
                      colors: tokens.primaryButtonGradient,
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ).createShader(r),
                    child: const Text(
                      'Signed out',
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

                  // Body
                  Text(
                    'Your session has ended. Please sign in again to continue.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: tokens.textSecondary.withOpacity(.96),
                      fontWeight: FontWeight.w600,
                    ),
                  ),

                  const SizedBox(height: 18),

                  // OK button with subtle pulse + press feedback
                  AnimatedBuilder(
                    animation: _btn,
                    builder: (_, __) {
                      final lift = 1 + (math.sin(_btn.value * 2 * math.pi) * 0.01); // ~1% lift
                      return Transform.scale(
                        scale: lift,
                        child: SizedBox(
                          width: double.infinity,
                          child: ElevatedButton(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: tokens.primaryButtonGradient.first,
                              foregroundColor: tokens.textPrimary,
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(14),
                              ),
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shadowColor: Colors.black.withOpacity(.35),
                              elevation: 8,
                            ),
                            onPressed: () => Navigator.of(context).pop(),
                            child: const Text(
                              'OK',
                              style: TextStyle(fontWeight: FontWeight.w900),
                            ),
                          ),
                        ),
                      );
                    },
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class BlockedDialog extends StatefulWidget {
  const BlockedDialog({super.key});

  @override
  State<BlockedDialog> createState() => _BlockedDialogState();
}

class _BlockedDialogState extends State<BlockedDialog>
    with TickerProviderStateMixin {
  static const _brand1 = Color(0xFF7B50C5);
  static const _brand2 = Color(0xFF3E2374);

  late final AnimationController _pop;
  late final AnimationController _glow;
  late final AnimationController _btn;

  @override
  void initState() {
    super.initState();
    HapticFeedback.lightImpact();
    _pop  = AnimationController(vsync: this, duration: const Duration(milliseconds: 220))..forward();
    _glow = AnimationController(vsync: this, duration: const Duration(milliseconds: 2200))..repeat(reverse: true);
    _btn  = AnimationController(vsync: this, duration: const Duration(milliseconds: 1900))..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pop.dispose();
    _glow.dispose();
    _btn.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final curve = CurvedAnimation(parent: _pop, curve: Curves.easeOutBack);

    return FadeTransition(
      opacity: curve,
      child: ScaleTransition(
        scale: Tween(begin: .94, end: 1.0).animate(curve),
        child: Dialog(
          elevation: 0,
          backgroundColor: Colors.transparent,
          insetPadding: const EdgeInsets.symmetric(horizontal: 22),
          child: _Frosted(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(18, 18, 18, 14),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  AnimatedBuilder(
                    animation: _glow,
                    builder: (_, __) {
                      final t = _glow.value;
                      return Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          boxShadow: [
                            BoxShadow(
                              color: _brand2.withOpacity(.26 + .10 * math.sin(t * math.pi)),
                              blurRadius: 26,
                              spreadRadius: 2,
                            ),
                          ],
                        ),
                        child: const TalkeeLogo(size: 54, showWordmark: false),
                      );
                    },
                  ),
                  const SizedBox(height: 12),

                  ShaderMask(
                    shaderCallback: (r) => const LinearGradient(
                      colors: [_brand1, _brand2],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ).createShader(r),
                    child: const Text(
                      'Account blocked',
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

                  Text(
                    'We’ve blocked your access to keep the community safe.\nIf this looks wrong, please reach out to support.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: Colors.white.withOpacity(.90),
                      fontWeight: FontWeight.w600,
                    ),
                  ),

                  const SizedBox(height: 18),

                  AnimatedBuilder(
                    animation: _btn,
                    builder: (_, __) {
                      final lift = 1 + (math.sin(_btn.value * 2 * math.pi) * 0.01);
                      return Transform.scale(
                        scale: lift,
                        child: SizedBox(
                          width: double.infinity,
                          child: ElevatedButton(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: _brand2,
                              foregroundColor: Colors.white,
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(14),
                              ),
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shadowColor: Colors.black.withOpacity(.35),
                              elevation: 8,
                            ),
                            onPressed: () => Navigator.of(context).pop(),
                            child: const Text(
                              'OK',
                              style: TextStyle(fontWeight: FontWeight.w900),
                            ),
                          ),
                        ),
                      );
                    },
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// Shared frosted container
class _Frosted extends StatelessWidget {
  const _Frosted({required this.child, this.tokens});
  final Widget child;
  final PremiumThemeTokens? tokens;

  @override
  Widget build(BuildContext context) {
    final activeTokens =
        tokens ??
        getPremiumThemeTokens(
          Get.find<AppSettingsService>().activePremiumThemeVariant,
        );
    return ClipRRect(
      borderRadius: BorderRadius.circular(22),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 22, sigmaY: 22),
        child: Container(
          decoration: BoxDecoration(
            color: activeTokens.glassColor.withOpacity(.58),
            border: Border.all(color: activeTokens.borderColor.withOpacity(.82)),
            gradient: LinearGradient(
              colors: activeTokens.cardGradient,
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withOpacity(.42),
                blurRadius: 26,
                offset: Offset(0, 12),
              ),
            ],
          ),
          child: child,
        ),
      ),
    );
  }
}
