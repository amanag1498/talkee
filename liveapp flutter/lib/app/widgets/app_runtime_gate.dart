import 'dart:ui' show ImageFilter;

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

import '../theme/brand.dart';
import 'talkee_logo.dart';
import '../../services/app_settings_service.dart';

const String _kAndroidPackageId = 'com.techybugs.talkee';

class AppRuntimeGate extends StatelessWidget {
  const AppRuntimeGate({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final settings = Get.find<AppSettingsService>();

    return Obx(() {
      if (settings.shouldForceUpgrade) {
        return _BlockingStateScreen(
          icon: Icons.system_update_rounded,
          eyebrow: 'Update required',
          title: 'Update Talkee to continue',
          message: settings.forceUpgradeMessage,
          detailLabel:
              'Installed ${AppSettingsService.appVersionName} (${AppSettingsService.appVersionCode})',
          detailValue:
              'Required ${settings.payload.value?.androidMinVersionName ?? 'latest'} (${settings.payload.value?.androidMinVersionCode ?? '-'})',
          primaryActionLabel: 'Update now',
          onPrimaryAction: _openAndroidStoreListing,
        );
      }

      if (settings.maintenanceModeEnabled) {
        return const _BlockingStateScreen(
          icon: Icons.build_circle_rounded,
          eyebrow: 'Maintenance mode',
          title: 'Talkieo is temporarily unavailable.',
          message:
              'The platform is under maintenance. Please try again shortly.',
          detailLabel: 'Service status',
          detailValue: 'We will be back soon',
        );
      }

      return child;
    });
  }
}

class _BlockingStateScreen extends StatelessWidget {
  const _BlockingStateScreen({
    required this.icon,
    required this.eyebrow,
    required this.title,
    required this.message,
    this.detailLabel,
    this.detailValue,
    this.primaryActionLabel,
    this.onPrimaryAction,
  });

  final IconData icon;
  final String eyebrow;
  final String title;
  final String message;
  final String? detailLabel;
  final String? detailValue;
  final String? primaryActionLabel;
  final Future<void> Function()? onPrimaryAction;

  @override
  Widget build(BuildContext context) {
    final settings = Get.find<AppSettingsService>();
    final tokens = getPremiumThemeTokens(settings.activePremiumThemeVariant);
    final media = MediaQuery.of(context);
    final compact = media.size.height < 700;

    return Material(
      color: tokens.backgroundGradient.first,
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              tokens.backgroundGradient.first,
              tokens.cardGradient.first,
              tokens.backgroundGradient.last,
            ],
          ),
        ),
        child: Stack(
          children: [
            Positioned(
              top: -90,
              right: -70,
              child: _GlowOrb(
                size: 240,
                color: tokens.primaryButtonGradient.first,
              ),
            ),
            Positioned(
              bottom: -120,
              left: -80,
              child: _GlowOrb(
                size: 280,
                color: tokens.primaryButtonGradient.last,
              ),
            ),
            SafeArea(
              child: Center(
                child: SingleChildScrollView(
                  padding: EdgeInsets.symmetric(
                    horizontal: 22,
                    vertical: compact ? 18 : 28,
                  ),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 430),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(34),
                      child: BackdropFilter(
                        filter: ImageFilter.blur(sigmaX: 22, sigmaY: 22),
                        child: Container(
                          padding: EdgeInsets.fromLTRB(
                            22,
                            compact ? 20 : 24,
                            22,
                            22,
                          ),
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                              colors: [
                                tokens.cardGradient.first.withOpacity(.96),
                                tokens.cardGradient.last.withOpacity(.90),
                              ],
                            ),
                            borderRadius: BorderRadius.circular(34),
                            border: Border.all(
                              color: tokens.borderColor.withOpacity(.9),
                            ),
                            boxShadow: [
                              BoxShadow(
                                color: tokens.glowColor.withOpacity(.35),
                                blurRadius: 42,
                                spreadRadius: 2,
                                offset: const Offset(0, 18),
                              ),
                              BoxShadow(
                                color: Colors.black.withOpacity(.35),
                                blurRadius: 28,
                                offset: const Offset(0, 16),
                              ),
                            ],
                          ),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              Row(
                                children: [
                                  TalkeeLogo(
                                    size: compact ? 48 : 54,
                                    showWordmark: false,
                                  ),
                                  const Spacer(),
                                  _StatusPill(
                                    label: eyebrow,
                                    color:
                                        primaryActionLabel == null
                                            ? tokens.primaryButtonGradient.first
                                            : tokens.dangerColor,
                                  ),
                                ],
                              ),
                              SizedBox(height: compact ? 18 : 24),
                              _IconBadge(icon: icon, tokens: tokens),
                              const SizedBox(height: 16),
                              Text(
                                title,
                                textAlign: TextAlign.center,
                                style: TextStyle(
                                  color: tokens.textPrimary,
                                  fontSize: compact ? 27 : 31,
                                  fontWeight: FontWeight.w900,
                                  height: 1.02,
                                  letterSpacing: -.8,
                                ),
                              ),
                              const SizedBox(height: 12),
                              Text(
                                message,
                                textAlign: TextAlign.center,
                                style: TextStyle(
                                  color: tokens.textSecondary.withOpacity(.92),
                                  fontSize: 15,
                                  height: 1.45,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              if (detailLabel != null ||
                                  detailValue != null) ...[
                                const SizedBox(height: 18),
                                _VersionStrip(
                                  label: detailLabel ?? '',
                                  value: detailValue ?? '',
                                  tokens: tokens,
                                ),
                              ],
                              if (primaryActionLabel != null &&
                                  onPrimaryAction != null) ...[
                                const SizedBox(height: 20),
                                _PrimaryUpgradeButton(
                                  label: primaryActionLabel!,
                                  tokens: tokens,
                                  onPressed: () => onPrimaryAction!.call(),
                                ),
                                const SizedBox(height: 12),
                                Text(
                                  'This update is required to keep rooms, calls, wallet and safety features working correctly.',
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    color: tokens.textSecondary.withOpacity(
                                      .68,
                                    ),
                                    fontSize: 12,
                                    height: 1.35,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ],
                          ),
                        ),
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
  }
}

class _GlowOrb extends StatelessWidget {
  const _GlowOrb({required this.size, required this.color});

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(
            colors: [
              color.withOpacity(.42),
              color.withOpacity(.14),
              Colors.transparent,
            ],
          ),
        ),
      ),
    );
  }
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: color.withOpacity(.14),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withOpacity(.42)),
      ),
      child: Text(
        label.toUpperCase(),
        style: TextStyle(
          color: color,
          fontSize: 10,
          fontWeight: FontWeight.w900,
          letterSpacing: .9,
        ),
      ),
    );
  }
}

class _IconBadge extends StatelessWidget {
  const _IconBadge({required this.icon, required this.tokens});

  final IconData icon;
  final PremiumThemeTokens tokens;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Container(
        width: 74,
        height: 74,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(26),
          gradient: LinearGradient(colors: tokens.primaryButtonGradient),
          boxShadow: [
            BoxShadow(
              color: tokens.glowColor.withOpacity(.45),
              blurRadius: 26,
              offset: const Offset(0, 14),
            ),
          ],
        ),
        child: Icon(icon, color: Colors.white, size: 34),
      ),
    );
  }
}

class _VersionStrip extends StatelessWidget {
  const _VersionStrip({
    required this.label,
    required this.value,
    required this.tokens,
  });

  final String label;
  final String value;
  final PremiumThemeTokens tokens;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(.07),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: tokens.borderColor.withOpacity(.72)),
      ),
      child: Row(
        children: [
          Expanded(child: _VersionText(label: 'Current app', value: label)),
          Container(
            width: 1,
            height: 34,
            margin: const EdgeInsets.symmetric(horizontal: 12),
            color: Colors.white.withOpacity(.12),
          ),
          Expanded(child: _VersionText(label: 'Minimum', value: value)),
        ],
      ),
    );
  }
}

class _VersionText extends StatelessWidget {
  const _VersionText({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          label,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            color: Colors.white.withOpacity(.55),
            fontSize: 10,
            fontWeight: FontWeight.w800,
            letterSpacing: .4,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          value,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: const TextStyle(
            color: Colors.white,
            fontSize: 12,
            fontWeight: FontWeight.w900,
          ),
        ),
      ],
    );
  }
}

class _PrimaryUpgradeButton extends StatelessWidget {
  const _PrimaryUpgradeButton({
    required this.label,
    required this.tokens,
    required this.onPressed,
  });

  final String label;
  final PremiumThemeTokens tokens;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        gradient: LinearGradient(colors: tokens.primaryButtonGradient),
        boxShadow: [
          BoxShadow(
            color: tokens.glowColor.withOpacity(.42),
            blurRadius: 24,
            offset: const Offset(0, 12),
          ),
        ],
      ),
      child: FilledButton.icon(
        onPressed: onPressed,
        icon: const Icon(Icons.arrow_outward_rounded, size: 20),
        label: Text(label),
        style: FilledButton.styleFrom(
          backgroundColor: Colors.transparent,
          shadowColor: Colors.transparent,
          foregroundColor: Colors.white,
          padding: const EdgeInsets.symmetric(vertical: 16),
          textStyle: const TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.w900,
            letterSpacing: .1,
          ),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(20),
          ),
        ),
      ),
    );
  }
}

Future<void> _openAndroidStoreListing() async {
  final marketUri = Uri.parse('market://details?id=$_kAndroidPackageId');
  final webUri = Uri.parse(
    'https://play.google.com/store/apps/details?id=$_kAndroidPackageId',
  );

  if (await canLaunchUrl(marketUri)) {
    await launchUrl(marketUri, mode: LaunchMode.externalApplication);
    return;
  }

  await launchUrl(webUri, mode: LaunchMode.externalApplication);
}
