import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../theme/brand.dart';
import '../../services/app_settings_service.dart';

class GlassCard extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;
  const GlassCard({super.key, required this.child, this.padding = const EdgeInsets.all(20)});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return AnimatedContainer(
      duration: const Duration(milliseconds: 450),
      curve: Curves.easeOut,
      padding: padding,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: tokens.cardGradient,
        ),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: tokens.borderColor),
        boxShadow: [
          BoxShadow(
            color: tokens.glowColor.withOpacity(.22),
            blurRadius: 30,
            spreadRadius: 4,
            offset: const Offset(0, 12),
          ),
        ],
      ),
      child: child,
    );
  }
}
