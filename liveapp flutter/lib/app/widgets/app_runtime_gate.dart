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
          title: 'Update Talkieo to continue',
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
          icon: Icons.engineering_rounded,
          eyebrow: 'Maintenance in progress',
          title: 'We are tuning Talkieo right now',
          message:
              'Rooms, calls and wallet actions are paused while we finish a platform update. Please check back shortly.',
          detailLabel: 'Current status',
          detailValue: 'Service paused temporarily',
          footerMessage:
              'Your wallet balance, subscriptions and host earnings are safe during maintenance.',
          maintenanceMode: true,
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
    this.footerMessage,
    this.maintenanceMode = false,
  });

  final IconData icon;
  final String eyebrow;
  final String title;
  final String message;
  final String? detailLabel;
  final String? detailValue;
  final String? primaryActionLabel;
  final Future<void> Function()? onPrimaryAction;
  final String? footerMessage;
  final bool maintenanceMode;

  @override
  Widget build(BuildContext context) {
    final settings = Get.find<AppSettingsService>();
    final tokens = getPremiumThemeTokens(settings.activePremiumThemeVariant);
    final media = MediaQuery.of(context);
    final compact = media.size.height < 700;
    final accent = maintenanceMode
        ? tokens.successColor
        : primaryActionLabel == null
            ? tokens.primaryButtonGradient.first
            : tokens.dangerColor;

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
              top: maintenanceMode ? -60 : -90,
              right: maintenanceMode ? -110 : -70,
              child: _GlowOrb(
                size: maintenanceMode ? 300 : 240,
                color: accent,
              ),
            ),
            Positioned(
              bottom: -120,
              left: -80,
              child: _GlowOrb(
                size: 280,
                color: maintenanceMode
                    ? tokens.primaryButtonGradient.first
                    : tokens.primaryButtonGradient.last,
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
                                    color: accent,
                                  ),
                                ],
                              ),
                              SizedBox(height: compact ? 18 : 24),
                              _IconBadge(
                                icon: icon,
                                tokens: tokens,
                                accent: accent,
                                maintenanceMode: maintenanceMode,
                              ),
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
                                  leadingLabel: maintenanceMode
                                      ? 'Mode'
                                      : 'Current app',
                                  trailingLabel: maintenanceMode
                                      ? 'Status'
                                      : 'Minimum',
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
                              if (footerMessage != null &&
                                  footerMessage!.trim().isNotEmpty) ...[
                                const SizedBox(height: 18),
                                _MaintenanceNote(
                                  message: footerMessage!,
                                  tokens: tokens,
                                  accent: accent,
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
  const _IconBadge({
    required this.icon,
    required this.tokens,
    required this.accent,
    required this.maintenanceMode,
  });

  final IconData icon;
  final PremiumThemeTokens tokens;
  final Color accent;
  final bool maintenanceMode;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Container(
        width: maintenanceMode ? 82 : 74,
        height: maintenanceMode ? 82 : 74,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(maintenanceMode ? 30 : 26),
          gradient: maintenanceMode
              ? LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [
                    accent.withOpacity(.95),
                    tokens.primaryButtonGradient.first.withOpacity(.92),
                  ],
                )
              : LinearGradient(colors: tokens.primaryButtonGradient),
          border: maintenanceMode
              ? Border.all(color: Colors.white.withOpacity(.18))
              : null,
          boxShadow: [
            BoxShadow(
              color: accent.withOpacity(.38),
              blurRadius: maintenanceMode ? 34 : 26,
              offset: const Offset(0, 14),
            ),
          ],
        ),
        child: Stack(
          alignment: Alignment.center,
          children: [
            if (maintenanceMode)
              Positioned.fill(
                child: Padding(
                  padding: const EdgeInsets.all(11),
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(22),
                      border: Border.all(color: Colors.white.withOpacity(.16)),
                    ),
                  ),
                ),
              ),
            Icon(icon, color: Colors.white, size: maintenanceMode ? 38 : 34),
          ],
        ),
      ),
    );
  }
}

class _VersionStrip extends StatelessWidget {
  const _VersionStrip({
    required this.label,
    required this.value,
    required this.tokens,
    required this.leadingLabel,
    required this.trailingLabel,
  });

  final String label;
  final String value;
  final PremiumThemeTokens tokens;
  final String leadingLabel;
  final String trailingLabel;

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
          Expanded(child: _VersionText(label: leadingLabel, value: label)),
          Container(
            width: 1,
            height: 34,
            margin: const EdgeInsets.symmetric(horizontal: 12),
            color: Colors.white.withOpacity(.12),
          ),
          Expanded(child: _VersionText(label: trailingLabel, value: value)),
        ],
      ),
    );
  }
}

class _MaintenanceNote extends StatelessWidget {
  const _MaintenanceNote({
    required this.message,
    required this.tokens,
    required this.accent,
  });

  final String message;
  final PremiumThemeTokens tokens;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(
        color: accent.withOpacity(.10),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: accent.withOpacity(.26)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.verified_user_rounded, color: accent, size: 18),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: TextStyle(
                color: tokens.textSecondary.withOpacity(.88),
                fontSize: 12.5,
                height: 1.35,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
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
