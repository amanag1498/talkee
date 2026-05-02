// lib/modules/notifications/widgets/bell_badge_button.dart
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';
import '../controllers/notification_controller.dart';
import '../../../app/routes/app_routes.dart';

class BellBadgeButton extends StatefulWidget {
  const BellBadgeButton({super.key});

  @override
  State<BellBadgeButton> createState() => _BellBadgeButtonState();
}

class _BellBadgeButtonState extends State<BellBadgeButton> with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 900))
      ..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = Get.isRegistered<NotificationsController>()
        ? Get.find<NotificationsController>()
        : Get.put(NotificationsController(Get.find()));

    return Obx(() {
      final count = c.unreadCount.value;
      final tokens = getPremiumThemeTokens(
        Get.find<AppSettingsService>().activePremiumThemeVariant,
      );
      return IconButton(
        onPressed: () => Get.toNamed(Routes.notifications),
        icon: Stack(
          clipBehavior: Clip.none,
          children: [
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: tokens.glassColor.withOpacity(.9),
                shape: BoxShape.circle,
                border: Border.all(color: tokens.borderColor.withOpacity(.8)),
              ),
              child: Icon(
                Icons.notifications_rounded,
                color: tokens.textPrimary,
              ),
            ),
            if (count > 0)
              Positioned(
                right: -2,
                top: -2,
                child: AnimatedBuilder(
                  animation: _pulse,
                  builder: (_, child) {
                    // pulse between 1.0 and 1.12 scale
                    final s = 1.0 + (0.12 * _pulse.value);
                    return Transform.scale(scale: s, child: child);
                  },
                  child: _Badge(count: count),
                ),
              ),
          ],
        ),
      );
    });
  }
}

class _Badge extends StatelessWidget {
  final int count;
  const _Badge({required this.count});

  @override
  Widget build(BuildContext context) {
    final text = count > 99 ? '99+' : (count > 9 ? '9+' : '$count');
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 220),
      transitionBuilder: (child, anim) =>
          ScaleTransition(scale: Tween<double>(begin: .85, end: 1).animate(anim), child: child),
      child: Container(
        key: ValueKey(text),
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
        decoration: BoxDecoration(
          color: tokens.dangerColor,
          shape: BoxShape.rectangle,
          borderRadius: const BorderRadius.all(Radius.circular(10)),
        ),
        constraints: const BoxConstraints(minWidth: 18, minHeight: 18),
        child: Text(text,
            style: const TextStyle(fontSize: 10, color: Colors.white, fontWeight: FontWeight.w700),
            textAlign: TextAlign.center),
      ),
    );
  }
}
