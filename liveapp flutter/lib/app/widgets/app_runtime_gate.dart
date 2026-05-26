import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

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
          title: 'A newer app version is required.',
          message: settings.forceUpgradeMessage,
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
    this.primaryActionLabel,
    this.onPrimaryAction,
  });

  final IconData icon;
  final String eyebrow;
  final String title;
  final String message;
  final String? primaryActionLabel;
  final Future<void> Function()? onPrimaryAction;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFF0E0821), Color(0xFF171133), Color(0xFF221745)],
        ),
      ),
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 460),
            child: Container(
              padding: const EdgeInsets.all(28),
              decoration: BoxDecoration(
                color: Colors.white.withOpacity(.06),
                borderRadius: BorderRadius.circular(30),
                border: Border.all(color: Colors.white.withOpacity(.10)),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 54,
                    height: 54,
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(.08),
                      borderRadius: BorderRadius.circular(18),
                    ),
                    child: Icon(icon, color: Colors.white, size: 28),
                  ),
                  const SizedBox(height: 18),
                  Text(
                    eyebrow.toUpperCase(),
                    style: TextStyle(
                      color: Colors.white.withOpacity(.65),
                      fontSize: 11,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 1.2,
                    ),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    title,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 28,
                      fontWeight: FontWeight.w900,
                      height: 1.05,
                    ),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    message,
                    style: TextStyle(
                      color: Colors.white.withOpacity(.74),
                      fontSize: 15,
                      height: 1.5,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  if (primaryActionLabel != null && onPrimaryAction != null) ...[
                    const SizedBox(height: 22),
                    SizedBox(
                      width: double.infinity,
                      child: FilledButton(
                        onPressed: () {
                          onPrimaryAction!.call();
                        },
                        style: FilledButton.styleFrom(
                          backgroundColor: const Color(0xFFE63E6D),
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 14),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(16),
                          ),
                        ),
                        child: Text(
                          primaryActionLabel!,
                          style: const TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
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
