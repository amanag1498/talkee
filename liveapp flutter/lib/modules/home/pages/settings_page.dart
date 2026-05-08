import 'dart:async';
import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../banners/models/banner_item.dart';
import '../../banners/services/banner_service.dart';
import '../../../app/routes/app_urls.dart';
import '../../../app/routes/app_routes.dart';
import '../../../app/widgets/framed_avatar.dart';
import '../../../app/widgets/haptics.dart';
import '../../../services/auth_service.dart';
import '../../../services/app_settings_service.dart';
import '../../../app/theme/brand.dart';
import '../../../services/api_client.dart';
import '../../../app/utils/avatar_url.dart';
import '../../profile/controllers/profile_controller.dart';
import '../../entry_packs/models/user_entry_pack_dto.dart';
import '../../entry_packs/services/entry_pack_api.dart';
import '../../subscriptions/models/user_subscription_dto.dart';
import '../../subscriptions/services/subscriptions_api.dart';
import '../../entry_packs/widgets/entry_pack_bottom_sheet.dart';
import '../../wallet/models/wallet_summary_dto.dart';
import '../../wallet/services/wallet_api.dart';
import '../../wallet/widgets/recharge_bottom_sheet.dart';

PremiumThemeTokens _settingsTokens() {
  return getPremiumThemeTokens(
    Get.find<AppSettingsService>().activePremiumThemeVariant,
  );
}

class SettingsPage extends StatefulWidget {
  final double bottomPadding;
  const SettingsPage({super.key, required this.bottomPadding});

  @override
  State<SettingsPage> createState() => _SettingsPageState();
}

class _SettingsPageState extends State<SettingsPage>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  late final AnimationController _bgMotion;
  late final ProfileController _profileController;
  late final SubscriptionsApi _subscriptionsApi;
  late final EntryPackApi _entryPackApi;
  late final WalletApi _walletApi;
  String? _subscriptionMeta;
  String? _entryMeta;
  String? _walletMeta;
  String? _rechargeMeta;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _profileController = Get.find<ProfileController>();
    _subscriptionsApi = SubscriptionsApi(Get.find<ApiClient>());
    _entryPackApi = Get.find<EntryPackApi>();
    _walletApi = Get.find<WalletApi>();
    _bgMotion = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 18),
    )..repeat();
    unawaited(_profileController.load());
    unawaited(_loadInlineMeta());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _bgMotion.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_profileController.load());
      unawaited(_loadInlineMeta());
    }
  }

  Future<void> _loadInlineMeta() async {
    try {
      final results = await Future.wait([
        _subscriptionsApi.mySubscriptions(),
        _entryPackApi.fetchMine(),
        _walletApi.fetchSummary(),
        _walletApi.fetchRechargeOrders(),
      ]);
      if (!mounted) return;

      final subscriptions = results[0] as List<UserSubscriptionDto>;
      final entryState = results[1] as EntryPackStateDto;
      final walletSummary = results[2] as WalletSummaryDto;
      final orders = results[3] as List<dynamic>;

      final activeSubscription =
          subscriptions.where((s) => s.isActiveNow).toList()..sort(
            (a, b) => (b.endsAt ?? DateTime.fromMillisecondsSinceEpoch(0))
                .compareTo(a.endsAt ?? DateTime.fromMillisecondsSinceEpoch(0)),
          );

      final activePlan =
          activeSubscription.isNotEmpty ? activeSubscription.first : null;
      final subscriptionMeta =
          activePlan != null
              ? '${activePlan.planName ?? 'Plan'} · ${_formatShortDate(activePlan.endsAt)}'
              : subscriptions.isNotEmpty
              ? '${subscriptions.length} in history'
              : 'No active plan';

      final entryMeta =
          entryState.active != null
              ? entryState.active!.entryPack?.name ?? 'Active effect'
              : entryState.owned.isNotEmpty
              ? '${entryState.owned.length} owned'
              : 'None owned';

      final walletMeta = '${_formatCompactNumber(walletSummary.balance)} coins';
      final rechargeMeta = '${orders.length} orders';

      setState(() {
        _subscriptionMeta = subscriptionMeta;
        _entryMeta = entryMeta;
        _walletMeta = walletMeta;
        _rechargeMeta = rechargeMeta;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _subscriptionMeta ??= 'View plans';
        _entryMeta ??= 'Manage effects';
        _walletMeta ??= 'Wallet';
        _rechargeMeta ??= 'View orders';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = Get.find<AuthService>();
    final api = Get.find<ApiClient>();
    final appSettings = Get.find<AppSettingsService>();
    final user = auth.currentUser;
    return Obx(() {
      final tokens = _settingsTokens();
      final monetizationChildren = <Widget>[
        if (appSettings.walletRechargeEnabled)
          _PremiumSettingTile(
            icon: Icons.account_balance_wallet_rounded,
            title: 'Recharge Wallet',
            subtitle: 'Top up coins and unlock calls, gifts, and rooms',
            meta: _walletMeta,
            onTap:
                () => Get.bottomSheet(
                  const RechargeBottomSheet(),
                  isScrollControlled: true,
                ),
          ),
        if (appSettings.subscriptionsEnabled)
          _PremiumSettingTile(
            icon: Icons.workspace_premium_rounded,
            title: 'Subscriptions',
            subtitle: 'View plans, benefits, and current subscription status',
            meta: _subscriptionMeta,
            onTap: () => Get.toNamed(Routes.subscriptions),
          ),
        if (appSettings.entryEffectsEnabled)
          _PremiumSettingTile(
            icon: Icons.auto_awesome_rounded,
            title: 'Entry Effects',
            subtitle: 'Manage your entry animations and owned effects',
            meta: _entryMeta,
            onTap:
                () => Get.bottomSheet(
                  const EntryPackBottomSheet(),
                  isScrollControlled: true,
                ),
          ),
      ];

      return Scaffold(
        backgroundColor: tokens.backgroundGradient.first,
        body: Stack(
          children: [
            Positioned.fill(child: _SettingsBackdrop(t: _bgMotion)),
            Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      tokens.cardGradient.first.withOpacity(.16),
                      Colors.transparent,
                      tokens.glassColor.withOpacity(.20),
                    ],
                  ),
                ),
              ),
            ),
            ListView(
              physics: const BouncingScrollPhysics(),
              padding: EdgeInsets.fromLTRB(16, 16, 4, widget.bottomPadding),
              children: [
              _AnimatedEntrance(
                index: 0,
                child: Obx(() {
                  final profile = _profileController.profile.value;
                  final effectiveName =
                      profile?.name ?? user?.name ?? 'Talkee user';
                  final effectiveRoles =
                      profile?.roles ?? user?.roles ?? const <String>[];
                  final avatarUrl = resolveAvatarUrl(
                    api,
                    profile?.avatarUrl ?? user?.avatarUrl,
                  );
                  final roleLabel =
                      effectiveRoles.isEmpty
                          ? 'USER'
                          : effectiveRoles.join(' • ').toUpperCase();
                  return _AccountCard(
                    userId: profile?.id ?? user?.id,
                    name: effectiveName,
                    roleLabel: roleLabel,
                    avatarUrl: avatarUrl,
                    profileFrameUrl:
                        profile?.profileFrame?.assetUrl ??
                        user?.profileFrame?.assetUrl,
                    initials:
                        (effectiveName.isNotEmpty
                                ? effectiveName.substring(0, 1)
                                : 'U')
                            .toUpperCase(),
                    level: profile?.level ?? user?.level,
                    levelTitle: profile?.levelTitle ?? user?.levelTitle,
                    badgeColor: profile?.badgeColor ?? user?.badgeColor,
                    lifetimeSpendCoins:
                        profile?.lifetimeSpendCoins ?? user?.lifetimeSpendCoins,
                    nextLevelTitle:
                        profile?.nextLevelTitle ?? user?.nextLevelTitle,
                    nextLevelRequiredSpend:
                        profile?.nextLevelRequiredSpend ??
                        user?.nextLevelRequiredSpend,
                    remainingSpendToNextLevel:
                        profile?.remainingSpendToNextLevel ??
                        user?.remainingSpendToNextLevel,
                    progressPercent:
                        profile?.progressPercent ?? user?.progressPercent,
                    actions: const [],
                  );
                }),
              ),
              const SizedBox(height: 12),
              _AnimatedEntrance(
                index: 1,
                child: _SettingsSection(
                  title: 'Wallet & Plans',
                  subtitle: 'Coins, subscriptions, and entry effects',
                  children:
                      monetizationChildren.isEmpty
                          ? const [
                            _PremiumEmptyState(
                              title: 'Wallet and premium tools are unavailable',
                              message:
                                  'This section is currently disabled by the platform configuration.',
                            ),
                          ]
                          : monetizationChildren,
                ),
              ),
              const SizedBox(height: 12),
              _AnimatedEntrance(
                index: 3,
                child: Obx(() {
                  final active = appSettings.activePremiumThemeVariant;
                  final unlocked = appSettings.unlockedThemeKeys.length;
                  final meta = '${_themeVariantLabel(active)} · $unlocked unlocked';
                  return _SettingsSection(
                    title: 'Appearance',
                    subtitle: 'Theme selection and unlock status',
                    children: [
                      _PremiumSettingTile(
                        icon: Icons.palette_rounded,
                        title: 'Theme Center',
                        subtitle: 'Browse, unlock, and select themes',
                        meta: meta,
                        onTap: () => Get.toNamed(Routes.themeCenter),
                      ),
                    ],
                  );
                }),
              ),
              const SizedBox(height: 12),
              _AnimatedEntrance(
                index: 4,
                child: _SettingsSection(
                  title: 'Support & Legal',
                  subtitle: 'Assistance, privacy, and policy documents',
                  children: [
                    _PremiumSettingTile(
                      icon: Icons.privacy_tip_rounded,
                      title: 'Privacy Policy',
                      subtitle: 'Read how Talkee handles your data',
                      onTap: () => _openExternal(AppUrls.privacyPolicyUrl),
                    ),
                    _PremiumSettingTile(
                      icon: Icons.article_rounded,
                      title: 'Terms & Conditions',
                      subtitle: 'Review the service terms',
                      onTap: () => _openExternal(AppUrls.termsOfServiceUrl),
                    ),
                    _PremiumSettingTile(
                      icon: Icons.support_agent_rounded,
                      title: 'Help / Support',
                      subtitle: 'Get assistance if something is wrong',
                      onTap: () => _openExternal(AppUrls.supportUrl),
                    ),
                    _PremiumSettingTile(
                      icon: Icons.delete_forever_rounded,
                      title: 'Account Deletion',
                      subtitle: 'Open the public account deletion request page',
                      tint: const Color(0xFFE45C30),
                      onTap: () => _openExternal(AppUrls.accountDeletionUrl),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              _AnimatedEntrance(
                index: 5,
                child: _SettingsSection(
                  title: 'Session',
                  subtitle: 'Account access on this device',
                  children: [
                    _PremiumSettingTile(
                      icon: Icons.person_off_rounded,
                      title: 'Deactivate Account',
                      subtitle:
                          'Request deactivation without permanently deleting your Talkee account',
                      tint: const Color(0xFFFF8A3D),
                      onTap: _confirmDeactivateAccount,
                    ),
                    _PremiumSettingTile(
                      icon: Icons.logout_rounded,
                      title: 'Logout',
                      subtitle: 'Sign out of this device',
                      tint: tokens.dangerColor,
                      onTap: () => _confirmLogout(auth),
                    ),
                  ],
                ),
              ),
              ],
            ),
          ],
        ),
      );
    });
  }

  static Future<void> _openExternal(String raw) async {
    final uri = Uri.parse(raw);
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }

  Future<void> _confirmDeactivateAccount() async {
    final tokens = _settingsTokens();
    final confirmed = await showDialog<bool>(
      context: context,
      barrierColor: Colors.black.withOpacity(.55),
      builder: (dialogContext) {
        return Dialog(
          backgroundColor: Colors.transparent,
          insetPadding: const EdgeInsets.symmetric(horizontal: 24),
          child: Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(28),
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  tokens.cardGradient.first,
                  tokens.cardGradient.last,
                ],
              ),
              border: Border.all(color: tokens.borderColor),
              boxShadow: [
                BoxShadow(
                  color: tokens.glowColor.withOpacity(.16),
                  blurRadius: 24,
                  offset: const Offset(0, 14),
                ),
              ],
            ),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 20, 20, 18),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        width: 42,
                        height: 42,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: const Color(0xFFFF8A3D).withOpacity(.14),
                          border: Border.all(
                            color: const Color(0xFFFF8A3D).withOpacity(.32),
                          ),
                        ),
                        alignment: Alignment.center,
                        child: const Icon(
                          Icons.person_off_rounded,
                          color: Color(0xFFFF8A3D),
                          size: 20,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          'Deactivate account?',
                          style: TextStyle(
                            color: tokens.textPrimary,
                            fontWeight: FontWeight.w900,
                            fontSize: 20,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  Text(
                    'This sends you to support so you can request account deactivation. Your account is not permanently deleted by this action.',
                    style: TextStyle(
                      color: tokens.textSecondary,
                      fontWeight: FontWeight.w600,
                      height: 1.4,
                    ),
                  ),
                  const SizedBox(height: 10),
                  Text(
                    'Support email: ${AppUrls.supportEmail}',
                    style: TextStyle(
                      color: tokens.textSecondary.withOpacity(.9),
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 18),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          style: OutlinedButton.styleFrom(
                            foregroundColor: tokens.textSecondary,
                            side: BorderSide(color: tokens.borderColor),
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(16),
                            ),
                          ),
                          onPressed: () => Navigator.of(dialogContext).pop(false),
                          child: const Text('Cancel'),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(16),
                            gradient: const LinearGradient(
                              colors: [
                                Color(0xFFFF8A3D),
                                Color(0xFFE45C30),
                              ],
                            ),
                          ),
                          child: FilledButton(
                            style: FilledButton.styleFrom(
                              backgroundColor: Colors.transparent,
                              shadowColor: Colors.transparent,
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(16),
                              ),
                            ),
                            onPressed: () => Navigator.of(dialogContext).pop(true),
                            child: const Text('Continue'),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );

    if (confirmed == true) {
      await _openExternal(AppUrls.deactivateAccountMailto);
    }
  }

  Future<void> _confirmLogout(AuthService auth) async {
    final tokens = _settingsTokens();
    final confirmed = await showDialog<bool>(
      context: context,
      barrierColor: Colors.black.withOpacity(.55),
      builder: (dialogContext) {
        return Dialog(
          backgroundColor: Colors.transparent,
          insetPadding: const EdgeInsets.symmetric(horizontal: 24),
          child: Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(28),
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  tokens.cardGradient.first,
                  tokens.cardGradient.last,
                ],
              ),
              border: Border.all(color: tokens.borderColor),
              boxShadow: [
                BoxShadow(
                  color: tokens.glowColor.withOpacity(.16),
                  blurRadius: 24,
                  offset: const Offset(0, 14),
                ),
              ],
            ),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 20, 20, 18),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        width: 42,
                        height: 42,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: tokens.dangerColor.withOpacity(.14),
                          border: Border.all(
                            color: tokens.dangerColor.withOpacity(.32),
                          ),
                        ),
                        alignment: Alignment.center,
                        child: Icon(
                          Icons.logout_rounded,
                          color: tokens.dangerColor,
                          size: 20,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          'Logout?',
                          style: TextStyle(
                            color: tokens.textPrimary,
                            fontWeight: FontWeight.w900,
                            fontSize: 20,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  Text(
                    'You will be signed out from this device.',
                    style: TextStyle(
                      color: tokens.textSecondary,
                      fontWeight: FontWeight.w600,
                      height: 1.4,
                    ),
                  ),
                  const SizedBox(height: 18),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          style: OutlinedButton.styleFrom(
                            foregroundColor: tokens.textSecondary,
                            side: BorderSide(color: tokens.borderColor),
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(16),
                            ),
                          ),
                          onPressed: () => Navigator.of(dialogContext).pop(false),
                          child: const Text('Cancel'),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: DecoratedBox(
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(16),
                            gradient: LinearGradient(
                              colors: [
                                tokens.dangerColor,
                                Color.lerp(tokens.dangerColor, Colors.black, .18)!,
                              ],
                            ),
                          ),
                          child: FilledButton(
                            style: FilledButton.styleFrom(
                              backgroundColor: Colors.transparent,
                              shadowColor: Colors.transparent,
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(16),
                              ),
                            ),
                            onPressed: () => Navigator.of(dialogContext).pop(true),
                            child: const Text('Logout'),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );

    if (confirmed == true) {
      await auth.logout();
    }
  }

  static String _formatShortDate(DateTime? value) {
    if (value == null) return 'active';
    const months = <String>[
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ];
    final local = value.toLocal();
    return '${local.day} ${months[local.month - 1]}';
  }

  static String _formatCompactNumber(int value) {
    if (value >= 1000000) {
      return '${(value / 1000000).toStringAsFixed(value % 1000000 == 0 ? 0 : 1)}M';
    }
    if (value >= 1000) {
      return '${(value / 1000).toStringAsFixed(value % 1000 == 0 ? 0 : 1)}K';
    }
    return '$value';
  }
}

class _SettingsPageHeader extends StatelessWidget {
  const _SettingsPageHeader();

  @override
  Widget build(BuildContext context) {
    final tokens = _settingsTokens();
    return Padding(
      padding: const EdgeInsets.only(left: 4, right: 12),
      child: Text(
        'Settings',
        style: Theme.of(context).textTheme.headlineSmall?.copyWith(
          color: tokens.textPrimary,
          fontWeight: FontWeight.w900,
          letterSpacing: -.4,
        ),
      ),
    );
  }
}

class _ThemeVariantSheet extends StatelessWidget {
  const _ThemeVariantSheet();

  @override
  Widget build(BuildContext context) {
    final appSettings = Get.find<AppSettingsService>();
    final tokens = _settingsTokens();
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 20),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(30),
          child: BackdropFilter(
            filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
            child: Container(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: tokens.cardGradient,
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(30),
                border: Border.all(color: tokens.borderColor),
                boxShadow: [
                  BoxShadow(
                    color: tokens.glowColor.withOpacity(.18),
                    blurRadius: 24,
                    offset: const Offset(0, 12),
                  ),
                ],
              ),
              child: Obx(() {
                final variantsEnabled = appSettings.premiumThemeVariantsEnabled;
                final selected = appSettings.localThemeVariantOverride;
                final configured = appSettings.configuredPremiumThemeVariant;
                return ConstrainedBox(
                  constraints: BoxConstraints(
                    maxHeight: MediaQuery.of(context).size.height * .82,
                  ),
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(18, 12, 18, 18),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Center(
                          child: Container(
                            width: 42,
                            height: 5,
                            decoration: BoxDecoration(
                              color: tokens.borderColor.withOpacity(.9),
                              borderRadius: BorderRadius.circular(999),
                            ),
                          ),
                        ),
                        const SizedBox(height: 14),
                        Text(
                          'Theme',
                          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                            color: tokens.textPrimary,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Text(
                          variantsEnabled
                              ? 'Choose a theme for this device or follow the app default.'
                              : 'App-controlled premium variants are off, but you can still choose a local theme on this device.',
                          style: TextStyle(
                            color: tokens.textSecondary.withOpacity(.9),
                            fontWeight: FontWeight.w600,
                            height: 1.4,
                          ),
                        ),
                        const SizedBox(height: 16),
                        _ThemeVariantOptionTile(
                          title: 'App default',
                          subtitle:
                              variantsEnabled
                                  ? _themeVariantLabel(configured)
                                  : 'Midnight fallback',
                          selected: selected == null,
                          enabled: true,
                          previewColor: _variantPreviewColor(configured),
                          onTap: () async {
                            await appSettings.setThemeVariantOverride(null);
                            if (context.mounted) Get.back();
                          },
                        ),
                        const SizedBox(height: 10),
                        for (final variant in kPremiumThemeVariants) ...[
                          _ThemeVariantOptionTile(
                            title: _themeVariantLabel(variant),
                            subtitle:
                                variant == configured
                                    ? 'Matches app default'
                                    : 'Use on this device',
                            selected: selected == variant,
                            enabled: true,
                            previewColor: _variantPreviewColor(variant),
                            onTap:
                                () async {
                                  await appSettings.setThemeVariantOverride(
                                    variant,
                                  );
                                  if (context.mounted) Get.back();
                                },
                          ),
                          if (variant != kPremiumThemeVariants.last)
                            const SizedBox(height: 10),
                        ],
                      ],
                    ),
                  ),
                );
              }),
            ),
          ),
        ),
      ),
    );
  }
}

class _ThemeVariantOptionTile extends StatelessWidget {
  final String title;
  final String subtitle;
  final bool selected;
  final bool enabled;
  final Color previewColor;
  final VoidCallback? onTap;

  const _ThemeVariantOptionTile({
    required this.title,
    required this.subtitle,
    required this.selected,
    required this.enabled,
    required this.previewColor,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _settingsTokens();
    final foreground =
        enabled ? tokens.textPrimary : tokens.textSecondary.withOpacity(.65);
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap:
            enabled
                ? () {
                  Haptics.light();
                  onTap?.call();
                }
                : null,
        borderRadius: BorderRadius.circular(22),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
          decoration: BoxDecoration(
            color:
                selected
                    ? tokens.chipColor.withOpacity(.92)
                    : tokens.glassColor.withOpacity(.82),
            borderRadius: BorderRadius.circular(22),
            border: Border.all(
              color: selected ? previewColor.withOpacity(.75) : tokens.borderColor,
            ),
          ),
          child: Row(
            children: [
              Container(
                width: 16,
                height: 16,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: previewColor,
                  boxShadow: [
                    BoxShadow(
                      color: previewColor.withOpacity(.4),
                      blurRadius: 10,
                      offset: const Offset(0, 2),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: TextStyle(
                        color: foreground,
                        fontWeight: FontWeight.w800,
                        fontSize: 15,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      subtitle,
                      style: TextStyle(
                        color: tokens.textSecondary.withOpacity(
                          enabled ? .9 : .65,
                        ),
                        fontWeight: FontWeight.w600,
                        fontSize: 12.5,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Icon(
                selected
                    ? Icons.check_circle_rounded
                    : Icons.radio_button_unchecked_rounded,
                color:
                    selected
                        ? previewColor
                        : tokens.textSecondary.withOpacity(enabled ? .7 : .45),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _AccountCard extends StatelessWidget {
  final int? userId;
  final String name;
  final String roleLabel;
  final String? avatarUrl;
  final String? profileFrameUrl;
  final String initials;
  final int? level;
  final String? levelTitle;
  final String? badgeColor;
  final int? lifetimeSpendCoins;
  final String? nextLevelTitle;
  final int? nextLevelRequiredSpend;
  final int? remainingSpendToNextLevel;
  final double? progressPercent;
  final List<_HeroAction> actions;

  const _AccountCard({
    this.userId,
    required this.name,
    required this.roleLabel,
    required this.avatarUrl,
    required this.profileFrameUrl,
    required this.initials,
    this.level,
    this.levelTitle,
    this.badgeColor,
    this.lifetimeSpendCoins,
    this.nextLevelTitle,
    this.nextLevelRequiredSpend,
    this.remainingSpendToNextLevel,
    this.progressPercent,
    required this.actions,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _settingsTokens();
    final hasProfileFrame = (profileFrameUrl ?? '').trim().isNotEmpty;
    return _GlassShell(
      padding: const EdgeInsets.all(15),
      borderRadius: 30,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Material(
            color: Colors.transparent,
            child: InkWell(
              borderRadius: BorderRadius.circular(24),
              onTap: () {
                Haptics.light();
                Get.toNamed(Routes.profile);
              },
              child: Padding(
                padding: const EdgeInsets.all(2),
                child: Row(
                  children: [
                    Container(
                      width: 56,
                      height: 56,
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(24),
                        gradient: hasProfileFrame
                            ? null
                            : LinearGradient(
                                colors: tokens.primaryButtonGradient,
                                begin: Alignment.topLeft,
                                end: Alignment.bottomRight,
                              ),
                        color: hasProfileFrame ? Colors.transparent : null,
                      ),
                      child: FramedAvatar(
                        size: 56,
                        label: initials,
                        avatarUrl: avatarUrl,
                        frameUrl: profileFrameUrl,
                        backgroundColor: tokens.cardGradient.first,
                        avatarInset: 0.04,
                        borderRadius: 22,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            name,
                            style: Theme.of(
                              context,
                            ).textTheme.titleLarge?.copyWith(
                              color: tokens.textPrimary,
                              fontWeight: FontWeight.w800,
                              fontSize: 21,
                            ),
                          ),
                          if (userId != null) ...[
                            const SizedBox(height: 4),
                            Text(
                              'User ID: $userId',
                              style: TextStyle(
                                color: tokens.textSecondary.withOpacity(.88),
                                fontWeight: FontWeight.w700,
                                fontSize: 12.4,
                              ),
                            ),
                          ],
                          const SizedBox(height: 4),
                          Wrap(
                            spacing: 8,
                            runSpacing: 6,
                            children: [
                              _RoleChip(label: roleLabel),
                              if (level != null ||
                                  (levelTitle?.trim().isNotEmpty ?? false))
                                _RoleChip(
                                  label:
                                      levelTitle?.trim().isNotEmpty == true
                                          ? 'L$level · ${levelTitle!.trim()}'
                                          : 'LEVEL ${level ?? 1}',
                                  color:
                                      _parseColor(badgeColor) ??
                                      tokens.glowColor,
                                ),
                            ],
                          ),
                        ],
                      ),
                    ),
                    Container(
                      width: 40,
                      height: 40,
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                          colors: [
                            tokens.chipColor.withOpacity(.96),
                            tokens.glassColor.withOpacity(.74),
                          ],
                        ),
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(
                          color: tokens.borderColor.withOpacity(.82),
                        ),
                      ),
                      child: Icon(
                        Icons.arrow_forward_rounded,
                        color: tokens.textPrimary,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
          if ((lifetimeSpendCoins ?? 0) > 0 || level != null) ...[
            const SizedBox(height: 12),
            _LevelProgressCard(
              level: level,
              levelTitle: levelTitle,
              lifetimeSpendCoins: lifetimeSpendCoins ?? 0,
              nextLevelTitle: nextLevelTitle,
              nextLevelRequiredSpend: nextLevelRequiredSpend,
              remainingSpendToNextLevel: remainingSpendToNextLevel,
              progressPercent: progressPercent ?? 0,
              badgeColor: _parseColor(badgeColor) ?? const Color(0xFF7B50C5),
            ),
          ],
          if (actions.isNotEmpty) ...[
            const SizedBox(height: 18),
            Row(
              children:
                  actions
                      .map(
                        (action) => Expanded(
                          child: Padding(
                            padding: EdgeInsets.only(
                              right: action == actions.last ? 0 : 10,
                            ),
                            child: _ActionCard(action: action),
                          ),
                        ),
                      )
                      .toList(),
            ),
          ],
        ],
      ),
    );
  }
}

class _LevelProgressCard extends StatelessWidget {
  final int? level;
  final String? levelTitle;
  final int lifetimeSpendCoins;
  final String? nextLevelTitle;
  final int? nextLevelRequiredSpend;
  final int? remainingSpendToNextLevel;
  final double progressPercent;
  final Color badgeColor;

  const _LevelProgressCard({
    required this.level,
    required this.levelTitle,
    required this.lifetimeSpendCoins,
    required this.nextLevelTitle,
    required this.nextLevelRequiredSpend,
    required this.remainingSpendToNextLevel,
    required this.progressPercent,
    required this.badgeColor,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _settingsTokens();
    final accent = badgeColor == const Color(0xFF7B50C5)
        ? tokens.primaryButtonGradient.first
        : badgeColor;
    final currentLevelLabel =
        levelTitle?.trim().isNotEmpty == true
            ? 'Level ${level ?? 1} · ${levelTitle!.trim()}'
            : 'Level ${level ?? 1}';
    final summary =
        nextLevelRequiredSpend == null
            ? '${_compact(lifetimeSpendCoins)} spent · highest active level'
            : '${_compact(lifetimeSpendCoins)} spent · ${_compact(remainingSpendToNextLevel ?? 0)} to ${nextLevelTitle ?? 'next'}';
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: tokens.cardGradient,
        ),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: tokens.borderColor),
        boxShadow: [
          BoxShadow(
            color: tokens.glowColor.withOpacity(.18),
            blurRadius: 20,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  color: accent.withOpacity(.16),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(
                  Icons.workspace_premium_rounded,
                  color: accent,
                  size: 18,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      currentLevelLabel,
                      style: TextStyle(
                        color: tokens.textPrimary,
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      summary,
                      style: TextStyle(
                        color: tokens.textSecondary.withOpacity(.9),
                        fontWeight: FontWeight.w600,
                        fontSize: 12.5,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              minHeight: 6,
              value: (progressPercent.clamp(0, 100)) / 100,
              backgroundColor: tokens.borderColor.withOpacity(.75),
              valueColor: AlwaysStoppedAnimation<Color>(accent),
            ),
          ),
        ],
      ),
    );
  }

  static String _compact(int value) {
    if (value >= 1000000) {
      return '${(value / 1000000).toStringAsFixed(value % 1000000 == 0 ? 0 : 1)}M';
    }
    if (value >= 1000) {
      return '${(value / 1000).toStringAsFixed(value % 1000 == 0 ? 0 : 1)}K';
    }
    return '$value';
  }
}

class _SettingsSection extends StatelessWidget {
  final String title;
  final String subtitle;
  final List<Widget> children;
  const _SettingsSection({
    required this.title,
    required this.subtitle,
    required this.children,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _settingsTokens();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(left: 4, bottom: 10),
          child: Text(
            title,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w800,
              color: tokens.textPrimary,
            ),
          ),
        ),
        _GlassShell(
          borderRadius: 28,
          child: Column(
            children: List.generate(children.length, (index) {
              return Column(
                children: [
                  children[index],
                  if (index != children.length - 1)
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 18),
                      child: Divider(
                        height: 1,
                        color: tokens.borderColor,
                      ),
                    ),
                ],
              );
            }),
          ),
        ),
      ],
    );
  }
}

class _PremiumEmptyState extends StatelessWidget {
  final String title;
  final String message;

  const _PremiumEmptyState({required this.title, required this.message});

  @override
  Widget build(BuildContext context) {
    final tokens = _settingsTokens();
    return Padding(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: TextStyle(
              color: tokens.textPrimary,
              fontSize: 16,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            message,
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.92),
              height: 1.45,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

class _PremiumSettingTile extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final String? meta;
  final VoidCallback onTap;
  final Color? tint;

  const _PremiumSettingTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.meta,
    required this.onTap,
    this.tint,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _settingsTokens();
    final color = tint ?? tokens.primaryButtonGradient.first;
    final iconFill = color.computeLuminance() > 0.4 ? .12 : .18;
    final iconBorder = color.computeLuminance() > 0.4 ? .18 : .24;
    final metaVisible = meta != null && meta!.trim().isNotEmpty;
    return InkWell(
      borderRadius: BorderRadius.circular(28),
      onTap: () {
        Haptics.light();
        onTap();
      },
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: color.withOpacity(iconFill),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: color.withOpacity(iconBorder)),
              ),
              child: Icon(icon, color: color, size: 20),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                title,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: tokens.textPrimary,
                  fontSize: 15.5,
                ),
              ),
            ),
            if (metaVisible) ...[
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: tokens.chipColor.withOpacity(.72),
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(color: tokens.borderColor),
                ),
                child: Text(
                  meta!,
                  style: TextStyle(
                    color: tokens.textSecondary.withOpacity(.96),
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                    letterSpacing: .1,
                  ),
                ),
              ),
            ],
            const SizedBox(width: 6),
            Icon(
              Icons.chevron_right_rounded,
              color: color.withOpacity(.9),
              size: 20,
            ),
          ],
        ),
      ),
    );
  }
}

class _HeroAction {
  final String label;
  final IconData icon;
  final VoidCallback onTap;

  const _HeroAction({
    required this.label,
    required this.icon,
    required this.onTap,
  });
}

class _ActionCard extends StatelessWidget {
  final _HeroAction action;

  const _ActionCard({required this.action});

  @override
  Widget build(BuildContext context) {
    final tokens = _settingsTokens();
    return Material(
      color: tokens.glassColor.withOpacity(.95),
      borderRadius: BorderRadius.circular(20),
      child: InkWell(
        borderRadius: BorderRadius.circular(20),
        onTap: () {
          Haptics.light();
          action.onTap();
        },
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: tokens.chipColor.withOpacity(.72),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(action.icon, color: tokens.textPrimary),
              ),
              const SizedBox(height: 8),
              Text(
                action.label,
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: tokens.textPrimary,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _AnimatedEntrance extends StatelessWidget {
  final int index;
  final Widget child;

  const _AnimatedEntrance({required this.index, required this.child});

  @override
  Widget build(BuildContext context) {
    final begin = 80 * index;
    final total = begin + 520;
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: Duration(milliseconds: total),
      curve: Curves.easeOutCubic,
      builder: (context, value, _) {
        final raw = ((value * total) - begin) / 520;
        final clamped = raw.clamp(0.0, 1.0);
        final eased = Curves.easeOutCubic.transform(clamped);
        return Opacity(
          opacity: eased,
          child: Transform.translate(
            offset: Offset(0, (1 - eased) * 28),
            child: child,
          ),
        );
      },
      child: child,
    );
  }
}

class _SettingsBackdrop extends StatelessWidget {
  final Animation<double> t;

  const _SettingsBackdrop({required this.t});

  @override
  Widget build(BuildContext context) {
    final tokens = _settingsTokens();
    return AnimatedBuilder(
      animation: t,
      builder: (context, _) {
        return CustomPaint(
          painter: _SettingsBackdropPainter(t.value),
          child: Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: tokens.backgroundGradient,
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
              ),
            ),
          ),
        );
      },
    );
  }
}

class _SettingsBackdropPainter extends CustomPainter {
  final double t;

  const _SettingsBackdropPainter(this.t);

  @override
  void paint(Canvas canvas, Size size) {
    final tokens = _settingsTokens();
    final paint1 =
        Paint()
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 64)
          ..color = tokens.glowColor.withOpacity(.24);
    final paint2 =
        Paint()
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 72)
          ..color = tokens.primaryButtonGradient.last.withOpacity(.28);

    final wobble1 = math.sin(t * math.pi * 2) * 18;
    final wobble2 = math.cos(t * math.pi * 2) * 24;
    final wobble3 = math.sin((t * math.pi * 2) + 1.2) * 20;

    canvas.drawCircle(
      Offset(size.width * .18, size.height * .18 + wobble1),
      size.height * .12,
      paint1,
    );
    canvas.drawCircle(
      Offset(size.width * .88, size.height * .32 + wobble2),
      size.height * .15,
      paint2,
    );
    canvas.drawCircle(
      Offset(size.width * .55, size.height * .78 + wobble3),
      size.height * .18,
      paint1,
    );
  }

  @override
  bool shouldRepaint(covariant _SettingsBackdropPainter oldDelegate) =>
      oldDelegate.t != t;
}

class _GlassShell extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;
  final double borderRadius;

  const _GlassShell({
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.borderRadius = 28,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _settingsTokens();
    return ClipRRect(
      borderRadius: BorderRadius.circular(borderRadius),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: Container(
          padding: padding,
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: tokens.cardGradient,
            ),
            borderRadius: BorderRadius.circular(borderRadius),
            border: Border.all(color: tokens.borderColor),
            boxShadow: [
              BoxShadow(
                color: tokens.glowColor.withOpacity(.22),
                blurRadius: 24,
                offset: const Offset(0, 10),
              ),
            ],
          ),
          child: child,
        ),
      ),
    );
  }
}

class _RoleChip extends StatelessWidget {
  final String label;
  final Color? color;

  const _RoleChip({required this.label, this.color});

  @override
  Widget build(BuildContext context) {
    final tokens = _settingsTokens();
    final chipColor = color ?? Colors.white;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        color: chipColor == Colors.white
            ? tokens.chipColor.withOpacity(.78)
            : chipColor.withOpacity(.12),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(
          color: chipColor == Colors.white
              ? tokens.borderColor
              : chipColor.withOpacity(.18),
        ),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: chipColor == Colors.white
              ? tokens.textPrimary
              : chipColor.withOpacity(.92),
          fontSize: 11,
          fontWeight: FontWeight.w700,
          letterSpacing: .4,
        ),
      ),
    );
  }
}

String _themeVariantLabel(String variant) {
  switch (variant.trim().toLowerCase()) {
    case 'crimson_velvet':
      return 'Crimson Velvet';
    case 'noir_opal':
      return 'Noir Opal';
    case 'imperial_jade':
      return 'Imperial Jade';
    case 'molten_pearl':
      return 'Molten Pearl';
    case 'amethyst_chrome':
      return 'Amethyst Chrome';
    case 'gold_black':
      return 'Gold Black';
    case 'obsidian_rose':
      return 'Obsidian Rose';
    case 'royal_sapphire':
      return 'Royal Sapphire';
    case 'ruby_sky':
      return 'Ruby Sky';
    case 'violet_lime':
      return 'Violet Lime';
    case 'sunset_pop':
      return 'Sunset Pop';
    case 'teal_rose':
      return 'Teal Rose';
    case 'ocean':
      return 'Ocean';
    case 'inferno':
      return 'Inferno';
    case 'emerald':
      return 'Emerald';
    case 'ice':
      return 'Ice';
    case 'cyberpunk':
      return 'Cyberpunk';
    case 'aurora':
      return 'Aurora';
    case 'gold':
      return 'Gold';
    case 'midnight':
    default:
      return 'Midnight';
  }
}

Color _variantPreviewColor(String variant) {
  switch (variant.trim().toLowerCase()) {
    case 'crimson_velvet':
      return const Color(0xFFFF6B81);
    case 'noir_opal':
      return const Color(0xFF7CF7E2);
    case 'imperial_jade':
      return const Color(0xFF5CE1B9);
    case 'molten_pearl':
      return const Color(0xFFFFC6A8);
    case 'amethyst_chrome':
      return const Color(0xFFC8B4FF);
    case 'gold_black':
      return const Color(0xFFFFD86B);
    case 'obsidian_rose':
      return const Color(0xFFFF77A8);
    case 'royal_sapphire':
      return const Color(0xFF6D8CFF);
    case 'ruby_sky':
      return const Color(0xFFFF5C8A);
    case 'violet_lime':
      return const Color(0xFF8E7CFF);
    case 'sunset_pop':
      return const Color(0xFFFF8A3D);
    case 'teal_rose':
      return const Color(0xFF54E3C2);
    case 'ocean':
      return const Color(0xFF38BDF8);
    case 'inferno':
      return const Color(0xFFFF3B3B);
    case 'emerald':
      return const Color(0xFF22C55E);
    case 'ice':
      return const Color(0xFF60A5FA);
    case 'cyberpunk':
      return const Color(0xFFFF00FF);
    case 'aurora':
      return const Color(0xFF38C4FF);
    case 'gold':
      return const Color(0xFFFFC845);
    case 'midnight':
    default:
      return const Color(0xFF8B5CF6);
  }
}

Color? _parseColor(String? raw) {
  if (raw == null || raw.trim().isEmpty) {
    return null;
  }
  final hex = raw.trim().replaceFirst('#', '');
  if (hex.length != 6 && hex.length != 8) {
    return null;
  }
  final normalized = hex.length == 6 ? 'FF$hex' : hex;
  final value = int.tryParse(normalized, radix: 16);
  if (value == null) {
    return null;
  }
  return Color(value);
}

class _PlacementBannerStrip extends StatefulWidget {
  final String placement;
  final String screen;
  final String slot;
  final bool dismissible;
  final double height;

  const _PlacementBannerStrip({
    required this.placement,
    required this.screen,
    required this.slot,
    required this.dismissible,
    required this.height,
  });

  @override
  State<_PlacementBannerStrip> createState() => _PlacementBannerStripState();
}

class _PlacementBannerStripState extends State<_PlacementBannerStrip> {
  late final PageController _pc = PageController(viewportFraction: 1);
  final Set<int> _impressed = <int>{};
  Timer? _ticker;
  bool _loading = true;
  bool _dismissed = false;
  int _index = 0;
  List<BannerItem> _banners = const <BannerItem>[];

  @override
  void initState() {
    super.initState();
    _load();
    _ticker = Timer.periodic(const Duration(seconds: 5), (_) {
      if (!mounted || !_pc.hasClients || _banners.length < 2 || _dismissed)
        return;
      _index = (_index + 1) % _banners.length;
      _pc.animateToPage(
        _index,
        duration: const Duration(milliseconds: 380),
        curve: Curves.easeOutCubic,
      );
    });
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _pc.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final service = Get.find<BannerService>();
    final remote = await service.fetchBanners(
      placement: widget.placement,
      forceRefresh: true,
    );
    if (!mounted) return;

    final fallback = <BannerItem>[
      BannerItem(
        id: -10,
        title: widget.placement == 'offer' ? 'Wallet offers' : 'Profile',
        imageUrl: '',
        actionType: 'none',
        buttonText: null,
      ),
    ];

    setState(() {
      _loading = false;
      _dismissed = false;
      _index = 0;
      _impressed.clear();
      _banners = remote.isNotEmpty ? remote : fallback;
    });
    await _trackImpression(0);
  }

  Future<void> _trackImpression(int index) async {
    if (index < 0 || index >= _banners.length) return;
    final b = _banners[index];
    if (b.id <= 0 || _impressed.contains(b.id)) return;
    _impressed.add(b.id);
    await Get.find<BannerService>().trackImpression(
      bannerId: b.id,
      placement: widget.placement,
      context: {'screen': widget.screen, 'slot': widget.slot},
    );
  }

  Future<void> _onTap(BannerItem b) async {
    if (b.id > 0) {
      await Get.find<BannerService>().trackClick(
        bannerId: b.id,
        placement: widget.placement,
        context: {'screen': widget.screen, 'slot': widget.slot},
      );
    }

    final actionType = b.actionType.trim().toLowerCase();
    final actionValue = b.actionValue?.trim();
    if (actionType == 'none' || actionValue == null || actionValue.isEmpty)
      return;

    try {
      if (actionType == 'route') {
        await Get.toNamed(actionValue);
        return;
      }
      if (actionType == 'deeplink' && actionValue.startsWith('/')) {
        await Get.toNamed(actionValue);
        return;
      }
      final uri = Uri.tryParse(actionValue);
      if (uri == null) return;
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    if (_dismissed) return const SizedBox.shrink();
    if (_loading && _banners.isEmpty) {
      return SizedBox(height: widget.height);
    }

    return SizedBox(
      height: widget.height,
      child: Stack(
        children: [
          PageView.builder(
            controller: _pc,
            itemCount: _banners.length,
            onPageChanged: (v) async {
              setState(() => _index = v);
              await _trackImpression(v);
            },
            itemBuilder: (_, i) {
              final b = _banners[i];
              final hasAction =
                  b.actionType.toLowerCase() != 'none' &&
                  (b.actionValue?.trim().isNotEmpty ?? false);
              return InkWell(
                borderRadius: BorderRadius.circular(24),
                onTap: () => _onTap(b),
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(24),
                  child: Stack(
                    fit: StackFit.expand,
                    children: [
                      if (b.hasImage)
                        Image.network(
                          b.imageUrl,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => const SizedBox.shrink(),
                        ),
                      Container(
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                            colors:
                                b.hasImage
                                    ? const [
                                      Color(0x660D0818),
                                      Color(0xB0261640),
                                      Color(0xD8382161),
                                    ]
                                    : const [
                                      Color(0xFF201236),
                                      Color(0xFF3A225E),
                                      Color(0xFF61409D),
                                    ],
                          ),
                          border: Border.all(
                            color: Colors.white.withOpacity(.18),
                          ),
                        ),
                      ),
                      Positioned(
                        right: -22,
                        top: -18,
                        child: Container(
                          width: 112,
                          height: 112,
                          decoration: BoxDecoration(
                            color: Colors.white.withOpacity(.08),
                            shape: BoxShape.circle,
                          ),
                        ),
                      ),
                      Padding(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 16,
                          vertical: 14,
                        ),
                        child: Row(
                          children: [
                            Container(
                              width: 40,
                              height: 40,
                              decoration: BoxDecoration(
                                color: Colors.white.withOpacity(.16),
                                shape: BoxShape.circle,
                              ),
                              child: const Icon(
                                Icons.auto_awesome_rounded,
                                color: Colors.white,
                                size: 21,
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Text(
                                b.title,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.w900,
                                  fontSize: 18,
                                ),
                              ),
                            ),
                            if (hasAction)
                              const Icon(
                                Icons.arrow_outward_rounded,
                                size: 18,
                                color: Colors.white,
                              ),
                          ],
                        ),
                      ),
                      if (widget.dismissible)
                        Positioned(
                          top: 8,
                          right: 8,
                          child: InkWell(
                            borderRadius: BorderRadius.circular(999),
                            onTap: () => setState(() => _dismissed = true),
                            child: Container(
                              width: 26,
                              height: 26,
                              decoration: BoxDecoration(
                                color: Colors.black.withOpacity(.24),
                                borderRadius: BorderRadius.circular(999),
                              ),
                              child: const Icon(
                                Icons.close_rounded,
                                color: Colors.white,
                                size: 14,
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              );
            },
          ),
          if (_banners.length > 1)
            Positioned(
              left: 0,
              right: 0,
              bottom: 6,
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(
                  _banners.length,
                  (i) => AnimatedContainer(
                    duration: const Duration(milliseconds: 220),
                    margin: const EdgeInsets.symmetric(horizontal: 2),
                    width: i == _index ? 12 : 5,
                    height: 5,
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(i == _index ? .90 : .40),
                      borderRadius: BorderRadius.circular(999),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
