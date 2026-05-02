import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import '../theme/brand.dart';
import '../../services/app_settings_service.dart';

class GoogleButton extends StatefulWidget {
  final Future<void> Function()? onPressed; // make it future-friendly
  final bool loading;
  const GoogleButton({super.key, required this.onPressed, required this.loading});

  @override
  State<GoogleButton> createState() => _GoogleButtonState();
}

class _GoogleButtonState extends State<GoogleButton> with SingleTickerProviderStateMixin {
  late final AnimationController _c;
  bool _pressed = false;

  @override
  void initState() {
    super.initState();
    _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 2200))..repeat();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final enabled = widget.onPressed != null && !widget.loading;
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );

    return AnimatedBuilder(
      animation: _c,
      builder: (_, __) {
        final dx = Tween<double>(begin: -1.0, end: 2.0).transform(_c.value);

        return AnimatedScale(
          scale: _pressed ? 0.98 : 1.0,
          duration: const Duration(milliseconds: 120),
          child: GestureDetector(
            behavior: HitTestBehavior.translucent,
            onTapDown: enabled ? (_) => setState(() => _pressed = true) : null,
            onTapCancel: enabled ? () => setState(() => _pressed = false) : null,
            onTapUp: enabled ? (_) => setState(() => _pressed = false) : null,
            child: Stack(
              children: [
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: enabled
                        ? () async {
                      HapticFeedback.lightImpact();
                      await widget.onPressed!.call();
                    }
                        : null,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: tokens.chipColor.withOpacity(.96),
                      foregroundColor: tokens.textPrimary,
                      disabledBackgroundColor: tokens.glassColor.withOpacity(.62),
                      disabledForegroundColor: tokens.textSecondary.withOpacity(.62),
                      padding: const EdgeInsets.symmetric(vertical: 16),
                      elevation: 0,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14),
                        side: BorderSide(
                          color: tokens.primaryButtonGradient.first.withOpacity(.3),
                          width: 1,
                        ),
                      ),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Container(
                          width: 22,
                          height: 22,
                          decoration: const BoxDecoration(
                            shape: BoxShape.circle,
                            gradient: SweepGradient(
                              colors: [Colors.red, Colors.yellow, Colors.green, Colors.blue, Colors.red],
                            ),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Text(
                          widget.loading ? 'Signing in...' : 'Continue with Google',
                          style: TextStyle(
                            fontWeight: FontWeight.w700,
                            fontSize: 16,
                            color: tokens.textPrimary,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                if (!widget.loading)
                  Positioned.fill(
                    child: IgnorePointer(
                      child: Opacity(
                        opacity: .15,
                        child: FractionalTranslation(
                          translation: Offset(dx, 0),
                          child: Container(
                            decoration: BoxDecoration(
                              gradient: const LinearGradient(
                                begin: Alignment.centerLeft,
                                end: Alignment.centerRight,
                                colors: [
                                  Colors.transparent,
                                  kTalkeePrimary,
                                  Colors.transparent,
                                ],
                              ),
                              borderRadius: BorderRadius.circular(14),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }
}
