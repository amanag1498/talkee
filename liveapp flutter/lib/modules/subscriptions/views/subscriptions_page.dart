import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../services/api_client.dart';
import '../../../services/app_settings_service.dart';
import '../../wallet/services/wallet_api.dart';
import '../../wallet/widgets/recharge_bottom_sheet.dart';
import '../models/subscription_plan_dto.dart';
import '../models/user_subscription_dto.dart';
import '../services/subscriptions_api.dart';
import '../widgets/choose_plan_sheet.dart';

class SubscriptionsPage extends StatefulWidget {
  const SubscriptionsPage({super.key});

  @override
  State<SubscriptionsPage> createState() => _SubscriptionsPageState();
}

class _SubscriptionsPageState extends State<SubscriptionsPage>
    with SingleTickerProviderStateMixin {
  late final SubscriptionsApi _api;
  late final WalletApi _walletApi;
  late final AnimationController _bgMotion;

  bool _loading = true;
  bool _buying = false;
  String? _error;
  int? _walletBalanceCoins;
  List<SubscriptionPlanDto> _plans = const <SubscriptionPlanDto>[];
  List<UserSubscriptionDto> _subscriptions = const <UserSubscriptionDto>[];

  @override
  void initState() {
    super.initState();
    _api = SubscriptionsApi(Get.find<ApiClient>());
    _walletApi = Get.find<WalletApi>();
    _bgMotion = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 18),
    )..repeat();
    if (!Get.find<AppSettingsService>().subscriptionsEnabled) {
      _loading = false;
      _error = 'Subscriptions are currently unavailable.';
      return;
    }
    _load();
  }

  @override
  void dispose() {
    _bgMotion.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final results = await Future.wait([
        _api.fetchPlans(),
        _api.mySubscriptions(),
        _walletApi.fetchSummary(),
      ]);

      if (!mounted) return;
      setState(() {
        _plans = results[0] as List<SubscriptionPlanDto>;
        _subscriptions = results[1] as List<UserSubscriptionDto>;
        _walletBalanceCoins = (results[2] as dynamic).balance as int;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString().replaceFirst('Exception: ', '');
      });
    } finally {
      if (mounted) {
        setState(() => _loading = false);
      }
    }
  }

  UserSubscriptionDto? get _activeSubscription {
    final active = _subscriptions.where((s) => s.isActiveNow).toList();
    if (active.isEmpty) return null;
    active.sort(
      (a, b) => (b.endsAt ?? DateTime.fromMillisecondsSinceEpoch(0)).compareTo(
        a.endsAt ?? DateTime.fromMillisecondsSinceEpoch(0),
      ),
    );
    return active.first;
  }

  List<UserSubscriptionDto> get _historySubscriptions {
    final active = _activeSubscription;
    return _subscriptions.where((sub) => sub != active).toList();
  }

  Future<void> _openPlanSheet() async {
    final plans = _plans.where((p) => p.isActive).toList();
    if (plans.isEmpty || _buying) return;

    final plan = await ChoosePlanSheet.show(context, plans: plans);
    if (plan == null || !mounted) return;

    setState(() {
      _buying = true;
      _error = null;
    });
    try {
      await _api.purchase(planId: plan.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('${plan.name} unlocked successfully.')),
      );
      await _load();
    } catch (e) {
      if (!mounted) return;
      final message = e.toString().replaceFirst('Exception: ', '');
      setState(() => _error = message);
      if (isInsufficientCoinsErrorMessage(message)) {
        await showRechargeWalletSheet(
          reasonTitle: 'Not enough coins',
          reasonMessage:
              'You need more coins to buy ${plan.name}. Recharge your wallet and try again.',
        );
        if (mounted) {
          await _load();
        }
      }
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(message)));
    } finally {
      if (mounted) {
        setState(() => _buying = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      final tokens = getPremiumThemeTokens(
        Get.find<AppSettingsService>().activePremiumThemeVariant,
      );
      final active = _activeSubscription;
      final activePlans = _plans.where((p) => p.isActive).toList();

      return Scaffold(
        backgroundColor: tokens.backgroundGradient.first,
        appBar: AppBar(
          title: Text(
            'Subscriptions',
            style: TextStyle(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w800,
            ),
          ),
          backgroundColor: Colors.transparent,
          elevation: 0,
          foregroundColor: tokens.textPrimary,
          iconTheme: IconThemeData(color: tokens.textPrimary),
          flexibleSpace: Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  tokens.cardGradient.first.withValues(alpha: .84),
                  tokens.cardGradient.last.withValues(alpha: .72),
                ],
              ),
              border: Border(
                bottom: BorderSide(
                  color: tokens.borderColor.withValues(alpha: .72),
                ),
              ),
            ),
          ),
        ),
        body: Stack(
          children: [
            Positioned.fill(child: _GlassyBackdrop(t: _bgMotion)),
            Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      tokens.cardGradient.first.withOpacity(.16),
                      Colors.transparent,
                      tokens.glassColor.withOpacity(.22),
                    ],
                  ),
                ),
              ),
            ),
            RefreshIndicator(
              onRefresh: _load,
              color: Colors.white,
              backgroundColor: tokens.primaryButtonGradient.last,
              child: ListView(
                physics: const BouncingScrollPhysics(),
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                children: [
                _HeroPanel(
                  active: active,
                  walletBalanceCoins: _walletBalanceCoins,
                  buying: _buying,
                  hasPlans: activePlans.isNotEmpty,
                  onChoosePlan: _openPlanSheet,
                ),
                const SizedBox(height: 16),
                if (_loading)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 80),
                    child: Center(
                      child: CircularProgressIndicator(color: Colors.white),
                    ),
                  )
                else if (_error != null)
                  _GlassMessageCard(
                    icon: Icons.sync_problem_rounded,
                    title: 'Unable to load subscriptions',
                    subtitle: _error!,
                    actionLabel: 'Retry',
                    onAction: _load,
                  )
                else ...[
                  _SectionTitle(
                    title: 'Current Access',
                    subtitle: 'Your active plan and entitlement window',
                  ),
                  const SizedBox(height: 10),
                  if (active == null)
                    const _GlassEmptyCard(
                      icon: Icons.lock_outline_rounded,
                      title: 'No active subscription',
                      subtitle:
                          'Choose a plan to unlock subscription-gated live rooms and premium viewer access.',
                    )
                  else
                    _ActiveSubscriptionCard(subscription: active),
                  if (_historySubscriptions.isNotEmpty) ...[
                    const SizedBox(height: 18),
                    _SectionTitle(
                      title: 'History',
                      subtitle: 'Previous subscriptions tied to your account',
                    ),
                    const SizedBox(height: 10),
                    ..._historySubscriptions.map(
                      (subscription) => Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: _HistoryCard(subscription: subscription),
                      ),
                    ),
                  ],
                ],
                ],
              ),
            ),
          ],
        ),
      );
    });
  }
}

class _HeroPanel extends StatelessWidget {
  final UserSubscriptionDto? active;
  final int? walletBalanceCoins;
  final bool buying;
  final bool hasPlans;
  final VoidCallback onChoosePlan;

  const _HeroPanel({
    required this.active,
    required this.walletBalanceCoins,
    required this.buying,
    required this.hasPlans,
    required this.onChoosePlan,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return _GlassShell(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: tokens.chipColor.withOpacity(.82),
                  borderRadius: BorderRadius.circular(18),
                  border: Border.all(color: tokens.borderColor),
                ),
                child: Icon(
                  Icons.workspace_premium_rounded,
                  color: tokens.primaryButtonGradient.first,
                  size: 28,
                ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    ShaderMask(
                      shaderCallback:
                          (r) => LinearGradient(
                            colors: [
                              tokens.primaryButtonGradient.first,
                              tokens.textPrimary,
                            ],
                          ).createShader(r),
                      child: Text(
                        active?.planName ?? 'Premium access',
                        style: Theme.of(
                          context,
                        ).textTheme.headlineSmall?.copyWith(
                          color: Colors.white,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      active == null
                          ? 'Unlock subscriber-only live rooms with a premium plan.'
                          : 'Your account currently has active subscription access.',
                      style: TextStyle(
                        color: tokens.textSecondary.withOpacity(.86),
                        height: 1.35,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _HeroPill(
                icon:
                    active == null
                        ? Icons.lock_outline_rounded
                        : Icons.verified_rounded,
                label:
                    active == null ? 'Inactive' : active!.status.toUpperCase(),
              ),
              if (active?.endsAt != null)
                _HeroPill(
                  icon: Icons.event_rounded,
                  label:
                      'Ends ${DateFormat.yMMMd().format(active!.endsAt!.toLocal())}',
                ),
              if (walletBalanceCoins != null)
                _HeroPill(
                  icon: Icons.account_balance_wallet_rounded,
                  label:
                      '${NumberFormat.compact().format(walletBalanceCoins)} coins',
                ),
            ],
          ),
          const SizedBox(height: 16),
          SizedBox(
            width: double.infinity,
            child: _PrimaryGlassButton(
              label:
                  active == null ? 'Choose a Subscription Plan' : 'Change Plan',
              icon: Icons.auto_awesome_rounded,
              loading: buying,
              enabled: hasPlans,
              onTap: onChoosePlan,
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  final String title;
  final String subtitle;
  final Widget? trailing;

  const _SectionTitle({
    required this.title,
    required this.subtitle,
    this.trailing,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  color: tokens.textPrimary,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                subtitle,
                style: TextStyle(
                  color: tokens.textSecondary.withOpacity(.8),
                  height: 1.3,
                ),
              ),
            ],
          ),
        ),
        if (trailing != null) ...[const SizedBox(width: 12), trailing!],
      ],
    );
  }
}

class _ActiveSubscriptionCard extends StatelessWidget {
  final UserSubscriptionDto subscription;

  const _ActiveSubscriptionCard({required this.subscription});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return _GlassShell(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  subscription.planName ?? 'Subscription',
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    color: tokens.textPrimary,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              _StatusBadge(
                label: subscription.status.toUpperCase(),
                color: const Color(0xFF6BFFBC),
                background: tokens.chipColor.withOpacity(.88),
              ),
            ],
          ),
          const SizedBox(height: 16),
          _InfoLine(label: 'Status', value: subscription.status.toUpperCase()),
          if (subscription.startsAt != null)
            _InfoLine(
              label: 'Started',
              value: DateFormat.yMMMd().add_jm().format(
                subscription.startsAt!.toLocal(),
              ),
            ),
          if (subscription.endsAt != null)
            _InfoLine(
              label: 'Ends',
              value: DateFormat.yMMMd().add_jm().format(
                subscription.endsAt!.toLocal(),
              ),
            ),
        ],
      ),
    );
  }
}

class _HistoryCard extends StatelessWidget {
  final UserSubscriptionDto subscription;

  const _HistoryCard({required this.subscription});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final statusColor =
        subscription.isExpired
            ? const Color(0xFFFFC56B)
            : subscription.status.toLowerCase() == 'cancelled'
            ? const Color(0xFFFF8AA3)
            : const Color(0xFFD7CCFF);

    return _GlassShell(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  subscription.planName ?? 'Subscription',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    color: tokens.textPrimary,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              _StatusBadge(
                label: subscription.status.toUpperCase(),
                color: statusColor,
                background: tokens.chipColor.withOpacity(.88),
              ),
            ],
          ),
          const SizedBox(height: 14),
          if (subscription.lastPurchasedAt != null)
            _InfoLine(
              label: 'Purchased',
              value: DateFormat.yMMMd().add_jm().format(
                subscription.lastPurchasedAt!.toLocal(),
              ),
            ),
          if (subscription.endsAt != null)
            _InfoLine(
              label: 'Ended',
              value: DateFormat.yMMMd().add_jm().format(
                subscription.endsAt!.toLocal(),
              ),
            ),
        ],
      ),
    );
  }
}

class _GlassEmptyCard extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;

  const _GlassEmptyCard({
    required this.icon,
    required this.title,
    required this.subtitle,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return _GlassShell(
      child: Column(
        children: [
          Icon(icon, size: 42, color: tokens.primaryButtonGradient.first),
          const SizedBox(height: 12),
          Text(
            title,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            subtitle,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.82),
              height: 1.4,
            ),
          ),
        ],
      ),
    );
  }
}

class _GlassMessageCard extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final String actionLabel;
  final VoidCallback onAction;

  const _GlassMessageCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.actionLabel,
    required this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return _GlassShell(
      child: Column(
        children: [
          Icon(icon, size: 48, color: tokens.primaryButtonGradient.first),
          const SizedBox(height: 12),
          Text(
            title,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            subtitle,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.82),
              height: 1.4,
            ),
          ),
          const SizedBox(height: 16),
          _PrimaryGlassButton(
            label: actionLabel,
            icon: Icons.refresh_rounded,
            onTap: onAction,
          ),
        ],
      ),
    );
  }
}

class _GlassShell extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;

  const _GlassShell({
    required this.child,
    this.padding = const EdgeInsets.all(18),
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return ClipRRect(
      borderRadius: BorderRadius.circular(28),
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
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: tokens.borderColor),
            boxShadow: [
              BoxShadow(
                color: tokens.glowColor.withOpacity(.22),
                blurRadius: 22,
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

class _PrimaryGlassButton extends StatelessWidget {
  final String label;
  final IconData icon;
  final bool enabled;
  final bool loading;
  final VoidCallback onTap;

  const _PrimaryGlassButton({
    required this.label,
    required this.icon,
    required this.onTap,
    this.enabled = true,
    this.loading = false,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Opacity(
      opacity: enabled ? 1 : .5,
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(20),
        child: InkWell(
          borderRadius: BorderRadius.circular(20),
          onTap: enabled && !loading ? onTap : null,
          child: Ink(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: tokens.primaryButtonGradient,
              ),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (loading)
                  const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                else
                  Icon(icon, color: tokens.textPrimary),
                const SizedBox(width: 10),
                Text(
                  label,
                  style: TextStyle(
                    color: tokens.textPrimary,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _HeroPill extends StatelessWidget {
  final String label;
  final IconData icon;

  const _HeroPill({required this.label, required this.icon});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: tokens.chipColor.withOpacity(.82),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 14, color: tokens.textPrimary),
          const SizedBox(width: 6),
          Text(
            label,
            style: TextStyle(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w700,
              fontSize: 12,
            ),
          ),
        ],
      ),
    );
  }
}

class _StatusBadge extends StatelessWidget {
  final String label;
  final Color color;
  final Color background;

  const _StatusBadge({
    required this.label,
    required this.color,
    required this.background,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: color,
          fontWeight: FontWeight.w800,
          fontSize: 12,
        ),
      ),
    );
  }
}

class _InfoLine extends StatelessWidget {
  final String label;
  final String value;

  const _InfoLine({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 82,
            child: Text(
              label,
              style: TextStyle(
                color: Colors.white.withOpacity(.64),
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: const TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _GlassyBackdrop extends StatelessWidget {
  final Animation<double> t;

  const _GlassyBackdrop({required this.t});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return AnimatedBuilder(
      animation: t,
      builder:
          (_, __) => CustomPaint(
            painter: _BlobPainter(
              t.value,
              colors: [
                tokens.primaryButtonGradient.first,
                tokens.cardGradient.first,
                tokens.glowColor,
              ],
            ),
          ),
    );
  }
}

class _BlobPainter extends CustomPainter {
  final double t;
  final List<Color> colors;

  _BlobPainter(this.t, {required this.colors});

  @override
  void paint(Canvas canvas, Size size) {
    final w = size.width;
    final h = size.height;

    void blob(Offset base, double r, Color c, double drift, double phase) {
      final dx = math.sin((t * 2 * math.pi) + phase) * drift;
      final dy = math.cos((t * 2 * math.pi) + phase) * (drift * .6);
      final center = base + Offset(dx, dy);
      final paint =
          Paint()
            ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 64)
            ..color = c.withOpacity(.40);
      canvas.drawCircle(center, r, paint);
    }

    blob(Offset(w * .22, h * .18), h * .24, colors[0], 24, 0.0);
    blob(Offset(w * .82, h * .25), h * .22, colors[1], 32, 1.4);
    blob(Offset(w * .52, h * .72), h * .30, colors[2], 26, 2.2);
  }

  @override
  bool shouldRepaint(covariant _BlobPainter oldDelegate) =>
      oldDelegate.t != t || oldDelegate.colors != colors;
}
