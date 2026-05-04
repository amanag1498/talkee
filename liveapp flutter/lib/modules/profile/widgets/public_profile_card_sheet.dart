import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';
import '../../../services/auth_service.dart';
import '../../Live/widgets/themed_room_frame.dart';
import '../controllers/host_follow_controller.dart';
import '../models/profile_dto.dart';
import '../services/profile_api.dart';

Future<void> showPublicProfileCardSheet(
  BuildContext context, {
  required int userId,
  String? initialName,
  String? initialSubtitle,
  String? initialThemeKey,
  bool initialIsVip = false,
  bool initialIsHost = false,
  bool initialSpeaking = false,
  int? initialLevel,
  String? initialAvatarUrl,
}) {
  return showGeneralDialog<void>(
    context: context,
    barrierLabel: 'Profile Card',
    barrierDismissible: true,
    barrierColor: Colors.transparent,
    transitionDuration: const Duration(milliseconds: 240),
    pageBuilder:
        (_, __, ___) => _PremiumProfileOverlay(
          child: _PublicProfileCardSheet(
            userId: userId,
            initialName: initialName,
            initialSubtitle: initialSubtitle,
            initialThemeKey: initialThemeKey,
            initialIsVip: initialIsVip,
            initialIsHost: initialIsHost,
            initialSpeaking: initialSpeaking,
            initialLevel: initialLevel,
            initialAvatarUrl: initialAvatarUrl,
          ),
        ),
    transitionBuilder: (_, animation, __, child) {
      final curved = CurvedAnimation(
        parent: animation,
        curve: Curves.easeOutCubic,
        reverseCurve: Curves.easeInCubic,
      );
      return FadeTransition(
        opacity: curved,
        child: ScaleTransition(
          scale: Tween<double>(
            begin: 0.94,
            end: 1,
          ).animate(curved),
          child: child,
        ),
      );
    },
  );
}

class _PremiumProfileOverlay extends StatelessWidget {
  const _PremiumProfileOverlay({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );

    return Material(
      color: Colors.transparent,
      child: LayoutBuilder(
        builder: (context, constraints) {
          final maxHeight = constraints.maxHeight * 0.76;
          return Stack(
            children: [
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: RadialGradient(
                      center: const Alignment(0, -0.2),
                      radius: 1.15,
                      colors: [
                        tokens.glowColor.withOpacity(.16),
                        Colors.black.withOpacity(.76),
                        Colors.black.withOpacity(.88),
                      ],
                    ),
                  ),
                  child: BackdropFilter(
                    filter: ImageFilter.blur(sigmaX: 16, sigmaY: 16),
                    child: const SizedBox.expand(),
                  ),
                ),
              ),
              SafeArea(
                child: Center(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 18,
                      vertical: 18,
                    ),
                    child: ConstrainedBox(
                      constraints: BoxConstraints(
                        maxWidth: 440,
                        maxHeight: maxHeight,
                      ),
                      child: child,
                    ),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _PublicProfileCardSheet extends StatefulWidget {
  const _PublicProfileCardSheet({
    required this.userId,
    this.initialName,
    this.initialSubtitle,
    this.initialThemeKey,
    this.initialIsVip = false,
    this.initialIsHost = false,
    this.initialSpeaking = false,
    this.initialLevel,
    this.initialAvatarUrl,
  });

  final int userId;
  final String? initialName;
  final String? initialSubtitle;
  final String? initialThemeKey;
  final bool initialIsVip;
  final bool initialIsHost;
  final bool initialSpeaking;
  final int? initialLevel;
  final String? initialAvatarUrl;

  @override
  State<_PublicProfileCardSheet> createState() => _PublicProfileCardSheetState();
}

class _PublicProfileCardSheetState extends State<_PublicProfileCardSheet> {
  late final ProfileApi _profiles;
  late final HostFollowController _follows;
  ProfileDto? _profile;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _profiles = Get.find<ProfileApi>();
    _follows = Get.find<HostFollowController>();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final profile = await _profiles.fetchPublicProfile(widget.userId);
      _follows.hydrateFromHostCard(_hostHydrationMap(profile));
      if (!mounted) return;
      setState(() {
        _profile = profile;
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

  Map<String, dynamic> _hostHydrationMap(ProfileDto profile) {
    return {
      'id': profile.id,
      'host_id': profile.hostId,
      'is_following': profile.isFollowing,
      'follower_count': profile.followersCount ?? 0,
      'host_profile': {'host_id': profile.hostId},
    };
  }

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final profile = _profile;
    final resolvedThemeKey = normalizePremiumThemeVariant(
      profile?.activeThemeKey ?? widget.initialThemeKey ?? 'midnight',
    );
    final frameTokens = getPremiumThemeTokens(resolvedThemeKey);
    final displayName =
        (profile?.displayName?.trim().isNotEmpty == true
            ? profile!.displayName!.trim()
            : profile?.name.trim().isNotEmpty == true
            ? profile!.name.trim()
            : widget.initialName?.trim().isNotEmpty == true
            ? widget.initialName!.trim()
            : 'Profile');
    final subtitle = _subtitleFor(profile);
    final isHost = profile?.isHost ?? widget.initialIsHost;
    final isVip = profile?.isVip ?? widget.initialIsVip;
    final speaking = widget.initialSpeaking;
    final level = profile?.level ?? widget.initialLevel;
    final avatarUrl = profile?.avatarUrl ?? widget.initialAvatarUrl;
    final followerCount = profile?.followersCount ?? 0;
    final joinedLabel =
        profile?.joinedAt != null
            ? 'Joined ${DateFormat('MMM yyyy').format(profile!.joinedAt!)}'
            : null;

    return Material(
      color: Colors.transparent,
      child: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [
              tokens.cardGradient.first.withOpacity(.98),
              tokens.cardGradient.last.withOpacity(.96),
              tokens.backgroundGradient.last.withOpacity(.95),
            ],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(34),
          border: Border.all(color: tokens.borderColor.withOpacity(.22)),
          boxShadow: [
            BoxShadow(
              color: tokens.glowColor.withOpacity(.22),
              blurRadius: 36,
              offset: const Offset(0, 20),
            ),
            BoxShadow(
              color: Colors.black.withOpacity(.30),
              blurRadius: 40,
              offset: const Offset(0, 16),
            ),
          ],
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(34),
          child: Stack(
            children: [
              Positioned(
                top: -8,
                left: 18,
                right: 18,
                child: Container(
                  height: 4,
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors: frameTokens.primaryButtonGradient,
                    ),
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
              ),
              Positioned(
                top: -22,
                right: -12,
                child: _GlowOrb(
                  color: tokens.glowColor.withOpacity(.10),
                  size: 132,
                ),
              ),
              Positioned(
                top: 74,
                left: -20,
                child: _GlowOrb(
                  color: frameTokens.primaryButtonGradient.last.withOpacity(.10),
                  size: 92,
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 12,
                            vertical: 7,
                          ),
                          decoration: BoxDecoration(
                            color: tokens.glassColor.withOpacity(.16),
                            borderRadius: BorderRadius.circular(999),
                            border: Border.all(
                              color: tokens.borderColor.withOpacity(.16),
                            ),
                          ),
                          child: Text(
                            'PROFILE',
                            style: TextStyle(
                              color: tokens.textSecondary,
                              fontSize: 10.5,
                              fontWeight: FontWeight.w900,
                              letterSpacing: .8,
                            ),
                          ),
                        ),
                        const Spacer(),
                        IconButton(
                          onPressed: () => Navigator.of(context).maybePop(),
                          style: IconButton.styleFrom(
                            backgroundColor: tokens.glassColor.withOpacity(.14),
                            foregroundColor: tokens.textPrimary,
                            side: BorderSide(
                              color: tokens.borderColor.withOpacity(.16),
                            ),
                          ),
                          icon: const Icon(Icons.close_rounded),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    Flexible(
                      fit: FlexFit.loose,
                      child: SingleChildScrollView(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            if (_loading && profile == null)
                              const _ProfileCardLoading()
                            else if (_error != null && profile == null)
                              _ProfileCardError(message: _error!, onRetry: _load)
                            else ...[
                              _ProfileHero(
                                userId: profile?.id ?? widget.userId,
                                tokens: tokens,
                                frameThemeKey: resolvedThemeKey,
                                frameTokens: frameTokens,
                                displayName: displayName,
                                subtitle: subtitle,
                                avatarUrl: avatarUrl,
                                isHost: isHost,
                                isVip: isVip,
                                isSpeaking: speaking,
                                level: level,
                                joinedLabel: joinedLabel,
                              ),
                              if (profile != null) ...[
                                const SizedBox(height: 14),
                                _HostFollowSection(
                                  profile: profile,
                                  follows: _follows,
                                ),
                              ],
                              const SizedBox(height: 16),
                              _ProfileStatsRow(
                                tokens: tokens,
                                followers: followerCount,
                                level: level,
                                city: profile?.hostProfile?.city ?? profile?.city,
                              ),
                              if ((profile?.bio ?? profile?.hostProfile?.bio ?? '')
                                  .trim()
                                  .isNotEmpty) ...[
                                const SizedBox(height: 14),
                                _ProfileInfoPanel(
                                  tokens: tokens,
                                  icon: Icons.notes_rounded,
                                  title: 'About',
                                  body:
                                      (profile?.hostProfile?.bio ?? profile?.bio ?? '')
                                          .trim(),
                                ),
                              ],
                              if (profile?.hostProfile?.agency?.name?.trim().isNotEmpty ==
                                  true) ...[
                                const SizedBox(height: 12),
                                _ProfileInfoPanel(
                                  tokens: tokens,
                                  icon: Icons.apartment_rounded,
                                  title: 'Agency',
                                  body: profile!.hostProfile!.agency!.name!.trim(),
                                ),
                              ],
                              if (_loading && profile != null) ...[
                                const SizedBox(height: 12),
                                _RefreshingStrip(tokens: tokens),
                              ],
                              if (_error != null && profile != null) ...[
                                const SizedBox(height: 12),
                                _ProfileInlineError(tokens: tokens, message: _error!),
                              ],
                            ],
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _subtitleFor(ProfileDto? profile) {
    final fallback = widget.initialSubtitle?.trim();
    if (profile == null) return fallback?.isNotEmpty == true ? fallback! : 'Room participant';
    if (profile.isHost) {
      final stageName = profile.hostProfile?.stageName?.trim();
      if (stageName != null && stageName.isNotEmpty && stageName != profile.name) {
        return stageName;
      }
      return 'Host';
    }
    if (profile.isAgency) return 'Agency member';
    if (profile.isVip) return 'VIP member';
    return fallback?.isNotEmpty == true ? fallback! : 'Room participant';
  }
}

class _ProfileHero extends StatelessWidget {
  const _ProfileHero({
    required this.userId,
    required this.tokens,
    required this.frameThemeKey,
    required this.frameTokens,
    required this.displayName,
    required this.subtitle,
    required this.isHost,
    required this.isVip,
    required this.isSpeaking,
    this.avatarUrl,
    this.level,
    this.joinedLabel,
  });

  final int userId;
  final PremiumThemeTokens tokens;
  final String frameThemeKey;
  final PremiumThemeTokens frameTokens;
  final String displayName;
  final String subtitle;
  final bool isHost;
  final bool isVip;
  final bool isSpeaking;
  final String? avatarUrl;
  final int? level;
  final String? joinedLabel;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        ThemedRoomFrame(
          themeKey: frameThemeKey,
          isHost: isHost,
          isVip: isVip,
          isSpeaking: isSpeaking,
          borderRadius: 26,
          size: 76,
          child: Container(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(26),
              gradient: LinearGradient(
                colors: [
                  frameTokens.primaryButtonGradient.first.withOpacity(.94),
                  frameTokens.primaryButtonGradient.last.withOpacity(.94),
                ],
              ),
            ),
            child: _ProfileAvatarFace(
              label: displayName,
              avatarUrl: avatarUrl,
              textColor: frameTokens.textPrimary,
            ),
          ),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                displayName,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: tokens.textPrimary,
                  fontSize: 20,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                subtitle,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: tokens.textSecondary.withOpacity(.88),
                  fontSize: 12.2,
                  fontWeight: FontWeight.w700,
                  letterSpacing: .24,
                ),
              ),
              const SizedBox(height: 5),
              Text(
                'User ID #$userId',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: tokens.textSecondary.withOpacity(.72),
                  fontSize: 11.4,
                  fontWeight: FontWeight.w800,
                  letterSpacing: .16,
                ),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  if (isSpeaking)
                    _ProfileChip(
                      label: 'Speaking',
                      background: frameTokens.successColor.withOpacity(.22),
                      foreground: tokens.textPrimary,
                    ),
                  if (isHost)
                    _ProfileChip(
                      label: 'Host',
                      background: frameTokens.primaryButtonGradient.last,
                      foreground: Colors.white,
                    ),
                  if (isVip)
                    _ProfileChip(
                      label: 'VIP',
                      background: frameTokens.primaryButtonGradient.first,
                      foreground: Colors.white,
                    ),
                  if (level != null)
                    _ProfileChip(
                      label: 'LV $level',
                      background: tokens.glassColor.withOpacity(.22),
                      foreground: tokens.textPrimary,
                    ),
                  if (joinedLabel != null)
                    _ProfileChip(
                      label: joinedLabel!,
                      background: tokens.glassColor.withOpacity(.16),
                      foreground: tokens.textSecondary,
                    ),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _HostFollowSection extends StatelessWidget {
  const _HostFollowSection({
    required this.profile,
    required this.follows,
  });

  final ProfileDto profile;
  final HostFollowController follows;

  @override
  Widget build(BuildContext context) {
    if (profile.hostId == null || profile.hostId! <= 0) {
      return const SizedBox.shrink();
    }

    final isSelf = profile.id == _currentUserId();
    if (isSelf) return const SizedBox.shrink();

    return Obx(() {
      final hostId = profile.hostId!;
      final isFollowing = follows.isFollowing(
        hostId,
        fallback: profile.isFollowing,
      );
      final followerCount = follows.followerCount(
        hostId,
        fallback: profile.followersCount ?? 0,
      );
      final busy = follows.isBusy(hostId);
      final tokens = getPremiumThemeTokens(
        Get.find<AppSettingsService>().activePremiumThemeVariant,
      );

      return Row(
        children: [
          Expanded(
            child: Text(
              '$followerCount followers',
              style: TextStyle(
                color: tokens.textSecondary.withOpacity(.92),
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          const SizedBox(width: 10),
          if (isFollowing)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: tokens.glassColor.withOpacity(.16),
                borderRadius: BorderRadius.circular(999),
                border: Border.all(color: tokens.borderColor.withOpacity(.18)),
              ),
              child: Text(
                'Following',
                style: TextStyle(
                  color: tokens.textPrimary,
                  fontWeight: FontWeight.w800,
                ),
              ),
            )
          else
            InkWell(
              onTap:
                  busy
                      ? null
                      : () => follows.toggleForHost(
                        hostId: hostId,
                        current: isFollowing,
                        currentCount: followerCount,
                      ),
              borderRadius: BorderRadius.circular(999),
              child: Ink(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                decoration: BoxDecoration(
                  gradient: LinearGradient(colors: tokens.primaryButtonGradient),
                  borderRadius: BorderRadius.circular(999),
                  boxShadow: [
                    BoxShadow(
                      color: tokens.glowColor.withOpacity(.20),
                      blurRadius: 16,
                      offset: const Offset(0, 8),
                    ),
                  ],
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (busy)
                      SizedBox(
                        width: 14,
                        height: 14,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          valueColor: AlwaysStoppedAnimation<Color>(
                            tokens.textPrimary,
                          ),
                        ),
                      )
                    else ...[
                      Icon(
                        Icons.add_rounded,
                        size: 16,
                        color: tokens.textPrimary,
                      ),
                      const SizedBox(width: 6),
                    ],
                    Text(
                      'Follow Host',
                      style: TextStyle(
                        color: tokens.textPrimary,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                  ],
                ),
              ),
            ),
        ],
      );
    });
  }

  int? _currentUserId() {
    try {
      return Get.find<AuthService>().currentUser?.id;
    } catch (_) {
      return null;
    }
  }
}

class _ProfileStatsRow extends StatelessWidget {
  const _ProfileStatsRow({
    required this.tokens,
    required this.followers,
    this.level,
    this.city,
  });

  final PremiumThemeTokens tokens;
  final int followers;
  final int? level;
  final String? city;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: _StatLine(
            tokens: tokens,
            label: 'Followers',
            value: followers.toString(),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _StatLine(
            tokens: tokens,
            label: 'Level',
            value: level?.toString() ?? '--',
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _StatLine(
            tokens: tokens,
            label: 'City',
            value: (city?.trim().isNotEmpty == true) ? city!.trim() : '--',
          ),
        ),
      ],
    );
  }
}

class _StatLine extends StatelessWidget {
  const _StatLine({
    required this.tokens,
    required this.label,
    required this.value,
  });

  final PremiumThemeTokens tokens;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label.toUpperCase(),
          style: TextStyle(
            color: tokens.textSecondary.withOpacity(.72),
            fontSize: 10.2,
            fontWeight: FontWeight.w800,
            letterSpacing: .7,
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
            fontWeight: FontWeight.w900,
          ),
        ),
      ],
    );
  }
}

class _ProfileInfoPanel extends StatelessWidget {
  const _ProfileInfoPanel({
    required this.tokens,
    required this.icon,
    required this.title,
    required this.body,
  });

  final PremiumThemeTokens tokens;
  final IconData icon;
  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(icon, color: tokens.textPrimary.withOpacity(.88), size: 16),
            const SizedBox(width: 8),
            Text(
              title,
              style: TextStyle(
                color: tokens.textPrimary,
                fontWeight: FontWeight.w800,
                fontSize: 12.5,
              ),
            ),
          ],
        ),
        const SizedBox(height: 6),
        Text(
          body,
          style: TextStyle(
            color: tokens.textSecondary.withOpacity(.92),
            fontWeight: FontWeight.w600,
            height: 1.34,
          ),
        ),
      ],
    );
  }
}

class _ProfileChip extends StatelessWidget {
  const _ProfileChip({
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
          fontSize: 10.8,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}

class _ProfileAvatarFace extends StatelessWidget {
  const _ProfileAvatarFace({
    required this.label,
    required this.textColor,
    this.avatarUrl,
  });

  final String label;
  final String? avatarUrl;
  final Color textColor;

  @override
  Widget build(BuildContext context) {
    final hasAvatar = avatarUrl != null && avatarUrl!.trim().isNotEmpty;
    if (hasAvatar) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(28),
        child: Image.network(
          avatarUrl!.trim(),
          fit: BoxFit.cover,
          errorBuilder:
              (_, __, ___) => Center(
                child: Text(
                  label.isNotEmpty ? label.characters.first.toUpperCase() : '?',
                  style: TextStyle(
                    color: textColor,
                    fontWeight: FontWeight.w900,
                    fontSize: 28,
                  ),
                ),
              ),
        ),
      );
    }
    return Center(
      child: Text(
        label.isNotEmpty ? label.characters.first.toUpperCase() : '?',
        style: TextStyle(
          color: textColor,
          fontWeight: FontWeight.w900,
          fontSize: 28,
        ),
      ),
    );
  }
}

class _GlowOrb extends StatelessWidget {
  const _GlowOrb({required this.color, required this.size});

  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(shape: BoxShape.circle, color: color),
    );
  }
}

class _ProfileCardLoading extends StatelessWidget {
  const _ProfileCardLoading();

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.symmetric(vertical: 32),
      child: Center(child: CircularProgressIndicator()),
    );
  }
}

class _ProfileCardError extends StatelessWidget {
  const _ProfileCardError({
    required this.message,
    required this.onRetry,
  });

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        children: [
          const SizedBox(height: 20),
          const Icon(Icons.error_outline_rounded, color: Colors.white70, size: 28),
          const SizedBox(height: 10),
          Text(
            message,
            textAlign: TextAlign.center,
            style: const TextStyle(color: Colors.white70),
          ),
          const SizedBox(height: 14),
          FilledButton.icon(
            onPressed: onRetry,
            icon: const Icon(Icons.refresh_rounded),
            label: const Text('Retry'),
          ),
        ],
      ),
    );
  }
}

class _RefreshingStrip extends StatelessWidget {
  const _RefreshingStrip({required this.tokens});

  final PremiumThemeTokens tokens;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        SizedBox(
          width: 14,
          height: 14,
          child: CircularProgressIndicator(
            strokeWidth: 2,
            valueColor: AlwaysStoppedAnimation<Color>(tokens.textPrimary),
          ),
        ),
        const SizedBox(width: 8),
        Text(
          'Refreshing profile details...',
          style: TextStyle(
            color: tokens.textSecondary.withOpacity(.84),
            fontSize: 11.5,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    );
  }
}

class _ProfileInlineError extends StatelessWidget {
  const _ProfileInlineError({
    required this.tokens,
    required this.message,
  });

  final PremiumThemeTokens tokens;
  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: tokens.dangerColor.withOpacity(.14),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: tokens.dangerColor.withOpacity(.24)),
      ),
      child: Row(
        children: [
          Icon(
            Icons.error_outline_rounded,
            color: tokens.textPrimary,
            size: 16,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: TextStyle(
                color: tokens.textPrimary,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
