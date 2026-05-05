import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../app/widgets/remote_media_art.dart';
import '../../../app/widgets/haptics.dart';
import '../../../services/app_settings_service.dart';
import '../../wallet/widgets/recharge_bottom_sheet.dart';
import '../models/entry_pack_dto.dart';
import '../models/user_entry_pack_dto.dart';
import '../services/entry_pack_api.dart';

enum _EntryCatalogFilter {
  all('All'),
  active('Active'),
  owned('Owned'),
  available('Available'),
  expired('Expired');

  const _EntryCatalogFilter(this.label);
  final String label;
}

class EntryPackCatalogPage extends StatefulWidget {
  const EntryPackCatalogPage({super.key});

  @override
  State<EntryPackCatalogPage> createState() => _EntryPackCatalogPageState();
}

PremiumThemeTokens _entryCatalogTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class _EntryPackCatalogPageState extends State<EntryPackCatalogPage> {
  late final EntryPackApi _api;
  bool _loading = true;
  bool _submitting = false;
  String? _error;
  List<EntryPackDto> _packs = const <EntryPackDto>[];
  EntryPackStateDto? _state;
  _EntryCatalogFilter _filter = _EntryCatalogFilter.all;

  @override
  void initState() {
    super.initState();
    _api = Get.find<EntryPackApi>();
    if (!Get.find<AppSettingsService>().entryEffectsEnabled) {
      _loading = false;
      _error = 'Entry effects are currently unavailable.';
      return;
    }
    _load();
  }

  UserEntryPackDto? _latestOwnedForPackFrom(
    EntryPackStateDto state,
    int packId,
  ) {
    final matches =
        state.owned.where((owned) => owned.entryPackId == packId).toList()
          ..sort((a, b) {
            final aTime =
                a.purchasedAt ?? DateTime.fromMillisecondsSinceEpoch(0);
            final bTime =
                b.purchasedAt ?? DateTime.fromMillisecondsSinceEpoch(0);
            return bTime.compareTo(aTime);
          });
    return matches.isEmpty ? null : matches.first;
  }

  UserEntryPackDto? _latestOwnedForPack(int packId) {
    final state = _state;
    if (state == null) return null;
    return _latestOwnedForPackFrom(state, packId);
  }

  List<EntryPackDto> _mergePackState(
    List<EntryPackDto> packs,
    EntryPackStateDto state,
  ) {
    return packs.map((pack) {
      final latest = _latestOwnedForPackFrom(state, pack.id);
      return pack.copyWith(
        owned: latest != null && !latest.isExpired,
        active:
            state.active?.entryPackId == pack.id &&
            !(state.active?.isExpired ?? false),
      );
    }).toList();
  }

  Future<void> _load() async {
    try {
      setState(() {
        _loading = true;
        _error = null;
      });
      final results = await Future.wait<dynamic>([
        _api.fetchPacks(),
        _api.fetchMine(),
      ]);
      final packs = results[0] as List<EntryPackDto>;
      final state = results[1] as EntryPackStateDto;
      if (!mounted) return;
      setState(() {
        _state = state;
        _packs = _mergePackState(packs, state);
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

  Future<void> _handlePackAction(EntryPackDto pack) async {
    if (_submitting) return;
    final owned = _latestOwnedForPack(pack.id);
    final canActivateOwned = owned != null && !owned.isExpired;
    setState(() => _submitting = true);
    try {
      if (!canActivateOwned) {
        await _api.purchase(pack.id);
      }
      await _api.activate(pack.id);
      Haptics.success();
      if (!mounted) return;
      await _load();
      Get.snackbar(
        'Entry updated',
        '${pack.name} is now active.',
        snackPosition: SnackPosition.BOTTOM,
      );
    } catch (e) {
      if (!mounted) return;
      Haptics.error();
      final message = e.toString().replaceFirst('Exception: ', '');
      if (isInsufficientCoinsErrorMessage(message)) {
        await showRechargeWalletSheet(
          reasonTitle: 'Not enough coins',
          reasonMessage:
              'You need more coins to unlock ${pack.name}. Recharge your wallet and try again.',
        );
      } else {
        Get.snackbar(
          'Entry pack',
          _friendlyActionMessage(message),
          snackPosition: SnackPosition.BOTTOM,
        );
      }
    } finally {
      if (mounted) {
        setState(() => _submitting = false);
      }
    }
  }

  String _friendlyActionMessage(String message) {
    final normalized = message.trim();
    switch (normalized) {
      case 'ENTRY_PACK_EXPIRED':
        return 'This entry pack has expired. Purchase it again to reactivate it.';
      case 'ENTRY_PACK_NOT_OWNED':
        return 'Purchase this entry pack before activating it.';
      case 'ENTRY_PACK_INACTIVE':
        return 'This entry pack is currently unavailable.';
      default:
        return normalized.isEmpty
            ? 'Unable to update entry pack right now.'
            : normalized;
    }
  }

  List<EntryPackDto> get _filteredPacks {
    final now = DateTime.now();
    final packs = [..._packs]..sort((a, b) {
      if (a.active != b.active) return a.active ? -1 : 1;
      if (a.owned != b.owned) return a.owned ? -1 : 1;
      return a.priority.compareTo(b.priority);
    });
    return packs.where((pack) {
      final owned = _latestOwnedForPack(pack.id);
      final isExpired =
          owned?.expiresAt != null && owned!.expiresAt!.isBefore(now);
      switch (_filter) {
        case _EntryCatalogFilter.all:
          return true;
        case _EntryCatalogFilter.active:
          return pack.active;
        case _EntryCatalogFilter.owned:
          return owned != null && !isExpired;
        case _EntryCatalogFilter.available:
          return owned == null || isExpired;
        case _EntryCatalogFilter.expired:
          return owned != null && isExpired;
      }
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _entryCatalogTokens();
    final active = _state?.active;
    final ownedCount = (_state?.owned ?? const <UserEntryPackDto>[]).length;
    final filtered = _filteredPacks;
    final width = MediaQuery.of(context).size.width;
    final crossAxisCount =
        width >= 1080
            ? 3
            : width >= 760
            ? 2
            : 1;

    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        title: Text(
          'Entry Catalog',
          style: TextStyle(color: tokens.textPrimary, fontWeight: FontWeight.w800),
        ),
        iconTheme: IconThemeData(color: tokens.textPrimary),
      ),
      body: Stack(
        children: [
          const Positioned.fill(child: _GlassyBackdrop()),
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
          SafeArea(
            child:
                _loading
                    ? Center(
                      child: CircularProgressIndicator(color: tokens.textPrimary),
                    )
                    : _error != null
                    ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(18),
                        child: _EntryCatalogMessageCard(
                          title: 'Unable to load entry packs',
                          message: _error!,
                          actionLabel: 'Retry',
                          onTap: _load,
                        ),
                      ),
                    )
                    : RefreshIndicator(
                      color: tokens.primaryButtonGradient.first,
                      backgroundColor: tokens.cardGradient.first,
                      onRefresh: _load,
                      child: CustomScrollView(
                        physics: const BouncingScrollPhysics(),
                        slivers: [
                          SliverPadding(
                            padding: const EdgeInsets.fromLTRB(18, 8, 18, 0),
                            sliver: SliverToBoxAdapter(
                              child: _EntryCatalogHero(
                                active: active,
                                totalCount: _packs.length,
                                ownedCount: ownedCount,
                                submitting: _submitting,
                              ),
                            ),
                          ),
                          SliverPadding(
                            padding: const EdgeInsets.fromLTRB(18, 18, 18, 0),
                            sliver: SliverToBoxAdapter(
                              child: SizedBox(
                                height: 42,
                                child: ListView.separated(
                                  scrollDirection: Axis.horizontal,
                                  itemBuilder: (context, index) {
                                    final filter =
                                        _EntryCatalogFilter.values[index];
                                    final selected = filter == _filter;
                                    return ChoiceChip(
                                      label: Text(filter.label),
                                      selected: selected,
                                      labelStyle: TextStyle(
                                        color:
                                            selected
                                                ? tokens.textPrimary
                                                : tokens.textSecondary.withOpacity(.82),
                                        fontWeight: FontWeight.w700,
                                      ),
                                      backgroundColor: tokens.chipColor.withOpacity(.78),
                                      selectedColor: tokens.chipColor.withOpacity(.96),
                                      shape: RoundedRectangleBorder(
                                        borderRadius: BorderRadius.circular(16),
                                      ),
                                      side: BorderSide(
                                        color:
                                            selected
                                                ? tokens.primaryButtonGradient.first
                                                : tokens.borderColor.withOpacity(.82),
                                      ),
                                      onSelected:
                                          (_) =>
                                              setState(() => _filter = filter),
                                    );
                                  },
                                  separatorBuilder:
                                      (_, __) => const SizedBox(width: 8),
                                  itemCount: _EntryCatalogFilter.values.length,
                                ),
                              ),
                            ),
                          ),
                          SliverPadding(
                            padding: const EdgeInsets.fromLTRB(18, 16, 18, 8),
                            sliver: SliverToBoxAdapter(
                              child: Row(
                                children: [
                                  const Expanded(
                                    child: _SectionTitle(
                                      title: 'Available Packs',
                                      subtitle:
                                          'Browse, compare, and activate the arrival effect you want to use.',
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                  Text(
                                    '${filtered.length} entries',
                                    style: TextStyle(
                                      color: tokens.textSecondary.withOpacity(.78),
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                          if (filtered.isEmpty)
                            const SliverFillRemaining(
                              hasScrollBody: false,
                              child: Center(
                                child: Padding(
                                  padding: EdgeInsets.all(18),
                                  child: _EntryCatalogMessageCard(
                                    title: 'No entries in this view',
                                    message:
                                        'Try another filter or check back after more entry packs are configured.',
                                  ),
                                ),
                              ),
                            )
                          else
                            SliverPadding(
                              padding: const EdgeInsets.fromLTRB(18, 4, 18, 24),
                              sliver: SliverGrid(
                                gridDelegate:
                                    SliverGridDelegateWithFixedCrossAxisCount(
                                      crossAxisCount: crossAxisCount,
                                      mainAxisSpacing: 14,
                                      crossAxisSpacing: 14,
                                      mainAxisExtent: 292,
                                    ),
                                delegate: SliverChildBuilderDelegate((
                                  context,
                                  index,
                                ) {
                                  final pack = filtered[index];
                                  final owned = _latestOwnedForPack(pack.id);
                                  return _EntryCatalogPackCard(
                                    pack: pack,
                                    ownedPack: owned,
                                    busy: _submitting,
                                    onTap:
                                        pack.active
                                            ? null
                                            : () => _handlePackAction(pack),
                                  );
                                }, childCount: filtered.length),
                              ),
                            ),
                        ],
                      ),
                    ),
          ),
        ],
      ),
    );
  }
}

class _EntryCatalogHero extends StatelessWidget {
  const _EntryCatalogHero({
    required this.active,
    required this.totalCount,
    required this.ownedCount,
    required this.submitting,
  });

  final UserEntryPackDto? active;
  final int totalCount;
  final int ownedCount;
  final bool submitting;

  @override
  Widget build(BuildContext context) {
    final pack = active?.entryPack;
    return _GlassShell(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              _EntryCatalogArt(
                svgUrl: pack?.svgUrl,
                assetType: pack?.assetType,
                size: 62,
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      pack?.name ?? 'No active entry',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 22,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      pack == null
                          ? 'Browse the full catalog, compare effects, and activate the entry that fits your live-room style.'
                          : 'Currently active when you join live rooms. Browse the catalog to switch, renew, or preview validity.',
                      style: TextStyle(
                        color: Colors.white.withOpacity(.72),
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
              _EntryCatalogPill(
                icon: Icons.grid_view_rounded,
                label: '$totalCount total',
              ),
              _EntryCatalogPill(
                icon: Icons.inventory_2_rounded,
                label: '$ownedCount owned',
              ),
              if (pack != null)
                _EntryCatalogPill(
                  icon: Icons.auto_awesome_rounded,
                  label:
                      '${pack.animationStyle.toUpperCase()} • ${pack.durationMs ~/ 1000}s • ${pack.durationDays}d',
                ),
              if (active?.expiresAt != null)
                _EntryCatalogPill(
                  icon: Icons.event_rounded,
                  label:
                      'Ends ${DateFormat.yMMMd().format(active!.expiresAt!.toLocal())}',
                ),
            ],
          ),
          if (submitting) ...[
            const SizedBox(height: 16),
            LinearProgressIndicator(
              minHeight: 4,
              color: _entryCatalogTokens().primaryButtonGradient.first,
              backgroundColor:
                  _entryCatalogTokens().glassColor.withOpacity(.6),
            ),
          ],
        ],
      ),
    );
  }
}

class _EntryCatalogPackCard extends StatelessWidget {
  const _EntryCatalogPackCard({
    required this.pack,
    required this.ownedPack,
    required this.busy,
    this.onTap,
  });

  final EntryPackDto pack;
  final UserEntryPackDto? ownedPack;
  final bool busy;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final tokens = _entryCatalogTokens();
    final isExpiredOwned = ownedPack != null && ownedPack!.isExpired;
    final statusLabel =
        pack.active
            ? 'Active'
            : isExpiredOwned
            ? (pack.priceCoins == 0 ? 'Claim Again' : 'Renew')
            : pack.owned
            ? 'Activate'
            : (pack.priceCoins == 0 ? 'Free' : '${pack.priceCoins} coins');

    return _GlassShell(
      padding: const EdgeInsets.all(18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _EntryCatalogArt(
                svgUrl: pack.svgUrl,
                assetType: pack.assetType,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      pack.name,
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                        fontSize: 17,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: [
                        _EntryCatalogBadge(
                          label:
                              pack.active
                                  ? 'ACTIVE'
                                  : isExpiredOwned
                                  ? 'EXPIRED'
                                  : pack.owned
                                  ? 'OWNED'
                                  : 'AVAILABLE',
                          color:
                              pack.active
                                  ? tokens.primaryButtonGradient.first
                                  : isExpiredOwned
                                  ? const Color(0xFFFFC56B)
                                  : tokens.textPrimary,
                        ),
                        _EntryCatalogBadge(
                          label: pack.animationStyle.toUpperCase(),
                          color: tokens.textSecondary,
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Text(
            pack.active
                ? 'This entry is currently active on your account.'
                : isExpiredOwned
                ? 'Your previous validity ended. Purchase again to restore this effect.'
                : pack.owned
                ? 'Already purchased and still valid. Activate it anytime.'
                : 'Unlock this entry for your live-room arrival with a full validity window.',
            style: TextStyle(
              color: Colors.white.withOpacity(.76),
              fontSize: 12.5,
              height: 1.35,
              fontWeight: FontWeight.w500,
            ),
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              _EntryCatalogMeta(
                icon: Icons.schedule_rounded,
                label: '${pack.durationDays} days',
              ),
              _EntryCatalogMeta(
                icon: Icons.movie_filter_rounded,
                label: '${pack.durationMs ~/ 1000}s effect',
              ),
              _EntryCatalogMeta(
                icon: Icons.flag_rounded,
                label: 'Priority ${pack.priority}',
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (ownedPack?.purchasedAt != null)
            Text(
              'Purchased ${DateFormat.yMMMd().add_jm().format(ownedPack!.purchasedAt!.toLocal())}',
              style: TextStyle(
                color: Colors.white.withOpacity(.68),
                fontSize: 11.5,
                fontWeight: FontWeight.w600,
              ),
            ),
          if (ownedPack?.expiresAt != null) ...[
            const SizedBox(height: 6),
            Text(
              '${isExpiredOwned ? 'Expired' : 'Valid until'} ${DateFormat.yMMMd().add_jm().format(ownedPack!.expiresAt!.toLocal())}',
              style: TextStyle(
                color:
                    isExpiredOwned
                        ? const Color(0xFFFFC56B)
                        : Colors.white.withOpacity(.78),
                fontSize: 11.5,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
          const Spacer(),
          Row(
            children: [
              Expanded(
                child: Text(
                  pack.priceCoins == 0
                      ? 'Free unlock'
                      : '${pack.priceCoins} coins',
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                    fontSize: 15,
                  ),
                ),
              ),
              const SizedBox(width: 12),
              SizedBox(
                width: 136,
                child: _PrimaryGlassButton(
                  label: statusLabel,
                  icon:
                      pack.active
                          ? Icons.verified_rounded
                          : Icons.auto_awesome_rounded,
                  loading: busy,
                  enabled: onTap != null,
                  onTap: onTap ?? () {},
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _EntryCatalogArt extends StatelessWidget {
  const _EntryCatalogArt({
    this.svgUrl,
    this.assetType,
    this.size = 64,
  });

  final String? svgUrl;
  final String? assetType;
  final double size;

  @override
  Widget build(BuildContext context) {
    final tokens = _entryCatalogTokens();
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: tokens.glassColor.withOpacity(.9),
        borderRadius: BorderRadius.circular(20),
      ),
      alignment: Alignment.center,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: RemoteMediaArt(
          url: svgUrl,
          explicitType: assetType,
          width: size * .62,
          height: size * .62,
          fallback: Icon(
            Icons.auto_awesome_rounded,
            color: tokens.textPrimary,
          ),
        ),
      ),
    );
  }
}

class _EntryCatalogPill extends StatelessWidget {
  const _EntryCatalogPill({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(.09),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: Colors.white.withOpacity(.08)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: Colors.white),
          const SizedBox(width: 7),
          Text(
            label,
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w700,
              fontSize: 12,
            ),
          ),
        ],
      ),
    );
  }
}

class _EntryCatalogBadge extends StatelessWidget {
  const _EntryCatalogBadge({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final tokens = _entryCatalogTokens();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Color.alphaBlend(
          tokens.glassColor.withOpacity(.28),
          color.withOpacity(.14),
        ),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: color.withOpacity(.34)),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: color,
          fontWeight: FontWeight.w800,
          fontSize: 11,
          letterSpacing: .4,
        ),
      ),
    );
  }
}

class _EntryCatalogMeta extends StatelessWidget {
  const _EntryCatalogMeta({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    final tokens = _entryCatalogTokens();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
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
        border: Border.all(color: tokens.borderColor.withOpacity(.82)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: tokens.textPrimary),
          const SizedBox(width: 7),
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

class _EntryCatalogMessageCard extends StatelessWidget {
  const _EntryCatalogMessageCard({
    required this.title,
    required this.message,
    this.actionLabel,
    this.onTap,
  });

  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final tokens = _entryCatalogTokens();
    return _GlassShell(
      padding: const EdgeInsets.all(20),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.auto_awesome_rounded,
            color: tokens.primaryButtonGradient.first,
            size: 32,
          ),
          const SizedBox(height: 12),
          Text(
            title,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w800,
              fontSize: 16,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            message,
            textAlign: TextAlign.center,
            style: TextStyle(color: tokens.textSecondary.withOpacity(.78)),
          ),
          if (actionLabel != null && onTap != null) ...[
            const SizedBox(height: 14),
            _PrimaryGlassButton(
              label: actionLabel!,
              icon: Icons.refresh_rounded,
              onTap: onTap!,
            ),
          ],
        ],
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  final String title;
  final String subtitle;

  const _SectionTitle({required this.title, required this.subtitle});

  @override
  Widget build(BuildContext context) {
    final tokens = _entryCatalogTokens();
    return Column(
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
            color: tokens.textSecondary.withOpacity(.78),
            height: 1.3,
          ),
        ),
      ],
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
    final tokens = _entryCatalogTokens();
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
    final tokens = _entryCatalogTokens();
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
              gradient: LinearGradient(colors: tokens.primaryButtonGradient),
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
                  Icon(icon, color: Colors.white),
                const SizedBox(width: 10),
                Flexible(
                  child: Text(
                    label,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
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
}

class _GlassyBackdrop extends StatefulWidget {
  const _GlassyBackdrop();

  @override
  State<_GlassyBackdrop> createState() => _GlassyBackdropState();
}

class _GlassyBackdropState extends State<_GlassyBackdrop>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 18),
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (_, __) => CustomPaint(painter: _BlobPainter(_controller.value)),
    );
  }
}

class _BlobPainter extends CustomPainter {
  final double t;

  _BlobPainter(this.t);

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

    final tokens = _entryCatalogTokens();
    blob(Offset(w * .22, h * .18), h * .24, tokens.primaryButtonGradient.first, 24, 0.0);
    blob(Offset(w * .82, h * .25), h * .22, tokens.cardGradient.last, 32, 1.4);
    blob(Offset(w * .52, h * .72), h * .30, tokens.glowColor, 26, 2.2);
  }

  @override
  bool shouldRepaint(covariant _BlobPainter oldDelegate) => oldDelegate.t != t;
}
