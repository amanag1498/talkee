import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';
import '../models/payment_order_dto.dart';
import '../models/wallet_transaction_dto.dart';
import '../services/wallet_api.dart';

PremiumThemeTokens _walletHistoryTokens() =>
    getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );

class WalletHistoryPage extends StatefulWidget {
  const WalletHistoryPage({super.key});

  @override
  State<WalletHistoryPage> createState() => _WalletHistoryPageState();
}

class _WalletHistoryPageState extends State<WalletHistoryPage> {
  static const _orderFilters = <String>['all', 'completed', 'pending', 'failed'];

  String _selectedOrderFilter = 'all';
  bool _loading = true;
  String? _error;
  List<WalletTransactionDto> _transactions = const [];
  List<PaymentOrderDto> _orders = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final api = Get.find<WalletApi>();
      final results = await Future.wait([
        api.fetchTransactions(filter: 'recharge'),
        api.fetchRechargeOrders(),
      ]);
      if (!mounted) return;
      setState(() {
        _transactions = results[0] as List<WalletTransactionDto>;
        _orders = results[1] as List<PaymentOrderDto>;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.toString().replaceFirst('Exception: ', '');
        _loading = false;
      });
    }
  }

  List<PaymentOrderDto> get _filteredOrders {
    if (_selectedOrderFilter == 'all') return _orders;
    return _orders.where((order) => _normalizedStatus(order.status) == _selectedOrderFilter).toList();
  }

  @override
  Widget build(BuildContext context) {
    final filteredOrders = _filteredOrders;
    return Obx(() {
      final tokens = _walletHistoryTokens();
      return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [
              tokens.backgroundGradient.first,
              tokens.cardGradient.first,
              tokens.backgroundGradient.last,
            ],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: SafeArea(
          child: RefreshIndicator(
            onRefresh: _load,
            color: tokens.textPrimary,
            backgroundColor: tokens.primaryButtonGradient.first,
            child: CustomScrollView(
              physics: const BouncingScrollPhysics(),
              slivers: [
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
                    child: Row(
                      children: [
                        IconButton(
                          onPressed: Get.back,
                          style: IconButton.styleFrom(
                            backgroundColor: tokens.glassColor.withOpacity(.72),
                          ),
                          icon: Icon(Icons.arrow_back_ios_new_rounded, color: tokens.textPrimary),
                        ),
                        Expanded(
                          child: Column(
                            children: [
                              Text(
                                'Recharge History',
                                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                                      color: tokens.textPrimary,
                                      fontWeight: FontWeight.w800,
                                    ),
                                textAlign: TextAlign.center,
                              ),
                              const SizedBox(height: 2),
                              Text(
                                'Orders and wallet top-ups',
                                style: TextStyle(
                                  color: tokens.textSecondary.withOpacity(.78),
                                  fontSize: 12,
                                  fontWeight: FontWeight.w500,
                                ),
                                textAlign: TextAlign.center,
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 48),
                      ],
                    ),
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 0, 20, 14),
                    child: _RechargeHero(
                      orders: _orders,
                      transactions: _transactions,
                    ),
                  ),
                ),
                SliverToBoxAdapter(
                  child: SizedBox(
                    height: 44,
                    child: ListView.separated(
                      padding: const EdgeInsets.symmetric(horizontal: 20),
                      scrollDirection: Axis.horizontal,
                      itemBuilder: (context, index) {
                        final filter = _orderFilters[index];
                        final selected = filter == _selectedOrderFilter;
                        return ChoiceChip(
                          label: Text(_labelForFilter(filter)),
                          selected: selected,
                          labelStyle: TextStyle(
                            color: selected ? tokens.textPrimary : tokens.textSecondary.withOpacity(.78),
                            fontWeight: FontWeight.w700,
                          ),
                          selectedColor: tokens.primaryButtonGradient.first.withOpacity(.34),
                          backgroundColor: tokens.glassColor.withOpacity(.7),
                          side: BorderSide(color: tokens.borderColor),
                          onSelected: (_) => setState(() => _selectedOrderFilter = filter),
                        );
                      },
                      separatorBuilder: (_, __) => const SizedBox(width: 8),
                      itemCount: _orderFilters.length,
                    ),
                  ),
                ),
                const SliverToBoxAdapter(child: SizedBox(height: 14)),
                if (_loading)
                  const SliverFillRemaining(
                    hasScrollBody: false,
                    child: Center(
                      child: CircularProgressIndicator(color: Colors.white),
                    ),
                  )
                else if (_error != null)
                  SliverFillRemaining(
                    hasScrollBody: false,
                    child: _HistoryStateCard(
                      icon: Icons.sync_problem_rounded,
                      title: 'Unable to load recharge history',
                      subtitle: _error!,
                      actionLabel: 'Retry',
                      onAction: _load,
                    ),
                  )
                else if (_orders.isEmpty && _transactions.isEmpty)
                  const SliverFillRemaining(
                    hasScrollBody: false,
                    child: _HistoryStateCard(
                      icon: Icons.receipt_long_rounded,
                      title: 'No recharge history yet',
                      subtitle: 'Completed recharges and wallet top-ups will appear here.',
                    ),
                  )
                else ...[
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(20, 0, 20, 10),
                      child: _SectionHeader(
                        title: 'Recharge Orders',
                        subtitle: '${filteredOrders.length} records',
                      ),
                    ),
                  ),
                  if (filteredOrders.isEmpty)
                    const SliverToBoxAdapter(
                      child: Padding(
                        padding: EdgeInsets.fromLTRB(20, 0, 20, 16),
                        child: _InlineEmptyCard(
                          title: 'No orders for this filter',
                          subtitle: 'Try another status to view recharge orders.',
                        ),
                      ),
                    )
                  else
                    SliverPadding(
                      padding: const EdgeInsets.fromLTRB(20, 0, 20, 18),
                      sliver: SliverList.builder(
                        itemCount: filteredOrders.length,
                        itemBuilder: (context, index) => Padding(
                          padding: EdgeInsets.only(bottom: index == filteredOrders.length - 1 ? 0 : 12),
                          child: _RechargeOrderCard(order: filteredOrders[index]),
                        ),
                      ),
                    ),
                  const SliverToBoxAdapter(child: SizedBox(height: 24)),
                ],
              ],
            ),
          ),
        ),
      ),
    );
    });
  }

  static String _normalizedStatus(String raw) {
    final value = raw.toLowerCase().trim();
    if (value == 'paid' || value == 'completed' || value == 'success') return 'completed';
    if (value == 'failed' || value == 'failure') return 'failed';
    return 'pending';
  }

  static String _labelForFilter(String filter) {
    return switch (filter) {
      'completed' => 'Completed',
      'pending' => 'Pending',
      'failed' => 'Failed',
      _ => 'All',
    };
  }
}

class _RechargeHero extends StatelessWidget {
  const _RechargeHero({
    required this.orders,
    required this.transactions,
  });

  final List<PaymentOrderDto> orders;
  final List<WalletTransactionDto> transactions;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final successfulOrders = orders.where((order) => _WalletHistoryPageState._normalizedStatus(order.status) == 'completed');
    final totalSpent = successfulOrders.fold<num>(0, (sum, order) => sum + order.amountRupees);
    final totalCoins = successfulOrders.fold<int>(0, (sum, order) => sum + order.totalCoins);
    final completedCount = successfulOrders.length;

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: tokens.cardGradient,
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: tokens.borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Recharge Overview',
            style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  color: tokens.textPrimary,
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: 6),
          Text(
            'View completed top-ups, payment attempts, and wallet credits in one place.',
            style: TextStyle(
              color: tokens.textSecondary.withValues(alpha: .82),
              fontWeight: FontWeight.w500,
            ),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: _HeadlineMetric(
                  label: 'Total spent',
                  value: '₹${_formatAmount(totalSpent)}',
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _HeadlineMetric(
                  label: 'Coins credited',
                  value: NumberFormat.compact().format(totalCoins),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _HeadlineMetric(
                  label: 'Successful orders',
                  value: completedCount.toString(),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  static String _formatAmount(num value) {
    if (value == value.roundToDouble()) return value.toInt().toString();
    return value.toStringAsFixed(2);
  }
}

class _HeadlineMetric extends StatelessWidget {
  const _HeadlineMetric({
    required this.label,
    required this.value,
  });

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.chipColor.withOpacity(.96),
            tokens.glassColor.withOpacity(.74),
          ],
        ),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: tokens.borderColor.withOpacity(.82)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            label,
            style: TextStyle(
              color: tokens.textSecondary.withValues(alpha: .7),
              fontSize: 11,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: tokens.textPrimary,
              fontSize: 15,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader({
    required this.title,
    required this.subtitle,
  });

  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                    ),
              ),
              const SizedBox(height: 4),
              Text(
                subtitle,
                style: TextStyle(
                  color: Colors.white.withValues(alpha: .58),
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _RechargeOrderCard extends StatelessWidget {
  const _RechargeOrderCard({required this.order});

  final PaymentOrderDto order;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final normalizedStatus = _WalletHistoryPageState._normalizedStatus(order.status);
    final accent = switch (normalizedStatus) {
      'completed' => const Color(0xFF55D38A),
      'failed' => const Color(0xFFFF6B7A),
      _ => kTalkeeGold,
    };
    final totalCoins = order.totalCoins;
    final baseCoins = (order.totalCoins - order.bonusCoins).clamp(0, order.totalCoins);
    final planTitle = order.rechargePlanTitle ?? '$totalCoins Coin Pack';

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: tokens.cardGradient,
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: tokens.borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: .14),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(Icons.bolt_rounded, color: accent),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      planTitle,
                      style: TextStyle(
                        color: tokens.textPrimary,
                        fontSize: 17,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      '₹${_RechargeHero._formatAmount(order.amountRupees)} • $totalCoins coins',
                      style: TextStyle(
                        color: tokens.textSecondary.withValues(alpha: .78),
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      tokens.chipColor.withOpacity(.96),
                      tokens.glassColor.withOpacity(.74),
                    ],
                  ),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: accent.withValues(alpha: .28)),
                ),
                child: Text(
                  _WalletHistoryPageState._labelForFilter(normalizedStatus),
                  style: TextStyle(
                    color: accent,
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _OrderPill(label: 'Base $baseCoins'),
              if (order.bonusCoins > 0) _OrderPill(label: 'Bonus ${order.bonusCoins}'),
              _OrderPill(label: order.gateway.toUpperCase()),
            ],
          ),
          if (order.createdAt != null) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                Icon(
                  Icons.schedule_rounded,
                  size: 14,
                  color: tokens.textSecondary.withValues(alpha: .58),
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    DateFormat('dd MMM yyyy, hh:mm a').format(order.createdAt!.toLocal()),
                    style: TextStyle(
                      color: tokens.textSecondary.withValues(alpha: .66),
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ],
            ),
          ],
          const SizedBox(height: 8),
          Text(
            order.orderId,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: tokens.textSecondary.withValues(alpha: .56),
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

class _OrderPill extends StatelessWidget {
  const _OrderPill({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.chipColor.withOpacity(.96),
            tokens.glassColor.withOpacity(.74),
          ],
        ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: tokens.borderColor.withValues(alpha: .82)),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: tokens.textPrimary.withValues(alpha: .92),
          fontSize: 11.5,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

class _WalletHistoryTile extends StatelessWidget {
  const _WalletHistoryTile({required this.tx});

  final WalletTransactionDto tx;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final positive = tx.type == 'credit';
    final accent = positive ? const Color(0xFF55D38A) : const Color(0xFFFF6B7A);
    final title = tx.description?.isNotEmpty == true
        ? tx.description!
        : tx.category.replaceAll('_', ' ').capitalizeFirst ?? tx.category;

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: tokens.cardGradient,
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: tokens.borderColor),
      ),
      child: Row(
        children: [
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: .14),
              borderRadius: BorderRadius.circular(16),
            ),
            child: Icon(
              positive ? Icons.arrow_downward_rounded : Icons.arrow_upward_rounded,
              color: accent,
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        color: tokens.textPrimary,
                        fontWeight: FontWeight.w800,
                      ),
                ),
                const SizedBox(height: 4),
                Text(
                  tx.createdAt != null
                      ? DateFormat('dd MMM yyyy, hh:mm a').format(tx.createdAt!.toLocal())
                      : 'Date unavailable',
                  style: TextStyle(
                    color: tokens.textSecondary.withValues(alpha: .72),
                    fontWeight: FontWeight.w600,
                  ),
                ),
                if (tx.reference?.isNotEmpty == true) ...[
                  const SizedBox(height: 4),
                  Text(
                    tx.reference!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: tokens.textSecondary.withValues(alpha: .6),
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: 10),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                '${positive ? '+' : '-'}${tx.coins}',
                style: TextStyle(
                  color: accent,
                  fontWeight: FontWeight.w800,
                  fontSize: 17,
                ),
              ),
              if (tx.amount != null) ...[
                const SizedBox(height: 4),
                Text(
                  '${tx.currency ?? 'INR'} ${tx.amount}',
                  style: TextStyle(
                    color: tokens.textSecondary.withValues(alpha: .7),
                    fontWeight: FontWeight.w600,
                    fontSize: 12,
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

class _InlineEmptyCard extends StatelessWidget {
  const _InlineEmptyCard({
    required this.title,
    required this.subtitle,
  });

  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .04),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: Colors.white.withValues(alpha: .08)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 15,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            subtitle,
            style: TextStyle(
              color: Colors.white.withValues(alpha: .62),
              fontWeight: FontWeight.w500,
            ),
          ),
        ],
      ),
    );
  }
}

class _HistoryStateCard extends StatelessWidget {
  const _HistoryStateCard({
    required this.icon,
    required this.title,
    required this.subtitle,
    this.actionLabel,
    this.onAction,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Container(
          padding: const EdgeInsets.all(24),
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: .06),
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: Colors.white.withValues(alpha: .10)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, color: Colors.white, size: 34),
              const SizedBox(height: 14),
              Text(
                title,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                subtitle,
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: Colors.white.withValues(alpha: .64),
                  fontWeight: FontWeight.w500,
                ),
              ),
              if (actionLabel != null && onAction != null) ...[
                const SizedBox(height: 18),
                FilledButton(
                  onPressed: onAction,
                  child: Text(actionLabel!),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
