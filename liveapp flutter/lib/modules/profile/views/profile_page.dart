import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/routes/app_routes.dart';
import '../../../app/theme/brand.dart';
import '../../../app/utils/avatar_url.dart';
import '../../../app/widgets/framed_avatar.dart';
import '../../../app/widgets/haptics.dart';
import '../../../services/api_client.dart';
import '../../../services/app_settings_service.dart';
import '../../applications/controllers/applications_controller.dart';
import '../../applications/views/my_applications_page.dart';
import '../controllers/host_follow_controller.dart';
import '../../wallet/widgets/recharge_bottom_sheet.dart';
import '../controllers/profile_controller.dart';
import '../models/profile_dto.dart';

PremiumThemeTokens _profileTokens() =>
    getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );

class ProfilePage extends StatefulWidget {
  const ProfilePage({super.key});

  @override
  State<ProfilePage> createState() => _ProfilePageState();
}

class _ProfilePageState extends State<ProfilePage>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  late final AnimationController _bgMotion;
  _HostReportRange _hostReportRange = _HostReportRange.today;

  ProfileController get controller => Get.find<ProfileController>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _bgMotion = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 18),
    )..repeat();
    controller.load();
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
      controller.load();
    }
  }

  @override
  Widget build(BuildContext context) {
    final api = Get.find<ApiClient>();
    final appSettings = Get.find<AppSettingsService>();
    final applicationsController = Get.find<ApplicationsController>();
    return Obx(() {
      final tokens = _profileTokens();
      return Scaffold(
        backgroundColor: tokens.backgroundGradient.first,
        appBar: AppBar(
          title: const Text('Profile'),
          backgroundColor: Colors.transparent,
          elevation: 0,
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
            Obx(() {
            if (controller.isLoading.value &&
                controller.profile.value == null) {
              return const Center(
                child: CircularProgressIndicator(color: Colors.white),
              );
            }

            final profile = controller.profile.value;
            if (profile == null) {
              return Center(
                child: _GlassMessageCard(
                  icon: Icons.sync_problem_rounded,
                  title: 'Unable to load profile',
                  subtitle: controller.error.value ?? 'Please retry.',
                  actionLabel: 'Retry',
                  onAction: controller.load,
                ),
              );
            }

            final avatar = resolveAvatarUrl(api, profile.avatarUrl);
            final roleLabels =
                profile.roles.isEmpty ? const ['user'] : profile.roles;
            final latestEnroll = applicationsController.latestByType(
              'host_enroll',
            );
            final host = profile.hostProfile;
            final agency = host?.agency;
            final agencyBlocked = agency?.isBlocked == true;
            final follows = Get.find<HostFollowController>();
            final canShowEnrollToAgency =
                latestEnroll?.isPending != true &&
                profile.status.agencyAttached != true;
            final location = [
                  profile.location,
                  profile.city,
                  host?.city,
                  host?.country,
                ]
                .where((e) => e != null && e.trim().isNotEmpty)
                .map((e) => e!.trim())
                .toSet()
                .join(', ');
            String? about;
            for (final candidate in [profile.bio, host?.bio]) {
              if (candidate != null && candidate.trim().isNotEmpty) {
                about = candidate.trim();
                break;
              }
            }

            return RefreshIndicator(
              onRefresh: controller.load,
              color: Colors.white,
              backgroundColor: tokens.primaryButtonGradient.last,
              child: ListView(
                physics: const BouncingScrollPhysics(),
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
                children: [
                  _AnimatedEntrance(
                    index: 0,
                    child: _ProfileHeaderCard(
                      profile: profile,
                      avatarUrl: avatar,
                      profileFrameUrl: profile.profileFrame?.assetUrl,
                      roleLabels: roleLabels,
                      location: location.isEmpty ? null : location,
                      about: about,
                      onEdit: () {
                        Haptics.light();
                        Get.toNamed(Routes.editProfile);
                      },
                    ),
                  ),
                  const SizedBox(height: 16),
                  _AnimatedEntrance(
                    index: 1,
                    child: _GlassSection(
                      title: 'Account Details',
                      subtitle: 'Safe rendering of available profile data',
                      child: Column(
                        children: [
                          _InfoLine(label: 'Email', value: profile.email),
                          _InfoLine(
                            label: 'Role',
                            value: roleLabels.join(' • ').toUpperCase(),
                          ),
                          _InfoLine(
                            label: 'Host Status',
                            value:
                                !profile.isHost
                                    ? 'Not a host'
                                    : (profile.status.hostBlocked ||
                                        host?.isBlocked == true)
                                    ? 'Host blocked by admin'
                                    : 'Host enabled',
                          ),
                          _InfoLine(
                            label: 'Agency Status',
                            value:
                                profile.status.agencyAttached
                                    ? (agencyBlocked
                                        ? 'Agency attached but blocked'
                                        : 'Agency attached')
                                    : (profile.isAgency
                                        ? 'Agency account'
                                        : 'Not attached'),
                          ),
                          if (location.isNotEmpty)
                            _InfoLine(label: 'Location', value: location),
                          if (about != null && about.isNotEmpty)
                            _InfoLine(label: 'About', value: about),
                          if (profile.joinedAt != null)
                            _InfoLine(
                              label: 'Joined',
                              value: DateFormat(
                                'dd MMM yyyy',
                              ).format(profile.joinedAt!),
                            ),
                          if (host != null) ...[
                            if ((host.stageName ?? '').isNotEmpty)
                              _InfoLine(
                                label: 'Stage Name',
                                value: host.stageName!,
                              ),
                            if ((host.contactPhone ?? '').isNotEmpty)
                              _InfoLine(
                                label: 'Phone',
                                value: host.contactPhone!,
                              ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  _AnimatedEntrance(
                    index: 2,
                    child: _ProfileFramesCard(
                      controller: controller,
                      profile: profile,
                      avatarUrl: avatar,
                    ),
                  ),
                  const SizedBox(height: 16),
                  if (agency != null)
                    _AnimatedEntrance(
                      index: 3,
                      child: _GlassSection(
                        title: 'Agency Details',
                        subtitle:
                            'Current agency attached to this host account',
                        child: Column(
                          children: [
                            if ((agency.name ?? '').isNotEmpty)
                              _InfoLine(label: 'Agency', value: agency.name!),
                            if ((agency.legalName ?? '').isNotEmpty)
                              _InfoLine(
                                label: 'Legal Name',
                                value: agency.legalName!,
                              ),
                            if ((agency.ownerName ?? '').isNotEmpty)
                              _InfoLine(
                                label: 'Owner',
                                value: agency.ownerName!,
                              ),
                            if ((agency.contactEmail ?? '').isNotEmpty)
                              _InfoLine(
                                label: 'Agency Email',
                                value: agency.contactEmail!,
                              ),
                            if ((agency.contactPhone ?? '').isNotEmpty)
                              _InfoLine(
                                label: 'Agency Phone',
                                value: agency.contactPhone!,
                              ),
                            _InfoLine(
                              label: 'Status',
                              value: agency.isBlocked ? 'Blocked' : 'Active',
                            ),
                          ],
                        ),
                      ),
                    ),
                  if (agency != null) const SizedBox(height: 16),
                  _AnimatedEntrance(
                    index: 3,
                    child: Row(
                      children: [
                        Expanded(
                          child: _MetricCard(
                            icon: Icons.account_balance_wallet_rounded,
                            title: 'Balance',
                            value:
                                '${NumberFormat.compact().format(profile.walletBalance)} coins',
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: _MetricCard(
                            icon: Icons.trending_up_rounded,
                            title: 'Lifetime Spend',
                            value:
                                profile.lifetimeSpendCoins == null
                                    ? 'Unavailable'
                                    : '${NumberFormat.compact().format(profile.lifetimeSpendCoins)} coins',
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  _AnimatedEntrance(
                    index: 4,
                    child: Row(
                      children: [
                        Expanded(
                          child: _MetricCard(
                            icon: Icons.calendar_month_rounded,
                            title: 'Joined',
                            value:
                                profile.joinedAt == null
                                    ? 'Unavailable'
                                    : DateFormat(
                                      'dd MMM yyyy',
                                    ).format(profile.joinedAt!),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: _MetricCard(
                            icon: Icons.badge_rounded,
                            title: 'Level',
                            value:
                                profile.levelTitle?.trim().isNotEmpty == true
                                    ? profile.levelTitle!.trim()
                                    : (profile.level == null
                                        ? 'Member'
                                        : 'Level ${profile.level}'),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  _AnimatedEntrance(
                    index: 5,
                    child: Row(
                      children: [
                        Expanded(
                          child: _MetricCard(
                            icon: Icons.favorite_outline_rounded,
                            title: 'Following',
                            value: '${profile.followingCount ?? 0}',
                            onTap: () => Get.toNamed(Routes.following),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: _MetricCard(
                            icon: Icons.people_alt_rounded,
                            title: profile.isHost ? 'Followers' : 'Audience',
                            value:
                                '${profile.isHost ? (profile.followersCount ?? 0) : 0}',
                            onTap:
                                profile.isHost
                                    ? () {
                                      follows.loadFollowers();
                                      Get.toNamed(Routes.followers);
                                    }
                                    : null,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  if (profile.isHost) ...[
                    _AnimatedEntrance(
                      index: 6,
                      child: _buildHostGoalsSection(profile),
                    ),
                    const SizedBox(height: 16),
                    _AnimatedEntrance(
                      index: 7,
                      child: _buildHostEarningsSection(),
                    ),
                    const SizedBox(height: 16),
                  ],
                  _AnimatedEntrance(
                    index: 8,
                    child: _GlassSection(
                      title: 'Quick Actions',
                      subtitle: 'Wallet, applications, and account upgrades',
                      child: Column(
                        children: [
                          if (appSettings.walletRechargeEnabled) ...[
                            _ProfileActionTile(
                              icon: Icons.account_balance_wallet_rounded,
                              title: 'Wallet / Recharge',
                              subtitle: 'Top up coins and view recharge packs',
                              onTap:
                                  () => Get.bottomSheet(
                                    const RechargeBottomSheet(),
                                    isScrollControlled: true,
                                  ),
                            ),
                            _DividerLine(),
                          ],
                          _ProfileActionTile(
                            icon: Icons.assignment_rounded,
                            title: 'My Applications',
                            subtitle:
                                'Track application status and review notes',
                            onTap: () => showMyApplicationsSheet(),
                          ),
                          if (profile.isNormalUser) ...[
                            _DividerLine(),
                            _ProfileActionTile(
                              icon: Icons.mic_external_on_rounded,
                              title: 'Apply Host',
                              subtitle: 'Submit a host application',
                              onTap: () => Get.toNamed(Routes.applyHost),
                            ),
                            _DividerLine(),
                            _ProfileActionTile(
                              icon: Icons.apartment_rounded,
                              title: 'Apply Agency',
                              subtitle: 'Submit an agency application',
                              onTap: () => Get.toNamed(Routes.applyAgency),
                            ),
                          ],
                          if (profile.isHost &&
                              !profile.isAgency &&
                              !profile.isAdmin &&
                              canShowEnrollToAgency) ...[
                            _DividerLine(),
                            _ProfileActionTile(
                              icon: Icons.groups_rounded,
                              title: 'Enroll to Agency',
                              subtitle: 'Join an agency as an existing host',
                              onTap: () => Get.toNamed(Routes.enrollAgency),
                            ),
                          ],
                          if (profile.isHost) ...[
                            _DividerLine(),
                            _ProfileActionTile(
                              icon: Icons.event_available_rounded,
                              title: 'Scheduled Lives',
                              subtitle: 'Start or cancel upcoming rooms you created',
                              onTap: () => Get.toNamed(Routes.profileScheduledLives),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  if (profile.isHost) ...[
                    const SizedBox(height: 16),
                    _AnimatedEntrance(
                      index: 8,
                      child: _GlassSection(
                        title: 'Moderation',
                        subtitle:
                            'Blocked users, appeals, and room moderation history',
                        child: Column(
                          children: [
                            _ProfileActionTile(
                              icon: Icons.block_rounded,
                              title: 'Blocked Users',
                              subtitle: 'Review and unblock users from your rooms',
                              onTap: () => Get.toNamed(Routes.profileBlockedUsers),
                            ),
                            _DividerLine(),
                            _ProfileActionTile(
                              icon: Icons.mark_email_unread_rounded,
                              title: 'Unblock Requests',
                              subtitle: 'Review pending unblock appeals',
                              onTap:
                                  () => Get.toNamed(Routes.profileUnblockRequests),
                            ),
                            _DividerLine(),
                            _ProfileActionTile(
                              icon: Icons.history_rounded,
                              title: 'Moderation History',
                              subtitle: 'View recent moderation actions',
                              onTap:
                                  () => Get.toNamed(
                                    Routes.profileModerationHistory,
                                  ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                  const SizedBox(height: 16),
                ],
              ),
            );
            }),
          ],
        ),
      );
    });
  }

  static Future<void> _openExternal(String raw) async {
    final uri = Uri.parse(raw);
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }

  Widget _buildHostGoalsSection(ProfileDto profile) {
    final report = controller.hostReport.value;
    final globalGoals = Get.find<AppSettingsService>().hostGoals;
    final overrides = profile.hostProfile?.goalOverrides;
    final currentWeek = report?.currentWeek.summary;
    final followerCount = profile.followersCount ?? 0;
    final liveMinutes =
        (currentWeek?.totalAudioRoomMinutes ?? 0) +
        (currentWeek?.totalVideoRoomMinutes ?? 0);
    final giftCoins = currentWeek?.totalGiftedCoins ?? 0;

    final followerMilestones =
        overrides?.followers.isNotEmpty == true
            ? overrides!.followers
            : globalGoals.followers;
    final minuteMilestones =
        overrides?.weeklyLiveMinutes.isNotEmpty == true
            ? overrides!.weeklyLiveMinutes
            : globalGoals.weeklyLiveMinutes;
    final giftMilestones =
        overrides?.weeklyGiftedCoins.isNotEmpty == true
            ? overrides!.weeklyGiftedCoins
            : globalGoals.weeklyGiftedCoins;

    final followerGoal = _nextGoal(followerCount, followerMilestones);
    final minutesGoal = _nextGoal(liveMinutes, minuteMilestones);
    final giftGoal = _nextGoal(giftCoins, giftMilestones);

    return _GlassSection(
      title: 'Host Goals',
      subtitle: 'Retention targets for followers, weekly live time, and gifting momentum',
      child: Column(
        children: [
          _GoalProgressTile(
            icon: Icons.people_alt_rounded,
            title: 'Follower milestone',
            current: followerCount,
            target: followerGoal,
            suffix: 'followers',
          ),
          const SizedBox(height: 10),
          _GoalProgressTile(
            icon: Icons.schedule_rounded,
            title: 'Weekly live minutes',
            current: liveMinutes,
            target: minutesGoal,
            suffix: 'minutes',
          ),
          const SizedBox(height: 10),
          _GoalProgressTile(
            icon: Icons.redeem_rounded,
            title: 'Weekly gifted coins',
            current: giftCoins,
            target: giftGoal,
            suffix: 'coins',
          ),
        ],
      ),
    );
  }

  Widget _buildHostEarningsSection() {
    final report = controller.hostReport.value;
    final loading = controller.isLoadingHostReport.value && report == null;
    final period = switch (_hostReportRange) {
      _HostReportRange.today => report?.today,
      _HostReportRange.currentWeek => report?.currentWeek,
      _HostReportRange.lastWeek => report?.lastWeek,
    };
    final summary = period?.summary;
    final grandTotalCoins =
        summary == null
            ? 0
            : summary.totalGiftedCoins +
                summary.audioCallEarnings +
                summary.videoCallEarnings;

    return _GlassSection(
      title: 'Earnings Report',
      subtitle: 'Today, this week, and last week for host calls, rooms, gifts, and PK',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              for (final range in _HostReportRange.values) ...[
                Expanded(
                  child: _HostReportRangeChip(
                    label: range.label,
                    selected: _hostReportRange == range,
                    onTap: () => setState(() => _hostReportRange = range),
                  ),
                ),
                if (range != _HostReportRange.values.last) const SizedBox(width: 8),
              ],
            ],
          ),
          const SizedBox(height: 14),
          if (loading)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 20),
              child: Center(child: CircularProgressIndicator(color: Colors.white)),
            )
          else if (summary == null)
            _GlassMessageCard(
              icon: Icons.query_stats_rounded,
              title: 'Report unavailable',
              subtitle: controller.error.value ?? 'No host earnings data is available yet.',
              actionLabel: 'Retry',
              onAction: controller.loadHostReport,
            )
          else ...[
            if ((period?.label ?? '').isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Text(
                  period!.label,
                  style: TextStyle(
                    color: _profileTokens().textSecondary.withOpacity(.84),
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            Row(
              children: [
                Expanded(
                  child: _MetricCard(
                    icon: Icons.videocam_rounded,
                    title: 'Video Room Minutes',
                    value: '${summary.totalVideoRoomMinutes}',
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: _MetricCard(
                    icon: Icons.graphic_eq_rounded,
                    title: 'Audio Room Minutes',
                    value: '${summary.totalAudioRoomMinutes}',
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: _MetricCard(
                    icon: Icons.redeem_rounded,
                    title: 'Total Gifted Coins',
                    value: '${NumberFormat.compact().format(summary.totalGiftedCoins)} coins',
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: _MetricCard(
                    icon: Icons.mic_rounded,
                    title: 'Audio Calls',
                    value:
                        '${summary.audioCallMinutes} min • ${NumberFormat.compact().format(summary.audioCallEarnings)} coins',
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: _MetricCard(
                    icon: Icons.video_camera_front_rounded,
                    title: 'Video Calls',
                    value:
                        '${summary.videoCallMinutes} min • ${NumberFormat.compact().format(summary.videoCallEarnings)} coins',
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: _MetricCard(
                    icon: Icons.local_fire_department_rounded,
                    title: 'PK Rooms',
                    value:
                        '${summary.pkRoomCount} • ${NumberFormat.compact().format(summary.pkEarnings)} coins',
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),
            _HostReportDetailLine(
              label: 'Audio room gift coins',
              value:
                  '${NumberFormat.compact().format(summary.audioRoomGiftsCoins)} coins',
            ),
            _HostReportDetailLine(
              label: 'Video room gift coins',
              value:
                  '${NumberFormat.compact().format(summary.videoRoomGiftsCoins)} coins',
            ),
            _HostReportDetailLine(
              label: 'PK gift coins',
              value: '${NumberFormat.compact().format(summary.pkGiftCoins)} coins',
            ),
            _HostReportDetailLine(
              label: 'Audio call earning',
              value: '${NumberFormat.compact().format(summary.audioCallEarnings)} coins',
            ),
            _HostReportDetailLine(
              label: 'Video call earning',
              value: '${NumberFormat.compact().format(summary.videoCallEarnings)} coins',
            ),
            _HostReportDetailLine(
              label: 'Grand total',
              value: '${NumberFormat.compact().format(grandTotalCoins)} coins',
            ),
          ],
        ],
      ),
    );
  }
}

enum _HostReportRange {
  today('Today'),
  currentWeek('This Week'),
  lastWeek('Last Week');

  const _HostReportRange(this.label);
  final String label;
}

class _ProfileFramesCard extends StatelessWidget {
  const _ProfileFramesCard({
    required this.controller,
    required this.profile,
    required this.avatarUrl,
  });

  final ProfileController controller;
  final ProfileDto profile;
  final String? avatarUrl;

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return Obx(() {
      final items = controller.frames;
      final equipped =
          controller.profile.value?.profileFrame ?? profile.profileFrame;
      final hasEquippedFrame = (equipped?.assetUrl ?? '').trim().isNotEmpty;

      return _GlassSection(
        title: 'Profile Frames',
        subtitle: 'Equip a cosmetic frame over your avatar',
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: 86,
                  height: 86,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(30),
                    gradient: hasEquippedFrame
                        ? null
                        : LinearGradient(colors: tokens.primaryButtonGradient),
                    color: hasEquippedFrame ? Colors.transparent : null,
                  ),
                  child: FramedAvatar(
                    size: 86,
                    label: profile.name,
                    avatarUrl: avatarUrl,
                    frameUrl: equipped?.assetUrl,
                    backgroundColor: tokens.cardGradient.first,
                    avatarInset: 0.05,
                    borderRadius: 28,
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        equipped?.name ?? 'No frame equipped',
                        style: TextStyle(
                          color: tokens.textPrimary,
                          fontWeight: FontWeight.w800,
                          fontSize: 16,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        equipped == null
                            ? 'Choose one from your active frame catalog.'
                            : '${equipped.rarity.toUpperCase()} · ${equipped.category}',
                        style: TextStyle(
                          color: tokens.textSecondary.withOpacity(.88),
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 10),
                      FilledButton.icon(
                        onPressed: () => _showProfileFramePicker(context),
                        icon: const Icon(Icons.photo_filter_rounded),
                        label: Text(items.isEmpty ? 'Load Frames' : 'Manage Frames'),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            if (items.isNotEmpty) ...[
              const SizedBox(height: 14),
              SizedBox(
                height: 104,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  itemCount: items.length > 6 ? 6 : items.length,
                  separatorBuilder: (_, __) => const SizedBox(width: 10),
                  itemBuilder: (context, index) {
                    final item = items[index];
                    return InkWell(
                      borderRadius: BorderRadius.circular(20),
                      onTap: () => _showProfileFramePicker(context),
                      child: Ink(
                        width: 92,
                        decoration: BoxDecoration(
                          color: tokens.glassColor.withOpacity(.14),
                          borderRadius: BorderRadius.circular(20),
                          border: Border.all(
                            color: item.isEquipped
                                ? tokens.glowColor.withOpacity(.78)
                                : tokens.borderColor.withOpacity(.18),
                            width: item.isEquipped ? 1.6 : 1,
                          ),
                        ),
                        child: Padding(
                          padding: const EdgeInsets.all(8),
                          child: Column(
                            children: [
                              Expanded(
                                child: FramedAvatar(
                                  size: 60,
                                  label: profile.name,
                                  avatarUrl: avatarUrl,
                                  frameUrl: item.thumbnailUrl ?? item.assetUrl,
                                  backgroundColor: tokens.cardGradient.first,
                                  avatarInset: 0.05,
                                ),
                              ),
                              const SizedBox(height: 6),
                              Text(
                                item.name,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                  color: tokens.textPrimary,
                                  fontSize: 10.8,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ),
            ],
          ],
        ),
      );
    });
  }

  Future<void> _showProfileFramePicker(BuildContext context) async {
    final tokens = _profileTokens();
    if (controller.frames.isEmpty && !controller.isLoadingFrames.value) {
      await controller.loadFrames();
    }

    if (!context.mounted) return;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (sheetContext) {
        return Obx(() {
          final items = controller.frames;
          final shopItems = controller.shopFrames;
          final busyId = controller.equippingFrameId.value;
          final purchaseBusyId = controller.purchasingFrameId.value;
          return SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 18),
              child: Container(
                constraints: BoxConstraints(
                  maxHeight: MediaQuery.of(sheetContext).size.height * 0.82,
                ),
                decoration: BoxDecoration(
                  color: tokens.cardGradient.last.withOpacity(.98),
                  borderRadius: BorderRadius.circular(28),
                  border: Border.all(color: tokens.borderColor.withOpacity(.20)),
                ),
                child: Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(18, 16, 18, 10),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'Choose Profile Frame',
                                  style: TextStyle(
                                    color: tokens.textPrimary,
                                    fontSize: 18,
                                    fontWeight: FontWeight.w900,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  'Unlocked frames can be equipped. Shop frames can be purchased below.',
                                  style: TextStyle(
                                    color: tokens.textSecondary.withOpacity(.82),
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          IconButton(
                            onPressed: () => Navigator.of(sheetContext).pop(),
                            icon: Icon(Icons.close_rounded, color: tokens.textPrimary),
                          ),
                        ],
                      ),
                    ),
                    const Divider(height: 1),
                    Expanded(
                      child: controller.isLoadingFrames.value && items.isEmpty && shopItems.isEmpty
                          ? const Center(child: CircularProgressIndicator())
                          : items.isEmpty && shopItems.isEmpty
                              ? Center(
                                  child: Padding(
                                    padding: const EdgeInsets.symmetric(horizontal: 28),
                                    child: Column(
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        Icon(
                                          Icons.workspace_premium_rounded,
                                          size: 44,
                                          color: tokens.textSecondary.withOpacity(.8),
                                        ),
                                        const SizedBox(height: 12),
                                        Text(
                                          'No unlocked frames yet',
                                          style: TextStyle(
                                            color: tokens.textPrimary,
                                            fontSize: 16,
                                            fontWeight: FontWeight.w800,
                                          ),
                                        ),
                                        const SizedBox(height: 6),
                                        Text(
                                          'Unlock frames from rewards, grants, or purchase them from the shop below when available.',
                                          textAlign: TextAlign.center,
                                          style: TextStyle(
                                            color: tokens.textSecondary.withOpacity(.82),
                                            fontWeight: FontWeight.w600,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                )
                          : SingleChildScrollView(
                              padding: const EdgeInsets.all(16),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  if (items.isNotEmpty) ...[
                                    Text(
                                      'Unlocked Frames',
                                      style: TextStyle(
                                        color: tokens.textPrimary,
                                        fontSize: 16,
                                        fontWeight: FontWeight.w800,
                                      ),
                                    ),
                                    const SizedBox(height: 12),
                                    GridView.builder(
                                      shrinkWrap: true,
                                      physics: const NeverScrollableScrollPhysics(),
                                      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                                        crossAxisCount: 2,
                                        mainAxisSpacing: 12,
                                        crossAxisSpacing: 12,
                                        childAspectRatio: 0.72,
                                      ),
                                      itemCount: items.length,
                                      itemBuilder: (context, index) {
                                        final item = items[index];
                                        final currentFrameUrl = item.thumbnailUrl ?? item.assetUrl;
                                        final isBusy = busyId == item.id;
                                        final enabled = item.canEquip && !item.isExpired;
                                        return _ProfileFrameTile(
                                          tokens: tokens,
                                          profileName: profile.name,
                                          avatarUrl: avatarUrl,
                                          frameUrl: currentFrameUrl,
                                          title: item.name,
                                          subtitle: '${item.rarity.toUpperCase()} · ${item.category}',
                                          buttonLabel: item.isEquipped
                                              ? 'Equipped'
                                              : enabled
                                                  ? 'Equip'
                                                  : 'Locked',
                                          buttonBusy: isBusy,
                                          buttonEnabled: enabled && !isBusy && !item.isEquipped,
                                          selected: item.isEquipped,
                                          onPressed: () async {
                                            final ok = await controller.equipProfileFrame(item);
                                            if (ok) {
                                              if (sheetContext.mounted) {
                                                ScaffoldMessenger.of(sheetContext).showSnackBar(
                                                  SnackBar(content: Text('${item.name} equipped.')),
                                                );
                                              }
                                            } else if (controller.error.value != null && sheetContext.mounted) {
                                              ScaffoldMessenger.of(sheetContext).showSnackBar(
                                                SnackBar(content: Text(controller.error.value!)),
                                              );
                                            }
                                          },
                                        );
                                      },
                                    ),
                                  ],
                                  if (shopItems.isNotEmpty) ...[
                                    if (items.isNotEmpty) const SizedBox(height: 18),
                                    Text(
                                      'Frame Shop',
                                      style: TextStyle(
                                        color: tokens.textPrimary,
                                        fontSize: 16,
                                        fontWeight: FontWeight.w800,
                                      ),
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      'Buy premium frames with wallet coins.',
                                      style: TextStyle(
                                        color: tokens.textSecondary.withOpacity(.82),
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                    const SizedBox(height: 12),
                                    GridView.builder(
                                      shrinkWrap: true,
                                      physics: const NeverScrollableScrollPhysics(),
                                      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                                        crossAxisCount: 2,
                                        mainAxisSpacing: 12,
                                        crossAxisSpacing: 12,
                                        childAspectRatio: 0.72,
                                      ),
                                      itemCount: shopItems.length,
                                      itemBuilder: (context, index) {
                                        final item = shopItems[index];
                                        final currentFrameUrl = item.thumbnailUrl ?? item.assetUrl;
                                        final isBusy = purchaseBusyId == item.id;
                                        final price = item.priceCoins ?? 0;
                                        return _ProfileFrameTile(
                                          tokens: tokens,
                                          profileName: profile.name,
                                          avatarUrl: avatarUrl,
                                          frameUrl: currentFrameUrl,
                                          title: item.name,
                                          subtitle: '${item.rarity.toUpperCase()} · $price coins',
                                          buttonLabel: price > 0 ? 'Buy $price' : 'Claim',
                                          buttonBusy: isBusy,
                                          buttonEnabled: item.canPurchase && !isBusy,
                                          selected: false,
                                          onPressed: () async {
                                            final ok = await controller.purchaseProfileFrame(item);
                                            if (ok) {
                                              if (sheetContext.mounted) {
                                                ScaffoldMessenger.of(sheetContext).showSnackBar(
                                                  SnackBar(content: Text('${item.name} purchased.')),
                                                );
                                              }
                                            } else if (controller.error.value != null && sheetContext.mounted) {
                                              ScaffoldMessenger.of(sheetContext).showSnackBar(
                                                SnackBar(content: Text(controller.error.value!)),
                                              );
                                            }
                                          },
                                        );
                                      },
                                    ),
                                  ],
                                ],
                              ),
                            ),
                    ),
                  ],
                ),
              ),
            ),
          );
        });
      },
    );
  }
}

class _ProfileFrameTile extends StatelessWidget {
  const _ProfileFrameTile({
    required this.tokens,
    required this.profileName,
    required this.avatarUrl,
    required this.frameUrl,
    required this.title,
    required this.subtitle,
    required this.buttonLabel,
    required this.buttonBusy,
    required this.buttonEnabled,
    required this.selected,
    required this.onPressed,
  });

  final PremiumThemeTokens tokens;
  final String profileName;
  final String? avatarUrl;
  final String? frameUrl;
  final String title;
  final String subtitle;
  final String buttonLabel;
  final bool buttonBusy;
  final bool buttonEnabled;
  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: tokens.glassColor.withOpacity(.12),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(
          color: selected
              ? tokens.glowColor.withOpacity(.78)
              : tokens.borderColor.withOpacity(.18),
          width: selected ? 1.6 : 1,
        ),
      ),
      padding: const EdgeInsets.all(10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: FramedAvatar(
              size: 92,
              label: profileName,
              avatarUrl: avatarUrl,
              frameUrl: frameUrl,
              backgroundColor: tokens.cardGradient.first,
              avatarInset: 0.05,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            title,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w800,
              fontSize: 13,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            subtitle,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.8),
              fontSize: 10.8,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 8),
          const Spacer(),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              style: FilledButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 10),
                textStyle: const TextStyle(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                ),
              ),
              onPressed: buttonEnabled ? onPressed : null,
              child: buttonBusy
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(buttonLabel),
            ),
          ),
        ],
      ),
    );
  }
}

class _ProfileHeaderCard extends StatelessWidget {
  final ProfileDto profile;
  final String? avatarUrl;
  final String? profileFrameUrl;
  final List<String> roleLabels;
  final String? location;
  final String? about;
  final VoidCallback onEdit;

  const _ProfileHeaderCard({
    required this.profile,
    required this.avatarUrl,
    required this.profileFrameUrl,
    required this.roleLabels,
    required this.location,
    required this.about,
    required this.onEdit,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    final levelLabel =
        profile.levelTitle?.toString().trim().isNotEmpty == true
            ? profile.levelTitle.toString()
            : (profile.level != null ? 'Level ${profile.level}' : 'Member');
    final progress = ((profile.progressPercent ?? .0).clamp(0.0, 100.0)) / 100;
    final levelTint =
        _parseProfileColor(profile.badgeColor) ?? const Color(0xFF4BE3C2);
    final hostBlocked =
        profile.status.hostBlocked || profile.hostProfile?.isBlocked == true;
    final agencyBlocked = profile.hostProfile?.agency?.isBlocked == true;
    final hasProfileFrame = (profileFrameUrl ?? '').trim().isNotEmpty;

    return _GlassShell(
      padding: const EdgeInsets.all(18),
      borderRadius: 28,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 64,
                height: 64,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(30),
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
                  size: 64,
                  label: profile.name.toString(),
                  avatarUrl: avatarUrl,
                  frameUrl: profileFrameUrl,
                  backgroundColor: tokens.cardGradient.first,
                  avatarInset: 0.04,
                  borderRadius: 28,
                ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      profile.name.toString(),
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        color: tokens.textPrimary,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      'User ID #${profile.id}',
                      style: TextStyle(
                        color: tokens.textSecondary.withOpacity(.76),
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
              OutlinedButton.icon(
                onPressed: onEdit,
                icon: const Icon(Icons.edit_rounded, size: 16),
                label: const Text('Edit'),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final role in roleLabels)
                _HeroChip(
                  label: role.toUpperCase(),
                  tint: tokens.primaryButtonGradient.first,
                ),
              _HeroChip(label: levelLabel.toUpperCase(), tint: levelTint),
              if (hostBlocked)
                const _HeroChip(label: 'HOST BLOCKED', tint: Color(0xFFE85D75))
              else if (profile.canGoLive == true)
                const _HeroChip(label: 'LIVE ENABLED', tint: Color(0xFFFFC857)),
              if (profile.status.agencyAttached == true)
                _HeroChip(
                  label: agencyBlocked ? 'AGENCY BLOCKED' : 'AGENCY LINKED',
                  tint:
                      agencyBlocked
                          ? const Color(0xFFE85D75)
                          : const Color(0xFFFF8A65),
                ),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: _HeroStat(
                  label: 'Balance',
                  value: NumberFormat.compact().format(profile.walletBalance),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _HeroStat(
                  label: 'Level',
                  value: profile.level?.toString() ?? '1',
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _HeroStat(
                  label: 'Spent',
                  value: NumberFormat.compact().format(
                    profile.lifetimeSpendCoins ?? 0,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          _ProgressBar(progress: progress),
          const SizedBox(height: 10),
          if (profile.nextLevelRequiredSpend != null)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Text(
                '${NumberFormat.compact().format(profile.lifetimeSpendCoins ?? 0)} / ${NumberFormat.compact().format(profile.nextLevelRequiredSpend)} coins spent • ${NumberFormat.compact().format(profile.remainingSpendToNextLevel ?? 0)} to ${profile.nextLevelTitle ?? 'next level'}',
                style: TextStyle(
                  color: Colors.white.withOpacity(.72),
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _HeroChip extends StatelessWidget {
  final String label;
  final Color tint;

  const _HeroChip({required this.label, required this.tint});

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: tint.withOpacity(.16),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tint.withOpacity(.32)),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: tokens.textPrimary.withOpacity(.92),
          fontSize: 11,
          fontWeight: FontWeight.w700,
          letterSpacing: .4,
        ),
      ),
    );
  }
}

Color? _parseProfileColor(String? raw) {
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

class _HeroStat extends StatelessWidget {
  final String label;
  final String value;

  const _HeroStat({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: tokens.chipColor.withOpacity(.62),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: tokens.borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.72),
              fontSize: 12,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            value,
            style: TextStyle(
              color: tokens.textPrimary,
              fontSize: 18,
              fontWeight: FontWeight.w900,
            ),
          ),
        ],
      ),
    );
  }
}

class _ProgressBar extends StatelessWidget {
  final double progress;

  const _ProgressBar({required this.progress});

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Level Progress',
          style: TextStyle(
            color: tokens.textSecondary.withOpacity(.82),
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 8),
        ClipRRect(
          borderRadius: BorderRadius.circular(999),
          child: LinearProgressIndicator(
            value: progress == 0 ? .06 : progress,
            minHeight: 9,
            backgroundColor: tokens.borderColor.withOpacity(.5),
            valueColor: AlwaysStoppedAnimation(tokens.primaryButtonGradient.first),
          ),
        ),
      ],
    );
  }
}

class _MetaLine extends StatelessWidget {
  final IconData icon;
  final String text;

  const _MetaLine({required this.icon, required this.text});

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: tokens.textSecondary.withOpacity(.82)),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: TextStyle(
                color: tokens.textPrimary.withOpacity(.84),
                height: 1.35,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _MetricCard extends StatelessWidget {
  final IconData icon;
  final String title;
  final String value;
  final VoidCallback? onTap;

  const _MetricCard({
    required this.icon,
    required this.title,
    required this.value,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: _GlassShell(
        padding: const EdgeInsets.all(16),
        borderRadius: 24,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                color: tokens.primaryButtonGradient.first.withOpacity(.18),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(icon, color: tokens.primaryButtonGradient.first),
            ),
            const SizedBox(height: 12),
            Text(
              title,
              style: TextStyle(
                color: tokens.textSecondary.withOpacity(.74),
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              value,
              style: TextStyle(
                color: tokens.textPrimary,
                fontSize: 18,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _HostReportRangeChip extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback onTap;

  const _HostReportRangeChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return Material(
      color: Colors.transparent,
      child: InkWell(
        borderRadius: BorderRadius.circular(999),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(999),
            color:
                selected
                    ? tokens.primaryButtonGradient.first.withOpacity(.22)
                    : tokens.chipColor.withOpacity(.52),
            border: Border.all(
              color:
                  selected
                      ? tokens.primaryButtonGradient.first.withOpacity(.45)
                      : tokens.borderColor,
            ),
          ),
          alignment: Alignment.center,
          child: Text(
            label,
            style: TextStyle(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w800,
              fontSize: 12,
            ),
          ),
        ),
      ),
    );
  }
}

class _HostReportDetailLine extends StatelessWidget {
  final String label;
  final String value;

  const _HostReportDetailLine({
    required this.label,
    required this.value,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text(
              label,
              style: TextStyle(
                color: tokens.textSecondary.withOpacity(.76),
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          const SizedBox(width: 10),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.right,
              style: TextStyle(
                color: tokens.textPrimary,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

int _nextGoal(int current, List<int> goals) {
  for (final goal in goals) {
    if (current < goal) return goal;
  }
  return goals.isEmpty ? current : goals.last + (goals.last ~/ 2);
}

class _GoalProgressTile extends StatelessWidget {
  const _GoalProgressTile({
    required this.icon,
    required this.title,
    required this.current,
    required this.target,
    required this.suffix,
  });

  final IconData icon;
  final String title;
  final int current;
  final int target;
  final String suffix;

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    final progress = target <= 0 ? 0.0 : (current / target).clamp(0.0, 1.0);
    final remaining = target > current ? target - current : 0;
    return _GlassShell(
      padding: const EdgeInsets.all(16),
      borderRadius: 22,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: tokens.primaryButtonGradient.first.withOpacity(.18),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(icon, color: tokens.primaryButtonGradient.first),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  title,
                  style: TextStyle(
                    color: tokens.textPrimary,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              Text(
                '${NumberFormat.compact().format(current)} / ${NumberFormat.compact().format(target)}',
                style: TextStyle(
                  color: tokens.textSecondary.withOpacity(.82),
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              value: progress == 0 ? .04 : progress,
              minHeight: 8,
              backgroundColor: tokens.borderColor.withOpacity(.5),
              valueColor: AlwaysStoppedAnimation(tokens.primaryButtonGradient.first),
            ),
          ),
          const SizedBox(height: 10),
          Text(
            remaining > 0
                ? '${NumberFormat.compact().format(remaining)} $suffix to the next milestone'
                : 'Goal reached. Keep pushing for the next milestone.',
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.76),
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

class _GlassSection extends StatelessWidget {
  final String title;
  final String subtitle;
  final Widget child;

  const _GlassSection({
    required this.title,
    required this.subtitle,
    required this.child,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return _GlassShell(
      padding: const EdgeInsets.all(18),
      borderRadius: 28,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: Theme.of(context).textTheme.titleMedium?.copyWith(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            subtitle,
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.72),
              fontWeight: FontWeight.w500,
            ),
          ),
          const SizedBox(height: 14),
          child,
        ],
      ),
    );
  }
}

class _ProfileActionTile extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  const _ProfileActionTile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return Material(
      color: Colors.transparent,
      child: InkWell(
        borderRadius: BorderRadius.circular(22),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 10),
          child: Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: tokens.primaryButtonGradient.first.withOpacity(.16),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Icon(icon, color: tokens.primaryButtonGradient.first),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: TextStyle(
                        color: tokens.textPrimary,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      subtitle,
                      style: TextStyle(
                        color: tokens.textSecondary.withOpacity(.74),
                        height: 1.3,
                      ),
                    ),
                  ],
                ),
              ),
              Icon(
                Icons.chevron_right_rounded,
                color: tokens.primaryButtonGradient.first,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _DividerLine extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return Divider(height: 1, color: tokens.borderColor.withOpacity(.5));
  }
}

class _InfoLine extends StatelessWidget {
  final String label;
  final String value;

  const _InfoLine({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 108,
            child: Text(
              label,
              style: TextStyle(
                color: tokens.textSecondary.withOpacity(.68),
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: TextStyle(
                color: tokens.textPrimary,
                height: 1.35,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
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
            child: Transform.scale(scale: 0.98 + (eased * .02), child: child),
          ),
        );
      },
      child: child,
    );
  }
}

class _GlassyBackdrop extends StatelessWidget {
  final Animation<double> t;

  const _GlassyBackdrop({required this.t});

  @override
  Widget build(BuildContext context) {
    final tokens = _profileTokens();
    return AnimatedBuilder(
      animation: t,
      builder: (_, __) => CustomPaint(painter: _BlobPainter(t.value, tokens)),
    );
  }
}

class _BlobPainter extends CustomPainter {
  final double t;
  final PremiumThemeTokens tokens;

  _BlobPainter(this.t, this.tokens);

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

    blob(Offset(w * .25, h * .18), h * .24, tokens.glowColor, 28, 0.0);
    blob(
      Offset(w * .82, h * .34),
      h * .20,
      tokens.primaryButtonGradient.last,
      36,
      1.1,
    );
    blob(
      Offset(w * .55, h * .80),
      h * .26,
      tokens.primaryButtonGradient.first,
      30,
      2.2,
    );
  }

  @override
  bool shouldRepaint(covariant _BlobPainter old) => old.t != t;
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
    final tokens = _profileTokens();
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
    final tokens = _profileTokens();
    return _GlassShell(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 42, color: tokens.textPrimary),
          const SizedBox(height: 12),
          Text(
            title,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: tokens.textPrimary,
              fontSize: 18,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            subtitle,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.92),
              height: 1.35,
            ),
          ),
          const SizedBox(height: 14),
          FilledButton(
            onPressed: onAction,
            style: FilledButton.styleFrom(
              backgroundColor: tokens.primaryButtonGradient.first,
            ),
            child: Text(actionLabel),
          ),
        ],
      ),
    );
  }
}
