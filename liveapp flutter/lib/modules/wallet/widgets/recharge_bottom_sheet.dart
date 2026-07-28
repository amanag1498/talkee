import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/theme/brand.dart';
import '../../../app/widgets/haptics.dart';
import '../../../services/auth_service.dart';
import '../../../services/meta_attribution_service.dart';
import '../../../services/app_settings_service.dart';
import '../models/wallet_summary_dto.dart';
import '../services/razorpay_checkout_service.dart';
import '../services/wallet_api.dart';

bool isInsufficientCoinsErrorMessage(String message) {
  final raw = message.trim();
  final normalized = raw.toLowerCase();
  return raw.contains('INSUFFICIENT_FUNDS') ||
      normalized.contains('not enough coins') ||
      normalized.contains('insufficient balance') ||
      normalized.contains('insufficient funds');
}

Future<void> showRechargeWalletSheet({
  String? reasonTitle,
  String? reasonMessage,
}) async {
  if (!Get.find<AppSettingsService>().walletRechargeEnabled) {
    final fallback =
        reasonMessage ?? 'Wallet recharge is currently unavailable.';
    Get.snackbar(
      reasonTitle ?? 'Not enough coins',
      fallback,
      snackPosition: SnackPosition.BOTTOM,
    );
    return;
  }

  if (reasonMessage != null && reasonMessage.trim().isNotEmpty) {
    Get.snackbar(
      reasonTitle ?? 'Not enough coins',
      reasonMessage.trim(),
      snackPosition: SnackPosition.BOTTOM,
      duration: const Duration(seconds: 2),
    );
  }

  await Get.bottomSheet<void>(
    const RechargeBottomSheet(),
    isScrollControlled: true,
  );
}

class RechargeBottomSheet extends StatefulWidget {
  const RechargeBottomSheet({super.key});

  @override
  State<RechargeBottomSheet> createState() => _RechargeBottomSheetState();
}

class _RechargeBottomSheetState extends State<RechargeBottomSheet> {
  WalletSummaryDto? _summary;
  bool _loading = true;
  bool _submitting = false;
  String? _error;
  int? _selectedPlanId;

  @override
  void initState() {
    super.initState();
    if (!Get.find<AppSettingsService>().walletRechargeEnabled) {
      _loading = false;
      _error = 'Wallet recharge is currently unavailable.';
      return;
    }
    _load();
  }

  Future<void> _load() async {
    try {
      setState(() {
        _loading = true;
        _error = null;
      });
      final summary = await Get.find<WalletApi>().fetchSummary();
      if (!mounted) return;
      setState(() {
        _summary = summary;
        _selectedPlanId =
            summary.quickPacks.isNotEmpty ? summary.quickPacks.first.id : null;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.toString().replaceFirst('Exception: ', '');
      });
    }
  }

  WalletPackDto? get _selectedPlan {
    final summary = _summary;
    if (summary == null || _selectedPlanId == null) return null;
    for (final pack in summary.quickPacks) {
      if (pack.id == _selectedPlanId) return pack;
    }
    return null;
  }

  Future<void> _startRecharge() async {
    final summary = _summary;
    final plan = _selectedPlan;
    if (summary == null || plan == null || _submitting) return;

    Haptics.medium();

    if (!summary.paymentReady) {
      Haptics.warning();
      Get.snackbar(
        'Recharge unavailable',
        summary.message ?? 'Payment setup required.',
        snackPosition: SnackPosition.BOTTOM,
      );
      return;
    }

    setState(() => _submitting = true);

    try {
      final api = Get.find<WalletApi>();
      final order = await api.createRechargeOrder(plan.id);
      if (order.gateway != 'razorpay' || order.checkout == null) {
        if (!mounted) return;
        setState(() => _submitting = false);
        Haptics.warning();
        Get.snackbar(
          'Recharge unavailable',
          summary.message ??
              'Payment gateway is not configured correctly on the server.',
          snackPosition: SnackPosition.BOTTOM,
        );
        return;
      }

      final checkoutService = Get.find<RazorpayCheckoutService>();
      final auth = Get.find<AuthService>();
      final checkoutResult = await checkoutService.openCheckout(
        order: order,
        user: auth.currentUser,
      );

      if (checkoutResult.type == RechargeCheckoutResultType.success) {
        try {
          final updated = await api.verifyRechargeOrder(
            order.orderId,
            result: 'success',
            gatewayPaymentId: checkoutResult.paymentId,
            gatewayOrderId: checkoutResult.orderId ?? order.gatewayOrderId,
            gatewaySignature: checkoutResult.signature,
            gatewayResponse: checkoutResult.raw,
          );
          if (Get.isRegistered<MetaAttributionService>()) {
            await Get.find<MetaAttributionService>().logVerifiedPurchase(
              amountInr: order.amountRupees.toDouble(),
              orderId: order.orderId,
            );
          }
          if (!mounted) return;
          setState(() {
            _summary = updated;
            _submitting = false;
          });
          Haptics.success();
          Get.snackbar(
            'Recharge successful',
            '${plan.totalCoins} coins added to your wallet.',
            snackPosition: SnackPosition.BOTTOM,
          );
        } catch (_) {
          WalletSummaryDto? refreshed;
          try {
            refreshed = await api.fetchSummary();
          } catch (_) {}
          if (!mounted) return;
          setState(() {
            _summary = refreshed ?? _summary;
            _submitting = false;
          });
          Haptics.warning();
          Get.snackbar(
            'Payment received',
            'Your recharge is awaiting confirmation. Coins will appear automatically once verified.',
            snackPosition: SnackPosition.BOTTOM,
            duration: const Duration(seconds: 4),
          );
        }
        return;
      }

      final result =
          checkoutResult.type == RechargeCheckoutResultType.cancelled
              ? 'cancelled'
              : 'failed';
      await api.verifyRechargeOrder(
        order.orderId,
        result: result,
        gatewayPaymentId: checkoutResult.paymentId,
        gatewayOrderId: checkoutResult.orderId ?? order.gatewayOrderId,
        gatewaySignature: checkoutResult.signature,
        gatewayResponse: {
          ...checkoutResult.raw,
          if (checkoutResult.code != null) 'code': checkoutResult.code,
          if (checkoutResult.message != null) 'message': checkoutResult.message,
        },
      );
      if (!mounted) return;
      setState(() => _submitting = false);
      Haptics.warning();
      Get.snackbar(
        result == 'cancelled' ? 'Payment cancelled' : 'Payment failed',
        result == 'cancelled'
            ? 'No coins were added.'
            : (checkoutResult.message?.trim().isNotEmpty == true
                ? checkoutResult.message!.trim()
                : 'Please try again.'),
        snackPosition: SnackPosition.BOTTOM,
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _submitting = false);
      Haptics.error();
      Get.snackbar(
        'Recharge failed',
        e.toString().replaceFirst('Exception: ', ''),
        snackPosition: SnackPosition.BOTTOM,
      );
    }
  }

  int _popularIndexFor(List<WalletPackDto> packs) {
    if (packs.isEmpty) return -1;
    var bestIndex = 0;
    var bestValue = -1.0;
    for (var i = 0; i < packs.length; i++) {
      final price = (packs[i].price ?? 0).toDouble();
      if (price <= 0) continue;
      final value = packs[i].totalCoins / price;
      if (value > bestValue) {
        bestValue = value;
        bestIndex = i;
      }
    }
    if ((packs.length == 3 || packs.length == 4) &&
        (bestIndex == 0 || bestIndex == packs.length - 1)) {
      return packs.length ~/ 2;
    }
    return bestIndex;
  }

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final media = MediaQuery.of(context);
    final maxHeight = media.size.height * .92;

    return SafeArea(
      top: false,
      child: Align(
        alignment: Alignment.bottomCenter,
        child: ConstrainedBox(
          constraints: BoxConstraints(
            maxHeight: maxHeight,
            minHeight: media.size.height * .48,
          ),
          child: Container(
            decoration: BoxDecoration(
              color: tokens.backgroundGradient.first,
              borderRadius: BorderRadius.vertical(top: Radius.circular(32)),
            ),
            child: Column(
              children: [
                const SizedBox(height: 10),
                Container(
                  width: 44,
                  height: 5,
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: .18),
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(18, 14, 18, 14),
                    child: Column(
                      children: [
                        const SizedBox(height: 16),
                        _BalanceCard(balance: _summary?.balance ?? 0),
                        const SizedBox(height: 14),
                        _PaymentNotice(summary: _summary),
                        const SizedBox(height: 14),
                        Expanded(child: _buildBody()),
                        const SizedBox(height: 14),
                        _FooterBar(
                          submitting: _submitting,
                          selectedPlan: _selectedPlan,
                          paymentReady: _summary?.paymentReady ?? false,
                          onContinue: _startRecharge,
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(
          strokeWidth: 2.6,
          color: Color(0xFF7C8CFF),
        ),
      );
    }

    if (_error != null) {
      return _StatusCard(
        icon: Icons.error_outline_rounded,
        title: 'Unable to load recharge packs',
        message: _error!,
        actionLabel: 'Retry',
        onTap: _load,
      );
    }

    final summary = _summary;
    if (summary == null || summary.quickPacks.isEmpty) {
      return _StatusCard(
        icon: Icons.wallet_giftcard_rounded,
        title: 'No recharge packs available',
        message: 'Recharge plans will appear here once they are configured.',
        actionLabel: 'Refresh',
        onTap: _load,
      );
    }

    final packs = summary.quickPacks;
    final highlightedIndex = _popularIndexFor(packs);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const Text(
              'Choose a pack',
              style: TextStyle(
                color: Colors.white,
                fontSize: 16,
                fontWeight: FontWeight.w800,
              ),
            ),
            const Spacer(),
            Text(
              '${packs.length} options',
              style: TextStyle(
                color: Colors.white.withValues(alpha: .54),
                fontSize: 12,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        Expanded(
          child: GridView.builder(
            padding: EdgeInsets.zero,
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 2,
              mainAxisSpacing: 12,
              crossAxisSpacing: 12,
              childAspectRatio: .98,
            ),
            itemCount: packs.length,
            itemBuilder: (context, index) {
              final pack = packs[index];
              final selected = pack.id == _selectedPlanId;
              final highlighted = index == highlightedIndex;

              return _PlanCard(
                pack: pack,
                selected: selected,
                highlighted: highlighted,
                onTap: () {
                  Haptics.selection();
                  setState(() => _selectedPlanId = pack.id);
                },
              );
            },
          ),
        ),
      ],
    );
  }

  static String _formatPrice(num? value) {
    final amount = value ?? 0;
    if (amount == amount.roundToDouble()) return amount.toInt().toString();
    return amount.toStringAsFixed(2);
  }
}

class _BalanceCard extends StatelessWidget {
  const _BalanceCard({required this.balance});

  final int balance;

  @override
  Widget build(BuildContext context) {
    return _GlassShell(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      child: Row(
        children: [
          Container(
            width: 52,
            height: 52,
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: .10),
              borderRadius: BorderRadius.circular(18),
            ),
            child: const Icon(Icons.savings_rounded, color: Colors.white),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Available balance',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: .66),
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  '$balance coins',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 28,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _PaymentNotice extends StatelessWidget {
  const _PaymentNotice({required this.summary});

  final WalletSummaryDto? summary;

  @override
  Widget build(BuildContext context) {
    final ready = summary?.paymentReady ?? false;
    final message = summary?.message;

    final title =
        !ready
            ? 'Payment setup required'
            : (message?.isNotEmpty == true
                ? message!
                : 'Secure payment with instant wallet credit');
    final icon =
        !ready ? Icons.warning_amber_rounded : Icons.verified_user_rounded;
    final bg =
        !ready
            ? const Color(0xFFFFD36E).withValues(alpha: .12)
            : Colors.white.withValues(alpha: .04);
    final border =
        !ready
            ? const Color(0xFFFFD36E).withValues(alpha: .18)
            : Colors.white.withValues(alpha: .08);
    final textColor =
        !ready ? const Color(0xFFFFE3A9) : const Color(0xFFD0CDDC);

    return _GlassShell(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        children: [
          Icon(icon, color: textColor, size: 18),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              title,
              style: TextStyle(
                color: textColor,
                fontSize: 12,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _FooterBar extends StatelessWidget {
  const _FooterBar({
    required this.submitting,
    required this.selectedPlan,
    required this.paymentReady,
    required this.onContinue,
  });

  final bool submitting;
  final WalletPackDto? selectedPlan;
  final bool paymentReady;
  final VoidCallback onContinue;

  @override
  Widget build(BuildContext context) {
    final enabled = !submitting && paymentReady && selectedPlan != null;
    final label =
        submitting
            ? 'Processing...'
            : !paymentReady
            ? 'Payment Setup Required'
            : selectedPlan == null
            ? 'Select a Pack'
            : 'Continue with ₹${_RechargeBottomSheetState._formatPrice(selectedPlan!.price)}';

    final secondaryLabel =
        selectedPlan == null ? null : 'Add ${selectedPlan!.totalCoins} coins';

    return _GlassShell(
      padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
      child: Row(
        children: [
          Expanded(
            child: _PrimaryGlassButton(
              enabled: enabled,
              loading: submitting,
              onTap: onContinue,
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 180),
                child:
                    submitting
                        ? Row(
                          key: const ValueKey('loading'),
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Colors.white,
                              ),
                            ),
                            const SizedBox(width: 10),
                            Text(label),
                          ],
                        )
                        : Column(
                          key: const ValueKey('idle'),
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(label),
                            if (secondaryLabel != null && paymentReady)
                              Text(
                                secondaryLabel,
                                style: TextStyle(
                                  color: const Color(
                                    0xFF2A1E4F,
                                  ).withValues(alpha: .72),
                                  fontSize: 11,
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                          ],
                        ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({
    required this.pack,
    required this.selected,
    required this.highlighted,
    required this.onTap,
  });

  final WalletPackDto pack;
  final bool selected;
  final bool highlighted;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final price = _RechargeBottomSheetState._formatPrice(pack.price);

    return AnimatedContainer(
      duration: const Duration(milliseconds: 180),
      curve: Curves.easeOut,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: tokens.cardGradient,
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(
          color:
              selected
                  ? tokens.textPrimary
                  : (highlighted
                      ? tokens.successColor.withValues(alpha: .46)
                      : tokens.borderColor),
          width: selected ? 1.4 : 1,
        ),
        boxShadow:
            selected
                ? [
                  BoxShadow(
                    color: tokens.glowColor.withOpacity(.22),
                    blurRadius: 18,
                    offset: Offset(0, 10),
                  ),
                ]
                : null,
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(22),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    if (highlighted)
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 8,
                          vertical: 4,
                        ),
                        decoration: BoxDecoration(
                          color: tokens.successColor.withValues(alpha: .14),
                          borderRadius: BorderRadius.circular(999),
                          border: Border.all(
                            color: tokens.successColor.withValues(alpha: .24),
                          ),
                        ),
                        child: Text(
                          'Best value',
                          style: TextStyle(
                            color: tokens.successColor.withValues(alpha: .92),
                            fontSize: 10,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      )
                    else
                      const SizedBox(height: 18),
                    const Spacer(),
                    Container(
                      width: 20,
                      height: 20,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        border: Border.all(
                          color:
                              selected
                                  ? tokens.primaryButtonGradient.first
                                  : tokens.borderColor.withValues(alpha: .9),
                          width: 1.6,
                        ),
                        color:
                            selected
                                ? tokens.primaryButtonGradient.first
                                : Colors.transparent,
                      ),
                      child:
                          selected
                              ? Icon(
                                Icons.check_rounded,
                                size: 12,
                                color: tokens.cardGradient.last,
                              )
                              : null,
                    ),
                  ],
                ),
                const Spacer(),
                Text(
                  '₹$price',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 24,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  pack.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: .70),
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  '${pack.totalCoins} coins',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  pack.bonusCoins > 0
                      ? '+${pack.bonusCoins} bonus included'
                      : '${pack.baseCoins} base coins',
                  style: TextStyle(
                    color:
                        pack.bonusCoins > 0
                            ? const Color(0xFF8AE7D9)
                            : Colors.white.withValues(alpha: .52),
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
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

class _StatusCard extends StatelessWidget {
  const _StatusCard({
    required this.icon,
    required this.title,
    required this.message,
    required this.actionLabel,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String message;
  final String actionLabel;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: _GlassShell(
        width: double.infinity,
        padding: const EdgeInsets.all(22),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, color: Colors.white.withValues(alpha: .82), size: 28),
            const SizedBox(height: 12),
            Text(
              title,
              textAlign: TextAlign.center,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 16,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              message,
              textAlign: TextAlign.center,
              style: TextStyle(
                color: Colors.white.withValues(alpha: .60),
                fontSize: 13,
                fontWeight: FontWeight.w500,
              ),
            ),
            const SizedBox(height: 16),
            _PrimaryGlassButton(onTap: onTap, child: Text(actionLabel)),
          ],
        ),
      ),
    );
  }
}

class _GlassShell extends StatelessWidget {
  const _GlassShell({
    required this.child,
    this.padding = const EdgeInsets.all(18),
    this.width,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final double? width;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return ClipRRect(
      borderRadius: BorderRadius.circular(24),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: Container(
          width: width,
          padding: padding,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(24),
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: tokens.cardGradient,
            ),
            border: Border.all(color: tokens.borderColor),
            boxShadow: [
              BoxShadow(
                color: tokens.glowColor.withOpacity(.16),
                blurRadius: 24,
                offset: Offset(0, 10),
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
  const _PrimaryGlassButton({
    required this.child,
    required this.onTap,
    this.enabled = true,
    this.loading = false,
  });

  final Widget child;
  final VoidCallback onTap;
  final bool enabled;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Opacity(
      opacity: enabled ? 1 : .5,
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: enabled && !loading ? onTap : null,
          child: Ink(
            padding: const EdgeInsets.symmetric(vertical: 15, horizontal: 14),
            decoration: BoxDecoration(
              gradient: LinearGradient(colors: tokens.primaryButtonGradient),
              borderRadius: BorderRadius.circular(18),
            ),
            child: DefaultTextStyle.merge(
              style: TextStyle(
                color: tokens.textPrimary,
                fontWeight: FontWeight.w800,
              ),
              child: IconTheme(
                data: IconThemeData(color: tokens.textPrimary),
                child: Center(child: child),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
