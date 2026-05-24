import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:livekit_client/livekit_client.dart';

import '../../../app/routes/app_routes.dart';
import '../../../app/theme/brand.dart';
import '../../../app/utils/profile_frame_payload.dart';
import '../../../app/widgets/haptics.dart';
import '../../../app/widgets/framed_avatar.dart';
import '../../../app/widgets/keep_awake_scope.dart';
import '../../../services/app_settings_service.dart';
import '../../../services/auth_service.dart';
import '../../../services/live_rooms_ws_service.dart';
import '../../profile/controllers/host_follow_controller.dart';
import '../../profile/widgets/public_profile_card_sheet.dart';
import '../../wallet/services/wallet_api.dart';
import '../../wallet/widgets/recharge_bottom_sheet.dart';
import '../models/live_gift_item.dart';
import '../models/live_pk_battle_model.dart';
import '../models/live_room_chat_message.dart';
import '../models/live_room_model.dart';
import '../services/live_service.dart';
import '../widgets/entry_effect_overlay.dart';
import '../widgets/gift_animation_overlay_manager.dart';
import '../widgets/live_room_chat_overlay.dart';
import '../widgets/room_join_animation_overlay_manager.dart';
import '../widgets/live_room_gift_sheet.dart';
import '../widgets/pk_battle_overlay.dart';
import '../widgets/themed_room_frame.dart';

class AudioRoomPage extends StatefulWidget {
  const AudioRoomPage({
    super.key,
    required this.room,
    required this.live,
    this.initialMicOn = true,
    this.devMode = false,
  });

  final LiveRoomModel room;
  final LiveService live;
  final bool initialMicOn;
  final bool devMode;

  @override
  State<AudioRoomPage> createState() => _AudioRoomPageState();
}

class _AudioRoomPageState extends State<AudioRoomPage>
    with TickerProviderStateMixin {
  Room? _room;
  EventsListener<RoomEvent>? _listener;

  bool _connecting = false;
  bool _reconnecting = false;
  bool _exiting = false;
  bool _ended = false;
  bool _leaveSent = false;
  bool _endSent = false;
  bool _roomSocketJoined = false;
  bool _micOn = false;
  bool _micBusy = false;
  bool _seatActionBusy = false;
  bool _giftBusy = false;
  bool _speakerTransitionBusy = false;
  String? _error;
  String? _seatError;
  String? _giftError;
  int? _walletBalanceCoins;
  String? _requestStatus;
  String _currentRole = 'listener';
  int? _pendingRequestId;
  int? _myUserId;
  int _participantCount = 0;
  int _listenerCount = 0;
  int _speakerCount = 0;
  int _maxSpeakers = 8;
  int _maxParticipants = 50;
  bool _mutedByHost = false;
  List<Map<String, dynamic>> _pendingRequests = const [];
  List<Map<String, dynamic>> _speakers = const [];
  List<LiveGiftItem> _availableGifts = const [];
  String? _recentGiftMessage;
  String? _recentGiftEmoji;
  String? _hostName;
  int? _hostUserId;
  int? _hostProfileId;
  int _hostFollowerCount = 0;
  bool _followLoaded = false;

  Timer? _heartbeatTimer;
  Timer? _recentGiftTimer;
  StreamSubscription<Map<String, dynamic>>? _seatEventsSub;
  StreamSubscription<Map<String, dynamic>>? _giftEventsSub;
  StreamSubscription<Map<String, dynamic>>? _roomLifecycleSub;
  StreamSubscription<Map<String, dynamic>>? _roomAudienceSub;
  StreamSubscription<Map<String, dynamic>>? _pkEventsSub;
  StreamSubscription<Map<String, dynamic>>? _chatEventsSub;
  StreamSubscription<Map<String, dynamic>>? _chatErrorsSub;
  StreamSubscription<Map<String, dynamic>>? _moderationEventsSub;
  StreamSubscription<Map<String, dynamic>>? _moderationErrorsSub;
  StreamSubscription<Map<String, dynamic>>? _moderationSystemMessagesSub;
  StreamSubscription<Map<String, dynamic>>? _profileEventsSub;
  StreamSubscription<Map<String, dynamic>>? _joinEventsSub;
  late final AnimationController _livePulse;
  late final AnimationController _giftOverlayPulse;
  Room? _opponentRoom;
  EventsListener<RoomEvent>? _opponentListener;
  LivePkBattleModel? _pkBattle;
  LivePkBattleModel? _incomingPkInvite;
  bool _pkBusy = false;
  bool _opponentConnecting = false;
  bool _opponentMediaUnavailable = false;
  String? _pkOverlayTitle;
  String? _pkOverlaySubtitle;
  Timer? _pkOverlayTimer;
  final RoomJoinAnimationOverlayManager _joinAnimationOverlay =
      RoomJoinAnimationOverlayManager();
  final GiftAnchorRegistry _giftAnchors = GiftAnchorRegistry();
  final GiftAnimationOverlayManager _giftAnimationOverlay =
      GiftAnimationOverlayManager();
  final ValueNotifier<int> _speakerSheetTick = ValueNotifier<int>(0);
  Set<String> _trackedParticipantIds = <String>{};
  bool _joinAnimationsArmed = false;
  final ValueNotifier<List<LiveRoomChatMessage>> _chatMessages =
      ValueNotifier<List<LiveRoomChatMessage>>(const <LiveRoomChatMessage>[]);
  Worker? _themeSyncWorker;

  bool get _isHost => _currentRole == 'host';
  bool get _isSpeaker => _currentRole == 'speaker';
  bool get _isListener =>
      _currentRole == 'listener' || _currentRole == 'viewer';
  bool get _canPublishAudio => _isHost || _isSpeaker;
  bool get _pkActive => _pkBattle?.isActive == true;
  bool get _pkCapable =>
      widget.room.roomType == 'video' &&
      Get.find<AppSettingsService>().pkBattlesEnabled;

  PremiumThemeTokens get _tokens => getPremiumThemeTokens(
    Get.find<AppSettingsService>().activePremiumThemeVariant,
  );

  String _sheetSubtitleWithUserId(String base, int? userId) {
    if (userId == null || userId <= 0) return base;
    return '$base • ID: $userId';
  }

  @override
  void initState() {
    super.initState();
    _livePulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1500),
    )..repeat(reverse: true);
    _giftOverlayPulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1200),
    )..repeat(reverse: true);
    _currentRole = (widget.room.role ?? 'listener').toLowerCase();
    _myUserId = Get.find<AuthService>().currentUser?.id;
    _micOn = widget.initialMicOn;
    _participantCount = widget.room.participantCount;
    _listenerCount = widget.room.listenerCount;
    _speakerCount = widget.room.speakerCount;
    _maxSpeakers = widget.room.maxSpeakers;
    _maxParticipants = widget.room.maxParticipants;
    _speakers = widget.room.speakers;
    final currentUser = Get.find<AuthService>().currentUser;
    _hostUserId =
        widget.room.meta?['host_user_id'] as int? ??
        widget.room.meta?['host_id'] as int?;
    _hostName = widget.room.meta?['host_name']?.toString();
    if (_isHost && currentUser != null) {
      _hostName ??= currentUser.name;
      _hostUserId ??= currentUser.id;
    }
    _themeSyncWorker = ever<AppSettingsPayload?>(
      Get.find<AppSettingsService>().payload,
      (_) => _syncOwnChatTheme(),
    );
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    await _refreshSeatSnapshot();
    await _loadGiftCatalog();
    await _refreshWalletBalance();
    await _loadFollowState();
    _bindSeatEvents();
    _bindGiftEvents();
    _bindRoomLifecycleEvents();
    _bindRoomAudienceEvents();
    _bindChatEvents();
    _bindModerationEvents();
    if (_pkCapable) {
      _bindPkEvents();
      await _syncPkState(prefill: widget.room.pkActive);
    }
    await _connect();
  }

  @override
  void dispose() {
    if (!_endSent) {
      _leaveSessionOnce();
    }
    _heartbeatTimer?.cancel();
    _recentGiftTimer?.cancel();
    _pkOverlayTimer?.cancel();
    _seatEventsSub?.cancel();
    _giftEventsSub?.cancel();
    _roomLifecycleSub?.cancel();
    _roomAudienceSub?.cancel();
    _pkEventsSub?.cancel();
    _chatEventsSub?.cancel();
    _chatErrorsSub?.cancel();
    _moderationEventsSub?.cancel();
    _moderationErrorsSub?.cancel();
    _moderationSystemMessagesSub?.cancel();
    _profileEventsSub?.cancel();
    _joinEventsSub?.cancel();
    _chatMessages.dispose();
    _joinAnimationOverlay.dispose();
    _giftAnimationOverlay.dispose();
    _giftAnchors.dispose();
    _speakerSheetTick.dispose();
    _themeSyncWorker?.dispose();
    _leaveSocketRoom();
    _listener?.dispose();
    _opponentListener?.dispose();
    try {
      _room?.disconnect();
    } catch (_) {}
    try {
      _opponentRoom?.disconnect();
    } catch (_) {}
    _room?.dispose();
    _opponentRoom?.dispose();
    _livePulse.dispose();
    _giftOverlayPulse.dispose();
    super.dispose();
  }

  Future<void> _connect() async {
    final url = widget.room.wsUrl?.trim();
    final token = widget.room.token?.trim();
    if (url == null || url.isEmpty || token == null || token.isEmpty) {
      setState(() => _error = 'Missing audio room connection details.');
      return;
    }

    setState(() {
      _connecting = true;
      _error = null;
    });

    try {
      final room = Room(
        roomOptions: const RoomOptions(
          adaptiveStream: true,
          dynacast: true,
          stopLocalTrackOnUnpublish: true,
          defaultAudioPublishOptions: AudioPublishOptions(dtx: true),
        ),
      );
      final l = room.createListener();
      _listener = l;

      l.on<RoomDisconnectedEvent>((_) async {
        if (_exiting || !mounted) return;
        await _handleRoomEndedExit();
      });
      l.on<RoomReconnectingEvent>((_) {
        if (!mounted) return;
        setState(() => _reconnecting = true);
      });
      l.on<RoomReconnectedEvent>((_) async {
        if (!mounted) return;
        setState(() => _reconnecting = false);
        _syncJoinAnimations(room, animate: false);
        await _refreshSeatSnapshot();
      });
      l.on<ParticipantConnectedEvent>((event) {
        if (!mounted) return;
        _handleParticipantConnected(event.participant, room);
        setState(() {});
      });
      l.on<ParticipantDisconnectedEvent>((_) {
        if (!mounted) return;
        _syncJoinAnimations(room, animate: false);
        setState(() {});
      });
      l.on<TrackSubscribedEvent>((_) {
        if (!mounted) return;
        setState(() {});
      });
      l.on<TrackUnsubscribedEvent>((_) {
        if (!mounted) return;
        setState(() {});
      });
      l.on<TrackMutedEvent>((_) {
        if (!mounted) return;
        setState(() {});
      });
      l.on<TrackUnmutedEvent>((_) {
        if (!mounted) return;
        setState(() {});
      });
      l.on<ActiveSpeakersChangedEvent>((_) {
        if (!mounted) return;
        setState(() {});
      });

      await room.connect(url, token);
      _trackedParticipantIds = _currentParticipantIds(room);
      _joinAnimationsArmed = true;
      if (!_isHost) {
        try {
          final hostParticipant = room.remoteParticipants.values.firstWhere(
            (participant) => participant.identity.startsWith('host-'),
          );
          _hostName ??= hostParticipant.name;
          _hostUserId ??= _userIdFromIdentity(hostParticipant.identity);
        } catch (_) {}
      }
      if (!_followLoaded) {
        unawaited(_loadFollowState());
      }
      _joinSocketRoom();

      if (_canPublishAudio) {
        await room.localParticipant?.setMicrophoneEnabled(_micOn);
      } else {
        await room.localParticipant?.setMicrophoneEnabled(false);
        _micOn = false;
      }

      if (_isHost) {
        _heartbeatTimer?.cancel();
        _heartbeatTimer = Timer.periodic(const Duration(seconds: 30), (
          _,
        ) async {
          try {
            await widget.live.heartbeat(widget.room.roomId);
          } catch (_) {}
        });
      }

      if (!mounted) return;
      setState(() {
        _room = room;
        _connecting = false;
        _reconnecting = false;
      });
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        _showSelfJoinAnimation();
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _connecting = false;
        _error = e.toString();
      });
    }
  }

  Future<void> _refreshSeatSnapshot() async {
    try {
      final raw = await widget.live.seatSnapshot(widget.room.roomId);
      final data = Map<String, dynamic>.from(
        (raw['data'] ?? raw['snapshot'] ?? const {}) as Map,
      );
      final requests =
          ((data['requests'] as List?) ?? const [])
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList();
      final speakers =
          ((data['speakers'] as List?) ?? const [])
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList();

      int? pendingRequestId;
      String? requestStatus;
      bool mutedByHost = false;
      String role = _currentRole;

      if (_myUserId != null) {
        final mine =
            requests
                .where((e) => (e['user_id'] as num?)?.toInt() == _myUserId)
                .toList();
        if (mine.isNotEmpty) {
          final latest = mine.first;
          requestStatus = latest['status']?.toString();
          if (requestStatus == 'pending') {
            pendingRequestId = (latest['request_id'] as num?)?.toInt();
          }
          role = (latest['role']?.toString() ?? role).toLowerCase();
          mutedByHost = latest['muted_by_host'] == true;
        }
      }

      if (!mounted) return;
      setState(() {
        _pendingRequests =
            requests
                .where((e) => e['status']?.toString() == 'pending')
                .toList();
        _speakers = speakers;
        _pendingRequestId = pendingRequestId;
        _requestStatus = requestStatus;
        _speakerCount =
            (data['speaker_count'] as num?)?.toInt() ?? speakers.length;
        _listenerCount =
            (data['listener_count'] as num?)?.toInt() ?? _listenerCount;
        _participantCount =
            (data['participant_count'] as num?)?.toInt() ?? _participantCount;
        _maxSpeakers = (data['max_speakers'] as num?)?.toInt() ?? _maxSpeakers;
        _maxParticipants =
            (data['max_participants'] as num?)?.toInt() ?? _maxParticipants;
        _currentRole = role;
        _mutedByHost = mutedByHost;
      });
      _speakerSheetTick.value++;
    } catch (e) {
      if (!mounted) return;
      setState(() => _seatError = e.toString());
    }
  }

  Future<void> _loadGiftCatalog() async {
    if (!Get.find<AppSettingsService>().giftsEnabled) {
      if (mounted) {
        setState(() {
          _availableGifts = const <LiveGiftItem>[];
          _giftError = 'Gifts are currently unavailable.';
        });
      }
      return;
    }
    try {
      final gifts = await widget.live.listGifts();
      if (!mounted) return;
      setState(() => _availableGifts = gifts);
    } catch (_) {}
  }

  Future<void> _refreshWalletBalance() async {
    try {
      final balance = (await Get.find<WalletApi>().fetchSummary()).balance;
      if (!mounted) return;
      setState(() => _walletBalanceCoins = balance);
    } catch (_) {}
  }

  Future<void> _loadFollowState() async {
    if (_myUserId == null || _hostUserId == null || _hostUserId == _myUserId) {
      if (mounted) {
        setState(() => _followLoaded = true);
      }
      return;
    }
    try {
      final follow = Get.find<HostFollowController>();
      final state = await follow.fetchStateByUserId(_hostUserId!);
      if (!mounted || state == null) return;
      setState(() {
        _hostProfileId = (state['host_id'] as num?)?.toInt();
        _hostFollowerCount =
            (state['follower_count'] as num?)?.toInt() ?? _hostFollowerCount;
        _followLoaded = true;
      });
    } catch (_) {
      if (mounted) {
        setState(() => _followLoaded = true);
      }
    }
  }

  void _bindSeatEvents() {
    if (!Get.isRegistered<RoomsSocketService>()) return;
    _seatEventsSub?.cancel();
    _seatEventsSub = Get.find<RoomsSocketService>().seatEvents.listen((
      event,
    ) async {
      if (event['room_id']?.toString() != widget.room.roomId) return;
      final name = event['event']?.toString() ?? '';
      final userId = (event['user_id'] as num?)?.toInt();
      final requestId = (event['request_id'] as num?)?.toInt();

      if (mounted) {
        setState(() {
          _speakerCount =
              (event['speaker_count'] as num?)?.toInt() ?? _speakerCount;
          _listenerCount =
              (event['listener_count'] as num?)?.toInt() ?? _listenerCount;
          _participantCount =
              (event['participant_count'] as num?)?.toInt() ??
              _participantCount;
          _maxSpeakers =
              (event['max_speakers'] as num?)?.toInt() ?? _maxSpeakers;
          if (requestId != null) {
            if (name == 'seat:request_created') {
              final exists = _pendingRequests.any(
                (row) => (row['request_id'] as num?)?.toInt() == requestId,
              );
              if (!exists) {
                _pendingRequests = [
                  ..._pendingRequests,
                  <String, dynamic>{
                    'request_id': requestId,
                    'id': requestId,
                    'user_id': userId,
                    'status': 'pending',
                    'user': <String, dynamic>{
                      'id': userId,
                      'name': 'Listener',
                    },
                  },
                ];
              }
            } else if (name == 'seat:request_accepted' ||
                name == 'seat:request_rejected' ||
                name == 'seat:request_cancelled') {
              _pendingRequests = _pendingRequests
                  .where(
                    (row) => (row['request_id'] as num?)?.toInt() != requestId,
                  )
                  .toList();
            }
          }
        });
      }

      if (_myUserId != null && userId == _myUserId) {
        if (name == 'seat:request_accepted') {
          await _promoteToSpeaker();
          if (mounted) {
            setState(() {
              _requestStatus = 'accepted';
              _currentRole = 'speaker';
            });
          }
          Haptics.medium();
        } else if (name == 'seat:request_rejected') {
          if (mounted) {
            setState(() {
              _pendingRequestId = null;
              _requestStatus = 'rejected';
            });
          }
          Haptics.light();
        } else if (name == 'seat:request_cancelled') {
          if (mounted) {
            setState(() {
              _pendingRequestId = null;
              _requestStatus = 'cancelled';
            });
          }
        } else if (name == 'speaker:removed') {
          await _demoteToListener();
          if (mounted) {
            setState(() {
              _currentRole = 'listener';
              _requestStatus = 'removed';
            });
          }
          Haptics.medium();
        } else if (name == 'speaker:muted') {
          await _forceMuteLocalMic();
          if (mounted) {
            setState(() => _mutedByHost = true);
          }
          Haptics.light();
        } else if (name == 'speaker:unmuted') {
          await _restoreMicAfterHostUnmute();
          Haptics.light();
        }
      }

      await _refreshSeatSnapshot();
    });
  }

  void _bindGiftEvents() {
    if (!Get.isRegistered<RoomsSocketService>()) return;
    _giftEventsSub?.cancel();
    _giftEventsSub = Get.find<RoomsSocketService>().giftEvents.listen((event) {
      if (!mounted) return;
      final eventRoomId = (event['room_id'] ?? '').toString();
      final eventRoomType = _normalizeGiftRoomType(event['room_type']);
      final expectedRoomType = _normalizeGiftRoomType(widget.room.roomType);
      if (eventRoomId != widget.room.roomId) return;
      if (eventRoomType.isNotEmpty && eventRoomType != expectedRoomType) return;
      final sender = event['sender_name']?.toString() ?? 'Someone';
      final gift = event['gift_name']?.toString() ?? 'gift';
      _giftAnimationOverlay.handleSocketGiftEvent(
        event,
        currentThemeKey:
            Get.find<AppSettingsService>().activePremiumThemeVariant,
        receiverFallbackId: _hostUserId,
        currentUserId: _myUserId,
        inferredPkSide: _pkActive ? GiftAnchorRegistry.pkLeft : null,
      );
      setState(() {
        _recentGiftMessage = '$sender sent $gift';
        _recentGiftEmoji = '🎁';
      });
      _recentGiftTimer?.cancel();
      _recentGiftTimer = Timer(const Duration(seconds: 4), () {
        if (mounted) {
          setState(() {
            _recentGiftMessage = null;
            _recentGiftEmoji = null;
          });
        }
      });
    });
  }

  String _normalizeGiftRoomType(dynamic value) {
    final normalized = value?.toString().trim().toLowerCase() ?? '';
    if (normalized == 'audio' || normalized == 'audio_room') return 'audio';
    if (normalized == 'video' || normalized == 'video_room') return 'video';
    return normalized;
  }

  void _bindRoomLifecycleEvents() {
    if (!Get.isRegistered<RoomsSocketService>()) return;
    _roomLifecycleSub?.cancel();
    _roomLifecycleSub = Get.find<RoomsSocketService>().roomLifecycleEvents
        .listen((event) async {
          if (event['room_id']?.toString() != widget.room.roomId) return;
          await _handleRoomEndedExit();
        });
  }

  void _bindRoomAudienceEvents() {
    if (!Get.isRegistered<RoomsSocketService>()) return;
    _roomAudienceSub?.cancel();
    _roomAudienceSub = Get.find<RoomsSocketService>().roomAudienceEvents.listen(
      (event) {
        if (event['room_id']?.toString() != widget.room.roomId || !mounted)
          return;
        setState(() {
          _participantCount =
              (event['audience'] as num?)?.toInt() ?? _participantCount;
        });
      },
    );
  }

  void _bindChatEvents() {
    if (!Get.isRegistered<RoomsSocketService>()) return;
    _chatEventsSub?.cancel();
    _chatErrorsSub?.cancel();
    _profileEventsSub?.cancel();
    _joinEventsSub?.cancel();
    final rooms = Get.find<RoomsSocketService>();
    _chatEventsSub = rooms.messageEvents.listen((event) {
      if (!mounted) return;
      if ((event['room_id'] ?? '').toString() != widget.room.roomId) return;
      _appendChatMessage(LiveRoomChatMessage.fromSocketJson(event));
    });
    _chatErrorsSub = rooms.messageErrors.listen((event) {
      if (!mounted) return;
      if ((event['room_id'] ?? '').toString() != widget.room.roomId) return;
      final message =
          (event['message'] ?? 'Unable to send message.').toString();
      Get.snackbar(
        'Chat',
        message,
        snackPosition: SnackPosition.BOTTOM,
        duration: const Duration(seconds: 2),
      );
    });
    _profileEventsSub = rooms.profileEvents.listen((event) {
      if (!mounted) return;
      if ((event['room_id'] ?? '').toString() != widget.room.roomId) return;
      final userId = _joinSafeInt(event['user_id']);
      if (userId == null) return;
      _applyUserProfileToChatMessages(
        userId: userId,
        activeThemeKey:
            event['active_theme_key']?.toString().trim().isNotEmpty == true
                ? normalizePremiumThemeVariant(
                  event['active_theme_key'].toString(),
                )
                : null,
        isVip: event['is_vip'] == true,
        level: _joinSafeInt(event['level']),
      );
    });
    _joinEventsSub = rooms.joinEvents.listen((event) {
      if (!mounted) return;
      if ((event['room_id'] ?? '').toString() != widget.room.roomId) return;
      _showJoinAnimationFromSocketEvent(event);
    });
  }

  void _bindModerationEvents() {
    if (!Get.isRegistered<RoomsSocketService>()) return;
    final rooms = Get.find<RoomsSocketService>();
    _moderationEventsSub?.cancel();
    _moderationErrorsSub?.cancel();
    _moderationSystemMessagesSub?.cancel();

    _moderationSystemMessagesSub = rooms.moderationSystemMessages.listen((
      event,
    ) {
      if (!mounted) return;
      if ((event['room_id'] ?? '').toString() != widget.room.roomId) return;
      final message = (event['message'] ?? '').toString().trim();
      if (message.isNotEmpty) {
        _appendSystemChatMessage(message);
      }
    });

    _moderationErrorsSub = rooms.moderationErrors.listen((event) {
      if (!mounted) return;
      final eventRoomId = (event['room_id'] ?? '').toString();
      if (eventRoomId.isNotEmpty && eventRoomId != widget.room.roomId) return;
      final message =
          (event['message'] ?? 'Unable to complete moderation action.')
              .toString();
      Get.snackbar(
        'Moderation',
        message,
        snackPosition: SnackPosition.BOTTOM,
        duration: const Duration(seconds: 3),
      );
    });

    _moderationEventsSub = rooms.moderationEvents.listen((event) async {
      if (!mounted) return;
      final eventRoomId = (event['room_id'] ?? '').toString();
      if (eventRoomId.isNotEmpty && eventRoomId != widget.room.roomId) return;
      final eventRoomType = _normalizeGiftRoomType(event['room_type']);
      final expectedRoomType = _normalizeGiftRoomType(widget.room.roomType);
      if (eventRoomType.isNotEmpty && eventRoomType != expectedRoomType) return;

      final targetUserId =
          _safeInt(event['target_user_id']) ?? _safeInt(event['user_id']);
      final message = (event['message'] ?? '').toString().trim();
      final eventName = (event['event'] ?? '').toString();

      if (targetUserId != null &&
          _myUserId != null &&
          targetUserId == _myUserId &&
          (eventName == 'room:user:kicked' ||
              eventName == 'room:user:blocked')) {
        await _handleModerationTargetExit(
          blocked: eventName == 'room:user:blocked',
          message:
              eventName == 'room:user:blocked'
                  ? 'You were blocked by this host.'
                  : 'You were removed from this room.',
        );
      }
    });
  }

  void _bindPkEvents() {
    if (!_pkCapable) return;
    if (!Get.isRegistered<RoomsSocketService>()) return;
    _pkEventsSub?.cancel();
    _pkEventsSub = Get.find<RoomsSocketService>().pkEvents.listen((
      event,
    ) async {
      final roomA =
          (event['room_a'] is Map)
              ? Map<String, dynamic>.from(event['room_a'] as Map)
              : const <String, dynamic>{};
      final roomB =
          (event['room_b'] is Map)
              ? Map<String, dynamic>.from(event['room_b'] as Map)
              : const <String, dynamic>{};
      final touchesRoom =
          roomA['id']?.toString() == widget.room.roomId ||
          roomB['id']?.toString() == widget.room.roomId;
      if (!touchesRoom || !mounted) return;

      final eventName = (event['event'] ?? '').toString();
      final model = LivePkBattleModel.fromJson(
        Map<String, dynamic>.from(event),
      );

      if (eventName == 'pk:invite_received' && _isHost) {
        setState(() => _incomingPkInvite = model.isPending ? model : null);
      }

      if (const {
        'pk:accepted',
        'pk:started',
        'pk:score_updated',
        'pk:ended',
        'pk:expired',
        'pk:cancelled',
        'pk:rejected',
      }.contains(eventName)) {
        await _syncPkState(prefill: Map<String, dynamic>.from(event));
      }
    });
  }

  Set<String> _currentParticipantIds(Room room) {
    return room.remoteParticipants.values
        .map((participant) => participant.identity.trim())
        .where((identity) => identity.isNotEmpty)
        .toSet();
  }

  void _handleParticipantConnected(Participant participant, Room room) {
    final participantId = participant.identity.trim();
    if (participantId.isEmpty) return;

    if (!_joinAnimationsArmed) {
      _trackedParticipantIds = _currentParticipantIds(room)..add(participantId);
      return;
    }

    if (_trackedParticipantIds.contains(participantId)) {
      _trackedParticipantIds = _currentParticipantIds(room)..add(participantId);
      return;
    }

    _trackedParticipantIds = _currentParticipantIds(room)..add(participantId);
    _showJoinAnimationForParticipant(participant);
  }

  void _syncJoinAnimations(Room room, {required bool animate}) {
    final currentIds = _currentParticipantIds(room);
    if (!_joinAnimationsArmed) {
      _trackedParticipantIds = currentIds;
      return;
    }

    final joinedIds =
        animate ? currentIds.difference(_trackedParticipantIds).toList() : const <String>[];
    _trackedParticipantIds = currentIds;

    if (!animate || joinedIds.isEmpty || !mounted) {
      return;
    }

    for (final participantId in joinedIds) {
      final participant = room.remoteParticipants[participantId];
      if (participant == null) continue;
      _showJoinAnimationForParticipant(participant);
    }
  }

  void _showJoinAnimationForParticipant(Participant participant) {
    if (!mounted) return;
    final request = _joinAnimationRequestForParticipant(participant);
    if (request == null) return;
    _showJoinAnimationRequest(request);
  }

  void _showJoinAnimationFromSocketEvent(Map<String, dynamic> event) {
    final userId =
        event['user_id']?.toString().trim().isNotEmpty == true
            ? event['user_id'].toString().trim()
            : null;
    final name = event['name']?.toString().trim() ?? '';
    if (userId == null || name.isEmpty) return;

    final request = RoomJoinAnimationRequest(
      userId: userId,
      name: name,
      avatarUrl: event['avatar_url']?.toString(),
      frameUrl: profileFrameAssetUrlFromPayload(event),
      themeKey:
          event['active_theme_key']?.toString().trim().isNotEmpty == true
              ? event['active_theme_key'].toString().trim()
              : 'midnight',
      isHost: event['is_host'] == true,
      isVip: event['is_vip'] == true,
      level: _joinSafeInt(event['level']),
    );
    _showJoinAnimationRequest(request);
  }

  void _showJoinAnimationRequest(RoomJoinAnimationRequest request) {
    final knownIdentity = _trackedParticipantIds.contains(request.userId);
    final knownUserId = _trackedParticipantIds.contains('user-${request.userId}');
    if (knownIdentity || knownUserId) return;
    _trackedParticipantIds = {
      ..._trackedParticipantIds,
      request.userId,
      'user-${request.userId}',
    };
    _joinAnimationOverlay.show(context, request);
  }

  void _showSelfJoinAnimation() {
    final currentUser = Get.find<AuthService>().currentUser;
    final userId = _myUserId;
    if (currentUser == null || userId == null) return;

    final request = RoomJoinAnimationRequest(
      userId: userId.toString(),
      name: currentUser.name.trim().isNotEmpty ? currentUser.name.trim() : 'You',
      avatarUrl: currentUser.avatarUrl?.trim().isNotEmpty == true
          ? currentUser.avatarUrl!.trim()
          : null,
      frameUrl: currentUser.profileFrame?.assetUrl,
      themeKey: Get.find<AppSettingsService>().activePremiumThemeVariant,
      isHost: _isHost,
      isVip: currentUser.roles.any(
        (role) => role.toLowerCase() == 'vip' || role.toLowerCase() == 'premium',
      ),
      level: currentUser.level,
    );
    _joinAnimationOverlay.show(context, request);
  }

  RoomJoinAnimationRequest? _joinAnimationRequestForParticipant(
    Participant participant,
  ) {
    final metadata = _participantMetadata(participant);
    final name = _joinParticipantName(participant, metadata);
    if (name.isEmpty) return null;

    return RoomJoinAnimationRequest(
      userId:
          metadata['user_id']?.toString().trim().isNotEmpty == true
              ? metadata['user_id'].toString().trim()
              : participant.identity,
      name: name,
      avatarUrl:
          metadata['avatar_url']?.toString() ?? metadata['avatar']?.toString(),
      frameUrl: profileFrameAssetUrlFromPayload(metadata),
      themeKey:
          metadata['active_theme_key']?.toString().trim().isNotEmpty == true
              ? metadata['active_theme_key'].toString().trim()
              : 'midnight',
      isHost:
          metadata['is_host'] == true ||
          (metadata['role']?.toString().toLowerCase() == 'host') ||
          participant.identity.startsWith('host-'),
      isVip: metadata['is_vip'] == true,
      level: _joinSafeInt(metadata['level']),
    );
  }

  Map<String, dynamic> _participantMetadata(Participant participant) {
    final raw = participant.metadata;
    if (raw == null || raw.trim().isEmpty) {
      return const <String, dynamic>{};
    }

    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) {
        return Map<String, dynamic>.from(decoded);
      }
    } catch (_) {}
    return const <String, dynamic>{};
  }

  String _joinParticipantName(
    Participant participant,
    Map<String, dynamic> metadata,
  ) {
    final metadataName = metadata['name']?.toString().trim() ?? '';
    if (metadataName.isNotEmpty) {
      return metadataName;
    }
    final directName = participant.name.trim();
    if (directName.isNotEmpty) {
      return directName;
    }
    return _participantLabel(participant);
  }

  int? _joinSafeInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  int? _safeInt(dynamic value) => _joinSafeInt(value);

  String _normalizedThemeKey(String? rawThemeKey) {
    return normalizePremiumThemeVariant(rawThemeKey?.trim() ?? 'midnight');
  }

  String _participantThemeKey(Participant participant) {
    final metadata = _participantMetadata(participant);
    final rawThemeKey = metadata['active_theme_key']?.toString();
    if (rawThemeKey?.trim().isNotEmpty == true) {
      return _normalizedThemeKey(rawThemeKey);
    }
    if (participant is LocalParticipant) {
      return Get.find<AppSettingsService>().activePremiumThemeVariant;
    }
    return 'midnight';
  }

  bool _participantIsVip(Participant participant) {
    final metadata = _participantMetadata(participant);
    return metadata['is_vip'] == true;
  }

  Participant? _hostParticipant() {
    if (_isHost) return _room?.localParticipant;
    for (final participant
        in _room?.remoteParticipants.values ?? const <RemoteParticipant>[]) {
      if (participant.identity.startsWith('host-')) {
        return participant;
      }
    }
    return null;
  }

  String _speakerThemeKey(Map<String, dynamic> speaker) {
    final rawThemeKey = speaker['active_theme_key']?.toString();
    if (rawThemeKey?.trim().isNotEmpty == true) {
      return _normalizedThemeKey(rawThemeKey);
    }
    return 'midnight';
  }

  bool _speakerIsVip(Map<String, dynamic> speaker) {
    return speaker['is_vip'] == true;
  }

  String _hostThemeKey() {
    final participant = _hostParticipant();
    if (participant != null) {
      return _participantThemeKey(participant);
    }
    return Get.find<AppSettingsService>().activePremiumThemeVariant;
  }

  bool _hostIsVip() {
    final participant = _hostParticipant();
    if (participant != null) {
      return _participantIsVip(participant);
    }
    return false;
  }

  String _pkHostThemeKey(Map<String, dynamic>? host) {
    final rawThemeKey = host?['active_theme_key']?.toString().trim();
    if (rawThemeKey?.isNotEmpty == true) {
      return normalizePremiumThemeVariant(rawThemeKey!);
    }
    return 'midnight';
  }

  bool _pkHostIsVip(Map<String, dynamic>? host) {
    return host?['is_vip'] == true;
  }

  void _appendChatMessage(LiveRoomChatMessage message) {
    final next = List<LiveRoomChatMessage>.from(_chatMessages.value);
    if (next.any((existing) => existing.id == message.id)) {
      return;
    }
    next.add(message);
    if (next.length > 100) {
      next.removeRange(0, next.length - 100);
    }
    _chatMessages.value = next;
  }

  void _syncOwnChatTheme() {
    final myUserId = _myUserId;
    if (myUserId == null) return;
    final activeThemeKey = Get.find<AppSettingsService>().activePremiumThemeVariant;
    final current = _chatMessages.value;
    var changed = false;
    final next =
        current.map((message) {
          if (message.isSystem || message.senderId != myUserId) {
            return message;
          }
          if (message.senderActiveThemeKey == activeThemeKey) {
            return message;
          }
          changed = true;
          return message.copyWith(senderActiveThemeKey: activeThemeKey);
        }).toList(growable: false);
    if (changed) {
      _chatMessages.value = next;
    }
  }

  void _applyUserProfileToChatMessages({
    required int userId,
    String? activeThemeKey,
    bool? isVip,
    int? level,
  }) {
    final current = _chatMessages.value;
    var changed = false;
    final next =
        current.map((message) {
          if (message.isSystem || message.senderId != userId) {
            return message;
          }
          final resolvedThemeKey = activeThemeKey ?? message.senderActiveThemeKey;
          final resolvedVip = isVip ?? message.senderIsVip;
          final resolvedLevel = level ?? message.senderLevel;
          if (message.senderActiveThemeKey == resolvedThemeKey &&
              message.senderIsVip == resolvedVip &&
              message.senderLevel == resolvedLevel) {
            return message;
          }
          changed = true;
          return message.copyWith(
            senderActiveThemeKey: resolvedThemeKey,
            senderIsVip: resolvedVip,
            senderLevel: resolvedLevel,
          );
        }).toList(growable: false);
    if (changed) {
      _chatMessages.value = next;
    }
  }

  void _appendSystemChatMessage(String message) {
    _appendChatMessage(
      LiveRoomChatMessage.system(
        roomId: widget.room.roomId,
        roomType: widget.room.roomType,
        message: message,
      ),
    );
  }

  bool _currentUserLooksVip() {
    final roles = Get.find<AuthService>().currentUser?.roles ?? const <String>[];
    return roles.any((role) {
      final normalized = role.toLowerCase();
      return normalized.contains('vip') ||
          normalized.contains('premium') ||
          normalized.contains('gold');
    });
  }

  Future<String?> _sendChatMessage(String message) async {
    final trimmed = message.trim();
    if (trimmed.isEmpty) {
      return 'Message cannot be empty.';
    }
    if (trimmed.length > 250) {
      return 'Message must be 250 characters or less.';
    }
    if (!Get.isRegistered<RoomsSocketService>()) {
      return 'Room chat is unavailable.';
    }

    Get.find<RoomsSocketService>().sendRoomMessage(
      roomId: widget.room.roomId,
      roomType: widget.room.roomType,
      message: trimmed,
    );
    return null;
  }

  void _joinSocketRoom() {
    if (_roomSocketJoined || !Get.isRegistered<RoomsSocketService>()) return;
    _roomSocketJoined = true;
    Get.find<RoomsSocketService>().joinRoom(widget.room.roomId);
  }

  void _leaveSocketRoom() {
    if (!_roomSocketJoined || !Get.isRegistered<RoomsSocketService>()) return;
    _roomSocketJoined = false;
    Get.find<RoomsSocketService>().leaveRoom(widget.room.roomId);
  }

  Future<void> _promoteToSpeaker() async {
    if (_speakerTransitionBusy) return;
    _speakerTransitionBusy = true;
    try {
      await _room?.localParticipant?.setMicrophoneEnabled(true);
      if (mounted) {
        setState(() {
          _micOn = true;
          _mutedByHost = false;
        });
      }
    } finally {
      _speakerTransitionBusy = false;
    }
  }

  Future<void> _demoteToListener() async {
    if (_speakerTransitionBusy) return;
    _speakerTransitionBusy = true;
    try {
      await _room?.localParticipant?.setMicrophoneEnabled(false);
      if (mounted) {
        setState(() {
          _micOn = false;
          _pendingRequestId = null;
          _mutedByHost = false;
        });
      }
    } finally {
      _speakerTransitionBusy = false;
    }
  }

  Future<void> _forceMuteLocalMic() async {
    try {
      await _room?.localParticipant?.setMicrophoneEnabled(false);
    } catch (_) {}
    if (mounted) {
      setState(() => _micOn = false);
    }
  }

  Future<void> _restoreMicAfterHostUnmute() async {
    try {
      if (_canPublishAudio) {
        await _room?.localParticipant?.setMicrophoneEnabled(true);
      }
    } catch (_) {}
    if (mounted) {
      setState(() {
        _mutedByHost = false;
        if (_canPublishAudio) _micOn = true;
      });
    }
  }

  Future<void> _toggleMic() async {
    if (!_canPublishAudio || _micBusy || _mutedByHost) return;
    final lp = _room?.localParticipant;
    if (lp == null) return;
    _micBusy = true;
    try {
      final next = !lp.isMicrophoneEnabled();
      await lp.setMicrophoneEnabled(next);
      if (!mounted) return;
      setState(() => _micOn = next);
    } finally {
      _micBusy = false;
    }
  }

  Future<void> _requestMic() async {
    if (_seatActionBusy) return;
    setState(() {
      _seatActionBusy = true;
      _seatError = null;
    });
    try {
      final response = await widget.live.requestSpeaker(widget.room.roomId);
      if (!mounted) return;
      setState(() {
        _pendingRequestId = (response['request_id'] as num?)?.toInt();
        _requestStatus = 'pending';
      });
      Haptics.light();
      await _refreshSeatSnapshot();
    } catch (e) {
      if (!mounted) return;
      setState(() => _seatError = e.toString());
    } finally {
      if (mounted) setState(() => _seatActionBusy = false);
    }
  }

  Future<void> _cancelMicRequest() async {
    final requestId = _pendingRequestId;
    if (_seatActionBusy || requestId == null) return;
    setState(() => _seatActionBusy = true);
    try {
      await widget.live.cancelSpeakerRequest(widget.room.roomId, requestId);
      if (!mounted) return;
      setState(() {
        _pendingRequestId = null;
        _requestStatus = 'cancelled';
      });
      await _refreshSeatSnapshot();
    } catch (e) {
      if (!mounted) return;
      setState(() => _seatError = e.toString());
    } finally {
      if (mounted) setState(() => _seatActionBusy = false);
    }
  }

  Future<void> _removeSpeaker(int userId) async {
    if (_seatActionBusy) return;
    setState(() => _seatActionBusy = true);
    try {
      await widget.live.removeSpeaker(widget.room.roomId, userId);
      await _refreshSeatSnapshot();
    } catch (e) {
      if (mounted) setState(() => _seatError = e.toString());
    } finally {
      if (mounted) setState(() => _seatActionBusy = false);
    }
  }

  Future<void> _muteSpeaker(int userId) async {
    if (_seatActionBusy) return;
    setState(() => _seatActionBusy = true);
    try {
      await widget.live.muteSpeaker(widget.room.roomId, userId);
      await _refreshSeatSnapshot();
    } catch (e) {
      if (mounted) setState(() => _seatError = e.toString());
    } finally {
      if (mounted) setState(() => _seatActionBusy = false);
    }
  }

  Future<void> _unmuteSpeaker(int userId) async {
    if (_seatActionBusy) return;
    setState(() => _seatActionBusy = true);
    try {
      await widget.live.unmuteSpeaker(widget.room.roomId, userId);
      await _refreshSeatSnapshot();
    } catch (e) {
      if (mounted) setState(() => _seatError = e.toString());
    } finally {
      if (mounted) setState(() => _seatActionBusy = false);
    }
  }

  Future<void> _acceptRequest(int requestId) async {
    if (_seatActionBusy) return;
    setState(() {
      _seatActionBusy = true;
      _pendingRequests = _pendingRequests
          .where((row) => (row['request_id'] as num?)?.toInt() != requestId)
          .toList();
    });
    try {
      await widget.live.acceptSpeakerRequest(widget.room.roomId, requestId);
      await _refreshSeatSnapshot();
      Haptics.medium();
    } catch (e) {
      if (mounted) setState(() => _seatError = e.toString());
    } finally {
      if (mounted) setState(() => _seatActionBusy = false);
    }
  }

  Future<void> _rejectRequest(int requestId) async {
    if (_seatActionBusy) return;
    setState(() {
      _seatActionBusy = true;
      _pendingRequests = _pendingRequests
          .where((row) => (row['request_id'] as num?)?.toInt() != requestId)
          .toList();
    });
    try {
      await widget.live.rejectSpeakerRequest(widget.room.roomId, requestId);
      await _refreshSeatSnapshot();
    } catch (e) {
      if (mounted) setState(() => _seatError = e.toString());
    } finally {
      if (mounted) setState(() => _seatActionBusy = false);
    }
  }

  Future<void> _openGiftSheet() async {
    if (!Get.find<AppSettingsService>().giftsEnabled) {
      if (mounted) {
        setState(() => _giftError = 'Gifts are currently unavailable.');
      }
      return;
    }
    if (_giftBusy || _availableGifts.isEmpty) return;
    final selection = await LiveRoomGiftSheet.show(
      context,
      gifts: _availableGifts,
      balanceCoins: _walletBalanceCoins,
    );
    if (selection == null) return;
    setState(() {
      _giftBusy = true;
      _giftError = null;
    });
    _giftAnimationOverlay.showLocalSenderFeedback(
      giftName: selection.gift.name,
      currentThemeKey: Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    try {
      await widget.live.sendRoomGift(
        widget.room.roomId,
        giftId: selection.gift.id,
        quantity: selection.quantity,
      );
      if (mounted) {
        setState(() {
          final spend = selection.gift.coins * selection.quantity;
          if (_walletBalanceCoins != null) {
            final nextBalance = _walletBalanceCoins! - spend;
            _walletBalanceCoins = nextBalance < 0 ? 0 : nextBalance;
          }
        });
      }
      Haptics.light();
    } catch (e) {
      if (!mounted) return;
      final message = e.toString().replaceFirst('Exception: ', '');
      setState(() => _giftError = message);
      if (isInsufficientCoinsErrorMessage(message)) {
        await showRechargeWalletSheet(
          reasonTitle: 'Not enough coins',
          reasonMessage:
              'You need more coins to send gifts in this room. Recharge your wallet and try again.',
        );
        await _refreshWalletBalance();
      }
    } finally {
      if (mounted) setState(() => _giftBusy = false);
    }
  }

  Future<void> _leaveSessionOnce() async {
    if (_leaveSent) return;
    _leaveSent = true;
    if (widget.devMode) return;
    try {
      await widget.live.leave(widget.room.roomId);
    } catch (_) {}
  }

  Future<void> _endSessionOnce() async {
    if (_endSent) return;
    _endSent = true;
    if (widget.devMode) return;
    try {
      await widget.live.end(widget.room.roomId);
    } catch (_) {}
  }

  Future<void> _handleRoomEndedExit() async {
    if (_exiting || !mounted) return;
    _exiting = true;
    setState(() {
      _ended = true;
      _reconnecting = false;
    });
    _heartbeatTimer?.cancel();
    _giftAnimationOverlay.clear();
    await _leaveSessionOnce();
    _leaveSocketRoom();
    await _disconnectOpponentRoom();
    try {
      await _room?.disconnect();
    } catch (_) {}
    await Future<void>.delayed(const Duration(milliseconds: 650));
    if (!mounted) return;
    Get.offAllNamed(Routes.home);
  }

  Future<bool> _confirmExit() async {
    final tokens = _tokens;
    final result = await showModalBottomSheet<bool>(
      context: context,
      backgroundColor: Colors.transparent,
      builder:
          (_) => SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
              child: Container(
                padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: tokens.cardGradient,
                  ),
                  borderRadius: BorderRadius.circular(28),
                  border: Border.all(color: tokens.borderColor),
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Center(
                      child: Container(
                        width: 42,
                        height: 4,
                        decoration: BoxDecoration(
                          color: tokens.borderColor.withOpacity(.85),
                          borderRadius: BorderRadius.circular(999),
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),
                    Text(
                      _isHost ? 'End audio room?' : 'Leave audio room?',
                      style: TextStyle(
                        color: tokens.textPrimary,
                        fontSize: 20,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      _isHost
                          ? 'This will end the room for everyone.'
                          : 'You will return to the main screen.',
                      style: TextStyle(color: tokens.textSecondary),
                    ),
                    const SizedBox(height: 18),
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton(
                            onPressed: () => Navigator.of(context).pop(false),
                            style: OutlinedButton.styleFrom(
                              foregroundColor: tokens.textPrimary,
                              side: BorderSide(color: tokens.borderColor),
                            ),
                            child: const Text('Cancel'),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: FilledButton(
                            onPressed: () => Navigator.of(context).pop(true),
                            style: FilledButton.styleFrom(
                              backgroundColor: tokens.dangerColor,
                              foregroundColor: tokens.textPrimary,
                            ),
                            child: Text(_isHost ? 'End Room' : 'Leave Room'),
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
    return result == true;
  }

  Future<bool?> _showActionSheet({
    required String title,
    required String message,
    required String primaryLabel,
    bool destructive = false,
  }) {
    return showModalBottomSheet<bool>(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (context) {
        return SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
            child: Container(
              padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: _tokens.cardGradient,
                ),
                borderRadius: BorderRadius.circular(28),
                border: Border.all(color: _tokens.borderColor),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Center(
                    child: Container(
                      width: 42,
                      height: 4,
                      decoration: BoxDecoration(
                        color: _tokens.borderColor.withOpacity(.85),
                        borderRadius: BorderRadius.circular(999),
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    title,
                    style: TextStyle(
                      color: _tokens.textPrimary,
                      fontSize: 20,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    message,
                    style: TextStyle(
                      color: _tokens.textSecondary,
                      height: 1.35,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 18),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => Navigator.of(context).pop(false),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: _tokens.textPrimary,
                            side: BorderSide(color: _tokens.borderColor),
                          ),
                          child: const Text('Cancel'),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: FilledButton(
                          onPressed: () => Navigator.of(context).pop(true),
                          style: FilledButton.styleFrom(
                            backgroundColor:
                                destructive
                                    ? _tokens.dangerColor
                                    : _tokens.primaryButtonGradient.first,
                            foregroundColor: _tokens.textPrimary,
                          ),
                          child: Text(primaryLabel),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Future<void> _exitRoom() async {
    final confirmed = await _confirmExit();
    if (!confirmed) return;
    _exiting = true;
    _heartbeatTimer?.cancel();
    _giftAnimationOverlay.clear();
    if (_isHost) {
      await _endSessionOnce();
    } else {
      await _leaveSessionOnce();
    }
    _leaveSocketRoom();
    await _disconnectOpponentRoom();
    try {
      await _room?.disconnect();
    } catch (_) {}
    if (!mounted) return;
    if (widget.devMode) {
      Get.back<void>();
      return;
    }
    Get.offAllNamed(Routes.home);
  }

  Set<int> _speakerUserIds() {
    return _speakers
        .map((speaker) => (speaker['user_id'] as num?)?.toInt())
        .whereType<int>()
        .toSet();
  }

  String _participantLabel(Participant participant) {
    final raw = participant.name.trim();
    if (raw.isNotEmpty) return raw;
    final identity = participant.identity;
    if (identity.startsWith('user:')) return 'User ${identity.substring(5)}';
    if (identity.startsWith('host-')) return 'Host';
    return 'Listener';
  }

  int? _userIdFromIdentity(String? identity) {
    if (identity == null || identity.isEmpty) return null;
    if (identity.startsWith('user:')) {
      return int.tryParse(identity.substring(5));
    }
    if (identity.startsWith('host-')) {
      final parts = identity.split('-');
      if (parts.length >= 2) return int.tryParse(parts[1]);
    }
    return null;
  }

  bool _isParticipantSpeaking(Participant participant) {
    final active = _room?.activeSpeakers ?? const <Participant>[];
    return active.any((speaker) => speaker.identity == participant.identity);
  }

  bool _isSpeakerUserSpeaking(int? userId, String? name) {
    final active = _room?.activeSpeakers ?? const <Participant>[];
    for (final participant in active) {
      final activeUserId = _userIdFromIdentity(participant.identity);
      if (userId != null && activeUserId == userId) return true;
      if (participant.name.trim().isNotEmpty &&
          participant.name.trim() == name?.trim()) {
        return true;
      }
    }
    return false;
  }

  Participant? _participantForSpeaker(int? userId, String? name) {
    final local = _room?.localParticipant;
    if (local != null) {
      final localUserId = _userIdFromIdentity(local.identity);
      if (userId != null && localUserId == userId) return local;
      if (local.name.trim().isNotEmpty && local.name.trim() == name?.trim())
        return local;
    }

    for (final participant
        in _room?.remoteParticipants.values ?? const <RemoteParticipant>[]) {
      final participantUserId = _userIdFromIdentity(participant.identity);
      if (userId != null && participantUserId == userId) return participant;
      if (participant.name.trim().isNotEmpty &&
          participant.name.trim() == name?.trim()) {
        return participant;
      }
    }
    return null;
  }

  bool _participantMicMuted(Participant participant) {
    for (final publication in participant.trackPublications.values) {
      if (publication.source == TrackSource.microphone ||
          publication.source == TrackSource.unknown) {
        return publication.muted;
      }
    }
    return participant is LocalParticipant
        ? !participant.isMicrophoneEnabled()
        : false;
  }

  bool _isSpeakerUserMuted(
    int? userId,
    String? name, {
    required bool mutedByHost,
  }) {
    if (mutedByHost) return true;
    final participant = _participantForSpeaker(userId, name);
    if (participant == null) return false;
    return _participantMicMuted(participant);
  }

  Future<void> _syncPkState({Map<String, dynamic>? prefill}) async {
    if (!_pkCapable) return;

    LivePkBattleModel? battle;
    if (prefill != null && prefill['battle_id'] != null) {
      battle = LivePkBattleModel.fromJson(prefill);
    } else {
      try {
        battle = await widget.live.activePk(widget.room.roomId);
      } catch (_) {
        battle = null;
      }
    }

    if (!mounted) return;
    if (battle == null || !battle.isActive) {
      final previous = _pkBattle;
      setState(() {
        _pkBattle = null;
        _incomingPkInvite = battle != null && battle.isPending ? battle : null;
      });
      await _disconnectOpponentRoom();
      if (previous != null && battle != null && battle.isTerminal) {
        _showPkResult(battle);
      }
      return;
    }

    setState(() {
      _pkBattle = battle;
      _incomingPkInvite = null;
    });
    await _ensureOpponentRoomConnected(forceRefresh: false);
  }

  Future<void> _ensureOpponentRoomConnected({
    required bool forceRefresh,
  }) async {
    final battle = _pkBattle;
    if (battle == null || !battle.isActive || _opponentConnecting) return;
    if (!forceRefresh && _opponentRoom != null) return;

    _opponentConnecting = true;
    try {
      await _disconnectOpponentRoom();
      final payload = await widget.live.pkMediaToken(
        widget.room.roomId,
        battle.battleId,
      );
      final token = payload['opponent_token']?.toString();
      final wsUrl = widget.room.wsUrl?.trim();
      if (token == null || token.isEmpty || wsUrl == null || wsUrl.isEmpty) {
        if (mounted) setState(() => _opponentMediaUnavailable = true);
        return;
      }

      final room = Room(
        roomOptions: const RoomOptions(
          adaptiveStream: true,
          dynacast: true,
          stopLocalTrackOnUnpublish: true,
          defaultAudioPublishOptions: AudioPublishOptions(dtx: true),
        ),
      );
      final listener = room.createListener();
      listener.on<ParticipantConnectedEvent>((_) {
        if (mounted) setState(() => _opponentMediaUnavailable = false);
      });
      listener.on<ParticipantDisconnectedEvent>((_) {
        if (mounted) setState(() {});
      });
      listener.on<TrackSubscribedEvent>((_) {
        if (mounted) setState(() => _opponentMediaUnavailable = false);
      });
      listener.on<TrackUnsubscribedEvent>((_) {
        if (mounted) setState(() {});
      });
      listener.on<ActiveSpeakersChangedEvent>((_) {
        if (mounted) setState(() {});
      });
      listener.on<RoomDisconnectedEvent>((_) {
        if (mounted && _pkActive) {
          setState(() => _opponentMediaUnavailable = true);
        }
      });

      await room.connect(wsUrl, token);
      if (!mounted) {
        await room.disconnect();
        room.dispose();
        listener.dispose();
        return;
      }
      setState(() {
        _opponentRoom = room;
        _opponentListener = listener;
        _opponentMediaUnavailable = room.remoteParticipants.isEmpty;
      });
    } catch (_) {
      if (mounted) {
        setState(() => _opponentMediaUnavailable = true);
      }
    } finally {
      _opponentConnecting = false;
    }
  }

  Future<void> _disconnectOpponentRoom() async {
    _opponentListener?.dispose();
    _opponentListener = null;
    try {
      await _opponentRoom?.disconnect();
    } catch (_) {}
    _opponentRoom?.dispose();
    _opponentRoom = null;
    if (mounted) {
      setState(() => _opponentMediaUnavailable = false);
    }
  }

  void _showPkResult(LivePkBattleModel battle) {
    final myRoomId = widget.room.roomId;
    final title =
        battle.winnerRoomId == null
            ? 'PK Draw'
            : (battle.winnerRoomId == myRoomId
                ? 'Your Side Won'
                : 'Opponent Won');
    _pkOverlayTimer?.cancel();
    setState(() {
      _pkOverlayTitle = title;
      _pkOverlaySubtitle =
          battle.endReason == 'timer_expired'
              ? 'The PK timer finished.'
              : 'The PK battle has ended.';
    });
    _pkOverlayTimer = Timer(const Duration(seconds: 4), () {
      if (mounted) {
        setState(() {
          _pkOverlayTitle = null;
          _pkOverlaySubtitle = null;
        });
      }
    });
  }

  Participant? _opponentHostParticipant() {
    final battle = _pkBattle;
    final opponentUserId =
        (battle?.opponentHostFor(widget.room.roomId)?['user_id'] as num?)
            ?.toInt();
    for (final participant
        in _opponentRoom?.remoteParticipants.values ??
            const <RemoteParticipant>[]) {
      final userId = _userIdFromIdentity(participant.identity);
      if (opponentUserId != null && userId == opponentUserId)
        return participant;
      if (participant.identity.startsWith('host-')) return participant;
    }
    return null;
  }

  bool _isOpponentSpeaking() {
    final participant = _opponentHostParticipant();
    if (participant == null) return false;
    final active = _opponentRoom?.activeSpeakers ?? const <Participant>[];
    return active.any((speaker) => speaker.identity == participant.identity);
  }

  Future<void> _showPkInviteSheet() async {
    if (!_isHost || _pkBusy) return;
    setState(() => _pkBusy = true);
    try {
      final rooms = await widget.live.listAudioRooms();
      if (!mounted) return;
      final candidates =
          rooms
              .where(
                (room) =>
                    room.id != widget.room.roomId && room.status == 'live',
              )
              .toList();
      await showModalBottomSheet<void>(
        context: context,
        backgroundColor: Colors.transparent,
        isScrollControlled: true,
        builder:
            (_) => _PkRoomPickerSheet(
              title: 'Start Audio PK',
              rooms:
                  candidates
                      .map(
                        (room) => {
                          'room_id': room.id,
                          'title': room.title.isNotEmpty ? room.title : room.id,
                          'count': room.participantCount,
                          'room_type': room.roomType,
                        },
                      )
                      .toList(),
              onSelect: (roomId) async {
                Navigator.of(context).pop();
                await _invitePk(roomId);
              },
            ),
      );
    } catch (e) {
      if (mounted)
        setState(
          () => _seatError = e.toString().replaceFirst('Exception: ', ''),
        );
    } finally {
      if (mounted) setState(() => _pkBusy = false);
    }
  }

  Future<void> _invitePk(String targetRoomId) async {
    try {
      final battle = await widget.live.invitePk(
        widget.room.roomId,
        targetRoomId: targetRoomId,
      );
      if (!mounted) return;
      setState(() => _incomingPkInvite = battle.isPending ? battle : null);
    } catch (e) {
      if (mounted)
        setState(
          () => _seatError = e.toString().replaceFirst('Exception: ', ''),
        );
    }
  }

  Future<void> _respondToIncomingPk(bool accept) async {
    final battle = _incomingPkInvite;
    if (battle == null || _pkBusy) return;
    setState(() => _pkBusy = true);
    try {
      if (accept) {
        await widget.live.acceptPk(widget.room.roomId, battle.battleId);
      } else {
        await widget.live.rejectPk(widget.room.roomId, battle.battleId);
      }
      await _syncPkState();
    } catch (e) {
      if (mounted)
        setState(
          () => _seatError = e.toString().replaceFirst('Exception: ', ''),
        );
    } finally {
      if (mounted) setState(() => _pkBusy = false);
    }
  }

  Future<void> _endPkBattle() async {
    final battle = _pkBattle;
    if (battle == null || _pkBusy) return;
    setState(() => _pkBusy = true);
    try {
      await widget.live.endPk(widget.room.roomId, battle.battleId);
      await _syncPkState();
    } catch (e) {
      if (mounted)
        setState(
          () => _seatError = e.toString().replaceFirst('Exception: ', ''),
        );
    } finally {
      if (mounted) setState(() => _pkBusy = false);
    }
  }

  Widget _buildPkAudioPanel() {
    final battle = _pkBattle!;
    final ownHost = battle.ownHostFor(widget.room.roomId);
    final opponentHost = battle.opponentHostFor(widget.room.roomId);
    final ownLabel = ownHost?['name']?.toString() ?? (_hostName ?? 'Host');
    final opponentLabel = opponentHost?['name']?.toString() ?? 'Opponent';

    return PkBattleOverlay(
      battle: battle,
      ownLabel: ownLabel,
      opponentLabel: opponentLabel,
      ownScore: battle.ownScoreFor(widget.room.roomId),
      opponentScore: battle.opponentScoreFor(widget.room.roomId),
      opponentUnavailable: _opponentConnecting || _opponentMediaUnavailable,
      canEnd: _isHost,
      onEnd: _isHost ? _endPkBattle : null,
      ownChild: KeyedSubtree(
        key: _giftAnchors.keyFor(GiftAnchorRegistry.pkLeft),
        child: ThemedRoomFrame(
          themeKey: _pkHostThemeKey(ownHost),
          isHost: true,
          isVip: _pkHostIsVip(ownHost),
          isSpeaking:
              _room?.activeSpeakers.any(
                (speaker) =>
                    _isHost
                        ? speaker is LocalParticipant
                        : speaker.identity.startsWith('host-'),
              ) ??
              false,
          isPkWinner: battle.winnerRoomId == widget.room.roomId,
          borderRadius: 22,
          child: _PkAudioCardBody(
            label: ownLabel,
            subtitle: 'Your host',
            speaking:
                _room?.activeSpeakers.any(
                  (speaker) =>
                      _isHost
                          ? speaker is LocalParticipant
                          : speaker.identity.startsWith('host-'),
                ) ??
                false,
          ),
        ),
      ),
      opponentChild: ThemedRoomFrame(
        key: _giftAnchors.keyFor(GiftAnchorRegistry.pkRight),
        themeKey: _pkHostThemeKey(opponentHost),
        isHost: true,
        isVip: _pkHostIsVip(opponentHost),
        isSpeaking: _isOpponentSpeaking(),
        isPkWinner:
            battle.winnerRoomId != null &&
            battle.winnerRoomId != widget.room.roomId,
        borderRadius: 22,
        child: _PkAudioCardBody(
          label: opponentLabel,
          subtitle: _opponentConnecting ? 'Connecting…' : 'Opponent host',
          speaking: _isOpponentSpeaking(),
        ),
      ),
    );
  }

  List<_SpeakerGridEntry> _buildSpeakerEntries() {
    final entries = <_SpeakerGridEntry>[
      _SpeakerGridEntry(
        key: _giftAnchors.keyFor(GiftAnchorRegistry.audioHostAvatar),
        name: _isHost ? 'You' : (_hostName ?? 'Host'),
        roleLabel: 'Host',
        highlighted: true,
        speaking:
            _room?.activeSpeakers.any(
              (p) =>
                  p.identity.startsWith('host-') ||
                  (_isHost && p is LocalParticipant),
            ) ??
            false,
        muted: _isHost ? _mutedByHost || !_micOn : false,
        themeKey: _hostThemeKey(),
        isVip: _hostIsVip(),
        isHost: true,
        userId: _hostUserId,
        avatarUrl: null,
        frameUrl: null,
        level: null,
        onProfileTap:
            () => _showParticipantProfileCard(
              name: _isHost ? 'You' : (_hostName ?? 'Host'),
              subtitle: 'Host',
              themeKey: _hostThemeKey(),
              isVip: _hostIsVip(),
              isHost: true,
              speaking:
                  _room?.activeSpeakers.any(
                    (p) =>
                        p.identity.startsWith('host-') ||
                        (_isHost && p is LocalParticipant),
                  ) ??
                  false,
              userId: _hostUserId,
            ),
      ),
    ];

    for (final speaker in _speakers) {
      final userId = (speaker['user_id'] as num?)?.toInt();
      final isMe = userId != null && userId == _myUserId;
      final mutedByHost = speaker['muted_by_host'] == true;
      final muted = _isSpeakerUserMuted(
        userId,
        speaker['name']?.toString(),
        mutedByHost: mutedByHost,
      );
      entries.add(
        _SpeakerGridEntry(
          key: ValueKey('speaker-${userId ?? speaker['name']}'),
          name: isMe ? 'You' : (speaker['name']?.toString() ?? 'Speaker'),
          roleLabel: isMe && mutedByHost ? 'Muted by host' : 'Speaker',
          highlighted: isMe,
          speaking:
              !muted &&
              _isSpeakerUserSpeaking(userId, speaker['name']?.toString()),
          muted: muted,
          themeKey:
              isMe && _room?.localParticipant != null
                  ? _participantThemeKey(_room!.localParticipant!)
                  : _speakerThemeKey(speaker),
          isVip:
              isMe && _room?.localParticipant != null
                  ? _participantIsVip(_room!.localParticipant!)
                  : _speakerIsVip(speaker),
          isHost: false,
          userId: userId,
          avatarUrl:
              speaker['avatar_url']?.toString() ?? speaker['avatar']?.toString(),
          frameUrl: profileFrameAssetUrlFromPayload(speaker),
          level: _joinSafeInt(speaker['level']),
          onProfileTap:
              () => _showParticipantProfileCard(
                name: isMe ? 'You' : (speaker['name']?.toString() ?? 'Speaker'),
                subtitle: isMe && mutedByHost ? 'Muted by host' : 'Speaker',
                themeKey:
                    isMe && _room?.localParticipant != null
                        ? _participantThemeKey(_room!.localParticipant!)
                        : _speakerThemeKey(speaker),
                isVip:
                    isMe && _room?.localParticipant != null
                        ? _participantIsVip(_room!.localParticipant!)
                        : _speakerIsVip(speaker),
                isHost: false,
                speaking:
                    !muted &&
                    _isSpeakerUserSpeaking(userId, speaker['name']?.toString()),
                userId: userId,
                level: _joinSafeInt(speaker['level']),
                avatarUrl:
                    speaker['avatar_url']?.toString() ??
                    speaker['avatar']?.toString(),
              ),
          onMute:
              _isHost && userId != null
                  ? (mutedByHost
                      ? () => _unmuteSpeaker(userId)
                      : () => _muteSpeaker(userId))
                  : null,
          muteActionLabel: mutedByHost ? 'Unmute' : 'Mute',
          onRemove:
              _isHost && userId != null ? () => _removeSpeaker(userId) : null,
        ),
      );
    }

    return entries;
  }

  List<_ListenerGridEntry> _buildListenerEntries(List<Participant> listeners) {
    return listeners
        .map(
          (participant) {
            final metadata = _participantMetadata(participant);
            final name = _participantLabel(participant);
            final isMe = participant is LocalParticipant && _isListener;
            final speaking = _isParticipantSpeaking(participant);
            final themeKey = _participantThemeKey(participant);
            final isVip = _participantIsVip(participant);
            final userId = _joinSafeInt(metadata['user_id']);
            final level = _joinSafeInt(metadata['level']);
            final avatarUrl =
                metadata['avatar_url']?.toString() ??
                metadata['avatar']?.toString();
            final frameUrl = profileFrameAssetUrlFromPayload(metadata);
            return _ListenerGridEntry(
              key: ValueKey('listener-${participant.identity}'),
              label: name,
              speaking: speaking,
              isMe: isMe,
              themeKey: themeKey,
              isVip: isVip,
              userId: userId,
              avatarUrl: avatarUrl,
              frameUrl: frameUrl,
              level: level,
              onProfileTap:
                  () => _showParticipantProfileCard(
                    name: isMe ? 'You' : name,
                    subtitle: isVip ? 'VIP Listener' : 'Listener',
                    themeKey: themeKey,
                    isVip: isVip,
                    isHost: false,
                    speaking: speaking,
                    userId: userId,
                    level: level,
                    avatarUrl: avatarUrl,
                  ),
            );
          },
        )
        .toList();
  }

  List<_ListenerGridEntry> _buildDevListenerEntries() {
    final raw =
        widget.room.meta?['dev_listeners'] as List<dynamic>? ?? const <dynamic>[];
    return raw.whereType<Map>().map((entry) {
      final data = Map<String, dynamic>.from(entry);
      final label = data['label']?.toString() ?? 'Listener';
      final speaking = data['speaking'] == true;
      final isMe = data['is_me'] == true;
      final themeKey = normalizePremiumThemeVariant(
        data['theme_key']?.toString() ?? 'midnight',
      );
      final isVip = data['is_vip'] == true;
      final userId = _joinSafeInt(data['user_id']);
      final avatarUrl =
          data['avatar_url']?.toString() ?? data['avatar']?.toString();
      final frameUrl = profileFrameAssetUrlFromPayload(data);
      final level = _joinSafeInt(data['level']);
      return _ListenerGridEntry(
        key: ValueKey('dev-listener-$label'),
        label: label,
        speaking: speaking,
        isMe: isMe,
        themeKey: themeKey,
        isVip: isVip,
        userId: userId,
        avatarUrl: avatarUrl,
        frameUrl: frameUrl,
        level: level,
        onProfileTap:
            () => _showParticipantProfileCard(
              name: isMe ? 'You' : label,
              subtitle: isVip ? 'VIP Listener' : 'Listener',
              themeKey: themeKey,
              isVip: isVip,
              isHost: false,
              speaking: speaking,
              userId: userId,
              level: level,
              avatarUrl: avatarUrl,
            ),
      );
    }).toList(growable: false);
  }

  List<Participant> _listenerParticipants() {
    final local = _room?.localParticipant;
    final remote =
        _room?.remoteParticipants.values.toList() ??
        const <RemoteParticipant>[];
    final speakerIds = _speakerUserIds();
    final result = <Participant>[];

    if (local != null && _isListener) {
      result.add(local);
    }

    for (final participant in remote) {
      final userId = _userIdFromIdentity(participant.identity);
      final isHostParticipant = participant.identity.startsWith('host-');
      if (isHostParticipant) continue;
      if (userId != null && speakerIds.contains(userId)) continue;
      result.add(participant);
    }

    return result;
  }

  Future<void> _toggleFollowHost() async {
    final hostProfileId = _hostProfileId;
    if (hostProfileId == null) return;
    final follow = Get.find<HostFollowController>();
    await follow.toggleForHost(
      hostId: hostProfileId,
      current: follow.isFollowing(hostProfileId),
      currentCount: follow.followerCount(
        hostProfileId,
        fallback: _hostFollowerCount,
      ),
    );
    if (!mounted) return;
    setState(() {
      _hostFollowerCount = follow.followerCount(
        hostProfileId,
        fallback: _hostFollowerCount,
      );
    });
  }

  Future<void> _showRequestsSheet() async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) {
        var pendingRequests = List<Map<String, dynamic>>.from(_pendingRequests);
        var busy = _seatActionBusy;

        void syncFromParent(StateSetter setModalState) {
          if (!mounted) return;
          setModalState(() {
            pendingRequests = List<Map<String, dynamic>>.from(_pendingRequests);
            busy = _seatActionBusy;
          });
        }

        return StatefulBuilder(
          builder: (context, setModalState) {
            Future<void> handleAccept(int requestId) async {
              setModalState(() => busy = true);
              await _acceptRequest(requestId);
              syncFromParent(setModalState);
            }

            Future<void> handleReject(int requestId) async {
              setModalState(() => busy = true);
              await _rejectRequest(requestId);
              syncFromParent(setModalState);
            }

            return _AudioSheet(
              title: 'Mic Requests',
              subtitle: '${pendingRequests.length} pending',
              child:
                  pendingRequests.isEmpty
                      ? const _ModalEmptyState(
                        icon: Icons.inbox_rounded,
                        title: 'No pending requests',
                        body: 'Listeners who request the mic will appear here.',
                      )
                      : ListView.separated(
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        itemCount: pendingRequests.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (_, i) {
                          final request = pendingRequests[i];
                          return _RequestCard(
                            request: request,
                            canModerate: _isHost,
                            busy: busy,
                            onAccept:
                                () => handleAccept(
                                  (request['request_id'] as num).toInt(),
                                ),
                            onReject:
                                () => handleReject(
                                  (request['request_id'] as num).toInt(),
                                ),
                          );
                        },
                      ),
            );
          },
        );
      },
    );
  }

  Future<void> _showSpeakersSheet() async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder:
          (_) => ValueListenableBuilder<int>(
            valueListenable: _speakerSheetTick,
            builder:
                (_, __, ___) => _AudioSheet(
                  title: 'Speakers',
                  subtitle: '${_speakerCount + 1}/${_maxSpeakers + 1} on stage',
                  child: Column(
                    children:
                        _buildSpeakerCards()
                            .map(
                              (card) => Padding(
                                padding: const EdgeInsets.only(bottom: 12),
                                child: SizedBox(
                                  width: double.infinity,
                                  child: card,
                                ),
                              ),
                            )
                            .toList(),
                  ),
                ),
          ),
    );
  }

  Future<void> _showListenersSheet() async {
    final listeners = _listenerParticipants();
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder:
          (_) => _AudioSheet(
            title: 'Listeners',
            subtitle: '${listeners.length} visible here',
            child:
                listeners.isEmpty
                    ? const _ModalEmptyState(
                      icon: Icons.headphones_rounded,
                      title: 'No listeners visible',
                      body:
                          'Listener previews populate as participants join the room.',
                    )
                    : ListView.separated(
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      itemCount: listeners.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 10),
                      itemBuilder:
                          (_, i) {
                            final participant = listeners[i];
                            final metadata = _participantMetadata(participant);
                            return _AudienceTile(
                              label: _participantLabel(participant),
                              subtitle: _sheetSubtitleWithUserId(
                                'Listening',
                                _joinSafeInt(metadata['user_id']),
                              ),
                              speaking: _isParticipantSpeaking(participant),
                              avatarUrl:
                                  metadata['avatar_url']?.toString() ??
                                  metadata['avatar']?.toString(),
                              frameUrl: profileFrameAssetUrlFromPayload(metadata),
                              onProfileTap:
                                  () => _showParticipantProfileCard(
                                    name: _participantLabel(participant),
                                    subtitle: 'Listener',
                                    themeKey: _participantThemeKey(participant),
                                    isVip: _participantIsVip(participant),
                                    isHost:
                                        metadata['is_host'] == true ||
                                        participant.identity.startsWith('host-'),
                                    speaking: _isParticipantSpeaking(participant),
                                    userId: _joinSafeInt(metadata['user_id']),
                                    level: _joinSafeInt(metadata['level']),
                                    avatarUrl:
                                        metadata['avatar_url']?.toString() ??
                                        metadata['avatar']?.toString(),
                                  ),
                            );
                          },
                    ),
          ),
    );
  }

  Future<void> _showParticipantProfileCard({
    required String name,
    required String subtitle,
    required String themeKey,
    required bool isVip,
    required bool isHost,
    required bool speaking,
    int? userId,
    int? level,
    String? avatarUrl,
  }) async {
    await _showParticipantActionsSheet(
      name: name,
      subtitle: subtitle,
      themeKey: themeKey,
      isVip: isVip,
      isHost: isHost,
      speaking: speaking,
      userId: userId,
      level: level,
      avatarUrl: avatarUrl,
    );
  }

  Future<void> _showParticipantActionsSheet({
    required String name,
    required String subtitle,
    required String themeKey,
    required bool isVip,
    required bool isHost,
    required bool speaking,
    int? userId,
    int? level,
    String? avatarUrl,
  }) async {
    final canModerate =
        _isHost &&
        userId != null &&
        userId > 0 &&
        _myUserId != null &&
        userId != _myUserId;
    var isBlocked = false;
    if (canModerate) {
      try {
        final rows = await widget.live.fetchHostBlockedUsers();
        isBlocked = rows.any((row) => _safeInt(row['user_id']) == userId);
      } catch (_) {}
    }
    if (!mounted) return;

    await showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) {
        return _AudioSheet(
          title: name,
          subtitle: subtitle,
          child: Column(
            children: [
              _buildParticipantActionTile(
                icon: Icons.person_rounded,
                title: 'View profile',
                subtitle: 'Open the participant profile card',
                onTap: () {
                  Navigator.of(context).pop();
                  _openParticipantProfile(
                    userId: userId,
                    name: name,
                    subtitle: subtitle,
                    themeKey: themeKey,
                    isVip: isVip,
                    isHost: isHost,
                    speaking: speaking,
                    level: level,
                    avatarUrl: avatarUrl,
                  );
                },
              ),
              _buildParticipantActionTile(
                icon: Icons.flag_rounded,
                title: 'Report user',
                subtitle: 'Send a moderation report',
                onTap:
                    userId == null || userId <= 0 || userId == _myUserId
                        ? null
                        : () {
                          Navigator.of(context).pop();
                          _showReportSheet(
                            reportedUserId: userId,
                            reportedName: name,
                          );
                        },
              ),
              if (canModerate)
                _buildParticipantActionTile(
                  icon: Icons.person_remove_rounded,
                  title: 'Kick from room',
                  subtitle: 'Remove this user from the current room only',
                  destructive: true,
                  onTap: () {
                    Navigator.of(context).pop();
                    _kickParticipant(userId!, name);
                  },
                ),
              if (canModerate)
                _buildParticipantActionTile(
                  icon:
                      isBlocked
                          ? Icons.lock_open_rounded
                          : Icons.block_rounded,
                  title: isBlocked ? 'Unblock user' : 'Block permanently',
                  subtitle:
                      isBlocked
                          ? 'Allow this user to join your rooms again'
                          : 'Remove and block from all your rooms',
                  destructive: !isBlocked,
                  onTap: () {
                    Navigator.of(context).pop();
                    if (isBlocked) {
                      _unblockParticipant(userId!, name);
                    } else {
                      _blockParticipant(userId!, name);
                    }
                  },
                ),
            ],
          ),
        );
      },
    );
  }

  Future<void> _openParticipantProfile({
    required int? userId,
    required String name,
    required String subtitle,
    required String themeKey,
    required bool isVip,
    required bool isHost,
    required bool speaking,
    int? level,
    String? avatarUrl,
  }) async {
    if (!widget.devMode && userId != null && userId > 0) {
      await showPublicProfileCardSheet(
        context,
        userId: userId,
        initialName: name,
        initialSubtitle: subtitle,
        initialThemeKey: themeKey,
        initialIsVip: isVip,
        initialIsHost: isHost,
        initialSpeaking: speaking,
        initialLevel: level,
        initialAvatarUrl: avatarUrl,
      );
      return;
    }

    await showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder:
          (_) => _ParticipantProfileCardSheet(
            name: name,
            subtitle: subtitle,
            themeKey: themeKey,
            isVip: isVip,
            isHost: isHost,
            speaking: speaking,
            userId: userId,
            level: level,
            avatarUrl: avatarUrl,
          ),
    );
  }

  Widget _buildParticipantActionTile({
    required IconData icon,
    required String title,
    required String subtitle,
    required VoidCallback? onTap,
    bool destructive = false,
  }) {
    final tokens = _tokens;
    final iconColor =
        destructive ? tokens.dangerColor : tokens.primaryButtonGradient.first;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(18),
          child: Ink(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(
              color: tokens.glassColor.withOpacity(.14),
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: tokens.borderColor.withOpacity(.20)),
            ),
            child: Row(
              children: [
                Icon(icon, color: iconColor, size: 20),
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
                      const SizedBox(height: 2),
                      Text(
                        subtitle,
                        style: TextStyle(
                          color: tokens.textSecondary,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                ),
                Icon(
                  Icons.chevron_right_rounded,
                  color: tokens.textSecondary.withOpacity(.72),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _kickParticipant(int userId, String name) async {
    final ok = await _showActionSheet(
      title: 'Kick $name?',
      message: 'This removes the user from the current audio room only.',
      primaryLabel: 'Kick user',
      destructive: true,
    );
    if (ok != true) return;
    try {
      await widget.live.kickUser(
        roomId: widget.room.roomId,
        roomType: widget.room.roomType,
        userId: userId,
      );
      if (!mounted) return;
      Get.snackbar(
        'Moderation',
        '$name was removed from the room.',
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

  Future<void> _blockParticipant(int userId, String name) async {
    final reason = await _showReasonPromptSheet(
      title: 'Block $name permanently',
      description:
          'This removes the user now and blocks them from joining any of your rooms.',
      ctaLabel: 'Block user',
    );
    if (reason == null) return;
    try {
      await widget.live.blockUser(
        userId: userId,
        reason: reason,
        roomId: widget.room.roomId,
        roomType: widget.room.roomType,
      );
      if (!mounted) return;
      Get.snackbar(
        'Moderation',
        '$name was blocked.',
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

  Future<void> _unblockParticipant(int userId, String name) async {
    final ok = await _showActionSheet(
      title: 'Unblock $name?',
      message: 'This user will be able to join your rooms again.',
      primaryLabel: 'Unblock user',
      destructive: false,
    );
    if (ok != true) return;
    try {
      await widget.live.unblockUser(userId: userId);
      if (!mounted) return;
      Get.snackbar(
        'Moderation',
        '$name was unblocked.',
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

  Future<void> _showReportSheet({
    required int reportedUserId,
    required String reportedName,
  }) async {
    final reasons = const <String>[
      'abuse',
      'spam',
      'harassment',
      'scam',
      'nudity',
      'hate_speech',
      'other',
    ];
    String selectedReason = reasons.first;
    final descriptionController = TextEditingController();
    final submitted = await showModalBottomSheet<bool>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            return _AudioSheet(
              title: 'Report $reportedName',
              subtitle: 'Choose a reason and add details if needed',
              child: Column(
                children: [
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      for (final reason in reasons)
                        ChoiceChip(
                          label: Text(reason.replaceAll('_', ' ')),
                          selected: selectedReason == reason,
                          onSelected:
                              (_) => setModalState(() => selectedReason = reason),
                        ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  TextField(
                    controller: descriptionController,
                    minLines: 3,
                    maxLines: 5,
                    decoration: const InputDecoration(
                      labelText: 'Description (optional)',
                      hintText: 'Share more detail for the review queue',
                    ),
                  ),
                  const SizedBox(height: 14),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: () => Navigator.of(context).pop(true),
                      child: const Text('Submit report'),
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
    if (submitted != true) {
      descriptionController.dispose();
      return;
    }
    try {
      await widget.live.submitReport(
        reportedUserId: reportedUserId,
        hostUserId: _hostUserId,
        roomId: widget.room.roomId,
        roomType: widget.room.roomType,
        reasonType: selectedReason,
        description: descriptionController.text.trim(),
      );
      if (!mounted) return;
      _appendSystemChatMessage('Report submitted');
      Get.snackbar(
        'Moderation',
        'Report submitted.',
        snackPosition: SnackPosition.BOTTOM,
      );
    } catch (e) {
      Get.snackbar(
        'Moderation',
        e.toString().replaceFirst('Exception: ', ''),
        snackPosition: SnackPosition.BOTTOM,
      );
    } finally {
      descriptionController.dispose();
    }
  }

  Future<String?> _showReasonPromptSheet({
    required String title,
    required String description,
    required String ctaLabel,
  }) async {
    final controller = TextEditingController();
    final result = await showModalBottomSheet<String?>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) {
        return _AudioSheet(
          title: title,
          subtitle: description,
          child: Column(
            children: [
              TextField(
                controller: controller,
                minLines: 2,
                maxLines: 4,
                decoration: const InputDecoration(
                  labelText: 'Reason (optional)',
                  hintText: 'Add context for this moderation action',
                ),
              ),
              const SizedBox(height: 14),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () => Navigator.of(context).pop(controller.text.trim()),
                  child: Text(ctaLabel),
                ),
              ),
            ],
          ),
        );
      },
    );
    controller.dispose();
    return result;
  }

  Future<void> _handleModerationTargetExit({
    required bool blocked,
    required String message,
  }) async {
    if (_exiting || !mounted) return;
    _exiting = true;
    _giftAnimationOverlay.clear();
    _heartbeatTimer?.cancel();
    await _leaveSessionOnce();
    _leaveSocketRoom();
    await _disconnectOpponentRoom();
    try {
      await _room?.disconnect();
    } catch (_) {}
    if (!mounted) return;
    _closeTransientSheets();
    Get.snackbar(
      'Moderation',
      message,
      snackPosition: SnackPosition.BOTTOM,
      duration: const Duration(seconds: 3),
    );
    Get.offAllNamed(Routes.home);
  }

  void _closeTransientSheets() {
    while ((Get.isBottomSheetOpen ?? false) || (Get.isDialogOpen ?? false)) {
      Get.back();
    }
  }

  List<Widget> _buildChatTrailingActions() {
    final tokens = _tokens;
    final busy = _seatActionBusy || _giftBusy;
    if (_isHost) {
      return <Widget>[
        KeyedSubtree(
          key: _giftAnchors.keyFor(GiftAnchorRegistry.giftButton),
          child: _ChatInputActionPill(
            icon: Icons.redeem_rounded,
            tokens: tokens,
            accent: const Color(0xFFFF8BC2),
            onTap: busy ? null : _openGiftSheet,
            iconOnly: true,
            tooltip: 'Gift',
          ),
        ),
        _ChatInputActionPill(
          icon:
              _mutedByHost
                  ? Icons.mic_off_rounded
                  : (_micOn ? Icons.mic_rounded : Icons.mic_off_rounded),
          tokens: tokens,
          accent: const Color(0xFF57E6B1),
          onTap: _mutedByHost || busy ? null : _toggleMic,
          iconOnly: true,
          tooltip: _mutedByHost ? 'Muted' : (_micOn ? 'Mic On' : 'Mic Off'),
        ),
        _ChatInputActionPill(
          icon: Icons.mark_chat_unread_rounded,
          tokens: tokens,
          accent: const Color(0xFF5D8BFF),
          onTap: _showRequestsSheet,
          iconOnly: true,
          tooltip:
              _pendingRequests.isEmpty
                  ? 'Requests'
                  : 'Requests (${_pendingRequests.length})',
        ),
      ];
    }

    final actions = <Widget>[
      KeyedSubtree(
        key: _giftAnchors.keyFor(GiftAnchorRegistry.giftButton),
        child: _ChatInputActionPill(
          icon: Icons.redeem_rounded,
          tokens: tokens,
          accent: const Color(0xFFFF8BC2),
          onTap: busy ? null : _openGiftSheet,
          iconOnly: true,
          tooltip: 'Gift',
        ),
      ),
    ];

    if (_isSpeaker) {
      actions.add(
        _ChatInputActionPill(
          icon: _mutedByHost
              ? Icons.mic_off_rounded
              : (_micOn ? Icons.mic_rounded : Icons.mic_off_rounded),
          tokens: tokens,
          accent: const Color(0xFF57E6B1),
          onTap: _mutedByHost || busy ? null : _toggleMic,
          iconOnly: true,
          tooltip: _mutedByHost ? 'Muted' : (_micOn ? 'Mic On' : 'Mic Off'),
        ),
      );
      return actions;
    }

    actions.add(
      _ChatInputActionPill(
        icon:
            _pkCapable && _pkActive
                ? Icons.lock_clock_rounded
                : (_requestStatus == 'pending'
                    ? Icons.hourglass_top_rounded
                    : Icons.record_voice_over_rounded),
        tokens: tokens,
        accent: const Color(0xFF5D8BFF),
        onTap:
            _pkCapable && _pkActive
                ? null
                : (busy
                    ? null
                    : (_requestStatus == 'pending'
                        ? _cancelMicRequest
                        : _requestMic)),
        label:
            _pkCapable && _pkActive
                ? 'Join Call Locked'
                : (_requestStatus == 'pending'
                    ? 'Join Call Pending'
                    : 'Join Call'),
        iconOnly: false,
        tooltip:
            _pkCapable && _pkActive
                ? 'Join Call Locked'
                : (_requestStatus == 'pending'
                    ? 'Join Call Pending'
                    : 'Join Call'),
      ),
    );
    return actions;
  }

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    final isCompactDevice =
        media.size.width < 360 || media.size.height < 760;
    final headerCount =
        _participantCount > 0
            ? _participantCount
            : _listenerCount + _speakerCount;
    final listeners = _listenerParticipants();
    final speakerEntries = _buildSpeakerEntries();
    final listenerEntries = _buildListenerEntries(listeners);

    return KeepAwakeScope(
      child: Obx(
        () => WillPopScope(
          onWillPop: () async {
            await _exitRoom();
            return false;
          },
          child: Scaffold(
            backgroundColor: _tokens.backgroundGradient.first,
            body: SafeArea(
              child: Stack(
                children: [
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [
                        _tokens.backgroundGradient.first,
                        _tokens.cardGradient.first,
                        _tokens.backgroundGradient.last,
                      ],
                    ),
                  ),
                ),
              ),
              Positioned(
                top: -60,
                right: -20,
                child: Container(
                  width: 180,
                  height: 180,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: _tokens.primaryButtonGradient.first.withOpacity(.14),
                  ),
                ),
              ),
              Positioned(
                left: -40,
                bottom: 120,
                child: Container(
                  width: 160,
                  height: 160,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: _tokens.glowColor.withOpacity(.12),
                  ),
                ),
              ),
              Positioned.fill(
                child: Padding(
                  padding: EdgeInsets.fromLTRB(
                    isCompactDevice ? 10 : 14,
                    isCompactDevice ? 8 : 10,
                    isCompactDevice ? 10 : 14,
                    0,
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _JoinedCountPill(
                        countText:
                            '$headerCount/${_maxParticipants > 0 ? _maxParticipants : 50}',
                        reconnecting: _reconnecting,
                        speakerCount: speakerEntries.length,
                        listenerCount: listenerEntries.length,
                        isHost: _isHost,
                        onOpenSpeakers: _showSpeakersSheet,
                        onOpenListeners: _showListenersSheet,
                        onLeave: _exitRoom,
                      ),
                      if (_isHost && _pkCapable)
                        Padding(
                          padding: EdgeInsets.only(
                            top: isCompactDevice ? 6 : 8,
                          ),
                          child: Wrap(
                            spacing: 8,
                            runSpacing: 8,
                            children: [
                              _SmallAction(
                                label: _pkActive ? 'End PK' : 'Start PK',
                                onTap:
                                    _pkActive
                                        ? _endPkBattle
                                        : _showPkInviteSheet,
                              ),
                              _SmallAction(
                                label: 'Requests ${_pendingRequests.length}',
                                onTap: _showRequestsSheet,
                              ),
                            ],
                          ),
                        ),
                      if (_requestStatus == 'pending' ||
                          _requestStatus == 'accepted' ||
                          _requestStatus == 'rejected' ||
                          _requestStatus == 'removed')
                        Padding(
                          padding: EdgeInsets.only(
                            top: isCompactDevice ? 6 : 8,
                          ),
                          child: _StateBanner(
                            status: _requestStatus!,
                            onDismiss: () => setState(() => _requestStatus = null),
                          ),
                        ),
                      if (_seatError != null || _giftError != null)
                        Padding(
                          padding: EdgeInsets.only(
                            top: isCompactDevice ? 6 : 8,
                          ),
                          child: _InlineErrorBanner(
                            message: _seatError ?? _giftError!,
                          ),
                        ),
                      if (_recentGiftMessage != null)
                        Padding(
                          padding: EdgeInsets.only(
                            top: isCompactDevice ? 6 : 8,
                          ),
                          child: _GiftBanner(
                            message: _recentGiftMessage!,
                            emoji: _recentGiftEmoji ?? '🎁',
                            pulse: _giftOverlayPulse,
                          ),
                        ),
                      if (_pkCapable && _pkActive)
                        Padding(
                          padding: EdgeInsets.only(top: isCompactDevice ? 6 : 8),
                          child: _buildPkAudioPanel(),
                        ),
                      SizedBox(height: isCompactDevice ? 6 : 10),
                      Expanded(
                        child:
                            _connecting
                                ? const _AudioRoomLoadingState()
                                : _error != null
                                ? _AudioRoomErrorState(
                                  message: _error!,
                                  onRetry: _connect,
                                )
                                : KeyedSubtree(
                                  key: _giftAnchors.keyFor(
                                    GiftAnchorRegistry.stageCenter,
                                  ),
                                  child: _AudioParticipantsStage(
                                    speakerEntries: speakerEntries,
                                    listenerEntries: listenerEntries,
                                    onOpenSpeakers: _showSpeakersSheet,
                                    onOpenListeners: _showListenersSheet,
                                  ),
                                ),
                      ),
                      const SizedBox.shrink(),
                    ],
                  ),
                ),
              ),
              Positioned(
                child: Obx(() {
                  final viewerThemeKey =
                      Get.find<AppSettingsService>().activePremiumThemeVariant;
                  return LiveRoomChatOverlay(
                    key: ValueKey('audio-room-chat-$viewerThemeKey'),
                    messagesListenable: _chatMessages,
                    viewerThemeKey: viewerThemeKey,
                    roomId: widget.room.roomId,
                    roomType: widget.room.roomType,
                    bottomOffset: isCompactDevice ? 12 : 18,
                    maxHeightFactor: isCompactDevice ? 0.30 : 0.40,
                    showEmptyPrompt: false,
                    trailingActions: _buildChatTrailingActions(),
                    showSendButton: false,
                    onSend: _sendChatMessage,
                    onMessageSenderTap: (message) {
                      if (message.isSystem || message.senderId <= 0) return;
                      _showParticipantProfileCard(
                        name: message.senderName,
                        subtitle: message.senderIsHost
                            ? 'Host'
                            : (message.senderIsVip ? 'VIP Participant' : 'Participant'),
                        themeKey: message.senderActiveThemeKey,
                        isVip: message.senderIsVip,
                        isHost: message.senderIsHost,
                        speaking: false,
                        userId: message.senderId,
                        level: message.senderLevel,
                        avatarUrl: message.senderAvatar,
                      );
                    },
                  );
                }),
              ),
              if (_ended) const Positioned.fill(child: _EndedOverlay()),
              Positioned.fill(
                child: EntryEffectOverlay(
                  roomId: widget.room.roomId,
                  initialEffect: widget.room.entryEffect,
                  events:
                      Get.isRegistered<RoomsSocketService>()
                          ? Get.find<RoomsSocketService>().entryEffectEvents
                          : null,
                ),
              ),
              Positioned.fill(
                child: GiftAnimationLayer(
                  manager: _giftAnimationOverlay,
                  anchors: _giftAnchors,
                  currentThemeKey:
                      Get.find<AppSettingsService>()
                          .activePremiumThemeVariant,
                  receiverAnchorName: GiftAnchorRegistry.audioHostAvatar,
                  stageCenterAnchorName: GiftAnchorRegistry.stageCenter,
                  pkLeftAnchorName:
                      _pkCapable && _pkActive ? GiftAnchorRegistry.pkLeft : null,
                  pkRightAnchorName:
                      _pkCapable && _pkActive
                          ? GiftAnchorRegistry.pkRight
                          : null,
                ),
              ),
              if (_pkCapable && _incomingPkInvite != null)
                Positioned(
                  left: 14,
                  right: 14,
                  bottom: 110,
                  child: _PkInvitePrompt(
                    battle: _incomingPkInvite!,
                    busy: _pkBusy,
                    onAccept: () => _respondToIncomingPk(true),
                    onReject: () => _respondToIncomingPk(false),
                  ),
                ),
              if (_pkCapable &&
                  _pkOverlayTitle != null &&
                  _pkOverlaySubtitle != null)
                PkWinnerOverlay(
                  title: _pkOverlayTitle!,
                  subtitle: _pkOverlaySubtitle!,
                ),
              ],
            ),
          ),
        ),
      ),
    ),
    );
  }

  List<Widget> _buildSpeakerCards() {
    final cards = <Widget>[
      _SpeakerCard(
        name: _isHost ? 'You' : (_hostName ?? 'Host'),
        subtitle: _sheetSubtitleWithUserId('Host', _hostUserId),
        highlighted: true,
        speaking:
            _room?.activeSpeakers.any(
              (p) =>
                  p.identity.startsWith('host-') ||
                  (_isHost && p is LocalParticipant),
            ) ??
            false,
        muted: _isHost ? (_mutedByHost || !_micOn) : false,
        themeKey: _hostThemeKey(),
        isVip: _hostIsVip(),
        isHost: true,
        userId: _hostUserId,
        avatarUrl: null,
        level: null,
        onProfileTap:
            () => _showParticipantProfileCard(
              name: _isHost ? 'You' : (_hostName ?? 'Host'),
              subtitle: 'Host',
              themeKey: _hostThemeKey(),
              isVip: _hostIsVip(),
              isHost: true,
              speaking:
                  _room?.activeSpeakers.any(
                    (p) =>
                        p.identity.startsWith('host-') ||
                        (_isHost && p is LocalParticipant),
                  ) ??
                  false,
              userId: _hostUserId,
            ),
      ),
    ];

    for (final speaker in _speakers) {
      final userId = (speaker['user_id'] as num?)?.toInt();
      final isMe = userId != null && userId == _myUserId;
      final mutedByHost = speaker['muted_by_host'] == true;
      final muted = _isSpeakerUserMuted(
        userId,
        speaker['name']?.toString(),
        mutedByHost: mutedByHost,
      );
      cards.add(
        _SpeakerCard(
          name: isMe ? 'You' : (speaker['name']?.toString() ?? 'Speaker'),
          subtitle: _sheetSubtitleWithUserId(
            isMe && mutedByHost ? 'Muted by host' : 'Speaker',
            userId,
          ),
          highlighted: isMe,
          speaking:
              !muted &&
              _isSpeakerUserSpeaking(userId, speaker['name']?.toString()),
          muted: muted,
          themeKey:
              isMe && _room?.localParticipant != null
                  ? _participantThemeKey(_room!.localParticipant!)
                  : _speakerThemeKey(speaker),
          isVip:
              isMe && _room?.localParticipant != null
                  ? _participantIsVip(_room!.localParticipant!)
                  : _speakerIsVip(speaker),
          isHost: false,
          userId: userId,
          avatarUrl:
              speaker['avatar_url']?.toString() ?? speaker['avatar']?.toString(),
          level: _joinSafeInt(speaker['level']),
          onProfileTap:
              () => _showParticipantProfileCard(
                name: isMe ? 'You' : (speaker['name']?.toString() ?? 'Speaker'),
                subtitle: isMe && mutedByHost ? 'Muted by host' : 'Speaker',
                themeKey:
                    isMe && _room?.localParticipant != null
                        ? _participantThemeKey(_room!.localParticipant!)
                        : _speakerThemeKey(speaker),
                isVip:
                    isMe && _room?.localParticipant != null
                        ? _participantIsVip(_room!.localParticipant!)
                        : _speakerIsVip(speaker),
                isHost: false,
                speaking:
                    !muted &&
                    _isSpeakerUserSpeaking(userId, speaker['name']?.toString()),
                userId: userId,
                level: _joinSafeInt(speaker['level']),
                avatarUrl:
                    speaker['avatar_url']?.toString() ??
                    speaker['avatar']?.toString(),
              ),
          onMute:
              _isHost && userId != null
                  ? (mutedByHost
                      ? () => _unmuteSpeaker(userId)
                      : () => _muteSpeaker(userId))
                  : null,
          muteActionLabel: mutedByHost ? 'Unmute' : 'Mute',
          onRemove:
              _isHost && userId != null ? () => _removeSpeaker(userId) : null,
        ),
      );
    }
    return cards;
  }
}

class _SpeakerGridEntry {
  const _SpeakerGridEntry({
    required this.key,
    required this.name,
    required this.roleLabel,
    required this.highlighted,
    required this.speaking,
    required this.muted,
    required this.themeKey,
    required this.isVip,
    required this.isHost,
    this.userId,
    this.avatarUrl,
    this.frameUrl,
    this.level,
    this.onProfileTap,
    this.onMute,
    this.muteActionLabel = 'Mute',
    this.onRemove,
  });

  final Key key;
  final String name;
  final String roleLabel;
  final bool highlighted;
  final bool speaking;
  final bool muted;
  final String themeKey;
  final bool isVip;
  final bool isHost;
  final int? userId;
  final String? avatarUrl;
  final String? frameUrl;
  final int? level;
  final VoidCallback? onProfileTap;
  final VoidCallback? onMute;
  final String muteActionLabel;
  final VoidCallback? onRemove;
}

class _ListenerGridEntry {
  const _ListenerGridEntry({
    required this.key,
    required this.label,
    required this.speaking,
    required this.isMe,
    required this.themeKey,
    required this.isVip,
    this.userId,
    this.avatarUrl,
    this.frameUrl,
    this.level,
    this.onProfileTap,
  });

  final Key key;
  final String label;
  final bool speaking;
  final bool isMe;
  final String themeKey;
  final bool isVip;
  final int? userId;
  final String? avatarUrl;
  final String? frameUrl;
  final int? level;
  final VoidCallback? onProfileTap;
}

class _StageGridItem {
  const _StageGridItem({
    required this.key,
    required this.name,
    required this.roleLabel,
    required this.highlighted,
    required this.speaking,
    required this.muted,
    required this.isSpeaker,
    required this.isHost,
    required this.isMe,
    required this.themeKey,
    required this.isVip,
    this.userId,
    this.avatarUrl,
    this.frameUrl,
    this.level,
    this.onProfileTap,
    this.onMute,
    this.muteActionLabel = 'Mute',
    this.onRemove,
  });

  final Key key;
  final String name;
  final String roleLabel;
  final bool highlighted;
  final bool speaking;
  final bool muted;
  final bool isSpeaker;
  final bool isHost;
  final bool isMe;
  final String themeKey;
  final bool isVip;
  final int? userId;
  final String? avatarUrl;
  final String? frameUrl;
  final int? level;
  final VoidCallback? onProfileTap;
  final VoidCallback? onMute;
  final String muteActionLabel;
  final VoidCallback? onRemove;
}

class _JoinedCountPill extends StatelessWidget {
  const _JoinedCountPill({
    required this.countText,
    required this.reconnecting,
    required this.speakerCount,
    required this.listenerCount,
    required this.isHost,
    required this.onOpenSpeakers,
    required this.onOpenListeners,
    required this.onLeave,
  });

  final String countText;
  final bool reconnecting;
  final int speakerCount;
  final int listenerCount;
  final bool isHost;
  final VoidCallback onOpenSpeakers;
  final VoidCallback onOpenListeners;
  final VoidCallback onLeave;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return LayoutBuilder(
      builder: (context, constraints) {
        final compact = constraints.maxWidth < 390;
        final ultraCompact = constraints.maxWidth < 350;
        final speakerLabel =
            ultraCompact
                ? '$speakerCount spk'
                : compact
                ? '$speakerCount speakers'
                : '$speakerCount speakers';
        final listenerLabel =
            ultraCompact
                ? '$listenerCount lst'
                : compact
                ? '$listenerCount listeners'
                : '$listenerCount listeners';

        return Row(
          children: [
            Container(
              padding: EdgeInsets.symmetric(
                horizontal: compact ? 10 : 12,
                vertical: compact ? 7 : 8,
              ),
              decoration: BoxDecoration(
                color: tokens.glassColor.withOpacity(.16),
                borderRadius: BorderRadius.circular(999),
                border: Border.all(color: tokens.borderColor.withOpacity(.32)),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withOpacity(.18),
                    blurRadius: 14,
                    offset: const Offset(0, 6),
                  ),
                ],
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    Icons.people_alt_rounded,
                    color: Colors.white,
                    size: compact ? 14 : 15,
                  ),
                  SizedBox(width: compact ? 5 : 6),
                  Text(
                    countText,
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: compact ? 12 : 13,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerRight,
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    _HeaderActionPill(
                      label: speakerLabel,
                      icon: Icons.mic_rounded,
                      tokens: tokens,
                      onTap: onOpenSpeakers,
                      compact: compact,
                    ),
                    const SizedBox(width: 8),
                    _HeaderActionPill(
                      label: listenerLabel,
                      icon: Icons.headphones_rounded,
                      tokens: tokens,
                      onTap: onOpenListeners,
                      compact: compact,
                    ),
                    if (reconnecting) ...[
                      const SizedBox(width: 8),
                      Container(
                        padding: EdgeInsets.symmetric(
                          horizontal: compact ? 8 : 10,
                          vertical: compact ? 6 : 7,
                        ),
                        decoration: BoxDecoration(
                          color: kTalkeeGold.withOpacity(.20),
                          borderRadius: BorderRadius.circular(999),
                          border: Border.all(
                            color: tokens.borderColor.withOpacity(.28),
                          ),
                        ),
                        child: Text(
                          ultraCompact ? 'Reconn' : 'Reconnecting',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: compact ? 10 : 11,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                      ),
                    ],
                    const SizedBox(width: 8),
                    _HeaderActionPill(
                      label: isHost ? 'End Room' : 'Leave',
                      icon:
                          isHost
                              ? Icons.stop_circle_outlined
                              : Icons.logout_rounded,
                      tokens: tokens,
                      onTap: onLeave,
                      danger: true,
                      compact: compact,
                    ),
                  ],
                ),
              ),
            ),
          ],
        );
      },
    );
  }
}

class _AudioParticipantsStage extends StatelessWidget {
  const _AudioParticipantsStage({
    required this.speakerEntries,
    required this.listenerEntries,
    required this.onOpenSpeakers,
    required this.onOpenListeners,
  });

  final List<_SpeakerGridEntry> speakerEntries;
  final List<_ListenerGridEntry> listenerEntries;
  final VoidCallback onOpenSpeakers;
  final VoidCallback onOpenListeners;

  @override
  Widget build(BuildContext context) {
    final items = <_StageGridItem>[
      ...speakerEntries.map(
        (entry) => _StageGridItem(
          key: entry.key,
          name: entry.name,
          roleLabel: entry.roleLabel,
          highlighted: entry.highlighted,
          speaking: entry.speaking,
          muted: entry.muted,
          isSpeaker: true,
          isHost: entry.isHost,
          isMe: entry.name == 'You',
          themeKey: entry.themeKey,
          isVip: entry.isVip,
          userId: entry.userId,
          avatarUrl: entry.avatarUrl,
          frameUrl: entry.frameUrl,
          level: entry.level,
          onProfileTap: entry.onProfileTap,
          onMute: entry.onMute,
          muteActionLabel: entry.muteActionLabel,
          onRemove: entry.onRemove,
        ),
      ),
    ];
    final dominantIndex = items.indexWhere((item) => item.isHost);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _UnifiedStageHeader(
          speakerCount: speakerEntries.length,
          listenerCount: listenerEntries.length,
          onOpenSpeakers: onOpenSpeakers,
          onOpenListeners: onOpenListeners,
        ),
        const SizedBox(height: 10),
        Expanded(
          child: _UnifiedParticipantsGrid(
            items: items,
            dominantIndex: dominantIndex,
          ),
        ),
      ],
    );
  }
}

class _UnifiedStageHeader extends StatelessWidget {
  const _UnifiedStageHeader({
    required this.speakerCount,
    required this.listenerCount,
    required this.onOpenSpeakers,
    required this.onOpenListeners,
  });

  final int speakerCount;
  final int listenerCount;
  final VoidCallback onOpenSpeakers;
  final VoidCallback onOpenListeners;

  @override
  Widget build(BuildContext context) {
    return const SizedBox.shrink();
  }
}

class _HeaderActionPill extends StatelessWidget {
  const _HeaderActionPill({
    required this.label,
    required this.icon,
    required this.tokens,
    required this.onTap,
    this.danger = false,
    this.compact = false,
  });

  final String label;
  final IconData icon;
  final PremiumThemeTokens tokens;
  final VoidCallback onTap;
  final bool danger;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final foreground =
        danger ? tokens.dangerColor.withOpacity(.96) : Colors.white;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(999),
      child: Container(
        padding: EdgeInsets.symmetric(
          horizontal: compact ? 9 : 11,
          vertical: compact ? 7 : 8,
        ),
        decoration: BoxDecoration(
          color:
              danger
                  ? tokens.dangerColor.withOpacity(.10)
                  : tokens.glassColor.withOpacity(.14),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(
            color:
                danger
                    ? tokens.dangerColor.withOpacity(.26)
                    : tokens.borderColor.withOpacity(.28),
          ),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(.12),
              blurRadius: 12,
              offset: const Offset(0, 5),
            ),
          ],
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: compact ? 13 : 14, color: foreground),
            SizedBox(width: compact ? 5 : 6),
            Text(
              label,
              style: TextStyle(
                color: foreground,
                fontSize: compact ? 11 : 12,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _MiniCountPill extends StatelessWidget {
  const _MiniCountPill({required this.label, this.onTap});

  final String label;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(999),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
          color: Colors.white.withOpacity(.08),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: Colors.white.withOpacity(.08)),
        ),
        child: Text(
          label,
          style: const TextStyle(
            color: Colors.white70,
            fontSize: 11,
            fontWeight: FontWeight.w800,
          ),
        ),
      ),
    );
  }
}

class _UnifiedParticipantsGrid extends StatelessWidget {
  const _UnifiedParticipantsGrid({
    required this.items,
    required this.dominantIndex,
  });

  final List<_StageGridItem> items;
  final int dominantIndex;

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) {
      return const _EmptyCard(label: 'No participants yet.');
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        const crossAxisCount = 3;
        const spacing = 10.0;
        final totalSpacing = spacing * (crossAxisCount - 1);
        final tileWidth = ((constraints.maxWidth - totalSpacing) / crossAxisCount)
            .clamp(96.0, 170.0);
        final tileHeight = (tileWidth * 1.18).clamp(122.0, 188.0);

        return GridView.builder(
          physics: const BouncingScrollPhysics(),
          itemCount: items.length,
          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: crossAxisCount,
            crossAxisSpacing: spacing,
            mainAxisSpacing: spacing,
            childAspectRatio: tileWidth / tileHeight,
          ),
          itemBuilder: (context, index) {
            final item = items[index];
            return _UnifiedParticipantTile(
              item: item,
              dominant: index == dominantIndex,
              height: tileHeight,
            );
          },
        );
      },
    );
  }
}

class _UnifiedParticipantTile extends StatelessWidget {
  const _UnifiedParticipantTile({
    required this.item,
    required this.dominant,
    required this.height,
  });

  final _StageGridItem item;
  final bool dominant;
  final double height;

  @override
  Widget build(BuildContext context) {
    final compact = height < 148;
    final avatarRadius =
        dominant
            ? (compact ? 20.0 : 24.0)
            : item.isSpeaker
            ? (compact ? 19.0 : 21.0)
            : (compact ? 17.0 : 18.0);

    return SizedBox(
      key: item.key,
      width: double.infinity,
      height: height,
      child: ThemedRoomFrame(
        themeKey: item.themeKey,
        isHost: item.isHost,
        isVip: item.isVip,
        isSpeaking: item.speaking,
        borderRadius: 20,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 240),
          curve: Curves.easeOutCubic,
          padding: EdgeInsets.all(compact ? 8 : 10),
          decoration: BoxDecoration(
            color: Colors.white.withOpacity(item.highlighted ? .11 : .06),
            borderRadius: BorderRadius.circular(20),
            gradient:
                item.isHost
                    ? const LinearGradient(
                      colors: [Color(0x223A2C6B), Color(0x111F355A)],
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    )
                    : null,
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Center(
                child: _SpeakingAvatar(
                  label: item.name,
                  radius: avatarRadius,
                  highlighted: item.highlighted,
                  speaking: item.speaking,
                  muted: item.muted,
                  themeKey: item.themeKey,
                  dominant: dominant,
                  avatarUrl: item.avatarUrl,
                  frameUrl: item.frameUrl,
                  onTap: item.onProfileTap,
                ),
              ),
              SizedBox(height: compact ? 5 : 7),
              Text(
                item.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: compact ? 11.5 : (dominant ? 13.0 : 12.0),
                ),
              ),
              SizedBox(height: compact ? 3 : 4),
              SizedBox(
                height: compact ? 20 : 24,
                child: Row(
                  children: [
                    Flexible(
                      child: _RoleBadge(
                        label: item.roleLabel,
                        highlighted: item.highlighted,
                        themeKey:
                            Get.find<AppSettingsService>().activePremiumThemeVariant,
                      ),
                    ),
                    if (item.isSpeaker) ...[
                      const SizedBox(width: 6),
                      _MicBadge(
                        muted: item.muted,
                        themeKey:
                            Get.find<AppSettingsService>().activePremiumThemeVariant,
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CompactStageHeader extends StatelessWidget {
  const _CompactStageHeader({
    required this.title,
    required this.count,
    required this.onTap,
  });

  final String title;
  final String count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Text(
          title,
          style: TextStyle(
            color: Colors.white.withOpacity(.92),
            fontSize: 15,
            fontWeight: FontWeight.w900,
            letterSpacing: .2,
          ),
        ),
        const SizedBox(width: 8),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
          decoration: BoxDecoration(
            color: Colors.white.withOpacity(.08),
            borderRadius: BorderRadius.circular(999),
          ),
          child: Text(
            count,
            style: const TextStyle(
              color: Colors.white70,
              fontSize: 11,
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
        const Spacer(),
        InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(999),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
            child: Text(
              'View all',
              style: TextStyle(
                color: Colors.white.withOpacity(.55),
                fontSize: 12,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _TopHeader extends StatelessWidget {
  const _TopHeader({
    required this.title,
    required this.topic,
    required this.hostLabel,
    required this.countText,
    required this.roleText,
    required this.language,
    required this.reconnecting,
    required this.pulse,
    required this.followerCount,
    required this.showFollow,
    required this.isFollowing,
    required this.onFollow,
    required this.onBack,
  });

  final String title;
  final String? topic;
  final String hostLabel;
  final String countText;
  final String roleText;
  final String? language;
  final bool reconnecting;
  final AnimationController pulse;
  final int followerCount;
  final bool showFollow;
  final bool isFollowing;
  final VoidCallback onFollow;
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    final normalizedRole =
        roleText.isEmpty
            ? 'Listener'
            : roleText[0].toUpperCase() + roleText.substring(1);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            IconButton(
              onPressed: onBack,
              style: IconButton.styleFrom(
                backgroundColor: Colors.white.withOpacity(.08),
                fixedSize: const Size(42, 42),
              ),
              icon: const Icon(Icons.arrow_back_rounded, color: Colors.white),
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
                      fontSize: 20,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    hostLabel,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: Colors.white.withOpacity(.68),
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
            ),
            if (showFollow)
              FilledButton.tonal(
                onPressed: onFollow,
                style: FilledButton.styleFrom(
                  backgroundColor:
                      isFollowing
                          ? Colors.white.withOpacity(.12)
                          : kTalkeePrimary.withOpacity(.24),
                  foregroundColor: Colors.white,
                  visualDensity: VisualDensity.compact,
                  padding: const EdgeInsets.symmetric(
                    horizontal: 14,
                    vertical: 10,
                  ),
                ),
                child: Text(isFollowing ? 'Following' : 'Follow'),
              ),
          ],
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            AnimatedBuilder(
              animation: pulse,
              builder:
                  (_, __) => _TopStatPill(
                    label: 'LIVE',
                    background: const Color(0xFFFF5B7E),
                    glowColor: const Color(
                      0xFFFF5B7E,
                    ).withOpacity(.18 + (pulse.value * .18)),
                  ),
            ),
            _TopStatPill(
              label: '$countText live',
              icon: Icons.people_alt_rounded,
            ),
            if (reconnecting)
              const _TopStatPill(
                label: 'Reconnecting',
                icon: Icons.wifi_protected_setup_rounded,
                background: Color(0x33F4B84A),
              ),
            if (topic != null && topic!.isNotEmpty) _TopStatPill(label: topic!),
            if (language != null && language!.isNotEmpty)
              _TopStatPill(label: language!),
            _TopStatPill(label: normalizedRole),
            if (followerCount > 0)
              _TopStatPill(label: '$followerCount followers'),
          ],
        ),
      ],
    );
  }
}

class _TopStatPill extends StatelessWidget {
  const _TopStatPill({
    required this.label,
    this.icon,
    this.background,
    this.glowColor,
  });

  final String label;
  final IconData? icon;
  final Color? background;
  final Color? glowColor;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 7),
      decoration: BoxDecoration(
        color: background ?? tokens.chipColor.withOpacity(.82),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor.withOpacity(.24)),
        boxShadow:
            glowColor == null
                ? null
                : [BoxShadow(color: glowColor!, blurRadius: 14)],
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, color: Colors.white70, size: 14),
            const SizedBox(width: 6),
          ],
          Text(
            label,
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w800,
              fontSize: 12,
            ),
          ),
        ],
      ),
    );
  }
}

class _HostSpotlightCard extends StatelessWidget {
  const _HostSpotlightCard({
    required this.title,
    required this.topic,
    required this.language,
    required this.hostName,
    required this.listenerCount,
    required this.speakerCount,
  });

  final String title;
  final String? topic;
  final String? language;
  final String hostName;
  final int listenerCount;
  final int speakerCount;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(28),
        gradient: LinearGradient(
          colors: [tokens.cardGradient.first, tokens.cardGradient.last],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(color: tokens.borderColor.withOpacity(.24)),
      ),
      child: Row(
        children: [
          Container(
            width: 76,
            height: 76,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: LinearGradient(
                colors: [tokens.dangerColor, tokens.primaryButtonGradient.last],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              border: Border.all(
                color: tokens.borderColor.withOpacity(.42),
                width: 2,
              ),
            ),
            child: Center(
              child: Text(
                hostName.isNotEmpty
                    ? hostName.characters.first.toUpperCase()
                    : 'H',
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w900,
                  fontSize: 28,
                ),
              ),
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'HOST',
                  style: TextStyle(
                    color: Colors.white54,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 1.1,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  hostName,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 22,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white.withOpacity(.72),
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    _InfoChip(label: '$listenerCount listeners'),
                    _InfoChip(label: '${speakerCount + 1} on stage'),
                    if (language != null && language!.isNotEmpty)
                      _InfoChip(label: language!),
                    if (topic != null && topic!.isNotEmpty)
                      _InfoChip(label: topic!),
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

class _SpeakerGridWidget extends StatelessWidget {
  const _SpeakerGridWidget({required this.entries});

  final List<_SpeakerGridEntry> entries;

  @override
  Widget build(BuildContext context) {
    if (entries.length == 1) {
      return Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 280),
          child: AnimatedSwitcher(
            duration: const Duration(milliseconds: 260),
            switchInCurve: Curves.easeOutCubic,
            switchOutCurve: Curves.easeInCubic,
            child: _SpeakerTileWidget(
              key: entries.first.key,
              entry: entries.first,
              compact: false,
            ),
          ),
        ),
      );
    }

    return LayoutBuilder(
      builder: (context, constraints) {
        final count = entries.length;
        final width = constraints.maxWidth;
        final crossAxisCount =
            width >= 430
                ? 4
                : count <= 2
                ? 2
                : 3;
        final spacing = width < 380 ? 8.0 : 10.0;
        final aspectRatio =
            crossAxisCount == 4
                ? .90
                : crossAxisCount == 3
                ? .90
                : 1.0;
        return AnimatedSwitcher(
          duration: const Duration(milliseconds: 260),
          switchInCurve: Curves.easeOutCubic,
          switchOutCurve: Curves.easeInCubic,
          child: GridView.builder(
            key: ValueKey('speakers-$count-$crossAxisCount'),
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: entries.length,
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: crossAxisCount,
              crossAxisSpacing: spacing,
              mainAxisSpacing: spacing,
              childAspectRatio: aspectRatio,
            ),
            itemBuilder:
                (context, index) => _SpeakerTileWidget(
                  key: entries[index].key,
                  entry: entries[index],
                  compact: crossAxisCount >= 3,
                ),
          ),
        );
      },
    );
  }
}

class _ListenerGridWidget extends StatelessWidget {
  const _ListenerGridWidget({required this.entries, required this.onOpen});

  final List<_ListenerGridEntry> entries;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    if (entries.isEmpty) {
      return _EmptyCard(
        label: 'No listeners yet. New listeners will appear here as they join.',
      );
    }

    return InkWell(
      onTap: onOpen,
      borderRadius: BorderRadius.circular(20),
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: tokens.glassColor.withOpacity(.08),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: tokens.borderColor.withOpacity(.22)),
        ),
        child: LayoutBuilder(
          builder: (context, constraints) {
            final width = constraints.maxWidth;
            final crossAxisCount =
                width < 340
                    ? 3
                    : width < 520
                    ? 4
                    : 5;
            return AnimatedSwitcher(
              duration: const Duration(milliseconds: 220),
              child: GridView.builder(
                key: ValueKey('listeners-${entries.length}-$crossAxisCount'),
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: entries.length,
                gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: crossAxisCount,
                  crossAxisSpacing: 10,
                  mainAxisSpacing: 10,
                  childAspectRatio: .9,
                ),
                itemBuilder:
                    (context, index) => _ListenerTileWidget(
                      key: entries[index].key,
                      entry: entries[index],
                    ),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _InfoChip extends StatelessWidget {
  const _InfoChip({required this.label});
  final String label;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: tokens.chipColor.withOpacity(.82),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: const TextStyle(
          color: Colors.white70,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

class _SpeakerTileWidget extends StatelessWidget {
  const _SpeakerTileWidget({
    super.key,
    required this.entry,
    required this.compact,
  });

  final _SpeakerGridEntry entry;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final surfaceTokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final avatarRadius = compact ? 20.0 : 24.0;
    return ThemedRoomFrame(
        themeKey: entry.themeKey,
        isHost: entry.isHost,
        isVip: entry.isVip,
        isSpeaking: entry.speaking,
        borderRadius: 18,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 220),
          padding: EdgeInsets.all(compact ? 8 : 9),
          decoration: BoxDecoration(
            color: surfaceTokens.glassColor.withOpacity(
              entry.highlighted ? .18 : .10,
            ),
            borderRadius: BorderRadius.circular(18),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: _SpeakingAvatar(
                  label: entry.name,
                  radius: avatarRadius,
                  highlighted: entry.highlighted,
                  speaking: entry.speaking,
                  muted: entry.muted,
                  themeKey: entry.themeKey,
                  avatarUrl: entry.avatarUrl,
                  frameUrl: entry.frameUrl,
                  onTap: entry.onProfileTap,
                ),
              ),
              SizedBox(height: compact ? 6 : 7),
              Text(
                entry.name,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: surfaceTokens.textPrimary,
                  fontWeight: FontWeight.w800,
                  fontSize: compact ? 11.5 : 12.5,
                ),
              ),
              const SizedBox(height: 2),
              Wrap(
                spacing: 4,
                runSpacing: 4,
                children: [
                  _RoleBadge(
                    label: entry.roleLabel,
                    highlighted: entry.highlighted,
                    themeKey:
                        Get.find<AppSettingsService>().activePremiumThemeVariant,
                  ),
                  _MicBadge(
                    muted: entry.muted,
                    themeKey:
                        Get.find<AppSettingsService>().activePremiumThemeVariant,
                  ),
                ],
              ),
              if (entry.onMute != null || entry.onRemove != null) ...[
                const Spacer(),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 4,
                  runSpacing: 4,
                  children: [
                    if (entry.onMute != null)
                      _SmallAction(
                        label: entry.muteActionLabel,
                        onTap: entry.onMute!,
                        tokens: surfaceTokens,
                        compact: true,
                      ),
                    if (entry.onRemove != null)
                      _SmallAction(
                        label: 'Remove',
                        onTap: entry.onRemove!,
                        tokens: surfaceTokens,
                        compact: true,
                      ),
                  ],
                ),
              ],
            ],
          ),
        ),
      );
  }
}

class _ListenerTileWidget extends StatelessWidget {
  const _ListenerTileWidget({super.key, required this.entry});

  final _ListenerGridEntry entry;

  @override
  Widget build(BuildContext context) {
    return ThemedRoomFrame(
        themeKey: entry.themeKey,
        isHost: false,
        isVip: entry.isVip,
        isSpeaking: entry.speaking,
        borderRadius: 18,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
          decoration: BoxDecoration(
            color: Colors.white.withOpacity(.08),
            borderRadius: BorderRadius.circular(18),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              _SpeakingAvatar(
                label: entry.label,
                radius: 22,
                highlighted: entry.isMe,
                speaking: entry.speaking,
                muted: false,
                themeKey: entry.themeKey,
                avatarUrl: entry.avatarUrl,
                frameUrl: entry.frameUrl,
                onTap: entry.onProfileTap,
              ),
              const SizedBox(height: 8),
              Text(
                entry.isMe ? 'You' : entry.label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  color: Colors.white70,
                  fontWeight: FontWeight.w700,
                  fontSize: 12,
                ),
              ),
            ],
          ),
        ),
      );
  }
}

class _SpeakingAvatar extends StatefulWidget {
  const _SpeakingAvatar({
    super.key,
    required this.label,
    required this.radius,
    required this.highlighted,
    required this.speaking,
    required this.muted,
    required this.themeKey,
    this.dominant = false,
    this.avatarUrl,
    this.frameUrl,
    this.onTap,
  });

  final String label;
  final double radius;
  final bool highlighted;
  final bool speaking;
  final bool muted;
  final String themeKey;
  final bool dominant;
  final String? avatarUrl;
  final String? frameUrl;
  final VoidCallback? onTap;

  @override
  State<_SpeakingAvatar> createState() => _SpeakingAvatarState();
}

class _SpeakingAvatarState extends State<_SpeakingAvatar>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(
      vsync: this,
      duration: Duration(milliseconds: widget.dominant ? 720 : 920),
    );
    _syncPulse();
  }

  @override
  void didUpdateWidget(covariant _SpeakingAvatar oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.dominant != widget.dominant) {
      _pulse.duration = Duration(milliseconds: widget.dominant ? 720 : 920);
    }
    if (oldWidget.speaking != widget.speaking ||
        oldWidget.dominant != widget.dominant) {
      _syncPulse();
    }
  }

  void _syncPulse() {
    if (widget.speaking) {
      _pulse.repeat(reverse: true);
    } else {
      _pulse.stop();
      _pulse.value = 0;
    }
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(widget.themeKey);
    final baseColor =
        widget.highlighted
            ? tokens.primaryButtonGradient.last
            : tokens.primaryButtonGradient.first;
    final speakingColor = tokens.successColor;
    return SizedBox(
      width: widget.radius * 2.5,
      height: widget.radius * 2.5,
      child: AnimatedBuilder(
        animation: _pulse,
        builder: (context, _) {
          final t = Curves.easeInOut.transform(_pulse.value);
          final primaryScale =
              widget.speaking
                  ? (widget.dominant ? 1.04 + (t * .16) : 1.02 + (t * .10))
                  : 1.0;
          final secondaryScale =
              widget.speaking
                  ? (widget.dominant ? 1.08 + (t * .18) : 1.04 + (t * .12))
                  : 1.0;
          final waveProgress = widget.speaking ? (.25 + (t * .75)) : 0.0;
          final glowOpacity =
              widget.speaking
                  ? (widget.dominant ? .18 + (t * .18) : .14 + (t * .12))
                  : 0.0;

          final avatar = SizedBox(
            width: widget.radius * 2,
            height: widget.radius * 2,
            child: FramedAvatar(
              avatarUrl: widget.avatarUrl,
              frameUrl: widget.frameUrl,
              label: widget.label,
              size: widget.radius * 2,
              backgroundColor: baseColor,
            ),
          );

          return Stack(
            alignment: Alignment.center,
            children: [
              AnimatedOpacity(
                duration: const Duration(milliseconds: 180),
                opacity: widget.speaking ? 1 : 0,
                child: Transform.scale(
                  scale: primaryScale,
                  child: Container(
                    width: widget.radius * (widget.dominant ? 2.34 : 2.18),
                    height: widget.radius * (widget.dominant ? 2.34 : 2.18),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      border: Border.all(
                        color: speakingColor.withOpacity(.88),
                        width: widget.dominant ? 2.4 : 2,
                      ),
                      boxShadow: [
                        BoxShadow(
                          color: speakingColor.withOpacity(glowOpacity),
                          blurRadius: widget.dominant ? 22 : 16,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              AnimatedOpacity(
                duration: const Duration(milliseconds: 180),
                opacity: widget.speaking ? .42 : 0,
                child: Transform.scale(
                  scale: secondaryScale,
                  child: Container(
                    width: widget.radius * (widget.dominant ? 2.7 : 2.48),
                    height: widget.radius * (widget.dominant ? 2.7 : 2.48),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      border: Border.all(
                        color: speakingColor.withOpacity(.44),
                        width: 1.4,
                      ),
                    ),
                  ),
                ),
              ),
              if (widget.speaking)
                Positioned.fill(
                  child: IgnorePointer(
                    child: CustomPaint(
                      painter: _VoiceWavePainter(
                        progress: waveProgress,
                        color: speakingColor,
                      ),
                    ),
                  ),
                ),
              InkWell(
                onTap: widget.onTap,
                borderRadius: BorderRadius.circular(999),
                child: avatar,
              ),
              if (widget.muted)
                Positioned(
                  right: widget.radius * .12,
                  bottom: widget.radius * .08,
                  child: Container(
                    width: widget.radius * .72,
                    height: widget.radius * .72,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: tokens.cardGradient.last.withOpacity(.96),
                      border: Border.all(color: tokens.borderColor.withOpacity(.32)),
                    ),
                    child: Icon(
                      Icons.mic_off_rounded,
                      size: widget.radius * .36,
                      color: tokens.dangerColor,
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

class _VoiceWavePainter extends CustomPainter {
  const _VoiceWavePainter({required this.progress, required this.color});

  final double progress;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final paint =
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.4
          ..color = color.withOpacity(.18 + (progress * .14));
    final center = Offset(size.width / 2, size.height / 2);
    final baseRadius = size.shortestSide * .34;

    for (var i = 0; i < 3; i++) {
      final radius = baseRadius + (i * 8) + (progress * 6);
      canvas.drawCircle(center, radius, paint);
    }
  }

  @override
  bool shouldRepaint(covariant _VoiceWavePainter oldDelegate) {
    return oldDelegate.progress != progress || oldDelegate.color != color;
  }
}

class _RoleBadge extends StatelessWidget {
  const _RoleBadge({
    required this.label,
    required this.highlighted,
    required this.themeKey,
  });

  final String label;
  final bool highlighted;
  final String themeKey;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(themeKey);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 4),
      decoration: BoxDecoration(
        color:
            highlighted
                ? tokens.primaryButtonGradient.last.withOpacity(.20)
                : tokens.glassColor.withOpacity(.12),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor.withOpacity(.18)),
      ),
      child: Text(
        label,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(
          color: tokens.textPrimary,
          fontWeight: FontWeight.w800,
          fontSize: 10,
        ),
      ),
    );
  }
}

class _MicBadge extends StatelessWidget {
  const _MicBadge({required this.muted, required this.themeKey});

  final bool muted;
  final String themeKey;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(themeKey);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 4),
      decoration: BoxDecoration(
        color:
            muted
                ? tokens.dangerColor.withOpacity(.18)
                : tokens.successColor.withOpacity(.18),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor.withOpacity(.18)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            muted ? Icons.mic_off_rounded : Icons.mic_rounded,
            size: 11,
            color: tokens.textPrimary,
          ),
          const SizedBox(width: 4),
          Text(
            muted ? 'Muted' : 'Live',
            style: TextStyle(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w800,
              fontSize: 10,
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({
    required this.title,
    required this.trailing,
    this.onTap,
  });
  final String title;
  final String trailing;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Text(
          title,
          style: const TextStyle(
            color: Colors.white,
            fontSize: 18,
            fontWeight: FontWeight.w800,
          ),
        ),
        const Spacer(),
        InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(999),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            child: Text(
              trailing,
              style: const TextStyle(
                color: Colors.white54,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _SpeakerCard extends StatelessWidget {
  const _SpeakerCard({
    required this.name,
    required this.subtitle,
    required this.highlighted,
    required this.speaking,
    required this.muted,
    required this.themeKey,
    required this.isVip,
    required this.isHost,
    this.userId,
    this.avatarUrl,
    this.frameUrl,
    this.level,
    this.onProfileTap,
    this.onMute,
    this.muteActionLabel = 'Mute',
    this.onRemove,
  });

  final String name;
  final String subtitle;
  final bool highlighted;
  final bool speaking;
  final bool muted;
  final String themeKey;
  final bool isVip;
  final bool isHost;
  final int? userId;
  final String? avatarUrl;
  final String? frameUrl;
  final int? level;
  final VoidCallback? onProfileTap;
  final VoidCallback? onMute;
  final String muteActionLabel;
  final VoidCallback? onRemove;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final frameTokens = getPremiumThemeTokens(themeKey);
    return ThemedRoomFrame(
      themeKey: themeKey,
      isHost: isHost,
      isVip: isVip,
      isSpeaking: speaking,
      borderRadius: 18,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              tokens.glassColor.withOpacity(highlighted ? .18 : .12),
              tokens.cardGradient.last.withOpacity(.10),
            ],
          ),
          borderRadius: BorderRadius.circular(18),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Stack(
              alignment: Alignment.center,
              children: [
                InkWell(
                  onTap: onProfileTap,
                  borderRadius: BorderRadius.circular(999),
                  child: Stack(
                    alignment: Alignment.center,
                    children: [
                      if (speaking)
                        Container(
                          width: 45,
                          height: 45,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            border: Border.all(
                              color: frameTokens.successColor.withOpacity(.34),
                              width: 1.8,
                            ),
                            boxShadow: [
                              BoxShadow(
                                color: frameTokens.successColor.withOpacity(.18),
                                blurRadius: 10,
                              ),
                            ],
                          ),
                        ),
                      SizedBox(
                        width: 40,
                        height: 40,
                        child: FramedAvatar(
                          avatarUrl: avatarUrl,
                          frameUrl: frameUrl,
                          label: name,
                          size: 40,
                          backgroundColor:
                              highlighted
                                  ? frameTokens.primaryButtonGradient.last
                                  : frameTokens.primaryButtonGradient.first,
                        ),
                      ),
                    ],
                  ),
                ),
                if (muted)
                  Positioned(
                    right: -1,
                    bottom: -1,
                    child: Container(
                      width: 15,
                      height: 15,
                      decoration: BoxDecoration(
                        color: tokens.cardGradient.last,
                        shape: BoxShape.circle,
                        border: Border.all(
                          color: tokens.borderColor.withOpacity(.32),
                        ),
                      ),
                      child: Icon(
                        Icons.mic_off_rounded,
                        size: 8,
                        color: tokens.dangerColor,
                      ),
                    ),
                  ),
              ],
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Row(
                    children: [
                      Flexible(
                        child: Text(
                          name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: tokens.textPrimary,
                            fontWeight: FontWeight.w800,
                            fontSize: 13,
                          ),
                        ),
                      ),
                      if (speaking) ...[
                        const SizedBox(width: 6),
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 6,
                            vertical: 2.5,
                          ),
                          decoration: BoxDecoration(
                            color: frameTokens.successColor.withOpacity(.14),
                            borderRadius: BorderRadius.circular(999),
                            border: Border.all(
                              color: frameTokens.successColor.withOpacity(.22),
                            ),
                          ),
                          child: Text(
                            'LIVE',
                            style: TextStyle(
                              color: frameTokens.successColor,
                              fontSize: 9.2,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                  const SizedBox(height: 3),
                  Text(
                    subtitle.toUpperCase(),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: tokens.textSecondary.withOpacity(.88),
                      fontSize: 10,
                      fontWeight: FontWeight.w700,
                      letterSpacing: .35,
                    ),
                  ),
                  if (onMute != null || onRemove != null) ...[
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 4,
                      runSpacing: 4,
                      children: [
                        if (onMute != null)
                          _SmallAction(
                            label: muteActionLabel,
                            onTap: onMute!,
                            tokens: tokens,
                            compact: true,
                          ),
                        if (onRemove != null)
                          _SmallAction(
                            label: 'Remove',
                            onTap: onRemove!,
                            tokens: tokens,
                            compact: true,
                          ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _SmallAction extends StatelessWidget {
  const _SmallAction({
    required this.label,
    required this.onTap,
    this.tokens,
    this.compact = false,
  });
  final String label;
  final VoidCallback onTap;
  final PremiumThemeTokens? tokens;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final resolvedTokens =
        tokens ??
        getPremiumThemeTokens(
          Get.find<AppSettingsService>().activePremiumThemeVariant,
        );
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(999),
      child: Container(
        padding: EdgeInsets.symmetric(
          horizontal: compact ? 8 : 10,
          vertical: compact ? 5 : 6,
        ),
        decoration: BoxDecoration(
          color: resolvedTokens.chipColor.withOpacity(.84),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: resolvedTokens.borderColor.withOpacity(.20)),
        ),
        child: Text(
          label,
          style: TextStyle(
            color: resolvedTokens.textPrimary,
            fontSize: compact ? 10.5 : 12,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }
}

class _RequestCard extends StatelessWidget {
  const _RequestCard({
    required this.request,
    required this.canModerate,
    required this.busy,
    required this.onAccept,
    required this.onReject,
  });

  final Map<String, dynamic> request;
  final bool canModerate;
  final bool busy;
  final VoidCallback onAccept;
  final VoidCallback onReject;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final user = request['user'] as Map<String, dynamic>?;
    final name = user?['name']?.toString() ?? 'Listener';
    final avatarUrl = user?['avatar_url']?.toString();
    final frameUrl = profileFrameAssetUrlFromPayload(user);
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: tokens.glassColor.withOpacity(.08),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: tokens.borderColor.withOpacity(.22)),
      ),
        child: Row(
        children: [
          SizedBox(
            width: 40,
            height: 40,
            child: FramedAvatar(
              avatarUrl: avatarUrl,
              frameUrl: frameUrl,
              label: name,
              size: 40,
              backgroundColor: tokens.primaryButtonGradient.first,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  name,
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 4),
                const Text(
                  'Requested microphone access',
                  style: TextStyle(color: Colors.white60),
                ),
              ],
            ),
          ),
          if (canModerate) ...[
            TextButton(
              onPressed: busy ? null : onReject,
              child: const Text('Reject'),
            ),
            FilledButton(
              onPressed: busy ? null : onAccept,
              child: const Text('Accept'),
            ),
          ],
        ],
      ),
    );
  }
}

class _EmptyCard extends StatelessWidget {
  const _EmptyCard({required this.label});
  final String label;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: tokens.glassColor.withOpacity(.08),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Text(label, style: const TextStyle(color: Colors.white60)),
    );
  }
}

class _StateBanner extends StatelessWidget {
  const _StateBanner({
    required this.status,
    this.onDismiss,
  });
  final String status;
  final VoidCallback? onDismiss;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final label = switch (status) {
      'pending' => 'Mic request pending',
      'accepted' => 'You are live',
      'rejected' => 'Mic request rejected',
      'removed' => 'You were moved back to listeners',
      _ => status,
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: tokens.chipColor.withOpacity(.84),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: tokens.borderColor.withOpacity(.22)),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.info_outline_rounded,
            color: Colors.white70,
            size: 18,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(label, style: const TextStyle(color: Colors.white)),
          ),
          Material(
            color: Colors.transparent,
            child: InkWell(
              onTap: onDismiss,
              borderRadius: BorderRadius.circular(999),
              child: const Padding(
                padding: EdgeInsets.all(4),
                child: Icon(
                  Icons.close_rounded,
                  color: Colors.white70,
                  size: 18,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _GiftBanner extends StatelessWidget {
  const _GiftBanner({
    required this.message,
    required this.emoji,
    required this.pulse,
  });
  final String message;
  final String emoji;
  final AnimationController pulse;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return AnimatedBuilder(
      animation: pulse,
      builder:
          (_, __) {
            final lift = (1 - pulse.value) * 2.0;
            return Transform.translate(
              offset: Offset(0, -lift),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      tokens.cardGradient.first.withOpacity(.96),
                      tokens.primaryButtonGradient.last.withOpacity(.34),
                      tokens.cardGradient.last.withOpacity(.94),
                    ],
                  ),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(
                    color: tokens.primaryButtonGradient.first.withOpacity(.34),
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: tokens.glowColor.withOpacity(.12 + (pulse.value * .16)),
                      blurRadius: 18 + (pulse.value * 10),
                      offset: const Offset(0, 10),
                    ),
                  ],
                ),
                child: Row(
                  children: [
                    Container(
                      width: 38,
                      height: 38,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: LinearGradient(
                          colors: [
                            tokens.primaryButtonGradient.first.withOpacity(.92),
                            tokens.primaryButtonGradient.last.withOpacity(.86),
                          ],
                        ),
                        boxShadow: [
                          BoxShadow(
                            color: tokens.glowColor.withOpacity(.24),
                            blurRadius: 14,
                            offset: const Offset(0, 6),
                          ),
                        ],
                      ),
                      alignment: Alignment.center,
                      child: Text(emoji, style: const TextStyle(fontSize: 17)),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            'LIVE GIFT',
                            style: TextStyle(
                              color: tokens.textSecondary,
                              fontWeight: FontWeight.w900,
                              fontSize: 10.5,
                              letterSpacing: 1.0,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            message,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              color: tokens.textPrimary,
                              fontWeight: FontWeight.w800,
                              fontSize: 13,
                              height: 1.15,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 8),
                    Icon(
                      Icons.auto_awesome_rounded,
                      color: tokens.primaryButtonGradient.first.withOpacity(.92),
                      size: 18,
                    ),
                  ],
                ),
              ),
            );
          },
    );
  }
}

class _PkAudioCardBody extends StatelessWidget {
  const _PkAudioCardBody({
    required this.label,
    required this.subtitle,
    required this.speaking,
  });

  final String label;
  final String subtitle;
  final bool speaking;

  @override
  Widget build(BuildContext context) {
    final themeKey = Get.find<AppSettingsService>().activePremiumThemeVariant;
    final tokens = getPremiumThemeTokens(themeKey);
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.cardGradient.first.withOpacity(.96),
            tokens.cardGradient.last.withOpacity(.92),
          ],
        ),
      ),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircleAvatar(
              radius: 28,
              backgroundColor:
                  speaking
                      ? tokens.primaryButtonGradient.last
                      : tokens.primaryButtonGradient.first,
              child: Text(
                label.isNotEmpty ? label[0].toUpperCase() : '?',
                style: const TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w900,
                  fontSize: 20,
                ),
              ),
            ),
            const SizedBox(height: 10),
            Text(
              label,
              textAlign: TextAlign.center,
              style: TextStyle(
                color: tokens.textPrimary,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              subtitle,
              textAlign: TextAlign.center,
              style: TextStyle(color: tokens.textSecondary.withOpacity(.88)),
            ),
            const SizedBox(height: 8),
            _MicBadge(muted: !speaking, themeKey: themeKey),
          ],
        ),
      ),
    );
  }
}

class _PkRoomPickerSheet extends StatelessWidget {
  const _PkRoomPickerSheet({
    required this.title,
    required this.rooms,
    required this.onSelect,
  });

  final String title;
  final List<Map<String, dynamic>> rooms;
  final ValueChanged<String> onSelect;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      top: false,
      child: Container(
        margin: const EdgeInsets.only(top: 40),
        padding: const EdgeInsets.fromLTRB(16, 18, 16, 20),
        decoration: const BoxDecoration(
          color: Color(0xFF151720),
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 22,
                fontWeight: FontWeight.w900,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              rooms.isEmpty
                  ? 'No live hosts available right now.'
                  : 'Select a live room to invite into PK.',
              style: TextStyle(
                color: Colors.white.withOpacity(.68),
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 16),
            if (rooms.isEmpty)
              const _ModalEmptyState(
                icon: Icons.sports_martial_arts_rounded,
                title: 'No live rooms found',
                body: 'Start PK when another host is live.',
              )
            else
              ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: rooms.length,
                separatorBuilder: (_, __) => const SizedBox(height: 10),
                itemBuilder: (_, i) {
                  final room = rooms[i];
                  return InkWell(
                    onTap: () => onSelect(room['room_id'].toString()),
                    borderRadius: BorderRadius.circular(18),
                    child: Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(.06),
                        borderRadius: BorderRadius.circular(18),
                        border: Border.all(
                          color: Colors.white.withOpacity(.08),
                        ),
                      ),
                      child: Row(
                        children: [
                          CircleAvatar(
                            backgroundColor: const Color(0xFF7B50C5),
                            child: Text(
                              room['title'].toString().isNotEmpty
                                  ? room['title'].toString()[0].toUpperCase()
                                  : 'R',
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  room['title'].toString(),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  '${room['room_type'].toString().toUpperCase()} • ${room['count']} listeners',
                                  style: TextStyle(
                                    color: Colors.white.withOpacity(.64),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const Icon(
                            Icons.chevron_right_rounded,
                            color: Colors.white70,
                          ),
                        ],
                      ),
                    ),
                  );
                },
              ),
          ],
        ),
      ),
    );
  }
}

class _PkInvitePrompt extends StatelessWidget {
  const _PkInvitePrompt({
    required this.battle,
    required this.busy,
    required this.onAccept,
    required this.onReject,
  });

  final LivePkBattleModel battle;
  final bool busy;
  final VoidCallback onAccept;
  final VoidCallback onReject;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final host = battle.hostA;
    final room = battle.roomA;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: tokens.cardGradient,
        ),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: tokens.borderColor),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Incoming PK Invite',
            style: TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
              fontSize: 18,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            '${host?['name'] ?? 'A host'} invited your room${room?['title'] != null ? ' from ${room!['title']}' : ''}.',
            style: TextStyle(
              color: Colors.white.withOpacity(.74),
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: busy ? null : onReject,
                  style: OutlinedButton.styleFrom(
                    foregroundColor: tokens.textPrimary,
                    side: BorderSide(color: tokens.borderColor),
                  ),
                  child: const Text('Reject'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: FilledButton(
                  onPressed: busy ? null : onAccept,
                  style: FilledButton.styleFrom(
                    backgroundColor: tokens.primaryButtonGradient.first,
                    foregroundColor: Colors.white,
                  ),
                  child: Text(busy ? 'Working...' : 'Accept'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _ChatInputActionPill extends StatelessWidget {
  const _ChatInputActionPill({
    required this.icon,
    required this.tokens,
    required this.accent,
    this.onTap,
    this.label = '',
    this.iconOnly = false,
    this.tooltip,
  });

  final IconData icon;
  final String label;
  final PremiumThemeTokens tokens;
  final Color accent;
  final VoidCallback? onTap;
  final bool iconOnly;
  final String? tooltip;

  @override
  Widget build(BuildContext context) {
    final enabled = onTap != null;
    const controlSize = 46.0;
    return Padding(
      padding: const EdgeInsets.only(left: 6),
      child: Tooltip(
        message: tooltip ?? label,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(999),
          child: AnimatedOpacity(
            duration: const Duration(milliseconds: 180),
            opacity: enabled ? 1 : .46,
            child: Container(
              width: iconOnly ? controlSize : null,
              height: iconOnly ? controlSize : null,
              padding:
                  iconOnly
                      ? EdgeInsets.zero
                      : const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              decoration: BoxDecoration(
                color: tokens.chipColor.withOpacity(.82),
                borderRadius: BorderRadius.circular(999),
                border: Border.all(color: tokens.borderColor.withOpacity(.26)),
                boxShadow: [
                  BoxShadow(
                    color: accent.withOpacity(.14),
                    blurRadius: 12,
                    offset: const Offset(0, 4),
                  ),
                ],
              ),
              child:
                  iconOnly
                      ? Icon(icon, size: 18, color: accent)
                      : Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(icon, size: 15, color: accent),
                          const SizedBox(width: 6),
                          Text(
                            label,
                            style: TextStyle(
                              color: tokens.textPrimary,
                              fontSize: 11.6,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                        ],
                      ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ControlDock extends StatelessWidget {
  const _ControlDock({
    required this.isHost,
    required this.isSpeaker,
    required this.isListener,
    required this.pkLocked,
    required this.micOn,
    required this.mutedByHost,
    required this.pending,
    required this.actionBusy,
    required this.requestCount,
    required this.onToggleMic,
    required this.onRequest,
    required this.onCancelRequest,
    required this.onGift,
    required this.onLeave,
    this.onOpenRequests,
  });

  final bool isHost;
  final bool isSpeaker;
  final bool isListener;
  final bool pkLocked;
  final bool micOn;
  final bool mutedByHost;
  final bool pending;
  final bool actionBusy;
  final int requestCount;
  final VoidCallback onToggleMic;
  final VoidCallback onRequest;
  final VoidCallback onCancelRequest;
  final VoidCallback onGift;
  final VoidCallback onLeave;
  final VoidCallback? onOpenRequests;

  @override
  Widget build(BuildContext context) {
    final actions =
        isHost
            ? <Widget>[
              if (onOpenRequests != null)
                _StripActionButton(
                  icon: Icons.mark_chat_unread_rounded,
                  label: 'Requests',
                  caption:
                      requestCount > 0 ? '$requestCount pending' : 'Open queue',
                  onTap: onOpenRequests!,
                  accent: const Color(0xFF5D8BFF),
                ),
              _StripActionButton(
                icon: micOn ? Icons.mic_rounded : Icons.mic_off_rounded,
                label: mutedByHost ? 'Muted' : (micOn ? 'Mute' : 'Unmute'),
                caption: mutedByHost ? 'Host locked' : 'Your mic',
                onTap: mutedByHost || actionBusy ? null : onToggleMic,
                accent: const Color(0xFF57E6B1),
              ),
              _StripActionButton(
                icon: Icons.stop_circle_outlined,
                label: 'End Room',
                caption: 'Close for all',
                onTap: onLeave,
                accent: const Color(0xFFFF7A7A),
                danger: true,
              ),
            ]
            : isSpeaker
            ? <Widget>[
              _StripActionButton(
                icon: micOn ? Icons.mic_rounded : Icons.mic_off_rounded,
                label: mutedByHost ? 'Muted' : (micOn ? 'Mute' : 'Unmute'),
                caption: mutedByHost ? 'Host locked' : 'Your mic',
                onTap: mutedByHost || actionBusy ? null : onToggleMic,
                accent: const Color(0xFF5D8BFF),
              ),
              _StripActionButton(
                icon: Icons.redeem_rounded,
                label: 'Gift',
                caption: 'Send support',
                onTap: actionBusy ? null : onGift,
                accent: const Color(0xFFFF8BC2),
              ),
              _StripActionButton(
                icon: Icons.logout_rounded,
                label: 'Leave',
                caption: 'Exit safely',
                onTap: onLeave,
                accent: const Color(0xFFFF7A7A),
                danger: true,
              ),
            ]
            : <Widget>[
              _StripActionButton(
                icon:
                    pkLocked
                        ? Icons.lock_clock_rounded
                        : (pending
                            ? Icons.hourglass_top_rounded
                            : Icons.record_voice_over_rounded),
                label:
                    pkLocked
                        ? 'Join Call Locked'
                        : (pending ? 'Join Call Pending' : 'Join Call'),
                caption:
                    pkLocked
                        ? 'Requests paused'
                        : (pending
                            ? 'Tap to cancel request'
                            : 'Request host approval'),
                onTap:
                    pkLocked
                        ? null
                        : (actionBusy
                            ? null
                            : (pending ? onCancelRequest : onRequest)),
                accent: const Color(0xFF5D8BFF),
              ),
              _StripActionButton(
                icon: Icons.redeem_rounded,
                label: 'Gift',
                caption: 'Send support',
                onTap: actionBusy ? null : onGift,
                accent: const Color(0xFFFF8BC2),
              ),
              _StripActionButton(
                icon: Icons.logout_rounded,
                label: 'Leave',
                caption: 'Exit safely',
                onTap: onLeave,
                accent: const Color(0xFFFF7A7A),
                danger: true,
              ),
            ];

    return Container(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 14),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [const Color(0xE6161B28), const Color(0xD10F1320)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: Colors.white.withOpacity(.10)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(.24),
            blurRadius: 22,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 44,
            height: 4,
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(.18),
              borderRadius: BorderRadius.circular(999),
            ),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              for (var i = 0; i < actions.length; i++) ...[
                Expanded(child: actions[i]),
                if (i != actions.length - 1) const SizedBox(width: 10),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

class _StripActionButton extends StatelessWidget {
  const _StripActionButton({
    required this.icon,
    required this.label,
    required this.caption,
    required this.accent,
    this.danger = false,
    this.onTap,
  });

  final IconData icon;
  final String label;
  final String caption;
  final Color accent;
  final VoidCallback? onTap;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(22),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors:
                danger
                    ? [const Color(0xFF61232C), const Color(0xFF421921)]
                    : [accent.withOpacity(.22), Colors.white.withOpacity(.05)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(22),
          border: Border.all(
            color:
                onTap == null
                    ? Colors.white.withOpacity(.06)
                    : accent.withOpacity(danger ? .22 : .28),
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 34,
              height: 34,
              decoration: BoxDecoration(
                color: Colors.white.withOpacity(.10),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, color: Colors.white, size: 18),
            ),
            const SizedBox(height: 10),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: onTap == null ? Colors.white54 : Colors.white,
                fontWeight: FontWeight.w800,
                fontSize: 13,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              caption,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: onTap == null ? Colors.white38 : Colors.white60,
                fontSize: 11,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _AudioRoomLoadingState extends StatelessWidget {
  const _AudioRoomLoadingState();

  @override
  Widget build(BuildContext context) {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(color: kTalkeePrimary),
            SizedBox(height: 16),
            Text(
              'Joining audio room…',
              style: TextStyle(
                color: Colors.white70,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _AudioRoomErrorState extends StatelessWidget {
  const _AudioRoomErrorState({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(22),
        child: Container(
          constraints: const BoxConstraints(maxWidth: 420),
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: Colors.white.withOpacity(.07),
            borderRadius: BorderRadius.circular(24),
            border: Border.all(color: Colors.white.withOpacity(.10)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(
                Icons.portable_wifi_off_rounded,
                size: 36,
                color: Colors.white,
              ),
              const SizedBox(height: 12),
              const Text(
                'Unable to join this room',
                style: TextStyle(
                  color: Colors.white,
                  fontSize: 18,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                message,
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.white.withOpacity(.72)),
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
    );
  }
}

class _InlineErrorBanner extends StatelessWidget {
  const _InlineErrorBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: tokens.dangerColor.withOpacity(.16),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: tokens.dangerColor.withOpacity(.28)),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.error_outline_rounded,
            color: Colors.white70,
            size: 18,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(message, style: const TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
  }
}

class _ParticipantProfileCardSheet extends StatelessWidget {
  const _ParticipantProfileCardSheet({
    required this.name,
    required this.subtitle,
    required this.themeKey,
    required this.isVip,
    required this.isHost,
    required this.speaking,
    this.userId,
    this.level,
    this.avatarUrl,
  });

  final String name;
  final String subtitle;
  final String themeKey;
  final bool isVip;
  final bool isHost;
  final bool speaking;
  final int? userId;
  final int? level;
  final String? avatarUrl;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final frameTokens = getPremiumThemeTokens(themeKey);
    final chips = <Widget>[
      if (speaking)
        _ProfileMetaChip(
          label: 'Speaking',
          color: frameTokens.successColor,
          textColor: tokens.textPrimary,
        ),
      if (isHost)
        _ProfileMetaChip(
          label: 'Host',
          color: frameTokens.primaryButtonGradient.last,
          textColor: Colors.white,
        ),
      if (isVip)
        _ProfileMetaChip(
          label: 'VIP',
          color: frameTokens.primaryButtonGradient.first,
          textColor: Colors.white,
        ),
      if (level != null)
        _ProfileMetaChip(
          label: 'LV $level',
          color: tokens.glassColor.withOpacity(.24),
          textColor: tokens.textPrimary,
        ),
      if (userId != null)
        _ProfileMetaChip(
          label: 'ID $userId',
          color: tokens.glassColor.withOpacity(.18),
          textColor: tokens.textSecondary,
        ),
    ];

    return _AudioSheet(
      title: name,
      subtitle: subtitle,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  tokens.glassColor.withOpacity(.18),
                  tokens.cardGradient.last.withOpacity(.10),
                ],
              ),
              borderRadius: BorderRadius.circular(24),
              border: Border.all(color: tokens.borderColor.withOpacity(.22)),
            ),
            child: Row(
              children: [
                ThemedRoomFrame(
                  themeKey: themeKey,
                  isHost: isHost,
                  isVip: isVip,
                  isSpeaking: speaking,
                  borderRadius: 24,
                  size: 74,
                  child: Container(
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(24),
                      gradient: LinearGradient(
                        colors: [
                          frameTokens.primaryButtonGradient.first.withOpacity(.94),
                          frameTokens.primaryButtonGradient.last.withOpacity(.94),
                        ],
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                      ),
                    ),
                    child: _ProfileAvatarFace(
                      label: name,
                      avatarUrl: avatarUrl,
                      textColor: frameTokens.textPrimary,
                    ),
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        name,
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
                        subtitle,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: tokens.textSecondary.withOpacity(.9),
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          letterSpacing: .24,
                        ),
                      ),
                      const SizedBox(height: 10),
                      Wrap(spacing: 6, runSpacing: 6, children: chips),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(
              color: tokens.glassColor.withOpacity(.12),
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: tokens.borderColor.withOpacity(.18)),
            ),
            child: Row(
              children: [
                Icon(
                  Icons.auto_awesome_rounded,
                  color: frameTokens.glowColor.withOpacity(.92),
                  size: 16,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    isHost
                        ? 'Host profile preview from the live room.'
                        : 'Participant profile preview from the live room.',
                    style: TextStyle(
                      color: tokens.textSecondary.withOpacity(.9),
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
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
        borderRadius: BorderRadius.circular(24),
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
                    fontSize: 26,
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
          fontSize: 26,
        ),
      ),
    );
  }
}

class _ProfileMetaChip extends StatelessWidget {
  const _ProfileMetaChip({
    required this.label,
    required this.color,
    required this.textColor,
  });

  final String label;
  final Color color;
  final Color textColor;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: textColor,
          fontSize: 10.8,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}

class _AudienceTile extends StatelessWidget {
  const _AudienceTile({
    required this.label,
    required this.subtitle,
    required this.speaking,
    this.avatarUrl,
    this.frameUrl,
    this.onProfileTap,
  });

  final String label;
  final String subtitle;
  final bool speaking;
  final String? avatarUrl;
  final String? frameUrl;
  final VoidCallback? onProfileTap;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.glassColor.withOpacity(.14),
            tokens.cardGradient.last.withOpacity(.10),
          ],
        ),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: tokens.borderColor.withOpacity(.20)),
      ),
      child: Row(
        children: [
          InkWell(
            onTap: onProfileTap,
            borderRadius: BorderRadius.circular(999),
            child: SizedBox(
              width: 36,
              height: 36,
              child: FramedAvatar(
                avatarUrl: avatarUrl,
                frameUrl: frameUrl,
                label: label,
                size: 36,
                backgroundColor: tokens.primaryButtonGradient.first.withOpacity(.92),
              ),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  label,
                  style: TextStyle(
                    color: tokens.textPrimary,
                    fontWeight: FontWeight.w800,
                    fontSize: 13,
                  ),
                ),
                const SizedBox(height: 1),
                Text(
                  subtitle,
                  style: TextStyle(
                    color: tokens.textSecondary.withOpacity(.88),
                    fontSize: 11.2,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
          if (speaking)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 4),
              decoration: BoxDecoration(
                color: tokens.successColor.withOpacity(.14),
                borderRadius: BorderRadius.circular(999),
                border: Border.all(color: tokens.successColor.withOpacity(.22)),
              ),
              child: Icon(
                Icons.multitrack_audio_rounded,
                color: tokens.successColor,
                size: 14,
              ),
            ),
        ],
      ),
    );
  }
}

class _HostActionStrip extends StatelessWidget {
  const _HostActionStrip({
    required this.pendingCount,
    required this.onOpenRequests,
    required this.onOpenSpeakers,
    required this.onOpenListeners,
  });

  final int pendingCount;
  final VoidCallback onOpenRequests;
  final VoidCallback onOpenSpeakers;
  final VoidCallback onOpenListeners;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 10,
      runSpacing: 10,
      children: [
        _SmallAction(
          label: pendingCount > 0 ? 'Requests $pendingCount' : 'Requests',
          onTap: onOpenRequests,
        ),
        _SmallAction(label: 'Speakers', onTap: onOpenSpeakers),
        _SmallAction(label: 'Listeners', onTap: onOpenListeners),
      ],
    );
  }
}

class _AudioSheet extends StatelessWidget {
  const _AudioSheet({
    required this.title,
    required this.subtitle,
    required this.child,
  });

  final String title;
  final String subtitle;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return SafeArea(
      top: false,
      child: Container(
        margin: const EdgeInsets.only(top: 40),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [
              tokens.cardGradient.first.withOpacity(.98),
              tokens.cardGradient.last.withOpacity(.96),
              tokens.backgroundGradient.last.withOpacity(.94),
            ],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: const BorderRadius.vertical(top: Radius.circular(32)),
          border: Border.all(color: tokens.borderColor.withOpacity(.22)),
          boxShadow: [
            BoxShadow(
              color: tokens.glowColor.withOpacity(.14),
              blurRadius: 28,
              offset: const Offset(0, -10),
            ),
          ],
        ),
        child: Stack(
          children: [
            Positioned(
              top: -20,
              right: -12,
              child: Container(
                width: 112,
                height: 112,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: tokens.glowColor.withOpacity(.11),
                ),
              ),
            ),
            Positioned(
              top: 44,
              left: -18,
              child: Container(
                width: 80,
                height: 80,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: tokens.primaryButtonGradient.last.withOpacity(.08),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 20),
              child: SingleChildScrollView(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Center(
                      child: Container(
                        width: 46,
                        height: 5,
                        decoration: BoxDecoration(
                          color: tokens.borderColor.withOpacity(.42),
                          borderRadius: BorderRadius.circular(999),
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),
                    Text(
                      title,
                      style: TextStyle(
                        color: tokens.textPrimary,
                        fontSize: 24,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      subtitle,
                      style: TextStyle(
                        color: tokens.textSecondary.withOpacity(.88),
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 16),
                    child,
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ModalEmptyState extends StatelessWidget {
  const _ModalEmptyState({
    required this.icon,
    required this.title,
    required this.body,
  });

  final IconData icon;
  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [
            Colors.white.withOpacity(.07),
            Colors.white.withOpacity(.03),
          ],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: Colors.white.withOpacity(.08)),
      ),
      child: Column(
        children: [
          Container(
            width: 54,
            height: 54,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: Colors.white.withOpacity(.08),
            ),
            child: Icon(icon, color: Colors.white, size: 28),
          ),
          const SizedBox(height: 10),
          Text(
            title,
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            body,
            textAlign: TextAlign.center,
            style: TextStyle(color: Colors.white.withOpacity(.66)),
          ),
        ],
      ),
    );
  }
}

class _EndedOverlay extends StatelessWidget {
  const _EndedOverlay();

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: const Color(0xD80A0D14),
      child: Center(
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
          decoration: BoxDecoration(
            color: Colors.white.withOpacity(.08),
            borderRadius: BorderRadius.circular(24),
            border: Border.all(color: Colors.white.withOpacity(.10)),
          ),
          child: const Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.stop_circle_outlined, color: Colors.white, size: 34),
              SizedBox(height: 12),
              Text(
                'Room ended',
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w900,
                  fontSize: 20,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
