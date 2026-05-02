import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../services/auth_service.dart';
import '../../../services/call_service.dart';
import '../../../services/app_settings_service.dart';

PremiumThemeTokens _callHistoryTokens() =>
    getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );

class CallHistoryView extends StatefulWidget {
  const CallHistoryView({super.key});

  @override
  State<CallHistoryView> createState() => _CallHistoryViewState();
}

class _CallHistoryViewState extends State<CallHistoryView> {
  final CallService _service = Get.find<CallService>();
  final int? _currentUserId = Get.find<AuthService>().currentUser?.id;
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _items = const <Map<String, dynamic>>[];
  Map<String, dynamic> _summary = const <String, dynamic>{};

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
      final data = await _service.fetchHistory();
      if (!mounted) return;
      setState(() {
        _items = (data['items'] as List?)
                ?.map((e) => Map<String, dynamic>.from(e as Map))
                .toList() ??
            <Map<String, dynamic>>[];
        _summary = Map<String, dynamic>.from(
          data['summary'] as Map? ?? const <String, dynamic>{},
        );
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

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Obx(() {
      final tokens = _callHistoryTokens();
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
                    padding: const EdgeInsets.fromLTRB(20, 12, 20, 10),
                    child: Row(
                      children: [
                        IconButton(
                          onPressed: Get.back,
                          style: IconButton.styleFrom(
                            backgroundColor: tokens.glassColor.withOpacity(.72),
                          ),
                          icon: Icon(
                            Icons.arrow_back_ios_new_rounded,
                            color: tokens.textPrimary,
                          ),
                        ),
                        Expanded(
                          child: Text(
                            'Call History',
                            textAlign: TextAlign.center,
                            style: theme.textTheme.titleLarge?.copyWith(
                              color: tokens.textPrimary,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                        const SizedBox(width: 48),
                      ],
                    ),
                  ),
                ),
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(20, 4, 20, 16),
                    child: _CallHistoryHero(
                      summary: _summary,
                      items: _items,
                      currentUserId: _currentUserId,
                    ),
                  ),
                ),
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
                    child: _StateCard(
                      icon: Icons.sync_problem_rounded,
                      title: 'Unable to load call history',
                      subtitle: _error!,
                      actionLabel: 'Retry',
                      onAction: _load,
                    ),
                  )
                else if (_items.isEmpty)
                  const SliverFillRemaining(
                    hasScrollBody: false,
                    child: _StateCard(
                      icon: Icons.phone_disabled_rounded,
                      title: 'No calls yet',
                      subtitle: 'Your recent audio and video calls will appear here.',
                    ),
                  )
                else
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
                    sliver: SliverList.separated(
                      itemCount: _items.length,
                      itemBuilder: (context, index) {
                        final item = _items[index];
                        return TweenAnimationBuilder<double>(
                          duration: Duration(milliseconds: 220 + (index * 40)),
                          tween: Tween(begin: .92, end: 1),
                          curve: Curves.easeOutCubic,
                          builder: (context, value, child) => Opacity(
                            opacity: value.clamp(0, 1),
                            child: Transform.translate(
                              offset: Offset(0, (1 - value) * 18),
                              child: child,
                            ),
                          ),
                          child: _CallHistoryTile(
                            item: item,
                            currentUserId: _currentUserId,
                          ),
                        );
                      },
                      separatorBuilder: (_, __) => const SizedBox(height: 12),
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
    });
  }
}

class _CallHistoryHero extends StatelessWidget {
  const _CallHistoryHero({
    required this.summary,
    required this.items,
    required this.currentUserId,
  });

  final Map<String, dynamic> summary;
  final List<Map<String, dynamic>> items;
  final int? currentUserId;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final outgoingCount = items.where((item) => _roleFor(item) == _CallPerspective.outgoing).length;
    final incomingCount = items.where((item) => _roleFor(item) == _CallPerspective.incoming).length;
    final hostedCount = items.where((item) => _roleFor(item) == _CallPerspective.hosted).length;

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
        boxShadow: [
          BoxShadow(
            color: tokens.glowColor.withValues(alpha: .18),
            blurRadius: 26,
            offset: const Offset(0, 14),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Call Overview',
            style: Theme.of(context).textTheme.titleLarge?.copyWith(
                  color: tokens.textPrimary,
                  fontWeight: FontWeight.w800,
                ),
          ),
          const SizedBox(height: 6),
          Text(
            'Track completed calls, call duration, and charged coins in one place.',
            style: TextStyle(
              color: tokens.textSecondary.withValues(alpha: .84),
              fontWeight: FontWeight.w500,
            ),
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _OverviewChip(label: 'Outgoing', value: outgoingCount.toString()),
              _OverviewChip(label: 'Incoming', value: incomingCount.toString()),
              _OverviewChip(label: 'Host Side', value: hostedCount.toString()),
            ],
          ),
          const SizedBox(height: 14),
          Container(
            child: Row(
              children: [
                Expanded(
                  child: _HeadlineMetric(
                    label: 'Completed',
                    value: _int(summary['completed_calls']),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _HeadlineMetric(
                    label: 'Minutes',
                    value: _int(summary['total_minutes']),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _HeadlineMetric(
                    label: 'Coins',
                    value: _int(summary['total_coins_charged']),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  String _int(dynamic value) => NumberFormat.compact().format((value as num?)?.toInt() ?? 0);

  _CallPerspective _roleFor(Map<String, dynamic> item) {
    final callerId = (item['caller_id'] as num?)?.toInt();
    final receiverId = (item['receiver_id'] as num?)?.toInt();
    if (currentUserId != null && callerId == currentUserId) return _CallPerspective.outgoing;
    if (currentUserId != null && receiverId == currentUserId) {
      final roles = ((item['receiver'] as Map?)?['roles'] as List?)?.map((e) => e.toString()).toList() ?? const <String>[];
      if (roles.contains('host')) return _CallPerspective.hosted;
      return _CallPerspective.incoming;
    }
    return _CallPerspective.unknown;
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
            tokens.glassColor.withOpacity(.76),
          ],
        ),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: tokens.borderColor.withOpacity(.82)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
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

class _OverviewChip extends StatelessWidget {
  const _OverviewChip({
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
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: tokens.chipColor.withValues(alpha: .82),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor.withValues(alpha: .8)),
      ),
      child: RichText(
        text: TextSpan(
          style: const TextStyle(fontFamily: 'inherit'),
          children: [
            TextSpan(
              text: '$label: ',
              style: TextStyle(
                color: tokens.textSecondary.withValues(alpha: .78),
                fontWeight: FontWeight.w700,
                fontSize: 12,
              ),
            ),
            TextSpan(
              text: value,
              style: TextStyle(
                color: tokens.textPrimary,
                fontWeight: FontWeight.w800,
                fontSize: 12,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

enum _CallPerspective { outgoing, incoming, hosted, unknown }

class _CallHistoryTile extends StatelessWidget {
  const _CallHistoryTile({
    required this.item,
    required this.currentUserId,
  });

  final Map<String, dynamic> item;
  final int? currentUserId;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final type = (item['type'] ?? 'audio').toString().toLowerCase();
    final status = (item['status'] ?? 'unknown').toString().toLowerCase();
    final endedReason = (item['end_reason'] ?? '').toString();
    final createdAt = DateTime.tryParse((item['created_at'] ?? '').toString());
    final durationSeconds = (item['duration_seconds'] as num?)?.toInt() ?? 0;
    final billableMinutes = (item['billable_minutes'] as num?)?.toInt() ?? 0;
    final chargedCoins = (item['total_coins_charged'] as num?)?.toInt() ?? 0;
    final perspective = _perspective();
    final counterpart = _counterpartyLabel();
    final hostLabel = _hostLabel();
    final roleLabel = switch (perspective) {
      _CallPerspective.outgoing => 'You called',
      _CallPerspective.incoming => 'You received',
      _CallPerspective.hosted => 'Received by host',
      _CallPerspective.unknown => 'Call record',
    };
    final detailLabel = switch (perspective) {
      _CallPerspective.outgoing => counterpart,
      _CallPerspective.incoming => counterpart,
      _CallPerspective.hosted => counterpart == hostLabel ? hostLabel : '$counterpart via $hostLabel',
      _CallPerspective.unknown => counterpart,
    };

    final accent = switch (status) {
      'ended' => const Color(0xFF55D38A),
      'accepted' => const Color(0xFF7D9BFF),
      'rejected' || 'failed' => const Color(0xFFFF6B7A),
      'missed' => tokens.dangerColor,
      _ => tokens.primaryButtonGradient.first,
    };

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
      child: Column(
        children: [
          Row(
            children: [
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: .14),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Icon(
                  type == 'video' ? Icons.videocam_rounded : Icons.call_rounded,
                  color: accent,
                ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      type == 'video' ? 'Video Call' : 'Audio Call',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 16,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      createdAt != null ? DateFormat('dd MMM, hh:mm a').format(createdAt.toLocal()) : 'Unknown time',
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: .62),
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
              _StatusBadge(status: status, accent: accent),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: _CallPartyCard(
                  title: roleLabel,
                  subtitle: detailLabel,
                  accent: accent,
                  icon: switch (perspective) {
                    _CallPerspective.outgoing => Icons.north_east_rounded,
                    _CallPerspective.incoming => Icons.south_west_rounded,
                    _CallPerspective.hosted => Icons.mic_rounded,
                    _CallPerspective.unknown => Icons.phone_rounded,
                  },
                ),
              ),
              const SizedBox(width: 10),
              if (hostLabel.isNotEmpty && perspective != _CallPerspective.hosted)
                Expanded(
                  child: _CallPartyCard(
                    title: 'Host',
                    subtitle: hostLabel,
                    accent: kTalkeePrimary,
                    icon: Icons.badge_rounded,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: _DetailPill(
                  label: 'Duration',
                  value: _duration(durationSeconds),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _DetailPill(
                  label: 'Billed',
                  value: '$billableMinutes min',
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _DetailPill(
                  label: 'Coins',
                  value: chargedCoins.toString(),
                  accent: kTalkeeGold,
                ),
              ),
            ],
          ),
          if (endedReason.isNotEmpty) ...[
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerLeft,
              child: Text(
                'Reason: ${endedReason.replaceAll('_', ' ')}',
                style: TextStyle(
                  color: Colors.white.withValues(alpha: .56),
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }

  _CallPerspective _perspective() {
    final callerId = (item['caller_id'] as num?)?.toInt();
    final receiverId = (item['receiver_id'] as num?)?.toInt();
    if (currentUserId != null && callerId == currentUserId) {
      return _CallPerspective.outgoing;
    }
    if (currentUserId != null && receiverId == currentUserId) {
      final roles = ((item['receiver'] as Map?)?['roles'] as List?)?.map((e) => e.toString()).toList() ?? const <String>[];
      if (roles.contains('host')) return _CallPerspective.hosted;
      return _CallPerspective.incoming;
    }
    return _CallPerspective.unknown;
  }

  String _counterpartyLabel() {
    final caller = Map<String, dynamic>.from(item['caller'] as Map? ?? const <String, dynamic>{});
    final receiver = Map<String, dynamic>.from(item['receiver'] as Map? ?? const <String, dynamic>{});
    final perspective = _perspective();
    if (perspective == _CallPerspective.outgoing) {
      return (receiver['name'] ?? 'Unknown receiver').toString();
    }
    if (perspective == _CallPerspective.incoming || perspective == _CallPerspective.hosted) {
      return (caller['name'] ?? 'Unknown caller').toString();
    }
    return (receiver['name'] ?? caller['name'] ?? 'Unknown party').toString();
  }

  String _hostLabel() {
    final host = Map<String, dynamic>.from(item['host'] as Map? ?? const <String, dynamic>{});
    final hostUser = Map<String, dynamic>.from(host['user'] as Map? ?? const <String, dynamic>{});
    return (hostUser['name'] ?? host['stage_name'] ?? '').toString();
  }

  String _duration(int seconds) {
    final mins = seconds ~/ 60;
    final secs = seconds % 60;
    return '${mins.toString().padLeft(2, '0')}:${secs.toString().padLeft(2, '0')}';
  }
}

class _CallPartyCard extends StatelessWidget {
  const _CallPartyCard({
    required this.title,
    required this.subtitle,
    required this.accent,
    required this.icon,
  });

  final String title;
  final String subtitle;
  final Color accent;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .05),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withValues(alpha: .06)),
      ),
      child: Row(
        children: [
          Container(
            width: 34,
            height: 34,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: .14),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: accent, size: 18),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: .56),
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  subtitle,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
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

class _StatusBadge extends StatelessWidget {
  const _StatusBadge({required this.status, required this.accent});

  final String status;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: accent.withValues(alpha: .12),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: accent.withValues(alpha: .18)),
      ),
      child: Text(
        status.replaceAll('_', ' ').capitalizeFirst ?? status,
        style: TextStyle(
          color: accent,
          fontSize: 12,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}

class _DetailPill extends StatelessWidget {
  const _DetailPill({
    required this.label,
    required this.value,
    this.accent = Colors.white,
  });

  final String label;
  final String value;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .05),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: TextStyle(
              color: Colors.white.withValues(alpha: .52),
              fontSize: 11,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            value,
            style: TextStyle(
              color: accent,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}

class _StateCard extends StatelessWidget {
  const _StateCard({
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
