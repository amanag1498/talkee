// lib/modules/home/pages/live_page.dart
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:liveapp/app/routes/app_routes.dart';

import '../../../app/theme/brand.dart';
import '../../../app/widgets/audio_room_tite.dart';
import '../../../app/widgets/framed_avatar.dart';
import '../../../services/app_settings_service.dart';
import '../../Live/services/live_service.dart';
import '../../banners/models/banner_item.dart';
import '../../banners/services/banner_service.dart';
import '../../profile/controllers/host_follow_controller.dart';
import '../controllers/live_room_controller.dart';
import '../models/live_room_dto.dart';
import 'package:liveapp/modules/subscriptions/controllers/viewer_gate_controller.dart';

class LivePage extends StatefulWidget {
  final double bottomPadding;
  final String bannerPlacement;
  const LivePage({
    super.key,
    required this.bottomPadding,
    this.bannerPlacement = 'home',
  });

  @override
  State<LivePage> createState() => _LivePageState();
}

class _LivePageState extends State<LivePage> {
  PremiumThemeTokens _tokens() {
    final settings = Get.find<AppSettingsService>();
    return getPremiumThemeTokens(settings.activePremiumThemeVariant);
  }

  Future<void> _handleBlockedJoin({
    required LiveService live,
    required LiveRoomModel room,
    required String message,
  }) async {
    if (room.hostId == null) {
      Get.snackbar(
        'Unable to join room',
        message,
        snackPosition: SnackPosition.BOTTOM,
      );
      return;
    }
    var hasPendingRequest = false;
    try {
      final rows = await live.fetchMyUnblockRequests(
        hostUserId: room.hostId,
        status: 'pending',
      );
      hasPendingRequest = rows.isNotEmpty;
    } catch (_) {}
    final controller = TextEditingController();
    final request = await showDialog<bool>(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: const Text('Blocked by host'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(message),
              if (hasPendingRequest) ...[
                const SizedBox(height: 12),
                const Text(
                  'Your unblock request is already pending review.',
                ),
              ] else ...[
                const SizedBox(height: 12),
                TextField(
                  controller: controller,
                  minLines: 2,
                  maxLines: 4,
                  decoration: const InputDecoration(
                    labelText: 'Request unblock message',
                    hintText: 'Add a short note for the host',
                  ),
                ),
              ],
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(false),
              child: const Text('Close'),
            ),
            if (!hasPendingRequest)
              FilledButton(
                onPressed: () => Navigator.of(context).pop(true),
                child: const Text('Request Unblock'),
              ),
          ],
        );
      },
    );
    if (request == true) {
      try {
        await live.requestUnblock(
          hostUserId: room.hostId!,
          message: controller.text.trim(),
        );
        Get.snackbar(
          'Moderation',
          'Unblock request submitted.',
          snackPosition: SnackPosition.BOTTOM,
        );
      } catch (e) {
        Get.snackbar(
          'Moderation',
          e.toString().replaceFirst('Exception: ', ''),
          snackPosition: SnackPosition.BOTTOM,
        );
      }
    }
    controller.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final ctrl = Get.find<LiveRoomsController>();
    final live = Get.find<LiveService>();

    // Ensure gate controller is available (safe if already registered elsewhere)
    final gate =
        Get.isRegistered<ViewerGateController>()
            ? Get.find<ViewerGateController>()
            : Get.put(ViewerGateController(), permanent: true);

    return Obx(() {
      final tokens = _tokens();
      final allAudioRooms =
          ctrl.liveRooms.where((room) => room.isAudioRoom).toList();
      final scheduledAudioRooms =
          ctrl.scheduledRooms.where((room) => room.isAudioRoom).toList();
      final list = allAudioRooms;
      final follows = Get.find<HostFollowController>();

      return RefreshIndicator(
        color: tokens.primaryButtonGradient.first,
        backgroundColor: tokens.cardGradient.first,
        onRefresh: ctrl.refreshRooms,
        child: LayoutBuilder(
          builder: (_, c) {
            final w = c.maxWidth;
            final cross = w >= 1180 ? 4 : (w >= 820 ? 3 : 2);

            return CustomScrollView(
              physics: const BouncingScrollPhysics(
                parent: AlwaysScrollableScrollPhysics(),
              ),
              slivers: [
                SliverToBoxAdapter(
                  child: _LiveBannerStrip(
                    roomsCount: allAudioRooms.length,
                    placement: widget.bannerPlacement,
                    tokens: tokens,
                  ),
                ),
                if (scheduledAudioRooms.isNotEmpty)
                  SliverToBoxAdapter(
                    child: _UpcomingRoomsStrip(
                      rooms: scheduledAudioRooms,
                      tokens: tokens,
                      onReminderToggle: ctrl.toggleReminder,
                      onFollowToggle: (room) async {
                        final hostProfileId = room.hostProfileId;
                        if (hostProfileId == null) return;
                        await follows.toggleForHost(
                          hostId: hostProfileId,
                          current: follows.isFollowing(
                            hostProfileId,
                            fallback: room.isFollowingHost,
                          ),
                          currentCount: follows.followerCount(
                            hostProfileId,
                            fallback: room.followerCount,
                          ),
                        );
                        await ctrl.refreshRooms();
                      },
                    ),
                  ),
                if (ctrl.loading.value && allAudioRooms.isEmpty)
                  SliverPadding(
                    padding: EdgeInsets.fromLTRB(
                      16,
                      0,
                      16,
                      widget.bottomPadding,
                    ),
                    sliver: SliverGrid(
                      delegate: SliverChildBuilderDelegate(
                        (_, index) => Transform.translate(
                          offset: Offset(0, _audioTileTopOffset(index, cross)),
                          child: _CompactAudioSkeletonTile(tokens: tokens),
                        ),
                        childCount: cross * 4,
                      ),
                      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: cross,
                        mainAxisSpacing: 0,
                        crossAxisSpacing: 0,
                        childAspectRatio: .95,
                      ),
                    ),
                  )
                else if (ctrl.error.value != null && allAudioRooms.isEmpty)
                  SliverFillRemaining(
                    hasScrollBody: false,
                    child: _AudioRoomsErrorState(
                      message: ctrl.error.value!,
                      tokens: tokens,
                      onRetry: ctrl.refreshRooms,
                    ),
                  )
                else if (list.isEmpty)
                  SliverFillRemaining(
                    hasScrollBody: false,
                    child: _EmptyLiveState(filtered: false, tokens: tokens),
                  )
                else
                  SliverPadding(
                    padding: EdgeInsets.fromLTRB(
                      16,
                      0,
                      16,
                      widget.bottomPadding,
                    ),
                    sliver: SliverGrid(
                      delegate: SliverChildBuilderDelegate((_, i) {
                        final room = list[i];
                        return Transform.translate(
                          offset: Offset(0, _audioTileTopOffset(i, cross)),
                          child: _CompactAudioRoomTile(
                            room: room,
                            tokens: tokens,
                            onTap: () async {
                              Future<void> attemptJoin() async {
                                final joined = await live.joinAudioRoom(room.id);
                                await Get.toNamed(
                                  Routes.liveAudio,
                                  arguments: {
                                    'room': joined,
                                    'viewer_only': true,
                                  },
                                );
                              }

                              try {
                                await attemptJoin();
                              } catch (e) {
                                final message = e.toString().replaceFirst(
                                  'Exception: ',
                                  '',
                                );
                                final normalized = message.toLowerCase();

                                if (normalized.contains(
                                  'active subscription is required',
                                )) {
                                  await gate.ensureAccessThen(
                                    onGranted: attemptJoin,
                                  );
                                  return;
                                }

                                if (normalized.contains(
                                  'blocked by this host',
                                )) {
                                  await _handleBlockedJoin(
                                    live: live,
                                    room: room,
                                    message: message,
                                  );
                                  return;
                                }

                                Get.snackbar(
                                  'Unable to join room',
                                  message,
                                  snackPosition: SnackPosition.BOTTOM,
                                  duration: const Duration(seconds: 3),
                                );
                              }
                            },
                          ),
                        );
                      }, childCount: list.length),
                      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: cross,
                        mainAxisSpacing: 0,
                        crossAxisSpacing: 0,
                        childAspectRatio: .95,
                      ),
                    ),
                  ),
              ],
            );
          },
        ),
      );
    });
  }
}

class _UpcomingRoomsStrip extends StatelessWidget {
  const _UpcomingRoomsStrip({
    required this.rooms,
    required this.tokens,
    required this.onReminderToggle,
    required this.onFollowToggle,
  });

  final List<LiveRoomModel> rooms;
  final PremiumThemeTokens tokens;
  final Future<void> Function(LiveRoomModel room) onReminderToggle;
  final Future<void> Function(LiveRoomModel room) onFollowToggle;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 18),
      child: SizedBox(
        height: 188,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          itemCount: rooms.length,
          separatorBuilder: (_, __) => const SizedBox(width: 12),
          itemBuilder: (context, index) {
            final room = rooms[index];
            return _UpcomingRoomCard(
              room: room,
              tokens: tokens,
              onReminderToggle: () => onReminderToggle(room),
              onFollowToggle: () => onFollowToggle(room),
            );
          },
        ),
      ),
    );
  }
}

class _UpcomingRoomCard extends StatelessWidget {
  const _UpcomingRoomCard({
    required this.room,
    required this.tokens,
    required this.onReminderToggle,
    required this.onFollowToggle,
  });

  final LiveRoomModel room;
  final PremiumThemeTokens tokens;
  final Future<void> Function() onReminderToggle;
  final Future<void> Function() onFollowToggle;

  @override
  Widget build(BuildContext context) {
    final when = room.scheduledAt;
    final timeText = when == null
        ? 'Schedule pending'
        : DateFormat('dd MMM • hh:mm a').format(when.toLocal());
    return Container(
      width: 272,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: tokens.borderColor.withOpacity(.5)),
      ),
      child: Stack(
        fit: StackFit.expand,
        children: [
          if ((room.thumbnail ?? '').isNotEmpty)
            Image.network(room.thumbnail!, fit: BoxFit.cover)
          else
            DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [
                    tokens.cardGradient.first.withOpacity(.92),
                    tokens.cardGradient.last.withOpacity(.94),
                  ],
                ),
              ),
            ),
          DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  Colors.black.withOpacity(.18),
                  Colors.black.withOpacity(.42),
                  Colors.black.withOpacity(.82),
                ],
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Align(
                  alignment: Alignment.topRight,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(999),
                      color: Colors.black.withOpacity(.28),
                      border: Border.all(color: Colors.white.withOpacity(.18)),
                    ),
                    child: Text(
                      room.roomType.toUpperCase(),
                      style: TextStyle(
                        color: tokens.textPrimary,
                        fontSize: 11,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                ),
                const Spacer(),
                Text(
                  room.hostName ?? 'Host',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: tokens.textPrimary,
                    fontSize: 18,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  timeText,
                  style: TextStyle(
                    color: Colors.white.withOpacity(.9),
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  room.isFollowingHost
                      ? 'Reminder is the only thing left.'
                      : 'Follow the host and set your reminder.',
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white.withOpacity(.82),
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    height: 1.2,
                  ),
                ),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Expanded(
                      child: FilledButton.tonal(
                        onPressed: onReminderToggle,
                        style: FilledButton.styleFrom(
                          backgroundColor: room.hasReminder
                              ? Colors.white.withOpacity(.18)
                              : tokens.primaryButtonGradient.first.withOpacity(.42),
                          foregroundColor: tokens.textPrimary,
                          padding: const EdgeInsets.symmetric(vertical: 10),
                          minimumSize: const Size(0, 38),
                          textStyle: const TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        child: Text(room.hasReminder ? 'Reminder Set' : 'Remind Me'),
                      ),
                    ),
                    if (!room.isFollowingHost && room.hostProfileId != null) ...[
                      const SizedBox(width: 8),
                      Expanded(
                        child: OutlinedButton(
                          onPressed: onFollowToggle,
                          style: OutlinedButton.styleFrom(
                            foregroundColor: tokens.textPrimary,
                            side: BorderSide(color: Colors.white.withOpacity(.28)),
                            backgroundColor: Colors.black.withOpacity(.12),
                            padding: const EdgeInsets.symmetric(vertical: 10),
                            minimumSize: const Size(0, 38),
                            textStyle: const TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          child: const Text('Follow Host'),
                        ),
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _LiveBannerStrip extends StatefulWidget {
  final int roomsCount;
  final String placement;
  final PremiumThemeTokens tokens;
  const _LiveBannerStrip({
    required this.roomsCount,
    required this.placement,
    required this.tokens,
  });

  @override
  State<_LiveBannerStrip> createState() => _LiveBannerStripState();
}

class _LiveBannerStripState extends State<_LiveBannerStrip>
    with SingleTickerProviderStateMixin {
  late final PageController _pc = PageController(viewportFraction: 1);
  late final AnimationController _flow;
  Timer? _ticker;
  int _index = 0;
  bool _loading = true;
  List<BannerItem> _banners = const <BannerItem>[];
  final Set<int> _impressed = <int>{};

  List<BannerItem> _fallbackBanners() => <BannerItem>[
    BannerItem(
      id: -1,
      title: 'Trending now',
      imageUrl: '',
      actionType: 'none',
      actionValue: null,
      buttonText: null,
    ),
    const BannerItem(
      id: -2,
      title: 'Creator spotlight',
      imageUrl: '',
      actionType: 'none',
      actionValue: null,
      buttonText: null,
    ),
    const BannerItem(
      id: -3,
      title: 'Go premium',
      imageUrl: '',
      actionType: 'route',
      actionValue: '/subscriptions',
      buttonText: null,
    ),
  ];

  String _shortTitle(String value) {
    final t = value.trim();
    if (t.length <= 22) return t;
    return '${t.substring(0, 22).trimRight()}…';
  }

  @override
  void initState() {
    super.initState();
    _flow = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 7),
    )..repeat();
    _loadBanners();
    _startTicker();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _flow.dispose();
    _pc.dispose();
    super.dispose();
  }

  void _startTicker() {
    _ticker?.cancel();
    _ticker = Timer.periodic(const Duration(seconds: 4), (_) {
      if (!mounted || !_pc.hasClients) return;
      if (_banners.length < 2) return;
      _index = (_index + 1) % _banners.length;
      _pc.animateToPage(
        _index,
        duration: const Duration(milliseconds: 420),
        curve: Curves.easeOutCubic,
      );
    });
  }

  Future<void> _loadBanners() async {
    final service = Get.find<BannerService>();
    final remote = await service.fetchBanners(
      placement: widget.placement,
      forceRefresh: true,
    );
    if (!mounted) return;

    final items = remote.isNotEmpty ? remote : _fallbackBanners();
    setState(() {
      _loading = false;
      _banners = items;
      _index = 0;
      _impressed.clear();
    });
    _startTicker();
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
      context: {'screen': widget.placement, 'slot': 'top_carousel'},
    );
  }

  Future<void> _onBannerTap(BannerItem banner) async {
    final actionType = banner.actionType.trim().toLowerCase();
    final actionValue = banner.actionValue?.trim();

    if (banner.id > 0) {
      await Get.find<BannerService>().trackClick(
        bannerId: banner.id,
        placement: widget.placement,
        context: {'screen': widget.placement, 'slot': 'top_carousel'},
      );
    }

    if (actionType == 'none' || actionValue == null || actionValue.isEmpty) {
      return;
    }

    try {
      if (actionType == 'url') {
        final uri = Uri.tryParse(actionValue);
        if (uri == null) return;
        await launchUrl(uri, mode: LaunchMode.externalApplication);
        return;
      }

      if (actionType == 'deeplink') {
        if (actionValue.startsWith('/')) {
          await Get.toNamed(actionValue);
          return;
        }
        final uri = Uri.tryParse(actionValue);
        if (uri == null) return;
        await launchUrl(uri, mode: LaunchMode.externalApplication);
        return;
      }

      if (actionType == 'route') {
        await Get.toNamed(actionValue);
      }
    } catch (_) {
      // Banner actions should never break the screen.
    }
  }

  @override
  Widget build(BuildContext context) {
    final banners = _banners;
    final tokens = widget.tokens;
    if (_loading && banners.isEmpty) {
      return const Padding(
        padding: EdgeInsets.fromLTRB(16, 8, 16, 4),
        child: SizedBox(height: 132),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
      child: SizedBox(
        height: 104,
        child: Stack(
          children: [
            PageView.builder(
              controller: _pc,
              onPageChanged: (v) async {
                setState(() => _index = v);
                await _trackImpression(v);
              },
              itemCount: banners.length,
              itemBuilder:
                  (_, i) => InkWell(
                    borderRadius: BorderRadius.circular(18),
                    onTap: () => _onBannerTap(banners[i]),
                    child: Stack(
                      fit: StackFit.expand,
                      children: [
                        if (banners[i].hasImage)
                          ClipRRect(
                            borderRadius: BorderRadius.circular(18),
                            child: Image.network(
                              banners[i].imageUrl,
                              fit: BoxFit.cover,
                              errorBuilder:
                                  (_, __, ___) => const SizedBox.shrink(),
                            ),
                          ),
                        Container(
                          decoration: BoxDecoration(
                            borderRadius: BorderRadius.circular(18),
                            gradient: LinearGradient(
                              begin: Alignment.topCenter,
                              end: Alignment.bottomCenter,
                              colors:
                                  banners[i].hasImage
                                      ? const [
                                        Color(0x2B06040C),
                                        Color(0x8A151020),
                                        Color(0xCC1E1731),
                                      ]
                                      : [
                                        tokens.cardGradient.first,
                                        tokens.cardGradient.last,
                                        tokens.primaryButtonGradient.last
                                            .withValues(alpha: .78),
                                      ],
                            ),
                            border: Border.all(
                              color: tokens.borderColor.withOpacity(.46),
                            ),
                            boxShadow: [
                              BoxShadow(
                                color: Colors.black.withOpacity(.28),
                                blurRadius: 14,
                                offset: const Offset(0, 6),
                              ),
                            ],
                          ),
                        ),
                        Positioned.fill(
                          child: IgnorePointer(
                            child: AnimatedBuilder(
                              animation: _flow,
                              builder: (_, __) {
                                final x = (_flow.value * 2) - 1;
                                return Transform.translate(
                                  offset: Offset(x * 26, 0),
                                  child: DecoratedBox(
                                    decoration: BoxDecoration(
                                      gradient: LinearGradient(
                                        begin: Alignment.topLeft,
                                        end: Alignment.bottomRight,
                                        colors: [
                                          Colors.transparent,
                                          Colors.white.withOpacity(.04),
                                          Colors.transparent,
                                        ],
                                        stops: const [0.3, 0.5, 0.7],
                                      ),
                                    ),
                                  ),
                                );
                              },
                            ),
                          ),
                        ),
                        Padding(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 14,
                            vertical: 12,
                          ),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  children: [
                                    Text(
                                      'LIVE NOW',
                                      style: TextStyle(
                                        color: tokens.textSecondary.withOpacity(
                                          .72,
                                        ),
                                        fontWeight: FontWeight.w700,
                                        fontSize: 10,
                                        letterSpacing: .8,
                                      ),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      _shortTitle(banners[i].title),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontWeight: FontWeight.w800,
                                        fontSize: 18,
                                      ),
                                    ),
                                    if (banners[i].buttonText
                                            ?.trim()
                                            .isNotEmpty ??
                                        false)
                                      Padding(
                                        padding: const EdgeInsets.only(top: 2),
                                        child: Text(
                                          banners[i].buttonText!,
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                          style: TextStyle(
                                            color: tokens.textSecondary
                                                .withOpacity(.84),
                                            fontWeight: FontWeight.w600,
                                            fontSize: 11.5,
                                          ),
                                        ),
                                      ),
                                  ],
                                ),
                              ),
                              if ((banners[i].actionType.toLowerCase() !=
                                      'none') &&
                                  (banners[i].actionValue?.trim().isNotEmpty ??
                                      false))
                                Container(
                                  width: 30,
                                  height: 30,
                                  decoration: BoxDecoration(
                                    color: tokens.glassColor.withOpacity(.18),
                                    borderRadius: BorderRadius.circular(8),
                                  ),
                                  child: const Icon(
                                    Icons.arrow_outward_rounded,
                                    color: Colors.white,
                                    size: 15,
                                  ),
                                ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
            ),
            if (banners.length > 1)
              Positioned(
                left: 0,
                right: 0,
                bottom: 6,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: List.generate(
                    banners.length,
                    (i) => AnimatedContainer(
                      duration: const Duration(milliseconds: 220),
                      margin: const EdgeInsets.symmetric(horizontal: 3),
                      width: i == _index ? 14 : 6,
                      height: 6,
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(999),
                        color:
                            i == _index
                                ? tokens.textPrimary.withOpacity(.90)
                                : tokens.textSecondary.withOpacity(.40),
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _AudioHeader extends StatelessWidget {
  const _AudioHeader({
    required this.totalCount,
    required this.tokens,
    required this.filters,
    required this.selectedFilter,
    required this.onFilterChanged,
  });

  final int totalCount;
  final PremiumThemeTokens tokens;
  final List<String> filters;
  final String selectedFilter;
  final ValueChanged<String> onFilterChanged;

  String _labelFor(String value) {
    if (value == 'all') return 'All';
    if (value == 'popular') return 'Popular';
    return value;
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 10, 16, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Audio Rooms',
                  style: TextStyle(
                    color: tokens.textPrimary,
                    fontSize: 24,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ),
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 6,
                ),
                decoration: BoxDecoration(
                  color: tokens.chipColor.withOpacity(.84),
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(
                    color: tokens.borderColor.withOpacity(.36),
                  ),
                ),
                child: Text(
                  '$totalCount live',
                  style: TextStyle(
                    color: tokens.textSecondary,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            'Join live conversations, request the mic, and move into the speaker circle in real time.',
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.92),
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 12),
          SizedBox(
            height: 38,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: filters.length,
              separatorBuilder: (_, __) => const SizedBox(width: 8),
              itemBuilder: (_, i) {
                final filter = filters[i];
                final selected = selectedFilter == filter;
                return InkWell(
                  onTap: () => onFilterChanged(filter),
                  borderRadius: BorderRadius.circular(999),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 180),
                    padding: const EdgeInsets.symmetric(
                      horizontal: 14,
                      vertical: 9,
                    ),
                    decoration: BoxDecoration(
                      color:
                          selected
                              ? tokens.chipColor.withOpacity(.96)
                              : tokens.glassColor.withOpacity(.10),
                      borderRadius: BorderRadius.circular(999),
                      border: Border.all(
                        color:
                            selected
                                ? tokens.primaryButtonGradient.first
                                    .withOpacity(.72)
                                : tokens.borderColor.withOpacity(.24),
                      ),
                    ),
                    child: Text(
                      _labelFor(filter),
                      style: TextStyle(
                        color: (selected
                                ? tokens.textPrimary
                                : tokens.textSecondary)
                            .withOpacity(selected ? .96 : .88),
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _CompactAudioHeader extends StatelessWidget {
  const _CompactAudioHeader({
    required this.tokens,
    required this.filters,
    required this.selectedFilter,
    required this.onFilterChanged,
  });

  final PremiumThemeTokens tokens;
  final List<String> filters;
  final String selectedFilter;
  final ValueChanged<String> onFilterChanged;

  String _labelFor(String value) {
    if (value == 'all') return 'All';
    if (value == 'popular') return 'Popular';
    if (value.startsWith('lang:')) return value.substring(5);
    if (value.startsWith('topic:')) return value.substring(6);
    return value;
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 2, 16, 8),
      child: SizedBox(
        height: 34,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          itemCount: filters.length,
          separatorBuilder: (_, __) => const SizedBox(width: 8),
          itemBuilder: (_, i) {
            final filter = filters[i];
            final selected = selectedFilter == filter;
            return InkWell(
              onTap: () => onFilterChanged(filter),
              borderRadius: BorderRadius.circular(999),
              child: Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 8,
                ),
                decoration: BoxDecoration(
                  color: selected
                      ? tokens.chipColor.withOpacity(.92)
                      : tokens.glassColor.withOpacity(.08),
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(
                    color: selected
                        ? tokens.primaryButtonGradient.first.withOpacity(.60)
                        : tokens.borderColor.withOpacity(.18),
                  ),
                ),
                child: Text(
                  _labelFor(filter),
                  style: TextStyle(
                    color: selected
                        ? tokens.textPrimary
                        : tokens.textSecondary.withOpacity(.90),
                    fontWeight: FontWeight.w800,
                    fontSize: 12,
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _CompactAudioRoomTile extends StatelessWidget {
  const _CompactAudioRoomTile({
    required this.room,
    required this.tokens,
    required this.onTap,
  });

  final LiveRoomModel room;
  final PremiumThemeTokens tokens;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final hostName =
        room.hostName?.trim().isNotEmpty == true ? room.hostName!.trim() : 'Host';
    final imageUrl = room.thumbnail;

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Ink(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(14),
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              tokens.cardGradient.first.withOpacity(.96),
              tokens.cardGradient.last.withOpacity(.98),
            ],
          ),
          border: Border.all(color: tokens.borderColor.withOpacity(.22)),
        ),
        child: Stack(
          fit: StackFit.expand,
          children: [
            if (imageUrl != null && imageUrl.isNotEmpty)
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: Image.network(
                  imageUrl,
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) => _CompactAudioFallback(tokens: tokens),
                ),
              )
            else
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: _CompactAudioFallback(tokens: tokens),
              ),
            DecoratedBox(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(14),
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    Colors.black.withOpacity(.06),
                    Colors.black.withOpacity(.20),
                    Colors.black.withOpacity(.72),
                  ],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 8,
                          vertical: 5,
                        ),
                        decoration: BoxDecoration(
                          color: tokens.dangerColor,
                          borderRadius: BorderRadius.circular(999),
                        ),
                        child: const Text(
                          'LIVE',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 10,
                            fontWeight: FontWeight.w900,
                            letterSpacing: .7,
                          ),
                        ),
                      ),
                      const Spacer(),
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 8,
                          vertical: 5,
                        ),
                        decoration: BoxDecoration(
                          color: Colors.white.withOpacity(.12),
                          borderRadius: BorderRadius.circular(999),
                        ),
                        child: Text(
                          '${room.liveAudience}',
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w800,
                            fontSize: 11,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const Spacer(),
                  Row(
                    children: [
                      _CompactAudioAvatar(
                        name: hostName,
                        imageUrl: imageUrl,
                        frameUrl: room.hostProfileFrameUrl,
                        tokens: tokens,
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              hostName,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.w900,
                                fontSize: 15,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              '${room.speakerCount} speakers',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                color: Colors.white.withOpacity(.86),
                                fontWeight: FontWeight.w700,
                                fontSize: 12,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
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

class _CompactAudioMetaPill extends StatelessWidget {
  const _CompactAudioMetaPill({required this.label, required this.tokens});

  final String label;
  final PremiumThemeTokens tokens;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      decoration: BoxDecoration(
        color: tokens.glassColor.withOpacity(.08),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor.withOpacity(.18)),
      ),
      child: Text(
        label,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(
          color: tokens.textSecondary.withOpacity(.92),
          fontWeight: FontWeight.w700,
          fontSize: 11,
        ),
      ),
    );
  }
}

class _CompactAudioFallback extends StatelessWidget {
  const _CompactAudioFallback({required this.tokens});

  final PremiumThemeTokens tokens;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.primaryButtonGradient.first,
            tokens.cardGradient.last,
          ],
        ),
      ),
      child: const Center(
        child: Icon(
          Icons.mic_rounded,
          color: Colors.white,
          size: 30,
        ),
      ),
    );
  }
}

class _CompactAudioAvatar extends StatelessWidget {
  const _CompactAudioAvatar({
    required this.name,
    required this.imageUrl,
    this.frameUrl,
    required this.tokens,
  });

  final String name;
  final String? imageUrl;
  final String? frameUrl;
  final PremiumThemeTokens tokens;

  @override
  Widget build(BuildContext context) {
    final first = name.isEmpty ? 'H' : name.characters.first.toUpperCase();
    return SizedBox(
      width: 38,
      height: 38,
      child: FramedAvatar(
        avatarUrl: imageUrl,
        frameUrl: frameUrl,
        label: first,
        size: 38,
        backgroundColor: tokens.glassColor.withOpacity(.18),
      ),
    );
  }
}

class _CompactAudioSkeletonTile extends StatelessWidget {
  const _CompactAudioSkeletonTile({required this.tokens});

  final PremiumThemeTokens tokens;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.cardGradient.first.withOpacity(.40),
            tokens.cardGradient.last.withOpacity(.24),
          ],
        ),
        border: Border.all(color: tokens.borderColor.withOpacity(.16)),
      ),
    );
  }
}

class _EmptyLiveState extends StatelessWidget {
  const _EmptyLiveState({required this.tokens, this.filtered = false});

  final bool filtered;
  final PremiumThemeTokens tokens;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Container(
          constraints: const BoxConstraints(maxWidth: 440),
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 20),
          decoration: BoxDecoration(
            color: tokens.glassColor.withOpacity(.10),
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: tokens.borderColor.withOpacity(.40)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 56,
                height: 56,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: tokens.chipColor.withOpacity(.72),
                ),
                child: const Icon(
                  Icons.graphic_eq_rounded,
                  color: Colors.white,
                  size: 30,
                ),
              ),
              const SizedBox(height: 12),
              const Text(
                'No audio rooms right now',
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: 18,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                filtered
                    ? 'Try another filter or pull to refresh.'
                    : 'Hosts will appear here when they start audio rooms.',
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: tokens.textSecondary.withOpacity(.92),
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

double _audioTileTopOffset(int index, int crossAxisCount) {
  final column = index % crossAxisCount;
  if (crossAxisCount <= 2) {
    return column.isOdd ? 18 : 0;
  }
  if (crossAxisCount == 3) {
    if (column == 1) return 20;
    if (column == 2) return 10;
    return 0;
  }
  if (column == 1 || column == 3) return 18;
  if (column == 2) return 10;
  return 0;
}

class _AudioRoomsErrorState extends StatelessWidget {
  const _AudioRoomsErrorState({
    required this.message,
    required this.tokens,
    required this.onRetry,
  });

  final String message;
  final PremiumThemeTokens tokens;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 440),
          child: Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: tokens.glassColor.withOpacity(.10),
              borderRadius: BorderRadius.circular(22),
              border: Border.all(color: tokens.borderColor.withOpacity(.36)),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(
                  Icons.wifi_tethering_error_rounded,
                  color: Colors.white,
                  size: 34,
                ),
                const SizedBox(height: 12),
                const Text(
                  'Unable to load audio rooms',
                  style: TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w900,
                    fontSize: 18,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  message,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: tokens.textSecondary.withOpacity(.9),
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 16),
                FilledButton.icon(
                  onPressed: onRetry,
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('Retry'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _AudioRoomSkeletonCard extends StatefulWidget {
  const _AudioRoomSkeletonCard();

  @override
  State<_AudioRoomSkeletonCard> createState() => _AudioRoomSkeletonCardState();
}

class _AudioRoomSkeletonCardState extends State<_AudioRoomSkeletonCard>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  PremiumThemeTokens _tokens() {
    final settings = Get.find<AppSettingsService>();
    return getPremiumThemeTokens(settings.activePremiumThemeVariant);
  }

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1200),
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
      builder: (_, __) {
        final opacity = .08 + (_controller.value * .08);
        final tokens = _tokens();
        return Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(26),
            color: tokens.glassColor.withOpacity(.08),
            border: Border.all(color: tokens.borderColor.withOpacity(.18)),
          ),
          padding: const EdgeInsets.all(18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  _ghost(56, 56, opacity, circular: true),
                  const Spacer(),
                  _ghost(70, 24, opacity),
                ],
              ),
              const SizedBox(height: 18),
              _ghost(double.infinity, 20, opacity),
              const SizedBox(height: 10),
              _ghost(160, 14, opacity),
              const Spacer(),
              Row(
                children: [
                  _ghost(94, 30, opacity),
                  const SizedBox(width: 8),
                  _ghost(90, 30, opacity),
                ],
              ),
              const SizedBox(height: 16),
              _ghost(double.infinity, 46, opacity),
            ],
          ),
        );
      },
    );
  }

  Widget _ghost(
    double width,
    double height,
    double opacity, {
    bool circular = false,
  }) {
    final tokens = _tokens();
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: tokens.textPrimary.withOpacity(opacity),
        shape: circular ? BoxShape.circle : BoxShape.rectangle,
        borderRadius: circular ? null : BorderRadius.circular(14),
      ),
    );
  }
}

class _AudioRoomCard extends StatefulWidget {
  const _AudioRoomCard({required this.room, required this.onTap});

  final LiveRoomModel room;
  final VoidCallback onTap;

  @override
  State<_AudioRoomCard> createState() => _AudioRoomCardState();
}

class _AudioRoomCardState extends State<_AudioRoomCard>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  PremiumThemeTokens _tokens() {
    final settings = Get.find<AppSettingsService>();
    return getPremiumThemeTokens(settings.activePremiumThemeVariant);
  }

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1800),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  String _fmt(int value) {
    if (value >= 1000000) return '${(value / 1000000).toStringAsFixed(1)}M';
    if (value >= 1000) return '${(value / 1000).toStringAsFixed(1)}K';
    return '$value';
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _tokens();
    final room = widget.room;
    final hostLabel =
        (room.hostName?.trim().isNotEmpty ?? false)
            ? room.hostName!.trim()
            : 'Host';
    final subtitleTokens = <String>[
      if (room.topic?.trim().isNotEmpty ?? false) room.topic!.trim(),
      if (room.language?.trim().isNotEmpty ?? false) room.language!.trim(),
    ];
    final people =
        {
          hostLabel,
          if (room.topic?.trim().isNotEmpty ?? false) room.topic!.trim(),
          if (room.language?.trim().isNotEmpty ?? false) room.language!.trim(),
        }.take(4).toList();

    return AnimatedBuilder(
      animation: _pulse,
      builder: (_, child) {
        final glow = 8 + (_pulse.value * 8);
        return Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(28),
            boxShadow: [
              BoxShadow(
                color: tokens.glowColor.withOpacity(.12 + (_pulse.value * .10)),
                blurRadius: glow,
                spreadRadius: 1,
              ),
            ],
          ),
          child: child,
        );
      },
      child: InkWell(
        onTap: widget.onTap,
        borderRadius: BorderRadius.circular(28),
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(28),
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                tokens.cardGradient.first,
                tokens.cardGradient.last,
                tokens.backgroundGradient.first.withValues(alpha: .96),
              ],
            ),
            border: Border.all(color: tokens.borderColor.withOpacity(.34)),
          ),
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 56,
                      height: 56,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: LinearGradient(
                          colors: [
                            tokens.primaryButtonGradient.first,
                            tokens.primaryButtonGradient.last,
                          ],
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                        ),
                        border: Border.all(
                          color: tokens.borderColor.withOpacity(.42),
                        ),
                      ),
                      child: Center(
                        child: Text(
                          hostLabel.isNotEmpty
                              ? hostLabel.characters.first.toUpperCase()
                              : 'T',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 22,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 10,
                                  vertical: 5,
                                ),
                                decoration: BoxDecoration(
                                  color: tokens.dangerColor,
                                  borderRadius: BorderRadius.circular(999),
                                ),
                                child: const Text(
                                  'LIVE',
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.w900,
                                    fontSize: 11,
                                  ),
                                ),
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  room.title,
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 18,
                                    fontWeight: FontWeight.w900,
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          Text(
                            hostLabel,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              color: Colors.white.withOpacity(.80),
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                if (subtitleTokens.isNotEmpty) ...[
                  const SizedBox(height: 14),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children:
                        subtitleTokens
                            .map(
                              (value) => Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 10,
                                  vertical: 6,
                                ),
                                decoration: BoxDecoration(
                                  color: tokens.chipColor.withOpacity(.86),
                                  borderRadius: BorderRadius.circular(999),
                                ),
                                child: Text(
                                  value,
                                  style: const TextStyle(
                                    color: Colors.white70,
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                              ),
                            )
                            .toList(),
                  ),
                ],
                const Spacer(),
                Row(
                  children: [
                    Expanded(
                      child: _MetricPill(
                        icon: Icons.headphones_rounded,
                        label:
                            '${_fmt(room.liveAudience)}/${room.maxParticipants}',
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _MetricPill(
                        icon: Icons.mic_rounded,
                        label: '${room.speakerCount}/${room.maxSpeakers}',
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 14),
                Row(
                  children: [
                    SizedBox(
                      width: 94,
                      height: 36,
                      child: Stack(
                        clipBehavior: Clip.none,
                        children: List.generate(people.length.clamp(0, 4), (i) {
                          final label = people.elementAt(i);
                          return Positioned(
                            left: i * 22,
                            child: Container(
                              width: 36,
                              height: 36,
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                color: tokens.cardGradient.last.withOpacity(
                                  .92,
                                ),
                                border: Border.all(
                                  color: tokens.borderColor.withOpacity(.42),
                                  width: 2,
                                ),
                              ),
                              child: Center(
                                child: Text(
                                  label.isNotEmpty
                                      ? label.characters.first.toUpperCase()
                                      : '?',
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                              ),
                            ),
                          );
                        }),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton(
                        onPressed: widget.onTap,
                        style: FilledButton.styleFrom(
                          backgroundColor: tokens.primaryButtonGradient.first,
                          foregroundColor: tokens.textPrimary,
                          padding: const EdgeInsets.symmetric(vertical: 13),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(18),
                          ),
                        ),
                        child: const Text('Join Room'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _MetricPill extends StatelessWidget {
  const _MetricPill({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: tokens.chipColor.withOpacity(.84),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: tokens.borderColor.withOpacity(.24)),
      ),
      child: Row(
        children: [
          Icon(icon, size: 16, color: tokens.textSecondary),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              label,
              style: const TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _LiveTile extends StatelessWidget {
  final LiveRoomModel room;
  final VoidCallback onTap;
  const _LiveTile({required this.room, required this.onTap});

  static String _fmt(int v) {
    if (v >= 1000000) return '${(v / 1000000).toStringAsFixed(1)}M';
    if (v >= 1000) return '${(v / 1000).toStringAsFixed(1)}K';
    return '$v';
  }

  String _initial(String prefer) {
    if (prefer.isNotEmpty) return prefer.characters.first.toUpperCase();
    if (room.title.isNotEmpty) return room.title.characters.first.toUpperCase();
    if (room.id.isNotEmpty) return room.id.characters.first.toUpperCase();
    return 'T';
  }

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final thumb = room.thumbnail;
    final hostLabel =
        (room.hostName?.isNotEmpty == true)
            ? room.hostName!
            : (room.hostId != null ? 'Host #${room.hostId}' : 'Host');
    final viewers = room.liveAudience;

    if (room.isAudioRoom) {
      return Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          child: Ink(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: [
                  tokens.cardGradient.first,
                  tokens.backgroundGradient.first.withValues(alpha: .96),
                ],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              border: Border.all(color: tokens.borderColor.withOpacity(.32)),
            ),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
              child: AudioRoomTile(
                title: room.title,
                speakers: [
                  if ((room.hostName ?? '').isNotEmpty) room.hostName!,
                  if ((room.topic ?? '').isNotEmpty) room.topic!,
                ],
                listeners: viewers,
                onTap: onTap,
              ),
            ),
          ),
        ),
      );
    }

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(0),
        child: Ink(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(0),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withOpacity(.08),
                blurRadius: 8,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(0),
            child: Stack(
              children: [
                if (thumb != null && thumb.isNotEmpty)
                  Image.network(
                    thumb,
                    fit: BoxFit.cover,
                    width: double.infinity,
                    height: double.infinity,
                    errorBuilder: (_, __, ___) => _fallbackBg(tokens),
                    loadingBuilder:
                        (ctx, child, prog) =>
                            prog == null ? child : _fallbackBg(tokens),
                  )
                else
                  _fallbackBg(tokens),

                Positioned.fill(
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.topCenter,
                        end: Alignment.bottomCenter,
                        colors: [
                          Colors.black.withOpacity(.02),
                          Colors.transparent,
                          Colors.black.withOpacity(.40),
                        ],
                        stops: const [.0, .5, 1],
                      ),
                    ),
                  ),
                ),

                Positioned.fill(
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(0),
                      border: Border.all(color: Colors.white.withOpacity(.06)),
                    ),
                  ),
                ),

                Positioned(
                  left: 10,
                  top: 10,
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 4,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.red.withOpacity(.88),
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: const Text(
                      'LIVE',
                      style: TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w800,
                        fontSize: 10,
                        letterSpacing: .4,
                      ),
                    ),
                  ),
                ),

                Positioned(
                  right: 10,
                  top: 10,
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 4,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.black.withOpacity(.28),
                      borderRadius: BorderRadius.circular(999),
                      border: Border.all(color: Colors.white.withOpacity(.14)),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(
                          Icons.visibility_rounded,
                          size: 11,
                          color: Colors.white,
                        ),
                        const SizedBox(width: 4),
                        Text(
                          _fmt(viewers),
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w700,
                            fontSize: 10.2,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),

                Positioned(
                  left: 0,
                  right: 0,
                  bottom: 0,
                  child: Container(
                    padding: const EdgeInsets.fromLTRB(10, 8, 8, 8),
                    decoration: BoxDecoration(
                      color: Colors.black.withOpacity(.42),
                    ),
                    child: Row(
                      children: [
                        SizedBox(
                          width: 24,
                          height: 24,
                          child: FramedAvatar(
                            avatarUrl: thumb,
                            frameUrl: room.hostProfileFrameUrl,
                            label: _initial(hostLabel),
                            size: 24,
                            backgroundColor: Colors.white.withOpacity(.18),
                          ),
                        ),
                        const SizedBox(width: 7),
                        Expanded(
                          child: Text(
                            hostLabel,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w700,
                              fontSize: 13,
                            ),
                          ),
                        ),
                        const Icon(
                          Icons.chevron_right_rounded,
                          color: Colors.white70,
                          size: 17,
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

  Widget _fallbackBg(PremiumThemeTokens tokens) {
    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            tokens.primaryButtonGradient.first,
            tokens.cardGradient.last,
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
    );
  }
}
