import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/theme/brand.dart'; // talkeeDarkTheme(), kTalkeePrimary, etc.
import '../../../app/widgets/animated_background.dart';
import '../../../app/widgets/entrance_fader.dart';
import '../../../app/widgets/equalizer_bars.dart';
import '../../../app/widgets/glass_card.dart';
import '../../../app/widgets/google_button.dart';
import '../../../app/widgets/shake.dart';
import '../../../app/widgets/talkee_logo.dart';
import '../../../services/app_settings_service.dart';
import '../controllers/auth_controller.dart';

class LoginView extends GetView<AuthController> {
  const LoginView({super.key});

  @override
  Widget build(BuildContext context) {
    return const _LoginDarkOnly();
  }
}

class _LoginDarkOnly extends GetView<AuthController> {
  const _LoginDarkOnly();

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    final reduceMotion = mq.disableAnimations || mq.accessibleNavigation;
    final bgCtl = AnimatedBackgroundController()
      ..setEnergy(reduceMotion ? 0.0 : 0.34);

    Future<void> openUrl(String url) async {
      final uri = Uri.parse(url);
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }

    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: () => FocusScope.of(context).unfocus(),
      child: Scaffold(
        backgroundColor: Colors.transparent,
        body: Stack(
          fit: StackFit.expand,
          children: [
            // Premium animated background
            if (!reduceMotion)
              AnimatedBackground(
                controller: bgCtl,
                visualScale: 1.25,
                richness: 18,
                reactToPointer: true,
                iconSet: [
                  Icons.mic_rounded,
                  Icons.videocam_rounded,
                  Icons.favorite_rounded,
                  Icons.chat_bubble_rounded,
                  Icons.card_giftcard_rounded,
                  Icons.wifi_tethering_rounded,
                  Icons.music_note_rounded,
                  Icons.send_rounded,
                ],
              )
            else
            // Static dark gradient fallback
              const DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment(-0.8, -1.0),
                    end: Alignment(0.8, 1.0),
                    colors: [
                      Color(0xFF0E0821),
                      Color(0xFF1A1134),
                      Color(0xFF23143F),
                    ],
                    stops: [0.0, 0.55, 1.0],
                  ),
                ),
              ),

            // Top/Bottom cinematic depth overlays
            IgnorePointer(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [
                      Colors.black.withOpacity(.30),
                      Colors.transparent,
                      Colors.black.withOpacity(.44),
                    ],
                    stops: const [0.0, 0.42, 1.0],
                  ),
                ),
              ),
            ),

            SafeArea(
              child: Center(
                child: SingleChildScrollView(
                  padding: EdgeInsets.fromLTRB(
                    24,
                    8,
                    24,
                    16 + mq.viewInsets.bottom,
                  ),
                  keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 480),
                    child: Obx(() {
                      final tokens = getPremiumThemeTokens(
                        Get.find<AppSettingsService>().activePremiumThemeVariant,
                      );
                      final theme = Theme.of(context);
                      final hasError = controller.error.value.isNotEmpty;
                      final loading = controller.loading.value;

                      return EntranceFader(
                        duration: reduceMotion ? Duration.zero : const Duration(milliseconds: 420),
                        child: Shake(
                          trigger: hasError,
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const SizedBox(height: 10),
                              const Hero(
                                tag: 'brand.logo',
                                child: TalkeeLogo(
                                  size: 112,
                                  showWordmark: true,
                                  wordmarkBelow: true,
                                ),
                              ),
                              const SizedBox(height: 14),

                              Text(
                                'Your stage is waiting',
                                textAlign: TextAlign.center,
                                style: theme.textTheme.headlineSmall?.copyWith(
                                  fontWeight: FontWeight.w900,
                                  color: tokens.textPrimary,
                                  letterSpacing: .2,
                                ),
                              ),
                              const SizedBox(height: 6),
                              Text(
                                'Go live, host audio rooms, and build your audience on Talkee.',
                                textAlign: TextAlign.center,
                                style: theme.textTheme.bodyMedium?.copyWith(
                                  color: tokens.textSecondary.withOpacity(.88),
                                  height: 1.35,
                                ),
                              ),

                              const SizedBox(height: 14),
                              Wrap(
                                alignment: WrapAlignment.center,
                                spacing: 8,
                                runSpacing: 8,
                                children: [
                                  _FeaturePill(icon: Icons.rocket_launch_rounded, label: 'Fast Onboarding'),
                                  _FeaturePill(icon: Icons.shield_rounded, label: 'Secure Sign-in'),
                                  _FeaturePill(icon: Icons.graphic_eq_rounded, label: 'Live + Audio Rooms'),
                                ],
                              ),

                              const SizedBox(height: 16),
                              GlassCard(
                                padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 18),
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                if (!reduceMotion) const EqualizerBars(),
                                if (!reduceMotion) const SizedBox(height: 14),

                                if (hasError)
                                  Padding(
                                    padding: const EdgeInsets.only(bottom: 12),
                                    child: Text(
                                      controller.error.value,
                                      textAlign: TextAlign.center,
                                      style: theme.textTheme.bodyMedium?.copyWith(
                                        color: theme.colorScheme.error,
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                  ),

                                GoogleButton(
                                  onPressed: loading ? null : controller.loginWithGoogle,
                                  loading: loading,
                                ),

                                const SizedBox(height: 14),
                                Text(
                                  'One tap with Google. No passwords, no forms.',
                                  textAlign: TextAlign.center,
                                  style: theme.textTheme.bodySmall?.copyWith(
                                    color: tokens.textSecondary.withOpacity(.82),
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),

                                const SizedBox(height: 12),
                                const _GlassDivider(),
                                const SizedBox(height: 12),
                                RichText(
                                  textAlign: TextAlign.center,
                                  text: TextSpan(
                                    style: theme.textTheme.bodySmall?.copyWith(
                                      color: tokens.textSecondary.withOpacity(.76),
                                      height: 1.25,
                                    ),
                                    children: [
                                      const TextSpan(text: 'By continuing you agree to our '),
                                      TextSpan(
                                        text: 'Terms',
                                        style: TextStyle(
                                          decoration: TextDecoration.underline,
                                          fontWeight: FontWeight.w700,
                                          color: tokens.textPrimary,
                                        ),
                                        recognizer: TapGestureRecognizer()
                                          ..onTap = () => openUrl('https://example.com/terms'),
                                      ),
                                      const TextSpan(text: ' & '),
                                      TextSpan(
                                        text: 'Privacy',
                                        style: TextStyle(
                                          decoration: TextDecoration.underline,
                                          fontWeight: FontWeight.w700,
                                          color: tokens.textPrimary,
                                        ),
                                        recognizer: TapGestureRecognizer()
                                          ..onTap = () => openUrl('https://example.com/privacy'),
                                      ),
                                      const TextSpan(text: '.'),
                                    ],
                                  ),
                                ),
                                const SizedBox(height: 10),
                                TextButton(
                                  onPressed: () => openUrl('https://example.com/help'),
                                  style: TextButton.styleFrom(
                                    foregroundColor: tokens.textSecondary.withOpacity(.88),
                                    textStyle: theme.textTheme.labelMedium?.copyWith(
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                  child: const Text('Need help?'),
                                ),
                              ],
                            ),
                              ),
                            ],
                          ),
                        ),
                      );
                    }),
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

class _FeaturePill extends StatelessWidget {
  final IconData icon;
  final String label;
  const _FeaturePill({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: tokens.chipColor.withOpacity(.82),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 14, color: tokens.textPrimary.withOpacity(.92)),
          const SizedBox(width: 6),
          Text(
            label,
            style: Theme.of(context).textTheme.labelSmall?.copyWith(
              color: tokens.textPrimary.withOpacity(.9),
              fontWeight: FontWeight.w700,
              letterSpacing: .2,
            ),
          ),
        ],
      ),
    );
  }
}

class _GlassDivider extends StatelessWidget {
  const _GlassDivider();

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      height: 1,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.centerLeft,
          end: Alignment.centerRight,
          colors: [
            Colors.transparent,
            tokens.borderColor.withOpacity(.72),
            Colors.transparent,
          ],
        ),
      ),
    );
  }
}
