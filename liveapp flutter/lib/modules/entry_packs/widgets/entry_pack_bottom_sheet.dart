import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/routes/app_routes.dart';
import '../../../app/theme/brand.dart';
import '../../../app/widgets/haptics.dart';
import '../../../services/app_settings_service.dart';
import '../models/entry_pack_dto.dart';
import '../models/user_entry_pack_dto.dart';
import '../services/entry_pack_api.dart';

PremiumThemeTokens _entryPackTokens() =>
    getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );

class EntryPackBottomSheet extends StatefulWidget {
  const EntryPackBottomSheet({super.key});

  @override
  State<EntryPackBottomSheet> createState() => _EntryPackBottomSheetState();
}

class _EntryPackBottomSheetState extends State<EntryPackBottomSheet> {
  late final EntryPackApi _api;
  bool _loading = true;
  bool _submitting = false;
  String? _error;
  List<EntryPackDto> _packs = const <EntryPackDto>[];
  EntryPackStateDto? _state;

  UserEntryPackDto? _latestOwnedForPack(int packId) {
    final matches =
        (_state?.owned ?? const <UserEntryPackDto>[])
            .where((owned) => owned.entryPackId == packId)
            .toList()
          ..sort((a, b) {
            final aTime =
                a.purchasedAt ?? DateTime.fromMillisecondsSinceEpoch(0);
            final bTime =
                b.purchasedAt ?? DateTime.fromMillisecondsSinceEpoch(0);
            return bTime.compareTo(aTime);
          });
    return matches.isEmpty ? null : matches.first;
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
      if (message.contains('INSUFFICIENT_FUNDS') ||
          message.toLowerCase().contains('not enough coins')) {
        await Get.dialog<void>(
          AlertDialog(
            backgroundColor: const Color(0xFF161028),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(22),
            ),
            title: const Text(
              'Not enough coins',
              style: TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w800,
              ),
            ),
            content: Text(
              'You need more coins to unlock ${pack.name}. Recharge your wallet and try again.',
              style: TextStyle(
                color: Colors.white.withOpacity(.78),
                height: 1.4,
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Get.back<void>(),
                child: const Text('OK'),
              ),
            ],
          ),
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

  @override
  Widget build(BuildContext context) {
    return Obx(() {
      final tokens = _entryPackTokens();
      final active = _state?.active;
      final ownedHistory =
          (_state?.owned ?? const <UserEntryPackDto>[])
              .where((pack) => active == null || pack.id != active.id)
              .toList();
      final featuredPacks = _packs.take(3).toList();

      return SafeArea(
        top: false,
        child: Container(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.of(context).size.height * .82,
          ),
          padding: const EdgeInsets.fromLTRB(18, 14, 18, 22),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: tokens.cardGradient,
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
            border: Border.all(color: tokens.borderColor),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
            Center(
              child: Container(
                width: 54,
                height: 5,
                decoration: BoxDecoration(
                  color: tokens.borderColor.withOpacity(.85),
                  borderRadius: BorderRadius.circular(99),
                ),
              ),
            ),
            const SizedBox(height: 14),
            Text(
              'Entry Packs',
              style: TextStyle(
                color: tokens.textPrimary,
                fontSize: 24,
                fontWeight: FontWeight.w900,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              'Buy or activate the arrival effect shown when you join live rooms.',
              style: TextStyle(
                color: tokens.textSecondary.withOpacity(.82),
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 14),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton(
                onPressed: () {
                  Get.back<void>();
                  Get.toNamed(Routes.entryCatalog);
                },
                style: OutlinedButton.styleFrom(
                  foregroundColor: tokens.textPrimary,
                  side: BorderSide(color: tokens.borderColor),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(16),
                  ),
                ),
                child: const Text(
                  'View Full Catalog',
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Expanded(
              child:
                  _loading
                      ? const Center(
                        child: CircularProgressIndicator(color: Colors.white),
                      )
                      : _error != null
                      ? _EntryMessageCard(
                        title: 'Unable to load entry packs',
                        message: _error!,
                        actionLabel: 'Retry',
                        onTap: _load,
                      )
                      : ListView(
                        physics: const BouncingScrollPhysics(),
                        children: [
                          _EntryHeroPanel(active: active, buying: _submitting),
                          const SizedBox(height: 18),
                          const _EntrySectionTitle(
                            title: 'Featured Packs',
                            subtitle:
                                'Quick access to highlighted entry effects. Open the full catalog to browse everything.',
                          ),
                          const SizedBox(height: 10),
                          if (featuredPacks.isEmpty)
                            const _EntryMessageCard(
                              title: 'No entry packs available',
                              message:
                                  'Entry packs will appear here once configured.',
                            )
                          else
                            ...featuredPacks.map((pack) {
                              final owned = _latestOwnedForPack(pack.id);
                              return Padding(
                                padding: const EdgeInsets.only(bottom: 12),
                                child: _EntryPackCard(
                                  pack: pack,
                                  ownedPack: owned,
                                  busy: _submitting,
                                  onTap:
                                      pack.active
                                          ? null
                                          : () => _handlePackAction(pack),
                                ),
                              );
                            }),
                          if (_packs.length > featuredPacks.length) ...[
                            const SizedBox(height: 6),
                            Align(
                              alignment: Alignment.centerLeft,
                              child: TextButton.icon(
                                onPressed: () {
                                  Get.back<void>();
                                  Get.toNamed(Routes.entryCatalog);
                                },
                                icon: const Icon(
                                  Icons.grid_view_rounded,
                                  size: 18,
                                ),
                                label: Text(
                                  'Browse all ${_packs.length} entries',
                                ),
                                style: TextButton.styleFrom(
                                  foregroundColor: _entryPackTokens().primaryButtonGradient.first,
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 4,
                                    vertical: 6,
                                  ),
                                ),
                              ),
                            ),
                          ],
                          if (ownedHistory.isNotEmpty) ...[
                            const SizedBox(height: 8),
                            const _EntrySectionTitle(
                              title: 'Owned Packs',
                              subtitle:
                                  'Your purchased entry effects and activation history.',
                            ),
                            const SizedBox(height: 10),
                            ...ownedHistory.map(
                              (owned) => Padding(
                                padding: const EdgeInsets.only(bottom: 12),
                                child: _OwnedEntryPackCard(owned: owned),
                              ),
                            ),
                          ],
                        ],
                      ),
            ),
            ],
          ),
        ),
      );
    });
  }
}

class _EntryHeroPanel extends StatelessWidget {
  const _EntryHeroPanel({required this.active, required this.buying});

  final UserEntryPackDto? active;
  final bool buying;

  @override
  Widget build(BuildContext context) {
    final pack = active?.entryPack;
    final tokens = _entryPackTokens();
    return _GlassShell(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              _EntryPackArt(svgUrl: pack?.svgUrl, size: 56),
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
                        pack?.name ?? 'No active entry',
                        style: Theme.of(
                          context,
                        ).textTheme.headlineSmall?.copyWith(
                          color: tokens.textPrimary,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      pack == null
                          ? 'Activate an entry pack to show a premium arrival overlay when you join live rooms.'
                          : 'This is the effect currently used when you enter a live room.',
                      style: TextStyle(
                        color: tokens.textSecondary.withOpacity(.84),
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
                    pack == null
                        ? Icons.lock_outline_rounded
                        : Icons.auto_awesome_rounded,
                label: pack == null ? 'Inactive' : 'ACTIVE',
              ),
              if (pack != null)
                _HeroPill(
                  icon: Icons.movie_filter_rounded,
                  label:
                      '${pack.animationStyle.toUpperCase()} • ${pack.durationMs ~/ 1000}s • ${pack.durationDays}d',
                ),
              if (active?.purchasedAt != null)
                _HeroPill(
                  icon: Icons.schedule_rounded,
                  label:
                      'Purchased ${DateFormat.yMMMd().format(active!.purchasedAt!.toLocal())}',
                ),
              if (active?.expiresAt != null)
                _HeroPill(
                  icon: Icons.event_rounded,
                  label:
                      'Ends ${DateFormat.yMMMd().format(active!.expiresAt!.toLocal())}',
                ),
            ],
          ),
          if (buying) ...[
            const SizedBox(height: 14),
            LinearProgressIndicator(
              minHeight: 4,
              color: tokens.primaryButtonGradient.first,
              backgroundColor: tokens.chipColor.withOpacity(.7),
            ),
          ],
        ],
      ),
    );
  }
}

class _EntryPackCard extends StatelessWidget {
  const _EntryPackCard({
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
    final tokens = _entryPackTokens();
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
      padding: const EdgeInsets.all(16),
      gradient: LinearGradient(
        colors:
            pack.active
                ? tokens.primaryButtonGradient
                : tokens.cardGradient,
      ),
      child: Row(
        children: [
          _EntryPackArt(svgUrl: pack.svgUrl),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  pack.name,
                  style: TextStyle(
                    color: tokens.textPrimary,
                    fontWeight: FontWeight.w800,
                    fontSize: 16,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  '${pack.animationStyle.toUpperCase()} • ${pack.durationMs ~/ 1000}s • ${pack.durationDays}d • P${pack.priority}',
                  style: TextStyle(
                    color: tokens.textSecondary.withOpacity(.78),
                    fontWeight: FontWeight.w600,
                    fontSize: 12,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  pack.active
                      ? 'Currently active on your account.'
                      : isExpiredOwned
                      ? 'Your previous access expired. Purchase again to reactivate this entry.'
                      : pack.owned
                      ? 'Already purchased and still valid. Switch to this entry anytime.'
                      : 'Unlock for ${pack.priceCoins} coins with ${pack.durationDays} days of validity.',
                  style: TextStyle(
                    color: tokens.textSecondary.withOpacity(.88),
                    fontWeight: FontWeight.w500,
                    fontSize: 12,
                  ),
                ),
                if (ownedPack?.expiresAt != null) ...[
                  const SizedBox(height: 8),
                  Text(
                    '${isExpiredOwned ? 'Expired' : 'Valid until'} ${DateFormat.yMMMd().add_jm().format(ownedPack!.expiresAt!.toLocal())}',
                    style: TextStyle(
                      color:
                          (isExpiredOwned
                              ? const Color(0xFFFFC56B)
                              : tokens.textSecondary.withOpacity(.82)),
                      fontWeight: FontWeight.w700,
                      fontSize: 11,
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(width: 10),
          SizedBox(
            width: 118,
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
    );
  }
}

class _OwnedEntryPackCard extends StatelessWidget {
  const _OwnedEntryPackCard({required this.owned});

  final UserEntryPackDto owned;

  @override
  Widget build(BuildContext context) {
    final pack = owned.entryPack;
    final tokens = _entryPackTokens();
    return _GlassShell(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  pack?.name ?? 'Entry Pack',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    color: tokens.textPrimary,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              _StatusBadge(
                label:
                    owned.isExpired
                        ? 'EXPIRED'
                        : (owned.isActive ? 'ACTIVE' : 'OWNED'),
                color:
                    owned.isExpired
                        ? const Color(0xFFFFC56B)
                        : tokens.primaryButtonGradient.first,
                  ),
            ],
          ),
          const SizedBox(height: 12),
          _InfoLine(
            label: 'Style',
            value: pack?.animationStyle.toUpperCase() ?? 'UNKNOWN',
          ),
          if (pack != null)
            _InfoLine(
              label: 'Validity',
              value:
                  '${pack.durationDays} day${pack.durationDays == 1 ? '' : 's'}',
            ),
          if (owned.purchasedAt != null)
            _InfoLine(
              label: 'Purchased',
              value: DateFormat.yMMMd().add_jm().format(
                owned.purchasedAt!.toLocal(),
              ),
            ),
          if (owned.expiresAt != null)
            _InfoLine(
              label: owned.isExpired ? 'Expired' : 'Expires',
              value: DateFormat.yMMMd().add_jm().format(
                owned.expiresAt!.toLocal(),
              ),
            ),
        ],
      ),
    );
  }
}

class _EntryPackArt extends StatelessWidget {
  const _EntryPackArt({this.svgUrl, this.size = 60});

  final String? svgUrl;
  final double size;

  @override
  Widget build(BuildContext context) {
    final tokens = _entryPackTokens();
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: tokens.chipColor.withOpacity(.72),
        borderRadius: BorderRadius.circular(18),
      ),
      alignment: Alignment.center,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(16),
        child:
            svgUrl != null && svgUrl!.isNotEmpty
                ? SvgPicture.network(
                  svgUrl!,
                  width: size * .6,
                  height: size * .6,
                  placeholderBuilder:
                      (_) => Icon(
                        Icons.auto_awesome_rounded,
                        color: tokens.textPrimary,
                      ),
                    )
                : Icon(Icons.auto_awesome_rounded, color: tokens.textPrimary),
      ),
    );
  }
}

class _EntrySectionTitle extends StatelessWidget {
  const _EntrySectionTitle({required this.title, required this.subtitle});

  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    final tokens = _entryPackTokens();
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
          style: TextStyle(color: tokens.textSecondary.withOpacity(.78), height: 1.3),
        ),
      ],
    );
  }
}

class _EntryMessageCard extends StatelessWidget {
  const _EntryMessageCard({
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
    final tokens = _entryPackTokens();
    return Center(
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          color: tokens.glassColor.withOpacity(.8),
          borderRadius: BorderRadius.circular(22),
          border: Border.all(color: tokens.borderColor),
        ),
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
              FilledButton(onPressed: onTap, child: Text(actionLabel!)),
            ],
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
    this.gradient,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final Gradient? gradient;

  @override
  Widget build(BuildContext context) {
    final tokens = _entryPackTokens();
    return ClipRRect(
      borderRadius: BorderRadius.circular(24),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: Container(
          padding: padding,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(24),
            gradient:
                gradient ??
                LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: tokens.cardGradient,
                ),
            border: Border.all(color: tokens.borderColor),
            boxShadow: [
              BoxShadow(
                color: tokens.glowColor.withOpacity(.22),
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
    final tokens = _entryPackTokens();
    return Opacity(
      opacity: enabled ? 1 : .5,
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: enabled && !loading ? onTap : null,
          child: Ink(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: tokens.primaryButtonGradient,
              ),
              borderRadius: BorderRadius.circular(18),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (loading)
                  const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                else
                  Icon(icon, color: tokens.textPrimary, size: 16),
                const SizedBox(width: 8),
                Flexible(
                  child: Text(
                    label,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: tokens.textPrimary,
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

class _HeroPill extends StatelessWidget {
  const _HeroPill({required this.icon, required this.label});

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

class _StatusBadge extends StatelessWidget {
  const _StatusBadge({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: color.withOpacity(.14),
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

class _InfoLine extends StatelessWidget {
  const _InfoLine({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Row(
        children: [
          SizedBox(
            width: 84,
            child: Text(
              label,
              style: TextStyle(
                color: Colors.white.withOpacity(.58),
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
