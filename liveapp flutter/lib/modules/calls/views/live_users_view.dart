import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/foundation.dart';
import 'package:get/get.dart';

import '../../../app/theme/brand.dart';
import '../../../app/utils/profile_frame_payload.dart';
import '../../../app/widgets/framed_avatar.dart';
import '../../../services/app_settings_service.dart';
import '../../profile/widgets/public_profile_card_sheet.dart';
import '../controllers/live_users_controller.dart';

class LiveUsersView extends StatefulWidget {
  const LiveUsersView({super.key});

  @override
  State<LiveUsersView> createState() => _LiveUsersViewState();
}

class _LiveUsersViewState extends State<LiveUsersView> {
  late final LiveUsersController controller;
  late final ScrollController _scrollController;

  @override
  void initState() {
    super.initState();
    controller = Get.find<LiveUsersController>();
    _scrollController = ScrollController()
      ..addListener(() {
        if (!_scrollController.hasClients) return;
        if (_scrollController.position.pixels >=
            _scrollController.position.maxScrollExtent - 280) {
          controller.loadMore();
        }
      });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      controller.activateDirectory();
    });
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return _LiveUsersScreen(scrollController: _scrollController);
  }
}

class _LiveUsersScreen extends GetView<LiveUsersController> {
  const _LiveUsersScreen({required this.scrollController});

  final ScrollController scrollController;

  PremiumThemeTokens _tokens() {
    final settings = Get.find<AppSettingsService>();
    return getPremiumThemeTokens(settings.activePremiumThemeVariant);
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).padding.bottom;
    final listBottomPadding = bottomInset + 92;
    final appSettings = Get.find<AppSettingsService>();
    final tokens = _tokens();

    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      body: Stack(
        children: [
          _CallsBackground(tokens: tokens),
          if (!appSettings.hostCallingEnabled)
            ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: EdgeInsets.fromLTRB(24, 24, 24, listBottomPadding),
              children: const [
                SizedBox(height: 32),
                _GlassPanel(
                  padding: EdgeInsets.all(24),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(
                        Icons.phone_disabled_rounded,
                        color: Colors.white,
                        size: 34,
                      ),
                      SizedBox(height: 14),
                      Text(
                        'Host calling is unavailable',
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: 20,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      SizedBox(height: 8),
                      Text(
                        'This feature is currently disabled by the platform configuration.',
                        style: TextStyle(color: Colors.white70, height: 1.4),
                      ),
                    ],
                  ),
                ),
              ],
            )
          else
            RefreshIndicator(
              onRefresh: controller.fetch,
              color: tokens.primaryButtonGradient.first,
              backgroundColor: tokens.cardGradient.first,
              child: Obx(() {
                final users = controller.users.toList();

                if (controller.loading.value && users.isEmpty) {
                  return ListView(
                    controller: scrollController,
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: EdgeInsets.fromLTRB(16, 12, 16, listBottomPadding),
                    children: const [
                      _HostCardSkeleton(),
                      SizedBox(height: 14),
                      _HostCardSkeleton(),
                      SizedBox(height: 14),
                      _HostCardSkeleton(),
                    ],
                  );
                }

                if (controller.errorMessage.value != null && users.isEmpty) {
                  return ListView(
                    controller: scrollController,
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: EdgeInsets.fromLTRB(24, 12, 24, listBottomPadding),
                    children: [
                      const SizedBox(height: 40),
                      _GlassPanel(
                        padding: const EdgeInsets.all(24),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Icon(
                              Icons.error_outline_rounded,
                              color: tokens.dangerColor,
                              size: 34,
                            ),
                            const SizedBox(height: 14),
                            const Text(
                              'Could not load live hosts',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 20,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(height: 8),
                            Text(
                              controller.errorMessage.value ?? 'Unknown error',
                              style: TextStyle(
                                color: Colors.white.withValues(alpha: .72),
                                height: 1.4,
                              ),
                            ),
                            const SizedBox(height: 20),
                            FilledButton(
                              style: FilledButton.styleFrom(
                                backgroundColor:
                                    tokens.primaryButtonGradient.first,
                                foregroundColor: tokens.textPrimary,
                              ),
                              onPressed: controller.fetch,
                              child: const Text('Retry'),
                            ),
                          ],
                        ),
                      ),
                    ],
                  );
                }

                return ListView(
                  controller: scrollController,
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: EdgeInsets.fromLTRB(16, 12, 16, listBottomPadding),
                  children: [
                    if (users.isNotEmpty) ...[
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: Text(
                          'Showing ${users.length} of ${controller.totalUsers.value}',
                          style: TextStyle(
                            color: Colors.white.withValues(alpha: .62),
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ],
                    if (users.isEmpty)
                      _GlassPanel(
                        padding: const EdgeInsets.all(22),
                        child: Column(
                          children: [
                            Container(
                              width: 58,
                              height: 58,
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                color: Colors.white.withValues(alpha: .08),
                              ),
                              child: Icon(
                                Icons.radar_rounded,
                                color: tokens.primaryButtonGradient.first,
                                size: 30,
                              ),
                            ),
                            const SizedBox(height: 16),
                            const Text(
                              'No live users right now',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 18,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(height: 8),
                            Text(
                              'Pull down to refresh. Hosts will appear here when they come online.',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: Colors.white.withValues(alpha: .68),
                                height: 1.5,
                              ),
                            ),
                          ],
                        ),
                      )
                    else
                      ...List.generate(users.length, (index) {
                        final user = users[index];
                        return Padding(
                          padding: EdgeInsets.only(
                            bottom: index == users.length - 1 ? 0 : 14,
                          ),
                          child: TweenAnimationBuilder<double>(
                            tween: Tween(begin: 0, end: 1),
                            duration: Duration(
                              milliseconds: 420 + (index * 70),
                            ),
                            curve: Curves.easeOutCubic,
                            builder: (context, value, child) {
                              return Opacity(
                                opacity: value,
                                child: Transform.translate(
                                  offset: Offset(0, 28 * (1 - value)),
                                  child: child,
                                ),
                              );
                            },
                            child: _LiveHostCard(
                              user: user,
                              controller: controller,
                              onAudioTap:
                                  () => _showCallPrompt(context, user, 'audio'),
                              onVideoTap:
                                  () => _showCallPrompt(context, user, 'video'),
                            ),
                          ),
                        );
                      }),
                    if (users.isNotEmpty && controller.loadingMore.value)
                      const Padding(
                        padding: EdgeInsets.only(top: 14),
                        child: Center(
                          child: CircularProgressIndicator(strokeWidth: 2.6),
                        ),
                      ),
                    if (users.isNotEmpty &&
                        !controller.hasMore.value &&
                        !controller.loadingMore.value)
                      Padding(
                        padding: const EdgeInsets.only(top: 14),
                        child: Center(
                          child: Text(
                            'All live hosts loaded',
                            style: TextStyle(
                              color: Colors.white.withValues(alpha: .56),
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ),
                  ],
                );
              }),
            ),
        ],
      ),
    );
  }

  Future<void> _showCallPrompt(
    BuildContext context,
    Map<String, dynamic> user,
    String type,
  ) async {
    if (Get.isBottomSheetOpen ?? false) {
      return;
    }
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final title = type == 'video' ? 'Video Call' : 'Audio Call';
    final rate = controller.rateFor(user, type);
    final requiredBalance = controller.requiredBalanceFor(user, type);
    final canStart = controller.canStartCall(user, type);
    final hostName = (user['name'] ?? 'Host').toString();
    var submitting = false;

    await Get.bottomSheet<void>(
      StatefulBuilder(
        builder: (context, setState) {
          Future<void> submit() async {
            if (submitting || !canStart) {
              return;
            }
            setState(() => submitting = true);
            Get.back<void>();
            await controller.startCall(user, type);
          }

          return _CallPromptSheet(
            hostName: hostName,
            title: title,
            rate: rate,
            viewerBalance: controller.viewerBalance.value,
            minimumBalance: controller.minimumBalance.value,
            requiredBalance: requiredBalance,
            canStart: canStart,
            disabledMessage: controller.unavailableMessage(user, type),
            submitting: submitting,
            confirmLabel:
                type == 'video' ? 'Start Video Call' : 'Start Audio Call',
            accent:
                type == 'video'
                    ? tokens.primaryButtonGradient.last
                    : tokens.primaryButtonGradient.first,
            icon: type == 'video' ? Icons.videocam_rounded : Icons.call_rounded,
            onConfirm: submit,
          );
        },
      ),
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
    );
  }
}

class _LiveHostCard extends StatelessWidget {
  const _LiveHostCard({
    required this.user,
    required this.controller,
    required this.onAudioTap,
    required this.onVideoTap,
  });

  final Map<String, dynamic> user;
  final LiveUsersController controller;
  final VoidCallback onAudioTap;
  final VoidCallback onVideoTap;

  Future<void> _openProfileCard(BuildContext context) async {
    final userId = (user['id'] as num?)?.toInt();
    if (userId == null || userId <= 0) return;
    await showPublicProfileCardSheet(
      context,
      userId: userId,
      initialName: (user['name'] ?? 'Host').toString(),
      initialSubtitle:
          user['stage_name']?.toString().trim().isNotEmpty == true
              ? user['stage_name'].toString().trim()
              : controller.availabilityLabel(user),
      initialThemeKey:
          user['active_theme_key']?.toString().trim().isNotEmpty == true
              ? user['active_theme_key'].toString().trim()
              : null,
      initialIsVip: user['is_vip'] == true,
      initialIsHost: true,
      initialAvatarUrl:
          (user['avatar_url'] ?? '').toString().trim().isNotEmpty
              ? (user['avatar_url'] ?? '').toString().trim()
              : null,
      initialProfileFrameUrl: profileFrameAssetUrlFromPayload(user),
    );
  }

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final avatarUrl = (user['avatar_url'] ?? '').toString();
    final frameUrl = profileFrameAssetUrlFromPayload(user);
    debugPrint(
      '[live-users][render] user=${user['id']} avatar=$avatarUrl frame=$frameUrl',
    );
    final agency = Map<String, dynamic>.from(
      user['agency'] as Map? ?? const {},
    );
    final agencyName = (agency['name'] ?? '').toString().trim();
    final availability = Map<String, dynamic>.from(
      user['availability'] as Map? ?? const {},
    );
    final online = availability['is_online'] == true;
    final available = availability['is_available'] == true;
    final subtitle = controller.availabilityLabel(user);
    final audioEnabled = controller.canStartCall(user, 'audio');
    final videoEnabled = controller.canStartCall(user, 'video');

    return LayoutBuilder(
      builder: (context, constraints) {
        final compact = constraints.maxWidth < 360;

        return _GlassPanel(
          padding: EdgeInsets.all(compact ? 12 : 14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  InkWell(
                    onTap: () => _openProfileCard(context),
                    borderRadius: BorderRadius.circular(999),
                    child: Padding(
                      padding: const EdgeInsets.all(2),
                      child: FramedAvatar(
                        avatarUrl: avatarUrl,
                        frameUrl: frameUrl,
                        size: compact ? 54 : 60,
                        avatarInset: 0.07,
                        frameScale: 1.28,
                        label: (user['name'] ?? '?').toString(),
                      ),
                    ),
                  ),
                  SizedBox(width: compact ? 10 : 12),
                  Expanded(
                    child: InkWell(
                      onTap: () => _openProfileCard(context),
                      borderRadius: BorderRadius.circular(14),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 4),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              (user['name'] ?? 'Host').toString(),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: compact ? 15 : 17,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              agencyName.isNotEmpty ? agencyName : subtitle,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                color: Colors.white.withValues(alpha: .62),
                                fontSize: compact ? 11 : 12,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      _StatusChip(
                        label: subtitle,
                        background:
                            !online
                                ? tokens.dangerColor.withValues(alpha: .16)
                                : available
                                ? tokens.primaryButtonGradient.first.withValues(
                                  alpha: .18,
                                )
                                : kTalkeeGold.withValues(alpha: .16),
                        foreground:
                            !online
                                ? tokens.dangerColor
                                : available
                                ? tokens.primaryButtonGradient.first
                                : const Color(0xFFFFD166),
                      ),
                    ],
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: _QuickCallButton(
                      icon: Icons.call_rounded,
                      label: 'Audio',
                      enabled: audioEnabled,
                      accent: tokens.primaryButtonGradient.first,
                      onTap: onAudioTap,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _QuickCallButton(
                      icon: Icons.videocam_rounded,
                      label: 'Video',
                      enabled: videoEnabled,
                      accent: tokens.primaryButtonGradient.last,
                      onTap: onVideoTap,
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }
}

class _QuickCallButton extends StatelessWidget {
  const _QuickCallButton({
    required this.icon,
    required this.label,
    required this.enabled,
    required this.accent,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final bool enabled;
  final Color accent;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return InkWell(
      onTap: enabled ? onTap : null,
      borderRadius: BorderRadius.circular(16),
      child: Ink(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          gradient: LinearGradient(
            colors:
                enabled
                    ? [
                      accent.withValues(alpha: .22),
                      tokens.cardGradient.last.withValues(alpha: .34),
                    ]
                    : [
                      tokens.glassColor.withValues(alpha: .10),
                      tokens.cardGradient.last.withValues(alpha: .18),
                    ],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          border: Border.all(
            color:
                enabled
                    ? accent.withValues(alpha: .36)
                    : tokens.borderColor.withValues(alpha: .20),
          ),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              icon,
              size: 16,
              color: enabled ? accent : Colors.white.withValues(alpha: .36),
            ),
            const SizedBox(width: 8),
            Text(
              label,
              style: TextStyle(
                color:
                    enabled
                        ? Colors.white
                        : Colors.white.withValues(alpha: .42),
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CallPromptSheet extends StatelessWidget {
  const _CallPromptSheet({
    required this.hostName,
    required this.title,
    required this.rate,
    required this.viewerBalance,
    required this.minimumBalance,
    required this.requiredBalance,
    required this.canStart,
    required this.disabledMessage,
    required this.submitting,
    required this.confirmLabel,
    required this.accent,
    required this.icon,
    required this.onConfirm,
  });

  final String hostName;
  final String title;
  final int rate;
  final int viewerBalance;
  final int minimumBalance;
  final int requiredBalance;
  final bool canStart;
  final String disabledMessage;
  final bool submitting;
  final String confirmLabel;
  final Color accent;
  final IconData icon;
  final VoidCallback onConfirm;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: Container(
        decoration: BoxDecoration(
          color: tokens.cardGradient.first.withValues(alpha: .98),
          borderRadius: const BorderRadius.vertical(top: Radius.circular(30)),
        ),
        padding: const EdgeInsets.fromLTRB(20, 14, 20, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                width: 42,
                height: 4,
                decoration: BoxDecoration(
                  color: tokens.borderColor.withValues(alpha: .42),
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
            ),
            const SizedBox(height: 18),
            Row(
              children: [
                Container(
                  width: 48,
                  height: 48,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(16),
                    color: accent.withValues(alpha: .16),
                  ),
                  child: Icon(icon, color: accent),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        style: TextStyle(
                          color: tokens.textPrimary,
                          fontSize: 20,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Calling $hostName',
                        style: TextStyle(
                          color: tokens.textSecondary.withValues(alpha: .82),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 18),
            Wrap(
              spacing: 10,
              runSpacing: 10,
              children: [
                _MetricChip(
                  icon: icon,
                  label: 'Rate',
                  value: '$rate coins/min',
                ),
                _MetricChip(
                  icon: Icons.account_balance_wallet_rounded,
                  label: 'Balance',
                  value: '$viewerBalance',
                ),
              ],
            ),
            const SizedBox(height: 16),
            Text(
              'By continuing, you agree that coins will be deducted from your wallet based on this call rate while the session is active.',
              style: TextStyle(
                color: tokens.textSecondary.withValues(alpha: .86),
                height: 1.45,
              ),
            ),
            if (!canStart) ...[
              const SizedBox(height: 14),
              Text(
                disabledMessage,
                style: TextStyle(
                  color: tokens.dangerColor,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
            const SizedBox(height: 20),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    style: OutlinedButton.styleFrom(
                      foregroundColor: tokens.textPrimary,
                      side: BorderSide(
                        color: tokens.borderColor.withValues(alpha: .54),
                      ),
                      padding: const EdgeInsets.symmetric(vertical: 14),
                    ),
                    onPressed: () => Get.back<void>(),
                    child: const Text('Cancel'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: accent,
                      foregroundColor: tokens.textPrimary,
                      disabledBackgroundColor: tokens.glassColor.withValues(
                        alpha: .55,
                      ),
                      disabledForegroundColor: tokens.textSecondary.withValues(
                        alpha: .58,
                      ),
                      padding: const EdgeInsets.symmetric(vertical: 14),
                    ),
                    onPressed: canStart && !submitting ? onConfirm : null,
                    child:
                        submitting
                            ? SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: tokens.textPrimary,
                              ),
                            )
                            : Text(confirmLabel),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _MetricChip extends StatelessWidget {
  const _MetricChip({
    required this.icon,
    required this.label,
    required this.value,
  });

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: tokens.chipColor.withValues(alpha: .74),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: tokens.borderColor.withValues(alpha: .28)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 16, color: tokens.primaryButtonGradient.first),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                label,
                style: TextStyle(
                  color: tokens.textSecondary.withValues(alpha: .82),
                  fontSize: 11,
                ),
              ),
              const SizedBox(height: 2),
              Text(
                value,
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({
    required this.label,
    required this.background,
    required this.foreground,
  });

  final String label;
  final Color background;
  final Color foreground;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: foreground,
          fontSize: 12,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

class _GlassPanel extends StatelessWidget {
  const _GlassPanel({
    required this.child,
    this.padding = EdgeInsets.zero,
    this.gradient,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final Gradient? gradient;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return ClipRRect(
      borderRadius: BorderRadius.circular(26),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: Container(
          padding: padding,
          decoration: BoxDecoration(
            gradient:
                gradient ??
                LinearGradient(
                  colors: [
                    tokens.cardGradient.first.withValues(alpha: .92),
                    tokens.cardGradient.last.withValues(alpha: .94),
                  ],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
            borderRadius: BorderRadius.circular(26),
            border: Border.all(
              color: tokens.borderColor.withValues(alpha: .34),
            ),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: .24),
                blurRadius: 32,
                offset: const Offset(0, 20),
              ),
            ],
          ),
          child: child,
        ),
      ),
    );
  }
}

class _CallsBackground extends StatelessWidget {
  const _CallsBackground({required this.tokens});

  final PremiumThemeTokens tokens;

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                ...tokens.backgroundGradient,
                tokens.glowColor.withValues(alpha: .18),
              ],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
          ),
        ),
        Positioned(
          top: -90,
          right: -70,
          child: _GlowBlob(
            size: 250,
            colors: [
              tokens.primaryButtonGradient.first.withValues(alpha: .26),
              Colors.transparent,
            ],
          ),
        ),
        Positioned(
          top: 220,
          left: -90,
          child: _GlowBlob(
            size: 220,
            colors: [
              tokens.glowColor.withValues(alpha: .16),
              Colors.transparent,
            ],
          ),
        ),
      ],
    );
  }
}

class _GlowBlob extends StatelessWidget {
  const _GlowBlob({required this.size, required this.colors});

  final double size;
  final List<Color> colors;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(colors: colors),
        ),
      ),
    );
  }
}

class _HostCardSkeleton extends StatelessWidget {
  const _HostCardSkeleton();

  @override
  Widget build(BuildContext context) {
    return const _GlassPanel(
      padding: EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              _SkeletonBlock(width: 56, height: 56, circular: true),
              SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _SkeletonBlock(width: 160, height: 16),
                    SizedBox(height: 10),
                    _SkeletonBlock(width: 210, height: 12),
                  ],
                ),
              ),
            ],
          ),
          SizedBox(height: 18),
          Row(
            children: [
              Expanded(child: _SkeletonBlock(height: 42)),
              SizedBox(width: 12),
              Expanded(child: _SkeletonBlock(height: 42)),
            ],
          ),
        ],
      ),
    );
  }
}

class _SkeletonBlock extends StatelessWidget {
  const _SkeletonBlock({this.width, this.height = 14, this.circular = false});

  final double? width;
  final double height;
  final bool circular;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .08),
        borderRadius:
            circular
                ? BorderRadius.circular(height)
                : BorderRadius.circular(14),
      ),
    );
  }
}
