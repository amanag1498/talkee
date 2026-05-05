import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';
import '../controllers/theme_center_controller.dart';
import '../models/theme_access_dto.dart';

PremiumThemeTokens _themeCenterTokens() {
  return getPremiumThemeTokens(
    Get.find<AppSettingsService>().activePremiumThemeVariant,
  );
}

class ThemeCenterPage extends GetView<ThemeCenterController> {
  const ThemeCenterPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      final shellTokens = _themeCenterTokens();
      return Scaffold(
        backgroundColor: shellTokens.backgroundGradient.first,
        appBar: AppBar(
          title: const Text('Theme Center'),
        ),
        body: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: shellTokens.backgroundGradient,
            ),
          ),
          child: Obx(() {
            if (controller.loading.value && controller.catalog.value == null) {
              return Center(
                child: CircularProgressIndicator(
                  color: shellTokens.primaryButtonGradient.first,
                ),
              );
            }

            if (controller.error.value != null && controller.catalog.value == null) {
              return _ThemeCenterState(
                title: 'Unable to load themes',
                message: controller.error.value!,
                buttonLabel: 'Retry',
                onTap: controller.load,
              );
            }

            final catalog = controller.catalog.value;
            if (catalog == null || catalog.themes.isEmpty) {
              return _ThemeCenterState(
                title: 'No themes available',
                message: 'The theme catalog is currently empty.',
                buttonLabel: 'Refresh',
                onTap: controller.load,
              );
            }

            return RefreshIndicator(
              onRefresh: controller.load,
              color: shellTokens.primaryButtonGradient.first,
              child: GridView.builder(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 2,
                  crossAxisSpacing: 14,
                  mainAxisSpacing: 14,
                  childAspectRatio: 0.62,
                ),
                itemCount: catalog.themes.length,
                itemBuilder: (context, index) {
                  final item = catalog.themes[index];
                  return _ThemeCard(
                    item: item,
                    selected: item.key == catalog.activeThemeKey,
                    submitting:
                        controller.submitting.value &&
                        item.key == controller.pendingThemeKey.value,
                    onTap:
                        item.unlocked ? () => controller.selectTheme(item) : null,
                    onPurchase:
                        !item.unlocked && item.unlockType == 'limited_paid'
                            ? () => controller.purchaseTheme(item)
                            : null,
                  );
                },
              ),
            );
          }),
        ),
      );
    });
  }
}

class _ThemeCard extends StatelessWidget {
  const _ThemeCard({
    required this.item,
    required this.selected,
    required this.submitting,
    required this.onTap,
    required this.onPurchase,
  });

  final ThemeAccessItemDto item;
  final bool selected;
  final bool submitting;
  final VoidCallback? onTap;
  final VoidCallback? onPurchase;

  @override
  Widget build(BuildContext context) {
    final preview = resolvePremiumThemeTokens(
      item.key,
      tokenSource: item.tokenSource,
      remoteTokens: item.remoteTokens,
    );
    final shell = _themeCenterTokens();
    final buttonLabel =
        selected
            ? 'Selected'
            : item.unlocked
                ? 'Use Theme'
                : item.unlockType == 'limited_paid'
                    ? ((item.price ?? 0) > 0
                        ? 'Buy ${_formatPrice(item.price!)}'
                        : 'Claim')
                    : 'Locked';

    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: preview.cardGradient,
        ),
        border: Border.all(
          color:
              selected
                  ? preview.primaryButtonGradient.first
                  : preview.borderColor,
        ),
        boxShadow: [
          BoxShadow(
            color: preview.glowColor,
            blurRadius: 22,
            offset: const Offset(0, 12),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    item.name,
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      color: preview.textPrimary,
                      fontWeight: FontWeight.w800,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                if (item.tokenSource != 'local') ...[
                  const SizedBox(width: 6),
                  _Badge(label: item.tokenSource.toUpperCase(), color: preview.borderColor),
                ],
                if (item.isLimited)
                  _Badge(label: 'Limited', color: preview.primaryButtonGradient.last),
              ],
            ),
            const SizedBox(height: 10),
            Expanded(
              child: Container(
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(18),
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: preview.backgroundGradient,
                  ),
                ),
                child: Stack(
                  children: [
                    Positioned(
                      top: -12,
                      right: -10,
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: preview.primaryButtonGradient.first.withValues(alpha: .24),
                        ),
                        child: const SizedBox(width: 72, height: 72),
                      ),
                    ),
                    Positioned.fill(
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Stack(
                          children: [
                            Positioned(
                              top: 0,
                              left: 0,
                              right: 0,
                              child: Text(
                                item.key,
                                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                                  color: preview.textSecondary,
                                ),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                            Positioned(
                              left: 0,
                              bottom: 0,
                              child: _Badge(
                                label: selected ? 'Active' : item.unlockType,
                                color:
                                    selected
                                        ? preview.primaryButtonGradient.first
                                        : preview.chipColor,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              item.description ?? item.lockedReason ?? 'Premium theme',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: preview.textSecondary,
                height: 1.3,
              ),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
            const SizedBox(height: 10),
            if (!item.unlocked && item.lockedReason != null)
              Text(
                item.lockedReason!,
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: preview.textSecondary,
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            if (item.expiresAt != null) ...[
              const SizedBox(height: 8),
              Text(
                'Expires ${_formatDate(item.expiresAt!)}',
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                  color: preview.textSecondary,
                ),
              ),
            ],
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: submitting
                    ? null
                    : item.unlocked
                        ? (!selected ? onTap : null)
                        : onPurchase,
                style: ElevatedButton.styleFrom(
                  backgroundColor: preview.primaryButtonGradient.first,
                  foregroundColor: preview.textPrimary,
                  disabledBackgroundColor: shell.chipColor,
                  disabledForegroundColor: shell.textSecondary,
                ),
                child:
                    submitting
                        ? SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: preview.textPrimary,
                          ),
                        )
                        : Text(buttonLabel),
              ),
            ),
          ],
        ),
      ),
    );
  }

  String _formatDate(DateTime value) {
    final local = value.toLocal();
    final month = local.month.toString().padLeft(2, '0');
    final day = local.day.toString().padLeft(2, '0');
    return '${local.year}-$month-$day';
  }

  String _formatPrice(double value) {
    if (value == value.roundToDouble()) {
      return '${value.toInt()} coins';
    }

    return '${value.toStringAsFixed(2)} coins';
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .18),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withValues(alpha: .36)),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
          color: Colors.white,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

class _ThemeCenterState extends StatelessWidget {
  const _ThemeCenterState({
    required this.title,
    required this.message,
    required this.buttonLabel,
    required this.onTap,
  });

  final String title;
  final String message;
  final String buttonLabel;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final tokens = _themeCenterTokens();
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              title,
              style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                color: tokens.textPrimary,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              message,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                color: tokens.textSecondary,
              ),
            ),
            const SizedBox(height: 16),
            ElevatedButton(onPressed: onTap, child: Text(buttonLabel)),
          ],
        ),
      ),
    );
  }
}
