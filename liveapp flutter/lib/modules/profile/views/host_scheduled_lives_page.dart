import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/routes/app_routes.dart';
import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';
import '../../../services/auth_service.dart';
import '../../Live/services/live_service.dart';
import '../../home/controllers/live_room_controller.dart';
import '../../home/models/live_room_dto.dart';

PremiumThemeTokens _scheduledLiveTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class HostScheduledLivesPage extends StatefulWidget {
  const HostScheduledLivesPage({super.key});

  @override
  State<HostScheduledLivesPage> createState() => _HostScheduledLivesPageState();
}

class _HostScheduledLivesPageState extends State<HostScheduledLivesPage> {
  final RxSet<String> _startingIds = <String>{}.obs;
  final RxSet<String> _cancellingIds = <String>{}.obs;

  LiveRoomsController get _rooms => Get.find<LiveRoomsController>();
  LiveService get _live => Get.find<LiveService>();
  AuthService get _auth => Get.find<AuthService>();

  int? get _currentUserId => _auth.currentUser?.id;

  @override
  void initState() {
    super.initState();
    _rooms.refreshRooms();
  }

  Future<void> _startRoom(LiveRoomModel room) async {
    if (_startingIds.contains(room.id)) return;
    _startingIds.add(room.id);
    try {
      final started = await _live.createOrStart(
        roomId: room.id,
        startNow: true,
      );
      await _rooms.refreshRooms();
      if (!mounted) return;
      await Get.toNamed(
        Routes.liveAudio,
        arguments: {'room': started},
      );
    } catch (e) {
      Get.snackbar(
        'Unable to start room',
        e.toString().replaceFirst('Exception: ', ''),
        snackPosition: SnackPosition.BOTTOM,
      );
    } finally {
      _startingIds.remove(room.id);
    }
  }

  Future<void> _cancelRoom(LiveRoomModel room) async {
    if (_cancellingIds.contains(room.id)) return;
    final confirm = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Cancel scheduled room'),
        content: Text(
          'Cancel "${room.title.isEmpty ? 'this room' : room.title}"?',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Keep'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Cancel Room'),
          ),
        ],
      ),
    );
    if (confirm != true) return;

    _cancellingIds.add(room.id);
    try {
      await _live.end(room.id);
      await _rooms.refreshRooms();
      Get.snackbar(
        'Scheduled room cancelled',
        'The room has been removed from upcoming sections.',
        snackPosition: SnackPosition.BOTTOM,
      );
    } catch (e) {
      Get.snackbar(
        'Unable to cancel room',
        e.toString().replaceFirst('Exception: ', ''),
        snackPosition: SnackPosition.BOTTOM,
      );
    } finally {
      _cancellingIds.remove(room.id);
    }
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _scheduledLiveTokens();
    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        title: const Text('Scheduled Lives'),
        backgroundColor: Colors.transparent,
        elevation: 0,
      ),
      body: Obx(() {
        final rooms = _rooms.scheduledRooms
            .where((room) => room.hostId == _currentUserId)
            .toList()
          ..sort((a, b) {
            final aAt = a.scheduledAt ?? DateTime.fromMillisecondsSinceEpoch(0);
            final bAt = b.scheduledAt ?? DateTime.fromMillisecondsSinceEpoch(0);
            return aAt.compareTo(bAt);
          });

        if (_rooms.loading.value && rooms.isEmpty) {
          return const Center(child: CircularProgressIndicator(color: Colors.white));
        }

        if (rooms.isEmpty) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    Icons.event_busy_rounded,
                    size: 44,
                    color: Colors.white.withOpacity(.78),
                  ),
                  const SizedBox(height: 14),
                  Text(
                    'No scheduled rooms yet',
                    style: TextStyle(
                      color: tokens.textPrimary,
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Use the live setup sheet and choose Schedule to create one.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: tokens.textSecondary.withOpacity(.8),
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ),
            ),
          );
        }

        return RefreshIndicator(
          onRefresh: _rooms.refreshRooms,
          color: tokens.primaryButtonGradient.first,
          child: ListView.separated(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
            itemCount: rooms.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, index) {
              final room = rooms[index];
              final starting = _startingIds.contains(room.id);
              final cancelling = _cancellingIds.contains(room.id);
              return _ScheduledRoomCard(
                room: room,
                tokens: tokens,
                starting: starting,
                cancelling: cancelling,
                onStart: starting ? null : () => _startRoom(room),
                onCancel: cancelling ? null : () => _cancelRoom(room),
              );
            },
          ),
        );
      }),
    );
  }
}

class _ScheduledRoomCard extends StatelessWidget {
  const _ScheduledRoomCard({
    required this.room,
    required this.tokens,
    required this.starting,
    required this.cancelling,
    required this.onStart,
    required this.onCancel,
  });

  final LiveRoomModel room;
  final PremiumThemeTokens tokens;
  final bool starting;
  final bool cancelling;
  final VoidCallback? onStart;
  final VoidCallback? onCancel;

  @override
  Widget build(BuildContext context) {
    final timeLabel = room.scheduledAt == null
        ? 'Schedule pending'
        : DateFormat('dd MMM yyyy • hh:mm a').format(room.scheduledAt!.toLocal());
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(26),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.cardGradient.first.withOpacity(.94),
            tokens.cardGradient.last.withOpacity(.92),
          ],
        ),
        border: Border.all(color: tokens.borderColor.withOpacity(.52)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  room.title.isEmpty ? 'Untitled room' : room.title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: tokens.textPrimary,
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(999),
                  color: tokens.primaryButtonGradient.first.withOpacity(.16),
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
            ],
          ),
          const SizedBox(height: 10),
          Text(
            timeLabel,
            style: TextStyle(
              color: tokens.textPrimary,
              fontSize: 20,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            'Followers can discover this room before it starts, and reminded viewers will be alerted when you go live.',
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.8),
              fontWeight: FontWeight.w600,
            ),
          ),
          if ((room.topic ?? '').isNotEmpty || (room.language ?? '').isNotEmpty) ...[
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if ((room.topic ?? '').isNotEmpty) _MetaChip(label: room.topic!),
                if ((room.language ?? '').isNotEmpty) _MetaChip(label: room.language!),
              ],
            ),
          ],
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: FilledButton(
                  onPressed: onStart,
                  child: Text(starting ? 'Starting...' : 'Go Live Now'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: OutlinedButton(
                  onPressed: onCancel,
                  style: OutlinedButton.styleFrom(
                    foregroundColor: tokens.textPrimary,
                    side: BorderSide(color: tokens.borderColor.withOpacity(.64)),
                  ),
                  child: Text(cancelling ? 'Cancelling...' : 'Cancel Schedule'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _MetaChip extends StatelessWidget {
  const _MetaChip({required this.label});

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
        style: const TextStyle(
          color: Colors.white,
          fontSize: 12,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}
