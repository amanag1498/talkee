import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../app/utils/avatar_url.dart';
import '../../../services/api_client.dart';
import '../../../services/app_settings_service.dart';
import '../controllers/dashboard_controller.dart';
import '../models/leaderboard_dto.dart';

PremiumThemeTokens _dashboardTokens() =>
    getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );

class DashboardPage extends StatefulWidget {
  const DashboardPage({
    super.key,
    this.bottomPadding = 120,
    this.isActive = true,
  });

  final double bottomPadding;
  final bool isActive;

  @override
  State<DashboardPage> createState() => _DashboardPageState();
}

class _DashboardPageState extends State<DashboardPage>
    with WidgetsBindingObserver {
  DashboardController get controller => Get.find<DashboardController>();
  _BoardGroup _group = _BoardGroup.users;
  _BoardPeriod _period = _BoardPeriod.weekly;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    if (controller.leaderboards.value == null) {
      controller.load();
    }
    _syncRefreshState();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    controller.stopAutoRefresh();
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant DashboardPage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.isActive != widget.isActive) {
      _syncRefreshState();
      if (widget.isActive) {
        controller.load(silent: true);
      }
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _syncRefreshState();
      if (widget.isActive) {
        controller.load(silent: true);
      }
    } else if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.inactive ||
        state == AppLifecycleState.detached) {
      controller.stopAutoRefresh();
    }
  }

  void _syncRefreshState() {
    if (widget.isActive) {
      controller.startAutoRefresh();
    } else {
      controller.stopAutoRefresh();
    }
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _dashboardTokens();
    final api = Get.find<ApiClient>();

    return Obx(() {
      final data = controller.leaderboards.value;

      if (controller.isLoading.value && data == null) {
        return const Center(child: CircularProgressIndicator(color: Colors.white));
      }

      if (data == null) {
        return Center(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24),
            child: Text(
              controller.error.value ?? 'Unable to load dashboard.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.white70),
            ),
          ),
        );
      }

      final focusedEntries = _focusedEntries(data, api);
      final focusedMeta = _focusedMeta();
      return RefreshIndicator(
        onRefresh: () => controller.load(silent: true),
        color: Colors.white,
        backgroundColor: tokens.primaryButtonGradient.last,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(
            parent: BouncingScrollPhysics(),
          ),
          padding: EdgeInsets.fromLTRB(14, 10, 14, widget.bottomPadding),
          children: [
            _WeekStatusCard(
              label: _thisWeekLabel(),
              countdown: _weeklyResetCountdownLabel(),
            ),
            const SizedBox(height: 12),
            _ModeSwitcher<_BoardGroup>(
              value: _group,
              options: const [
                _ModeOption(_BoardGroup.users, 'Users'),
                _ModeOption(_BoardGroup.hosts, 'Hosts'),
                _ModeOption(_BoardGroup.agencies, 'Agencies'),
              ],
              onChanged: (value) => setState(() => _group = value),
            ),
            const SizedBox(height: 10),
            _ModeSwitcher<_BoardPeriod>(
              value: _period,
              options: const [
                _ModeOption(_BoardPeriod.weekly, 'This Week'),
                _ModeOption(_BoardPeriod.alltime, 'All Time'),
              ],
              onChanged: (value) => setState(() => _period = value),
            ),
            const SizedBox(height: 12),
            _SectionCard(
              icon: focusedMeta.icon,
              accent: focusedMeta.accent,
              title: focusedMeta.title,
              subtitle: focusedMeta.subtitle,
              child: _FocusedLeaderboard(entries: focusedEntries),
            ),
          ],
        ),
      );
    });
  }

  List<_SpotlightEntry> _focusedEntries(
    DashboardLeaderboardsDto data,
    ApiClient api,
  ) {
    switch (_group) {
      case _BoardGroup.users:
        final items = _period == _BoardPeriod.weekly
            ? data.usersWeekly
            : data.usersAlltime;
        return items.map((item) {
          final subtitle = _period == _BoardPeriod.weekly
              ? [
                  if (item.giftCoins > 0) 'Gift ${_compact(item.giftCoins)}',
                  if (item.callCoins > 0) 'Call ${_compact(item.callCoins)}',
                  if (item.subscriptionCoins > 0) 'Sub ${_compact(item.subscriptionCoins)}',
                  if (item.entryCoins > 0) 'Entry ${_compact(item.entryCoins)}',
                ].join(' • ')
              : (item.level != null ? 'Level ${item.level}' : 'User');
          return _SpotlightEntry(
            rank: item.rank,
            avatarUrl: resolveAvatarUrl(api, item.avatar),
            title: item.name,
            subtitle: subtitle.isEmpty ? 'User' : subtitle,
            value: _period == _BoardPeriod.weekly
                ? item.totalCoins
                : item.lifetimeSpendCoins,
            valueLabel: _period == _BoardPeriod.weekly
                ? 'weekly coins'
                : 'lifetime coins',
          );
        }).toList(growable: false);
      case _BoardGroup.hosts:
        final items = _period == _BoardPeriod.weekly
            ? data.hostsWeekly
            : data.hostsAlltime;
        return items
            .map(
              (item) => _SpotlightEntry(
                rank: item.rank,
                avatarUrl: resolveAvatarUrl(api, item.avatar),
                title: item.name,
                subtitle:
                    'Gift ${_compact(item.giftCoins)} • Call ${_compact(item.callCoins)}',
                value: item.totalCoins,
                valueLabel: 'gross coins',
              ),
            )
            .toList(growable: false);
      case _BoardGroup.agencies:
        final items = _period == _BoardPeriod.weekly
            ? data.agenciesWeekly
            : data.agenciesAlltime;
        return items
            .map(
              (item) => _SpotlightEntry(
                rank: item.rank,
                avatarUrl: null,
                title: item.name,
                subtitle:
                    'Gift ${_compact(item.giftCoins)} • Call ${_compact(item.callCoins)}',
                value: item.totalCoins,
                valueLabel: 'gross coins',
                fallbackIcon: Icons.apartment_rounded,
              ),
            )
            .toList(growable: false);
    }
  }

  _BoardMeta _focusedMeta() {
    switch ((_group, _period)) {
      case (_BoardGroup.users, _BoardPeriod.weekly):
        return const _BoardMeta(
          title: 'Weekly User Race',
          subtitle: 'This Week • gifts + calls + subscriptions + entries',
          icon: Icons.whatshot_rounded,
          accent: Color(0xFFFF8966),
        );
      case (_BoardGroup.users, _BoardPeriod.alltime):
        return const _BoardMeta(
          title: 'All-Time User Royals',
          subtitle: 'Lifetime spend leaders across the app',
          icon: Icons.workspace_premium_rounded,
          accent: Color(0xFFFFD66B),
        );
      case (_BoardGroup.hosts, _BoardPeriod.weekly):
        return const _BoardMeta(
          title: 'Weekly Host Clash',
          subtitle: 'This Week • gross gift + call coins',
          icon: Icons.live_tv_rounded,
          accent: Color(0xFF73E0A9),
        );
      case (_BoardGroup.hosts, _BoardPeriod.alltime):
        return const _BoardMeta(
          title: 'All-Time Host Titans',
          subtitle: 'Gross leaders across live rooms and calls',
          icon: Icons.emoji_events_rounded,
          accent: Color(0xFF7DD3FC),
        );
      case (_BoardGroup.agencies, _BoardPeriod.weekly):
        return const _BoardMeta(
          title: 'Weekly Agency Power',
          subtitle: 'This Week • host gross rolled up',
          icon: Icons.apartment_rounded,
          accent: Color(0xFFB794F4),
        );
      case (_BoardGroup.agencies, _BoardPeriod.alltime):
        return const _BoardMeta(
          title: 'All-Time Agency Empires',
          subtitle: 'Strongest agency networks by gross coin power',
          icon: Icons.domain_add_rounded,
          accent: Color(0xFFA5F3FC),
        );
    }
  }

}

enum _BoardGroup { users, hosts, agencies }

enum _BoardPeriod { weekly, alltime }

class _BoardMeta {
  const _BoardMeta({
    required this.title,
    required this.subtitle,
    required this.icon,
    required this.accent,
  });

  final String title;
  final String subtitle;
  final IconData icon;
  final Color accent;
}

class _ModeOption<T> {
  const _ModeOption(this.value, this.label);

  final T value;
  final String label;
}

class _DashboardHero extends StatelessWidget {
  const _DashboardHero({
    this.topUser,
    this.topWeeklyUser,
    this.topHost,
    this.topAgency,
    this.refreshing = false,
  });

  final LeaderboardUserItemDto? topUser;
  final LeaderboardUserItemDto? topWeeklyUser;
  final LeaderboardHostItemDto? topHost;
  final LeaderboardAgencyItemDto? topAgency;
  final bool refreshing;

  @override
  Widget build(BuildContext context) {
    final tokens = _dashboardTokens();
    final spotlightName = topWeeklyUser?.name ?? topUser?.name ?? 'No leader yet';

    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(28),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.cardGradient.first.withOpacity(.98),
            Color.alphaBlend(
              Colors.white.withOpacity(.025),
              tokens.cardGradient.last.withOpacity(.96),
            ),
          ],
        ),
        border: Border.all(color: Colors.white.withOpacity(.08)),
        boxShadow: [
          BoxShadow(
            color: tokens.glowColor.withOpacity(.12),
            blurRadius: 24,
            offset: const Offset(0, 12),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(28),
        child: Stack(
          children: [
            Positioned(
              top: -36,
              right: -18,
              child: _HeroGlow(
                size: 140,
                color: tokens.primaryButtonGradient.first.withOpacity(.18),
              ),
            ),
            Positioned(
              bottom: -48,
              left: -24,
              child: _HeroGlow(
                size: 170,
                color: const Color(0xFFFFD66B).withOpacity(.10),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'Leaderboard',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 22,
                                fontWeight: FontWeight.w900,
                                letterSpacing: -.4,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              'Current crowns across all live leaderboards',
                              style: TextStyle(
                                color: Colors.white.withOpacity(.64),
                                fontSize: 12,
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 10),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          _StatusPill(refreshing: refreshing),
                          const SizedBox(height: 8),
                          _InfoPill(label: 'Auto refresh 60s'),
                        ],
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.fromLTRB(14, 16, 14, 14),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(24),
                      color: Colors.white.withOpacity(.05),
                      border: Border.all(color: Colors.white.withOpacity(.07)),
                    ),
                    child: Column(
                      children: [
                        _HeroMedalShowcase(
                          title: spotlightName,
                          subtitle: 'Weekly Spotlight',
                          accent: const Color(0xFFFF8966),
                        ),
                        const SizedBox(height: 14),
                        LayoutBuilder(
                          builder: (context, constraints) {
                            final compact = constraints.maxWidth < 380;
                            final medals = [
                              _HeroMedalCard(
                                label: 'All-Time User',
                                value: topUser?.name ?? '—',
                                accent: const Color(0xFFFFD66B),
                                icon: Icons.workspace_premium_rounded,
                              ),
                              _HeroMedalCard(
                                label: 'Weekly Host',
                                value: topHost?.name ?? '—',
                                accent: const Color(0xFF73E0A9),
                                icon: Icons.mic_rounded,
                              ),
                              _HeroMedalCard(
                                label: 'Weekly Agency',
                                value: topAgency?.name ?? '—',
                                accent: const Color(0xFFB794F4),
                                icon: Icons.apartment_rounded,
                              ),
                            ];

                            if (compact) {
                              return Column(
                                children: medals.map((medal) {
                                  return Padding(
                                    padding: EdgeInsets.only(
                                      bottom: medal == medals.last ? 0 : 8,
                                    ),
                                    child: medal,
                                  );
                                }).toList(growable: false),
                              );
                            }

                            return Row(
                              children: medals.map((medal) {
                                return Expanded(
                                  child: Padding(
                                    padding: EdgeInsets.only(
                                      right: medal == medals.last ? 0 : 8,
                                    ),
                                    child: medal,
                                  ),
                                );
                              }).toList(growable: false),
                            );
                          },
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _HeroMedalShowcase extends StatelessWidget {
  const _HeroMedalShowcase({
    required this.title,
    required this.subtitle,
    required this.accent,
  });

  final String title;
  final String subtitle;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: .92, end: 1),
      duration: const Duration(milliseconds: 820),
      curve: Curves.easeOutCubic,
      builder: (context, scale, _) {
        return Transform.scale(
          scale: scale,
          child: Container(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(24),
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  accent.withOpacity(.18),
                  Colors.white.withOpacity(.05),
                ],
              ),
              border: Border.all(color: accent.withOpacity(.20)),
            ),
            child: Row(
              children: [
                _HeroCrownMedal(accent: accent),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 10,
                          vertical: 6,
                        ),
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(999),
                          color: Colors.white.withOpacity(.08),
                        ),
                        child: Text(
                          subtitle.toUpperCase(),
                          style: TextStyle(
                            color: Colors.white.withOpacity(.64),
                            fontSize: 9,
                            fontWeight: FontWeight.w800,
                            letterSpacing: .8,
                          ),
                        ),
                      ),
                      const SizedBox(height: 12),
                      Text(
                        title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 20,
                          fontWeight: FontWeight.w900,
                          height: 1.0,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Leading the current weekly board',
                        style: TextStyle(
                          color: Colors.white.withOpacity(.62),
                          fontSize: 12,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _HeroCrownMedal extends StatelessWidget {
  const _HeroCrownMedal({required this.accent});

  final Color accent;

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: .9, end: 1),
      duration: const Duration(milliseconds: 950),
      curve: Curves.easeOutBack,
      builder: (context, scale, _) {
        return Transform.scale(
          scale: scale,
          child: Container(
            width: 86,
            height: 96,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(28),
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  accent.withOpacity(.34),
                  accent.withOpacity(.10),
                ],
              ),
              border: Border.all(color: accent.withOpacity(.22)),
            ),
            child: Stack(
              alignment: Alignment.center,
              children: [
                Positioned(
                  top: 10,
                  child: Icon(
                    Icons.workspace_premium_rounded,
                    color: const Color(0xFFFFD66B),
                    size: 22,
                  ),
                ),
                Positioned(
                  top: 30,
                  child: Container(
                    width: 52,
                    height: 52,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: Colors.white.withOpacity(.10),
                      border: Border.all(color: Colors.white.withOpacity(.14)),
                    ),
                    alignment: Alignment.center,
                    child: const Text(
                      '1',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 22,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ),
                ),
                Positioned(
                  bottom: 0,
                  child: Container(
                    width: 42,
                    height: 20,
                    decoration: BoxDecoration(
                      color: const Color(0xFFFFD66B).withOpacity(.90),
                      borderRadius: const BorderRadius.only(
                        topLeft: Radius.circular(16),
                        topRight: Radius.circular(16),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _HeroMedalCard extends StatelessWidget {
  const _HeroMedalCard({
    required this.label,
    required this.value,
    required this.accent,
    required this.icon,
  });

  final String label;
  final String value;
  final Color accent;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(18),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            accent.withOpacity(.16),
            Colors.white.withOpacity(.04),
          ],
        ),
        border: Border.all(color: accent.withOpacity(.18)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, color: accent, size: 16),
              const Spacer(),
              Container(
                width: 8,
                height: 8,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: accent,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            label,
            style: TextStyle(
              color: Colors.white.withOpacity(.58),
              fontSize: 10,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            value,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 14,
              fontWeight: FontWeight.w800,
              height: 1.1,
            ),
          ),
        ],
      ),
    );
  }
}

class _HeroGlow extends StatelessWidget {
  const _HeroGlow({
    required this.size,
    required this.color,
  });

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(
            colors: [
              color,
              color.withOpacity(.0),
            ],
          ),
        ),
      ),
    );
  }
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.refreshing});

  final bool refreshing;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: Colors.white.withOpacity(.08),
        border: Border.all(color: Colors.white.withOpacity(.08)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          TweenAnimationBuilder<double>(
            tween: Tween(begin: .7, end: 1),
            duration: const Duration(milliseconds: 900),
            curve: Curves.easeInOut,
            builder: (context, scale, _) {
              return Transform.scale(
                scale: scale,
                child: Container(
                  width: 7,
                  height: 7,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: refreshing
                        ? const Color(0xFFFFD66B)
                        : const Color(0xFF73E0A9),
                  ),
                ),
              );
            },
          ),
          const SizedBox(width: 7),
          Text(
            refreshing ? 'Refreshing' : 'Live',
            style: TextStyle(
              color: Colors.white.withOpacity(.78),
              fontSize: 11,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}

class _InfoPill extends StatelessWidget {
  const _InfoPill({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: Colors.white.withOpacity(.08),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: Colors.white.withOpacity(.7),
          fontSize: 11,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

class _WeekStatusCard extends StatelessWidget {
  const _WeekStatusCard({
    required this.label,
    required this.countdown,
  });

  final String label;
  final String countdown;

  @override
  Widget build(BuildContext context) {
    final tokens = _dashboardTokens();

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(18),
        gradient: LinearGradient(
          colors: [
            tokens.primaryButtonGradient.first.withOpacity(.28),
            tokens.primaryButtonGradient.last.withOpacity(.22),
          ],
        ),
        border: Border.all(color: Colors.white.withOpacity(.1)),
      ),
      child: Row(
        children: [
          Container(
            width: 34,
            height: 34,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: Colors.white.withOpacity(.12),
            ),
            alignment: Alignment.center,
            child: const Icon(
              Icons.date_range_rounded,
              color: Colors.white,
              size: 18,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'This Week',
                  style: TextStyle(
                    color: Colors.white.withOpacity(.72),
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  label,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 14,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ),
          Text(
            countdown,
            style: TextStyle(
              color: Colors.white.withOpacity(.74),
              fontSize: 11,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

class _ModeSwitcher<T> extends StatelessWidget {
  const _ModeSwitcher({
    required this.value,
    required this.options,
    required this.onChanged,
  });

  final T value;
  final List<_ModeOption<T>> options;
  final ValueChanged<T> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: Colors.white.withOpacity(.06),
        border: Border.all(color: Colors.white.withOpacity(.08)),
      ),
      child: Row(
        children: options.map((option) {
          final selected = option.value == value;
          return Expanded(
            child: GestureDetector(
              onTap: () => onChanged(option.value),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 220),
                curve: Curves.easeOutCubic,
                padding: const EdgeInsets.symmetric(vertical: 10),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(999),
                  gradient: selected
                      ? LinearGradient(
                          colors: [
                            Colors.white.withOpacity(.18),
                            Colors.white.withOpacity(.08),
                          ],
                        )
                      : null,
                ),
                alignment: Alignment.center,
                child: Text(
                  option.label,
                  style: TextStyle(
                    color: selected ? Colors.white : Colors.white.withOpacity(.68),
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ),
          );
        }).toList(growable: false),
      ),
    );
  }
}

class _SectionCard extends StatelessWidget {
  const _SectionCard({
    required this.icon,
    required this.accent,
    required this.title,
    required this.subtitle,
    required this.child,
  });

  final IconData icon;
  final Color accent;
  final String title;
  final String subtitle;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final tokens = _dashboardTokens();

    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(30),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            Color.alphaBlend(
              accent.withOpacity(.07),
              tokens.cardGradient.first.withOpacity(.98),
            ),
            Color.alphaBlend(
              Colors.white.withOpacity(.02),
              tokens.cardGradient.last.withOpacity(.97),
            ),
          ],
        ),
        border: Border.all(color: Colors.white.withOpacity(.08)),
        boxShadow: [
          BoxShadow(
            color: accent.withOpacity(.10),
            blurRadius: 28,
            offset: const Offset(0, 14),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(30),
        child: Stack(
          children: [
            Positioned.fill(
              child: TweenAnimationBuilder<double>(
                tween: Tween(begin: .86, end: 1),
                duration: const Duration(milliseconds: 1100),
                curve: Curves.easeOutCubic,
                builder: (context, value, _) {
                  return Stack(
                    children: [
                      Positioned(
                        top: -20,
                        right: -10,
                        child: Transform.scale(
                          scale: value,
                          child: _AmbientGlow(
                            size: 136,
                            color: accent.withOpacity(.14),
                          ),
                        ),
                      ),
                      Positioned(
                        bottom: -34,
                        left: -24,
                        child: Transform.scale(
                          scale: 1.12 - ((value - .86) * .35),
                          child: _AmbientGlow(
                            size: 168,
                            color: Colors.white.withOpacity(.05),
                          ),
                        ),
                      ),
                    ],
                  );
                },
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(18, 18, 18, 18),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Container(
                        width: 44,
                        height: 44,
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(16),
                          color: accent.withOpacity(.14),
                          border: Border.all(color: accent.withOpacity(.18)),
                        ),
                        alignment: Alignment.center,
                        child: Icon(icon, color: accent, size: 20),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              title,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 20,
                                fontWeight: FontWeight.w900,
                                letterSpacing: -.4,
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 10),
                      Container(
                        width: 10,
                        height: 56,
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(999),
                          gradient: LinearGradient(
                            begin: Alignment.topCenter,
                            end: Alignment.bottomCenter,
                            colors: [
                              accent,
                              accent.withOpacity(.16),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  child,
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _FocusedLeaderboard extends StatelessWidget {
  const _FocusedLeaderboard({required this.entries});

  final List<_SpotlightEntry> entries;

  @override
  Widget build(BuildContext context) {
    if (entries.isEmpty) {
      return const _EmptyLeaderboard();
    }

    _SpotlightEntry? byRank(int rank) {
      for (final entry in entries) {
        if (entry.rank == rank) return entry;
      }
      return null;
    }

    final leader = byRank(1) ?? entries.first;
    final second = byRank(2);
    final third = byRank(3);
    final rest = entries.skip(3).toList(growable: false);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _PremiumStageShowcase(
          leader: leader,
          second: second,
          third: third,
        ),
        if (rest.isNotEmpty) const SizedBox(height: 14),
        if (rest.isNotEmpty)
          Container(
            padding: const EdgeInsets.fromLTRB(14, 14, 14, 6),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(24),
              color: Colors.black.withOpacity(.10),
              border: Border.all(color: Colors.white.withOpacity(.05)),
            ),
            child: Column(children: [
              Row(
                children: [
                  Text(
                    'Standings',
                    style: TextStyle(
                      color: Colors.white.withOpacity(.84),
                      fontSize: 12,
                      fontWeight: FontWeight.w800,
                      letterSpacing: .3,
                    ),
                  ),
                  const Spacer(),
                  Text(
                    '${rest.length} more',
                    style: TextStyle(
                      color: Colors.white.withOpacity(.52),
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              ...rest.map((entry) {
                return _LeaderboardRow(
                  rank: entry.rank,
                  avatarUrl: entry.avatarUrl,
                  title: entry.title,
                  subtitle: entry.subtitle,
                  value: entry.value,
                  valueLabel: entry.valueLabel,
                  fallbackIcon: entry.fallbackIcon,
                );
              }),
            ]),
          ),
      ],
    );
  }
}

class _PremiumStageShowcase extends StatelessWidget {
  const _PremiumStageShowcase({
    required this.leader,
    required this.second,
    required this.third,
  });

  final _SpotlightEntry leader;
  final _SpotlightEntry? second;
  final _SpotlightEntry? third;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(26),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            const Color(0xFFFFD66B).withOpacity(.13),
            Colors.white.withOpacity(.03),
          ],
        ),
        border: Border.all(color: Colors.white.withOpacity(.08)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _StagePodium(
            leader: leader,
            second: second,
            third: third,
          ),
        ],
      ),
    );
  }
}

class _StagePodium extends StatelessWidget {
  const _StagePodium({
    required this.leader,
    required this.second,
    required this.third,
  });

  final _SpotlightEntry leader;
  final _SpotlightEntry? second;
  final _SpotlightEntry? third;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Expanded(
          child: _StageLane(
            entry: second,
            rank: 2,
            tone: const Color(0xFFD9E1EA),
            stageHeight: 70,
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: _StageLane(
            entry: leader,
            rank: 1,
            tone: const Color(0xFFFFD66B),
            stageHeight: 108,
            champion: true,
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: _StageLane(
            entry: third,
            rank: 3,
            tone: const Color(0xFFD79863),
            stageHeight: 56,
          ),
        ),
      ],
    );
  }
}

class _StageLane extends StatelessWidget {
  const _StageLane({
    required this.entry,
    required this.rank,
    required this.tone,
    required this.stageHeight,
    this.champion = false,
  });

  final _SpotlightEntry? entry;
  final int rank;
  final Color tone;
  final double stageHeight;
  final bool champion;

  @override
  Widget build(BuildContext context) {
    if (entry == null) {
      return Container(
        height: stageHeight + 78,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(22),
          color: Colors.white.withOpacity(.025),
          border: Border.all(color: Colors.white.withOpacity(.04)),
        ),
      );
    }

    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 12, end: 0),
      duration: Duration(milliseconds: champion ? 820 : 900),
      curve: Curves.easeOutCubic,
      builder: (context, offsetY, _) {
        return Transform.translate(
          offset: Offset(0, offsetY),
          child: Opacity(
            opacity: 1 - (offsetY / 12).clamp(0, 1),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                if (champion)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 4,
                      ),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(999),
                        color: tone.withOpacity(.22),
                      ),
                      child: const Text(
                        'CROWN',
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: 9,
                          fontWeight: FontWeight.w800,
                          letterSpacing: .8,
                        ),
                      ),
                    ),
                  ),
                _EntryAvatar(
                  avatarUrl: entry!.avatarUrl,
                  fallbackIcon: entry!.fallbackIcon,
                  radius: champion ? 24 : 20,
                ),
                const SizedBox(height: 10),
                Text(
                  entry!.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: champion ? 14 : 13,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 10),
                Container(
                  width: double.infinity,
                  height: stageHeight,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(22),
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [
                        tone.withOpacity(champion ? .30 : .22),
                        tone.withOpacity(.08),
                      ],
                    ),
                    border: Border.all(color: tone.withOpacity(.24)),
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        '$rank',
                        style: TextStyle(
                          color: tone,
                          fontSize: champion ? 26 : 22,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        _compact(entry!.value),
                        style: TextStyle(
                          color: Colors.white.withOpacity(.86),
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}


class _UserLeaderboardList extends StatelessWidget {
  const _UserLeaderboardList({
    required this.items,
    required this.api,
    this.weekly = false,
  });

  final List<LeaderboardUserItemDto> items;
  final ApiClient api;
  final bool weekly;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) {
      return const _EmptyLeaderboard();
    }

    final topThree = items.take(3).toList(growable: false);
    final rest = items.skip(3).toList(growable: false);

    return Column(
      children: [
        _PodiumStage(
          entries: topThree
              .map(
                (item) => _SpotlightEntry(
                  rank: item.rank,
                  avatarUrl: resolveAvatarUrl(api, item.avatar),
                  title: item.name,
                  subtitle: weekly
                      ? [
                          if (item.giftCoins > 0) 'Gift ${_compact(item.giftCoins)}',
                          if (item.callCoins > 0) 'Call ${_compact(item.callCoins)}',
                          if (item.subscriptionCoins > 0) 'Sub ${_compact(item.subscriptionCoins)}',
                          if (item.entryCoins > 0) 'Entry ${_compact(item.entryCoins)}',
                        ].join(' • ')
                      : (item.level != null ? 'Level ${item.level}' : 'User'),
                  value: weekly ? item.totalCoins : item.lifetimeSpendCoins,
                  valueLabel: weekly ? 'weekly coins' : 'lifetime coins',
                ),
              )
              .toList(growable: false),
        ),
        if (rest.isNotEmpty) const SizedBox(height: 12),
        ...rest.map((item) {
          final weeklyBreakdown = [
            if (item.giftCoins > 0) 'Gift ${_compact(item.giftCoins)}',
            if (item.callCoins > 0) 'Call ${_compact(item.callCoins)}',
            if (item.subscriptionCoins > 0) 'Sub ${_compact(item.subscriptionCoins)}',
            if (item.entryCoins > 0) 'Entry ${_compact(item.entryCoins)}',
          ].join(' • ');

          return _LeaderboardRow(
            rank: item.rank,
            avatarUrl: resolveAvatarUrl(api, item.avatar),
            title: item.name,
            subtitle: weekly
                ? (weeklyBreakdown.isNotEmpty
                    ? weeklyBreakdown
                    : (item.level != null ? 'Level ${item.level}' : 'User'))
                : (item.level != null ? 'Level ${item.level}' : 'User'),
            value: weekly ? item.totalCoins : item.lifetimeSpendCoins,
            valueLabel: weekly ? 'weekly coins' : 'lifetime coins',
          );
        }),
      ],
    );
  }
}

class _HostLeaderboardList extends StatelessWidget {
  const _HostLeaderboardList({
    required this.items,
    required this.api,
  });

  final List<LeaderboardHostItemDto> items;
  final ApiClient api;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) {
      return const _EmptyLeaderboard();
    }

    final topThree = items.take(3).toList(growable: false);
    final rest = items.skip(3).toList(growable: false);

    return Column(
      children: [
        _PodiumStage(
          entries: topThree
              .map(
                (item) => _SpotlightEntry(
                  rank: item.rank,
                  avatarUrl: resolveAvatarUrl(api, item.avatar),
                  title: item.name,
                  subtitle: 'Gift ${_compact(item.giftCoins)} • Call ${_compact(item.callCoins)}',
                  value: item.totalCoins,
                  valueLabel: 'gross coins',
                ),
              )
              .toList(growable: false),
        ),
        if (rest.isNotEmpty) const SizedBox(height: 12),
        ...rest.map((item) {
          return _LeaderboardRow(
            rank: item.rank,
            avatarUrl: resolveAvatarUrl(api, item.avatar),
            title: item.name,
            subtitle: 'Gift ${_compact(item.giftCoins)} • Call ${_compact(item.callCoins)}',
            value: item.totalCoins,
            valueLabel: 'gross coins',
          );
        }),
      ],
    );
  }
}

class _AgencyLeaderboardList extends StatelessWidget {
  const _AgencyLeaderboardList({required this.items});

  final List<LeaderboardAgencyItemDto> items;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) {
      return const _EmptyLeaderboard();
    }

    final topThree = items.take(3).toList(growable: false);
    final rest = items.skip(3).toList(growable: false);

    return Column(
      children: [
        _PodiumStage(
          entries: topThree
              .map(
                (item) => _SpotlightEntry(
                  rank: item.rank,
                  avatarUrl: null,
                  title: item.name,
                  subtitle: 'Gift ${_compact(item.giftCoins)} • Call ${_compact(item.callCoins)}',
                  value: item.totalCoins,
                  valueLabel: 'gross coins',
                  fallbackIcon: Icons.apartment_rounded,
                ),
              )
              .toList(growable: false),
        ),
        if (rest.isNotEmpty) const SizedBox(height: 12),
        ...rest.map((item) {
          return _LeaderboardRow(
            rank: item.rank,
            avatarUrl: null,
            title: item.name,
            subtitle: 'Gift ${_compact(item.giftCoins)} • Call ${_compact(item.callCoins)}',
            value: item.totalCoins,
            valueLabel: 'gross coins',
            fallbackIcon: Icons.apartment_rounded,
          );
        }),
      ],
    );
  }
}

class _SpotlightEntry {
  const _SpotlightEntry({
    required this.rank,
    required this.title,
    required this.subtitle,
    required this.value,
    required this.valueLabel,
    this.avatarUrl,
    this.fallbackIcon = Icons.person_rounded,
  });

  final int rank;
  final String title;
  final String subtitle;
  final int value;
  final String valueLabel;
  final String? avatarUrl;
  final IconData fallbackIcon;
}

class _PodiumStage extends StatelessWidget {
  const _PodiumStage({required this.entries});

  final List<_SpotlightEntry> entries;

  @override
  Widget build(BuildContext context) {
    if (entries.isEmpty) {
      return const SizedBox.shrink();
    }

    _SpotlightEntry? findRank(int rank) {
      for (final entry in entries) {
        if (entry.rank == rank) return entry;
      }
      return null;
    }

    final rank1 = findRank(1);
    final rank2 = findRank(2);
    final rank3 = findRank(3);
    final ordered = [rank2, rank1, rank3]
        .whereType<_SpotlightEntry>()
        .toList(growable: false);

    return LayoutBuilder(
      builder: (context, constraints) {
        final compact = constraints.maxWidth < 360;
        if (compact) {
          return Column(
            children: ordered.map((entry) {
              final isChampion = entry.rank == 1;
              return Padding(
                padding: EdgeInsets.only(bottom: entry == ordered.last ? 0 : 10),
                child: _SpotlightCard(
                  entry: entry,
                  champion: isChampion,
                  compact: true,
                ),
              );
            }).toList(growable: false),
          );
        }

        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: ordered.map((entry) {
            final isChampion = entry.rank == 1;
            return Expanded(
              child: Padding(
                padding: EdgeInsets.only(
                  right: entry == ordered.last ? 0 : 8,
                ),
                child: _SpotlightCard(
                  entry: entry,
                  champion: isChampion,
                ),
              ),
            );
          }).toList(growable: false),
        );
      },
    );
  }
}

class _SpotlightCard extends StatelessWidget {
  const _SpotlightCard({
    required this.entry,
    this.champion = false,
    this.compact = false,
  });

  final _SpotlightEntry entry;
  final bool champion;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final accent = switch (entry.rank) {
      1 => const Color(0xFFFFD66B),
      2 => const Color(0xFFD9E1EA),
      3 => const Color(0xFFD79863),
      _ => Colors.white70,
    };

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        color: champion
            ? accent.withOpacity(.14)
            : Colors.white.withOpacity(.05),
        border: Border.all(
          color: champion
              ? accent.withOpacity(.28)
              : Colors.white.withOpacity(.06),
        ),
        boxShadow: champion
            ? [
                BoxShadow(
                  color: accent.withOpacity(.10),
                  blurRadius: 14,
                  offset: const Offset(0, 8),
                ),
              ]
            : null,
      ),
      child: compact
          ? Row(
              children: [
                _RankBadge(rank: entry.rank, accent: accent),
                const SizedBox(width: 10),
                _EntryAvatar(
                  avatarUrl: entry.avatarUrl,
                  fallbackIcon: entry.fallbackIcon,
                  radius: 18,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        entry.title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 14,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        entry.subtitle,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: Colors.white.withOpacity(.64),
                          fontSize: 11,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                _ValuePill(
                  value: _compact(entry.value),
                  label: entry.valueLabel,
                ),
              ],
            )
          : Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    _RankBadge(rank: entry.rank, accent: accent),
                    const Spacer(),
                    _EntryAvatar(
                      avatarUrl: entry.avatarUrl,
                      fallbackIcon: entry.fallbackIcon,
                      radius: 19,
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Text(
                  entry.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: champion ? 15 : 14,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  entry.subtitle,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white.withOpacity(.64),
                    fontSize: 11,
                    fontWeight: FontWeight.w500,
                    height: 1.3,
                  ),
                ),
                const SizedBox(height: 12),
                _ValuePill(
                  value: _compact(entry.value),
                  label: entry.valueLabel,
                ),
              ],
            ),
    );
  }
}

class _RankBadge extends StatelessWidget {
  const _RankBadge({
    required this.rank,
    required this.accent,
  });

  final int rank;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 28,
      height: 28,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: accent,
      ),
      alignment: Alignment.center,
      child: Text(
        '$rank',
        style: TextStyle(
          color: rank <= 3 ? Colors.black87 : Colors.white,
          fontWeight: FontWeight.w900,
          fontSize: 12,
        ),
      ),
    );
  }
}

class _EntryAvatar extends StatelessWidget {
  const _EntryAvatar({
    required this.avatarUrl,
    required this.fallbackIcon,
    required this.radius,
  });

  final String? avatarUrl;
  final IconData fallbackIcon;
  final double radius;

  @override
  Widget build(BuildContext context) {
    return CircleAvatar(
      radius: radius,
      backgroundColor: Colors.white.withOpacity(.12),
      backgroundImage: _safeNetworkImage(avatarUrl),
      child: _safeNetworkImage(avatarUrl) == null
          ? Icon(fallbackIcon, color: Colors.white70, size: radius)
          : null,
    );
  }
}

class _ValuePill extends StatelessWidget {
  const _ValuePill({
    required this.value,
    required this.label,
  });

  final String value;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        color: Colors.black.withOpacity(.14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(
            value,
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w800,
              fontSize: 14,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            label,
            style: TextStyle(
              color: Colors.white.withOpacity(.56),
              fontWeight: FontWeight.w600,
              fontSize: 10,
            ),
          ),
        ],
      ),
    );
  }
}

class _AmbientGlow extends StatelessWidget {
  const _AmbientGlow({
    required this.size,
    required this.color,
  });

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(
            colors: [
              color,
              color.withOpacity(.0),
            ],
          ),
        ),
      ),
    );
  }
}

class _ChampionShowcase extends StatelessWidget {
  const _ChampionShowcase({required this.entry});

  final _SpotlightEntry entry;

  @override
  Widget build(BuildContext context) {
    const accent = Color(0xFFFFD66B);

    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 18, end: 0),
      duration: const Duration(milliseconds: 700),
      curve: Curves.easeOutCubic,
      builder: (context, offsetY, _) {
        return Transform.translate(
          offset: Offset(0, offsetY),
          child: Opacity(
            opacity: 1 - (offsetY / 18).clamp(0, 1),
            child: Container(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(26),
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [
                    accent.withOpacity(.15),
                    Colors.white.withOpacity(.035),
                  ],
                ),
                border: Border.all(color: accent.withOpacity(.22)),
              ),
              child: LayoutBuilder(
                builder: (context, constraints) {
                  final compact = constraints.maxWidth < 370;
                  final body = _ChampionIdentity(entry: entry);
                  final value = _ValuePill(
                    value: _compact(entry.value),
                    label: entry.valueLabel,
                  );

                  if (compact) {
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        body,
                        const SizedBox(height: 14),
                        value,
                      ],
                    );
                  }

                  return Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(child: body),
                      const SizedBox(width: 12),
                      value,
                    ],
                  );
                },
              ),
            ),
          ),
        );
      },
    );
  }
}

class _ChampionIdentity extends StatelessWidget {
  const _ChampionIdentity({required this.entry});

  final _SpotlightEntry entry;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const _RankBadge(rank: 1, accent: Color(0xFFFFD66B)),
            const SizedBox(width: 10),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(999),
                color: Colors.white.withOpacity(.08),
              ),
              child: Text(
                'Premium Leader',
                style: TextStyle(
                  color: Colors.white.withOpacity(.82),
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 14),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _EntryAvatar(
              avatarUrl: entry.avatarUrl,
              fallbackIcon: entry.fallbackIcon,
              radius: 28,
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    entry.title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 22,
                      fontWeight: FontWeight.w900,
                      letterSpacing: -.4,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    entry.subtitle,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: Colors.white.withOpacity(.66),
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                      height: 1.35,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _ChallengerRail extends StatelessWidget {
  const _ChallengerRail({required this.entries});

  final List<_SpotlightEntry> entries;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: entries.map((entry) {
        return Expanded(
          child: Padding(
            padding: EdgeInsets.only(right: entry == entries.last ? 0 : 10),
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: 14, end: 0),
              duration: const Duration(milliseconds: 850),
              curve: Curves.easeOutCubic,
              builder: (context, offsetY, _) {
                return Transform.translate(
                  offset: Offset(0, offsetY),
                  child: Opacity(
                    opacity: 1 - (offsetY / 14).clamp(0, 1),
                    child: _SpotlightCard(entry: entry),
                  ),
                );
              },
            ),
          ),
        );
      }).toList(growable: false),
    );
  }
}

class _LeaderboardRow extends StatelessWidget {
  const _LeaderboardRow({
    required this.rank,
    required this.avatarUrl,
    required this.title,
    required this.subtitle,
    required this.value,
    required this.valueLabel,
    this.fallbackIcon = Icons.person_rounded,
  });

  final int rank;
  final String? avatarUrl;
  final String title;
  final String subtitle;
  final int value;
  final String valueLabel;
  final IconData fallbackIcon;

  @override
  Widget build(BuildContext context) {
    final badgeColor = switch (rank) {
      1 => const Color(0xFFFFD66B),
      2 => const Color(0xFFD9E1EA),
      3 => const Color(0xFFD79863),
      _ => Colors.white.withOpacity(.16),
    };

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(.05),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.white.withOpacity(.06)),
      ),
      child: Row(
        children: [
          Container(
            width: 30,
            height: 30,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: badgeColor,
            ),
            alignment: Alignment.center,
            child: Text(
              '$rank',
              style: TextStyle(
                color: rank <= 3 ? Colors.black87 : Colors.white,
                fontWeight: FontWeight.w800,
                fontSize: 13,
              ),
            ),
          ),
          const SizedBox(width: 10),
          Container(
            width: 46,
            height: 46,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              border: Border.all(color: Colors.white.withOpacity(.08)),
            ),
            child: CircleAvatar(
              radius: 22,
              backgroundColor: Colors.white.withOpacity(.1),
              backgroundImage: _safeNetworkImage(avatarUrl),
              child: _safeNetworkImage(avatarUrl) == null
                  ? Icon(fallbackIcon, color: Colors.white70, size: 20)
                  : null,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                    fontSize: 14,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  subtitle,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white.withOpacity(.66),
                    fontWeight: FontWeight.w500,
                    fontSize: 11,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              color: Colors.black.withOpacity(.16),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(
                  _compact(value),
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                    fontSize: 14,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  valueLabel,
                  style: TextStyle(
                    color: Colors.white.withOpacity(.58),
                    fontWeight: FontWeight.w500,
                    fontSize: 10,
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

class _EmptyLeaderboard extends StatelessWidget {
  const _EmptyLeaderboard();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(vertical: 24, horizontal: 16),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        color: Colors.white.withOpacity(.04),
        border: Border.all(color: Colors.white.withOpacity(.06)),
      ),
      child: Column(
        children: [
          Icon(
            Icons.leaderboard_rounded,
            color: Colors.white.withOpacity(.42),
            size: 26,
          ),
          const SizedBox(height: 10),
          Text(
            'No ranking data yet.',
            style: TextStyle(
              color: Colors.white.withOpacity(.62),
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

String _compact(int value) => NumberFormat.compact().format(value);

ImageProvider? _safeNetworkImage(String? url) {
  final trimmed = url?.trim() ?? '';
  if (trimmed.isEmpty) {
    return null;
  }
  return NetworkImage(trimmed);
}

DateTime _istNow() => DateTime.now().toUtc().add(const Duration(hours: 5, minutes: 30));

String _thisWeekLabel() {
  final nowIst = _istNow();
  final weekStart = DateTime(nowIst.year, nowIst.month, nowIst.day)
      .subtract(Duration(days: nowIst.weekday - DateTime.monday));
  final weekEnd = weekStart.add(const Duration(days: 6));

  return '${DateFormat('dd MMM').format(weekStart)} - ${DateFormat('dd MMM').format(weekEnd)} IST';
}

String _weeklyResetCountdownLabel() {
  final nowIst = _istNow();
  final nextWeekStart = DateTime(nowIst.year, nowIst.month, nowIst.day)
      .add(Duration(days: 8 - nowIst.weekday));

  final remaining = nextWeekStart.difference(nowIst);
  final days = remaining.inDays;
  final hours = remaining.inHours.remainder(24);
  final minutes = remaining.inMinutes.remainder(60);

  if (days > 0) {
    return 'Resets in ${days}d ${hours}h';
  }
  if (hours > 0) {
    return 'Resets in ${hours}h ${minutes}m';
  }
  return 'Resets in ${minutes}m';
}
