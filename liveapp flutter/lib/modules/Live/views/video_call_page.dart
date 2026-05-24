// Host-only video page (full-screen layout, zero-lag reactions)
// Layout:
//  - TopCenter: Live HUD
//  - TopLeft:   Back
//  - TopRight:  Remote strip (scroll)
//  - LeftCenter:  Reaction RAIL (expandable, vertical, scrollable)
//  - RightCenter: Tool RAIL (Mic/Cam/Flip) with slide-in animations
//  - BottomLeft:  End button
//  - BottomCenter: Lower-third banner (when visible)
// Rendering:
//  - Emoji bursts: pre-baked ui.Image cache; no saveLayer; capped pool

import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:livekit_client/livekit_client.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart'
    show RTCVideoRenderer, RTCVideoView, RTCVideoViewObjectFit;
import 'package:flutter_webrtc/flutter_webrtc.dart' as webrtc;

import '../../banners/models/banner_item.dart';
import '../../banners/services/banner_service.dart';
import '../../../app/theme/brand.dart';
import '../../../app/routes/app_routes.dart';
import '../../../app/utils/profile_frame_payload.dart';
import '../../../app/widgets/haptics.dart';
import '../../../app/widgets/framed_avatar.dart';
import '../../../app/widgets/keep_awake_scope.dart';
import '../../../services/app_settings_service.dart';
import '../../../services/auth_service.dart';
import '../../../services/live_rooms_ws_service.dart';
import '../../profile/widgets/public_profile_card_sheet.dart';
import '../../wallet/services/wallet_api.dart';
import '../../wallet/widgets/recharge_bottom_sheet.dart';
import '../dev/live_room_dev_fixtures.dart';
import '../models/live_gift_item.dart';
import '../models/live_pk_battle_model.dart';
import '../models/live_room_chat_message.dart';
import '../models/live_room_model.dart';
import '../services/live_service.dart';
import '../widgets/entry_effect_overlay.dart';
import '../widgets/gift_animation_overlay_manager.dart';
import '../widgets/live_room_chat_overlay.dart';
import '../widgets/live_room_gift_sheet.dart';
import '../widgets/pk_battle_overlay.dart';
import '../widgets/room_join_animation_overlay_manager.dart';
import '../widgets/themed_room_frame.dart';

class VideoCallPage extends StatefulWidget {
  final LiveRoomModel room; // requires: wsUrl, token, roomId
  final LiveService live;
  final bool initialMicOn;
  final bool initialCamOn;
  final bool viewerOnly;
  final bool devMode;
  const VideoCallPage({
    super.key,
    required this.room,
    required this.live,
    this.initialMicOn = false,
    this.initialCamOn = true,
    this.viewerOnly = false,
    this.devMode = false,
  });

  @override
  State<VideoCallPage> createState() => _VideoCallPageState();
}

class _VideoCallPageState extends State<VideoCallPage>
    with SingleTickerProviderStateMixin {
  Room? _room;
  EventsListener<RoomEvent>? _listener;

  final RTCVideoRenderer _renderer = RTCVideoRenderer();
  bool _rendererReady = false;
  webrtc.MediaStream? _previewStream;
  LocalVideoTrack? _boundTrack;

  bool _connecting = false;
  String? _error;
  bool _micOn = false;
  bool _camOn = true;
  bool _frontFacing = true;

  bool _camBusy = false, _flipBusy = false;

  DateTime? _liveStart;
  Timer? _hudTimer;
  Timer? _heartbeatTimer;
  String _timerText = 'LIVE • 00:00';

  bool _localSpeaking = false;
  late final AnimationController _glow = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1300),
  )..repeat(reverse: true);

  Timer? _noFrameWatchdog;
  bool _cameraRestartedOnce = false;
  bool _leaveSent = false;
  bool _endSent = false;
  bool _roomSocketJoined = false;
  bool _exiting = false;
  StreamSubscription<Map<String, dynamic>>? _seatEventsSub;
  StreamSubscription<Map<String, dynamic>>? _giftEventsSub;
  StreamSubscription<Map<String, dynamic>>? _roomLifecycleSub;
  StreamSubscription<Map<String, dynamic>>? _pkEventsSub;
  StreamSubscription<Map<String, dynamic>>? _socketConnectionSub;
  StreamSubscription<Map<String, dynamic>>? _chatEventsSub;
  StreamSubscription<Map<String, dynamic>>? _chatErrorsSub;
  StreamSubscription<Map<String, dynamic>>? _moderationEventsSub;
  StreamSubscription<Map<String, dynamic>>? _moderationErrorsSub;
  StreamSubscription<Map<String, dynamic>>? _moderationSystemMessagesSub;
  StreamSubscription<Map<String, dynamic>>? _profileEventsSub;
  StreamSubscription<Map<String, dynamic>>? _joinEventsSub;
  DateTime? _lastSeatEventAt;
  String _currentRole = 'viewer';
  int? _myUserId;
  bool _seatActionBusy = false;
  String? _seatError;
  int? _pendingRequestId;
  String? _requestStatus;
  List<Map<String, dynamic>> _pendingRequests = const [];
  List<Map<String, dynamic>> _speakers = const [];
  int _speakerCount = 0;
  int _maxSpeakers = 4;
  bool _giftBusy = false;
  String? _giftError;
  List<LiveGiftItem> _availableGifts = const [];
  int? _walletBalanceCoins;
  bool _speakerTransitionBusy = false;
  String? _recentGiftMessage;
  Timer? _recentGiftTimer;
  Room? _opponentRoom;
  EventsListener<RoomEvent>? _opponentListener;
  LivePkBattleModel? _pkBattle;
  LivePkBattleModel? _incomingPkInvite;
  bool _pkBusy = false;
  bool _opponentConnecting = false;
  bool _opponentMediaUnavailable = false;
  String? _pkOverlayTitle;
  String? _pkOverlaySubtitle;
  int _pkOverlayWinnerSide = 0;
  String? _pkOverlayWinnerName;
  String? _pkOverlayWinnerAvatarUrl;
  List<PkWinnerSupporter> _pkOverlayTopSupporters = const <PkWinnerSupporter>[];
  Timer? _pkOverlayTimer;
  Timer? _devPkTransitionTimer;
  Timer? _pkExpiryWatcher;
  bool _pkExpiryActionInFlight = false;
  String? _pkGiftLeadersBattleId;
  Map<String, Map<int, _PkSupporterStanding>> _pkGiftLeadersBySide =
      const <String, Map<int, _PkSupporterStanding>>{
        'left': <int, _PkSupporterStanding>{},
        'right': <int, _PkSupporterStanding>{},
      };
  final RoomJoinAnimationOverlayManager _joinAnimationOverlay =
      RoomJoinAnimationOverlayManager();
  final GiftAnchorRegistry _giftAnchors = GiftAnchorRegistry();
  final GiftAnimationOverlayManager _giftAnimationOverlay =
      GiftAnimationOverlayManager();
  Set<String> _trackedParticipantIds = <String>{};
  bool _joinAnimationsArmed = false;
  final ValueNotifier<List<LiveRoomChatMessage>> _chatMessages =
      ValueNotifier<List<LiveRoomChatMessage>>(const <LiveRoomChatMessage>[]);
  Worker? _themeSyncWorker;
  bool _handlingBackNavigation = false;

  final _emojiKey = GlobalKey<_EmojiBurstState>();

  PremiumThemeTokens get _tokens => getPremiumThemeTokens(
    Get.find<AppSettingsService>().activePremiumThemeVariant,
  );
  @override
  void initState() {
    super.initState();
    _currentRole =
        (widget.room.role ?? (widget.viewerOnly ? 'viewer' : 'host'))
            .toLowerCase();
    _myUserId = Get.find<AuthService>().currentUser?.id;
    _micOn = widget.initialMicOn;
    _camOn = widget.initialCamOn;
    _themeSyncWorker = ever<AppSettingsPayload?>(
      Get.find<AppSettingsService>().payload,
      (_) => _syncOwnChatTheme(),
    );
    _bootstrap();
  }

  Map<String, dynamic> _buildDevPkBattlePayload({
    required int durationSeconds,
  }) {
    final now = DateTime.now();
    final endsAt = now.add(Duration(seconds: durationSeconds));
    return <String, dynamic>{
      'battle_id': 'dev-pk-${now.millisecondsSinceEpoch}',
      'status': 'active',
      'duration_seconds': durationSeconds,
      'score_a': 12400,
      'score_b': 9800,
      'started_at': now.toIso8601String(),
      'ends_at': endsAt.toIso8601String(),
      'updated_at': now.toIso8601String(),
      'winner_room_id': null,
      'room_a': <String, dynamic>{
        'id': widget.room.roomId,
        'name': 'Team Aman',
      },
      'room_b': <String, dynamic>{
        'id': 'dev-video-pk-room-b',
        'name': 'Team Zoya',
      },
      'host_a': <String, dynamic>{
        'user_id': 501,
        'name': 'Host Aman',
        'active_theme_key': 'gold_black',
        'is_vip': true,
      },
      'host_b': <String, dynamic>{
        'user_id': 502,
        'name': 'Zoya',
        'active_theme_key': 'aurora',
        'is_vip': true,
      },
    };
  }

  void _mockDevEnterPkBattle() {
    final battle = LivePkBattleModel.fromJson(
      _buildDevPkBattlePayload(durationSeconds: 30),
    );
    setState(() {
      _pkBattle = battle;
      _incomingPkInvite = null;
      _opponentConnecting = false;
      _opponentMediaUnavailable = true;
      _pkOverlayTitle = 'PK Battle Started';
      _pkOverlaySubtitle = 'Host stage switched into a 30 second PK preview.';
      _pkOverlayWinnerSide = 0;
      _pkOverlayWinnerName = null;
      _pkOverlayWinnerAvatarUrl = null;
      _pkOverlayTopSupporters = const <PkWinnerSupporter>[];
      _primePkGiftLeadersForBattle(battle);
      _seedDevPkSupporters();
    });
    _appendChatMessage(
      LiveRoomChatMessage.system(
        roomId: widget.room.roomId,
        roomType: widget.room.roomType,
        message: 'PK battle started for 30 seconds.',
      ),
    );
    _clearPkOverlayLater();
  }

  void _mockDevExitPkBattle() {
    setState(() {
      _pkBattle = null;
      _incomingPkInvite = null;
      _opponentConnecting = false;
      _opponentMediaUnavailable = false;
      _pkOverlayTitle = 'PK Battle Ended';
      _pkOverlaySubtitle = 'Returning to the normal host video room preview.';
      _pkOverlayWinnerSide = 0;
      _pkOverlayWinnerName = null;
      _pkOverlayWinnerAvatarUrl = null;
      _pkOverlayTopSupporters = const <PkWinnerSupporter>[];
      _clearPkGiftLeaders();
    });
    _appendChatMessage(
      LiveRoomChatMessage.system(
        roomId: widget.room.roomId,
        roomType: widget.room.roomType,
        message: 'PK battle ended. Back to normal host stage.',
      ),
    );
    _clearPkOverlayLater();
  }

  void _seedDevPkSupporters() {
    _pkGiftLeadersBySide = <String, Map<int, _PkSupporterStanding>>{
      'left': <int, _PkSupporterStanding>{
        901: const _PkSupporterStanding(
          senderId: 901,
          senderName: 'Riya',
          totalCoins: 5400,
          avatarUrl: 'https://i.pravatar.cc/120?img=32',
        ),
        902: const _PkSupporterStanding(
          senderId: 902,
          senderName: 'Kabir',
          totalCoins: 3300,
          avatarUrl: 'https://i.pravatar.cc/120?img=14',
        ),
        903: const _PkSupporterStanding(
          senderId: 903,
          senderName: 'Meera',
          totalCoins: 1800,
          avatarUrl: 'https://i.pravatar.cc/120?img=47',
        ),
      },
      'right': <int, _PkSupporterStanding>{
        904: const _PkSupporterStanding(
          senderId: 904,
          senderName: 'Arjun',
          totalCoins: 6200,
          avatarUrl: 'https://i.pravatar.cc/120?img=12',
        ),
        905: const _PkSupporterStanding(
          senderId: 905,
          senderName: 'Sara',
          totalCoins: 2600,
          avatarUrl: 'https://i.pravatar.cc/120?img=5',
        ),
        906: const _PkSupporterStanding(
          senderId: 906,
          senderName: 'Dev',
          totalCoins: 1200,
          avatarUrl: 'https://i.pravatar.cc/120?img=25',
        ),
      },
    };
  }

  void _mockDevGiftToSide(String side) {
    if (!widget.devMode || !_pkActive) return;
    final left = side == 'left';
    final payload = LiveRoomDevFixtures.mockGiftPayload(
      roomId: widget.room.roomId,
      roomType: 'video',
      receiverId: left ? 501 : 502,
      receiverName: left ? 'Host Aman' : 'Zoya',
      receiverAvatar: null,
      gift: const LiveGiftItem(
        id: 999,
        name: 'PK Burst',
        coins: 500,
        giftUrl: 'https://picsum.photos/seed/pkburst/320/320',
      ),
      quantity: left ? 1 : 2,
      pkSide: side,
    );
    payload['sender_user_id'] = left ? 921 : 922;
    payload['sender_name'] = left ? 'Nova' : 'Aisha';
    payload['sender_avatar'] =
        left
            ? 'https://i.pravatar.cc/120?img=41'
            : 'https://i.pravatar.cc/120?img=9';
    payload['total_coins'] = left ? 500 : 1000;
    payload['coins_per_unit'] = 500;
    _recordPkGiftFromEvent(payload, fallbackSide: side);
    _giftAnimationOverlay.handleSocketGiftEvent(
      payload,
      currentThemeKey: Get.find<AppSettingsService>().activePremiumThemeVariant,
      receiverFallbackId: left ? 501 : 502,
      currentUserId: _myUserId ?? 90061,
      inferredPkSide: side,
    );
    final battle = _pkBattle;
    if (battle != null) {
      final scoreA = battle.scoreA + (left ? 500 : 0);
      final scoreB = battle.scoreB + (left ? 0 : 1000);
      setState(() {
        _pkBattle = LivePkBattleModel.fromJson({
          'battle_id': battle.battleId,
          'status': battle.status,
          'duration_seconds': battle.durationSeconds,
          'score_a': scoreA,
          'score_b': scoreB,
          'started_at': battle.startedAt?.toIso8601String(),
          'ended_at': battle.endedAt?.toIso8601String(),
          'ends_at': battle.endsAt?.toIso8601String(),
          'winner_room_id': battle.winnerRoomId,
          'end_reason': battle.endReason,
          'room_a': battle.roomA,
          'room_b': battle.roomB,
          'host_a': battle.hostA,
          'host_b': battle.hostB,
          'updated_at': DateTime.now().toIso8601String(),
        });
        _recentGiftMessage = '${payload['sender_name']} boosted ${left ? 'left' : 'right'} side';
      });
      _recentGiftTimer?.cancel();
      _recentGiftTimer = Timer(const Duration(seconds: 4), () {
        if (!mounted) return;
        setState(() => _recentGiftMessage = null);
      });
    }
  }

  void _mockDevResolvePk(int winnerSide) {
    if (!widget.devMode || !_pkActive) return;
    final winLeft = winnerSide == 1;
    final winnerHost =
        winLeft
            ? _pkBattle?.ownHostFor(widget.room.roomId)
            : _pkBattle?.opponentHostFor(widget.room.roomId);
    final winnerSupporters = _topPkSupportersFor(winLeft ? 'left' : 'right');
    setState(() {
      _pkOverlayTitle = winLeft ? 'Left Side Won' : 'Right Side Won';
      _pkOverlaySubtitle = 'Mock PK result preview.';
      _pkOverlayWinnerSide = winnerSide;
      _pkOverlayWinnerName = winnerHost?['name']?.toString();
      _pkOverlayWinnerAvatarUrl =
          winnerHost?['avatar_url']?.toString() ?? winnerHost?['avatar']?.toString();
      _pkOverlayTopSupporters =
          winnerSupporters
              .map(
                (supporter) => PkWinnerSupporter(
                  userId: supporter.senderId,
                  name: supporter.senderName,
                  coins: supporter.totalCoins,
                  avatarUrl: supporter.avatarUrl,
                ),
              )
              .toList(growable: false);
    });
    _clearPkOverlayLater();
  }

  void _clearPkOverlayLater() {
    _pkOverlayTimer?.cancel();
    _pkOverlayTimer = Timer(const Duration(seconds: 4), () {
      if (!mounted) return;
      setState(() {
        _pkOverlayTitle = null;
        _pkOverlaySubtitle = null;
        _pkOverlayWinnerSide = 0;
        _pkOverlayWinnerName = null;
        _pkOverlayWinnerAvatarUrl = null;
        _pkOverlayTopSupporters = const <PkWinnerSupporter>[];
      });
    });
  }

  Future<void> _bootstrap() async {
    await _renderer.initialize();
    _rendererReady = true;
    if (widget.devMode) {
      setState(() {
        _availableGifts = LiveRoomDevFixtures.mockGiftCatalog();
        _speakerCount =
            widget.room.speakerCount > 0 ? widget.room.speakerCount : 1;
        _maxSpeakers = widget.room.maxSpeakers;
        _connecting = false;
        _error = null;
      });
      if (_pkCapable) {
        await _syncPkState(prefill: widget.room.pkActive);
      }
      _startLiveHud(widget.room.startedAt ?? DateTime.now());
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        _showSelfJoinAnimation();
      });
      return;
    }
    await _refreshSeatSnapshot();
    await _loadGiftCatalog();
    await _refreshWalletBalance();
    _bindSeatEvents();
    _bindGiftEvents();
    _bindRoomLifecycleEvents();
    _bindChatEvents();
    _bindModerationEvents();
    if (_pkCapable) {
      _bindPkEvents();
      _bindSocketConnectionEvents();
      await _syncPkState(prefill: widget.room.pkActive);
    }
    _connect();
  }

  void _startLiveHud(DateTime startAt) {
    _liveStart = startAt;
    _hudTimer?.cancel();
    _hudTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted || _liveStart == null) return;
      final diff = DateTime.now().difference(_liveStart!);
      final hh = diff.inHours;
      final mm = diff.inMinutes.remainder(60).toString().padLeft(2, '0');
      final ss = diff.inSeconds.remainder(60).toString().padLeft(2, '0');
      final hPrefix = hh > 0 ? '$hh:' : '';
      setState(() => _timerText = 'LIVE • $hPrefix$mm:$ss');
    });
  }

  @override
  void dispose() {
    if (!_endSent) {
      _leaveSessionOnce();
    }
    _leaveSocketRoom();
    _hudTimer?.cancel();
    _heartbeatTimer?.cancel();
    _noFrameWatchdog?.cancel();
    _recentGiftTimer?.cancel();
    _pkOverlayTimer?.cancel();
    _devPkTransitionTimer?.cancel();
    _pkExpiryWatcher?.cancel();
    _glow.dispose();
    _detachPreview();
    _listener?.dispose();
    _opponentListener?.dispose();
    _seatEventsSub?.cancel();
    _giftEventsSub?.cancel();
    _roomLifecycleSub?.cancel();
    _pkEventsSub?.cancel();
    _socketConnectionSub?.cancel();
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
    _themeSyncWorker?.dispose();
    try {
      _room?.disconnect();
    } catch (_) {}
    try {
      _opponentRoom?.disconnect();
    } catch (_) {}
    _room?.dispose();
    _opponentRoom?.dispose();
    if (_rendererReady) {
      _renderer.dispose();
    }
    super.dispose();
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

  void _joinSocketRoom() {
    if (_roomSocketJoined) return;
    _roomSocketJoined = true;
    try {
      if (Get.isRegistered<RoomsSocketService>()) {
        Get.find<RoomsSocketService>().joinRoom(widget.room.roomId);
      }
    } catch (_) {}
  }

  void _leaveSocketRoom() {
    if (!_roomSocketJoined) return;
    _roomSocketJoined = false;
    try {
      if (Get.isRegistered<RoomsSocketService>()) {
        Get.find<RoomsSocketService>().leaveRoom(widget.room.roomId);
      }
    } catch (_) {}
  }

  Future<void> _connect() async {
    final url = widget.room.wsUrl;
    final token = widget.room.token;
    if (url == null || token == null) {
      setState(() => _error = 'Missing ws_url or token');
      return;
    }

    setState(() {
      _connecting = true;
      _error = null;
    });

    try {
      final room = Room(
        roomOptions: const RoomOptions(adaptiveStream: true, dynacast: true),
        connectOptions: const ConnectOptions(autoSubscribe: true),
      );
      final l = room.createListener();
      _listener = l;

      l.on<RoomReconnectingEvent>(
        (_) => setState(() => _error = 'Reconnecting…'),
      );
      l.on<RoomReconnectedEvent>((_) async {
        if (!mounted) return;
        setState(() => _error = null);
        _syncJoinAnimations(room, animate: false);
        if (_pkCapable) {
          await _syncPkState();
        }
      });
      l.on<RoomDisconnectedEvent>((e) {
        if (!mounted || _exiting) return;
        final reason = (e.reason ?? 'unknown').toString();
        if (!_isHost) {
          unawaited(
            _exitBecauseRoomEnded(
              reason == 'client initiated' ? 'host_ended' : 'host_disconnected',
            ),
          );
          return;
        }
        setState(() => _error = 'Disconnected: $reason');
      });

      l.on<LocalTrackPublishedEvent>((_) async => _attachLocalPreview(room));
      l.on<LocalTrackUnpublishedEvent>((_) async => _detachPreview());
      l.on<ParticipantConnectedEvent>((event) {
        if (!mounted) return;
        _handleParticipantConnected(event.participant, room);
        setState(() {});
        unawaited(_refreshSeatSnapshot());
      });
      l.on<ParticipantDisconnectedEvent>((_) {
        if (!mounted) return;
        _syncJoinAnimations(room, animate: false);
        setState(() {});
        unawaited(_refreshSeatSnapshot());
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
        final lp = room.localParticipant;
        final isSpeaking = lp != null && room.activeSpeakers.contains(lp);
        if (isSpeaking != _localSpeaking)
          setState(() => _localSpeaking = isSpeaking);
      });

      l.on<DataReceivedEvent>((ev) {
        try {
          final msg = String.fromCharCodes(ev.data);
          if (msg.startsWith('rx:')) {
            _emojiKey.currentState?.burst(msg.substring(3));
          }
        } catch (_) {}
      });

      await room.connect(url, token);
      _trackedParticipantIds = _currentParticipantIds(room);
      _joinAnimationsArmed = true;
      _joinSocketRoom();

      if (_isViewerOnly) {
        await room.localParticipant?.setCameraEnabled(false);
        await room.localParticipant?.setMicrophoneEnabled(false);
        _camOn = false;
        _micOn = false;
        await _detachPreview();
      } else {
        await room.localParticipant?.setCameraEnabled(
          _camOn,
          cameraCaptureOptions: const CameraCaptureOptions(
            cameraPosition: CameraPosition.front,
          ),
        );
        await room.localParticipant?.setMicrophoneEnabled(_micOn);
        _frontFacing = true;

        if (_camOn) {
          await _waitForLocalTrack(room, timeoutMs: 750);
          await _attachLocalPreview(room);
        } else {
          await _detachPreview();
        }
      }

      _liveStart = DateTime.now();
      _hudTimer?.cancel();
      _hudTimer = Timer.periodic(const Duration(seconds: 1), (_) {
        if (!mounted || _liveStart == null) return;
        final diff = DateTime.now().difference(_liveStart!);
        final hh = diff.inHours;
        final mm = diff.inMinutes.remainder(60).toString().padLeft(2, '0');
        final ss = diff.inSeconds.remainder(60).toString().padLeft(2, '0');
        final hPrefix = hh > 0 ? '$hh:' : '';
        setState(() => _timerText = 'LIVE • $hPrefix$mm:$ss');
      });
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

      setState(() {
        _room = room;
        _connecting = false;
      });
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        _showSelfJoinAnimation();
      });

      _noFrameWatchdog?.cancel();
      _noFrameWatchdog = Timer(const Duration(milliseconds: 1200), () async {
        if (!mounted) return;
        if (_camOn &&
            _localCameraTrack(room) == null &&
            !_cameraRestartedOnce) {
          _cameraRestartedOnce = true;
          try {
            await room.localParticipant?.setCameraEnabled(false);
            await Future.delayed(const Duration(milliseconds: 250));
            await room.localParticipant?.setCameraEnabled(
              true,
              cameraCaptureOptions: const CameraCaptureOptions(
                cameraPosition: CameraPosition.front,
              ),
            );
            await _waitForLocalTrack(room, timeoutMs: 750);
          } catch (_) {}
          await _attachLocalPreview(room);
          setState(() {});
        }
      });
    } catch (e) {
      setState(() {
        _error = e.toString();
        _connecting = false;
      });
    }
  }

  Future<void> _attachLocalPreview(Room room, {bool force = false}) async {
    final t = _localCameraTrack(room);
    if (t == null) return;
    if (!force && _boundTrack == t && _renderer.srcObject != null) return;
    _boundTrack = t;

    try {
      _renderer.srcObject = null;
      await _previewStream?.dispose();
      _previewStream = await webrtc.createLocalMediaStream('lk-preview');
      await _previewStream!.addTrack(t.mediaStreamTrack);
      _renderer.srcObject = _previewStream;
      if (mounted) setState(() {});
    } catch (e) {
      if (mounted) setState(() => _error = 'Preview bind failed: $e');
    }
  }

  Future<void> _detachPreview() async {
    try {
      _renderer.srcObject = null;
      await _previewStream?.dispose();
    } catch (_) {}
    _previewStream = null;
    _boundTrack = null;
  }

  Future<LocalVideoTrack?> _waitForLocalTrack(
    Room room, {
    int timeoutMs = 750,
  }) async {
    final t0 = DateTime.now();
    LocalVideoTrack? t;
    while (DateTime.now().difference(t0).inMilliseconds < timeoutMs) {
      t = _localCameraTrack(room);
      if (t != null) return t;
      await Future.delayed(const Duration(milliseconds: 50));
    }
    return null;
  }

  LocalVideoTrack? _localCameraTrack(Room room) {
    final lp = room.localParticipant;
    if (lp == null) return null;
    for (final pub in lp.trackPublications.values) {
      if (pub.source == TrackSource.camera ||
          pub.source == TrackSource.unknown) {
        final tr = pub.track;
        if (tr is LocalVideoTrack) return tr;
      }
    }
    return null;
  }

  /* ===================== Controls ===================== */

  Future<void> _toggleMic() async {
    if (!_canPublishMedia) return;
    if (widget.devMode) {
      setState(() => _micOn = !_micOn);
      return;
    }
    final lp = _room?.localParticipant;
    if (lp == null) return;
    final next = !(lp.isMicrophoneEnabled());
    await lp.setMicrophoneEnabled(next);
    if (!mounted) return;
    setState(() => _micOn = next);
  }

  Future<void> _toggleCam() async {
    if (!_canPublishMedia) return;
    if (_camBusy) return;
    if (widget.devMode) {
      setState(() => _camOn = !_camOn);
      return;
    }
    _camBusy = true;
    try {
      final room = _room;
      final lp = room?.localParticipant;
      if (lp == null) return;

      final enabling = !(lp.isCameraEnabled());

      if (!enabling) {
        await lp.setCameraEnabled(false);
        await _detachPreview();
        if (mounted) setState(() => _camOn = false);
        return;
      }

      final pos = _frontFacing ? CameraPosition.front : CameraPosition.back;
      await lp.setCameraEnabled(
        true,
        cameraCaptureOptions: CameraCaptureOptions(cameraPosition: pos),
      );

      await _waitForLocalTrack(room!, timeoutMs: 750);
      await _attachLocalPreview(room);

      _noFrameWatchdog?.cancel();
      _noFrameWatchdog = Timer(const Duration(milliseconds: 800), () async {
        if (!mounted) return;
        if (_localCameraTrack(room) == null && !_cameraRestartedOnce) {
          _cameraRestartedOnce = true;
          try {
            await lp.setCameraEnabled(false);
            await Future.delayed(const Duration(milliseconds: 200));
            await lp.setCameraEnabled(
              true,
              cameraCaptureOptions: CameraCaptureOptions(cameraPosition: pos),
            );
            await _waitForLocalTrack(room, timeoutMs: 700);
          } catch (_) {}
          await _attachLocalPreview(room);
          if (mounted) setState(() {});
        }
      });

      if (mounted) setState(() => _camOn = true);
    } finally {
      _camBusy = false;
    }
  }

  Future<void> _flipCamera() async {
    if (!_canPublishMedia) return;
    if (_flipBusy) return;
    if (widget.devMode) {
      setState(() => _frontFacing = !_frontFacing);
      return;
    }
    _flipBusy = true;
    try {
      final nextFrontFacing = !_frontFacing;
      final room = _room;
      final lp = room?.localParticipant;
      if (lp == null) return;

      if (!lp.isCameraEnabled()) {
        if (mounted) {
          setState(() => _frontFacing = nextFrontFacing);
        }
        return;
      }

      final pos = nextFrontFacing ? CameraPosition.front : CameraPosition.back;
      final track = room != null ? _localCameraTrack(room) : null;

      if (track != null) {
        await track.setCameraPosition(pos);
      } else {
        await lp.setCameraEnabled(
          true,
          cameraCaptureOptions: CameraCaptureOptions(cameraPosition: pos),
        );
        if (room != null) {
          await _waitForLocalTrack(room, timeoutMs: 750);
        }
      }

      if (room != null) {
        await _attachLocalPreview(room, force: true);
      }
      if (mounted) {
        setState(() {
          _frontFacing = nextFrontFacing;
          _camOn = true;
        });
      }
    } finally {
      _flipBusy = false;
    }
  }

  Future<void> _sendReaction(String emoji) async {
    final lp = _room?.localParticipant;
    if (lp == null) return;
    _emojiKey.currentState?.burst(emoji);
    try {
      lp.publishData('rx:$emoji'.codeUnits, reliable: false);
    } catch (_) {}
  }

  Future<void> _endSession() async {
    if (_exiting) return;
    final ok = await _showActionSheet(
      title: 'End Live',
      message: 'This will end the live room for everyone.',
      primaryLabel: 'End Live',
      destructive: true,
    );
    if (ok != true) return;

    _exiting = true;
    _giftAnimationOverlay.clear();
    await _endSessionOnce();
    _leaveSocketRoom();
    _closeTransientOverlays();
    _popLivePage();
  }

  Future<void> _exitViewerSession() async {
    if (_exiting) return;
    final ok = await _showActionSheet(
      title: _currentRole == 'speaker' ? 'Leave Live' : 'Leave Room',
      message:
          _currentRole == 'speaker'
              ? 'You will leave the speaker stage and return to the previous screen.'
              : 'You will leave this live room.',
      primaryLabel: _currentRole == 'speaker' ? 'Leave Live' : 'Leave Room',
    );
    if (ok != true) return;
    _exiting = true;
    _giftAnimationOverlay.clear();
    await _leaveSessionOnce();
    _leaveSocketRoom();
    try {
      await _room?.disconnect();
    } catch (_) {}
    if (mounted) Get.back();
  }

  Future<bool> _handleBackNavigation() async {
    if (_handlingBackNavigation) return false;
    _handlingBackNavigation = true;
    try {
      if (!_isHost) {
        await _exitViewerSession();
        return false;
      }
      await _endSession();
      return false;
    } finally {
      _handlingBackNavigation = false;
    }
  }

  bool get _isHost => _currentRole == 'host';
  bool get _canModerate => _isHost;
  bool get _isViewerOnly => _currentRole == 'viewer';
  bool get _canPublishMedia =>
      _currentRole == 'host' || _currentRole == 'speaker';
  bool get _pkActive => _pkBattle?.isActive == true;
  bool get _pkCapable =>
      widget.room.roomType == 'video' &&
      Get.find<AppSettingsService>().pkBattlesEnabled;

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
      level: _safeInt(event['level']),
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
      level: _safeInt(metadata['level']),
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
    final identity = participant.identity.trim();
    if (identity.startsWith('user:')) {
      return 'User ${identity.split(':').last}';
    }
    return identity.isEmpty ? 'Someone' : identity;
  }

  String _participantThemeKey(Participant participant) {
    final metadata = _participantMetadata(participant);
    final rawThemeKey = metadata['active_theme_key']?.toString().trim();
    if (rawThemeKey?.isNotEmpty == true) {
      return normalizePremiumThemeVariant(rawThemeKey!);
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

  int get _viewerCount {
    final room = _room;
    if (room == null) return widget.room.participantCount;
    return room.remoteParticipants.length + 1;
  }

  String get _hostDisplayName {
    final roomHostName = widget.room.hostName?.trim();
    if (roomHostName?.isNotEmpty == true) {
      return roomHostName!;
    }
    final stageName = widget.room.meta?['host_name']?.toString().trim();
    if (stageName?.isNotEmpty == true) {
      return stageName!;
    }
    final fallback =
        widget.room.title?.trim().isNotEmpty == true
            ? widget.room.title!.trim()
            : 'Talkieo Host';
    if (_isHost) {
      final currentUser = Get.find<AuthService>().currentUser;
      final hostStageName = currentUser?.hostProfile?.stageName?.trim();
      if (hostStageName?.isNotEmpty == true) {
        return hostStageName!;
      }
      final currentUserName = currentUser?.name.trim();
      if (currentUserName?.isNotEmpty == true) {
        return currentUserName!;
      }
      return fallback;
    }
    final room = _room;
    if (room == null) return fallback;
    for (final participant in room.remoteParticipants.values) {
      if (participant.identity.startsWith('host-')) {
        final metadata = _participantMetadata(participant);
        final metadataStageName = metadata['stage_name']?.toString().trim();
        if (metadataStageName?.isNotEmpty == true) {
          return metadataStageName!;
        }
        final metadataName = metadata['name']?.toString().trim();
        if (metadataName?.isNotEmpty == true) {
          return metadataName!;
        }
        return participant.name.isNotEmpty ? participant.name : fallback;
      }
    }
    return fallback;
  }

  String? get _hostAvatarUrl {
    if (_isHost) {
      final currentUserAvatar =
          Get.find<AuthService>().currentUser?.avatarUrl?.trim();
      if (currentUserAvatar?.isNotEmpty == true) {
        return currentUserAvatar;
      }
    }
    final room = _room;
    if (room != null) {
      for (final participant in room.remoteParticipants.values) {
        if (participant.identity.startsWith('host-')) {
          final metadata = _participantMetadata(participant);
          final metadataAvatar =
              metadata['avatar_url']?.toString().trim().isNotEmpty == true
                  ? metadata['avatar_url'].toString().trim()
                  : metadata['avatar']?.toString().trim();
          if (metadataAvatar?.isNotEmpty == true) {
            return metadataAvatar;
          }
        }
      }
    }
    return null;
  }

  String? get _hostProfileFrameUrl {
    if (_isHost) {
      final currentUserFrame =
          Get.find<AuthService>().currentUser?.profileFrame?.assetUrl?.trim();
      if (currentUserFrame?.isNotEmpty == true) {
        return currentUserFrame;
      }
    }
    final room = _room;
    if (room != null) {
      for (final participant in room.remoteParticipants.values) {
        if (participant.identity.startsWith('host-')) {
          final metadata = _participantMetadata(participant);
          final metadataFrame = profileFrameAssetUrlFromPayload(metadata)?.trim();
          if (metadataFrame?.isNotEmpty == true) {
            return metadataFrame;
          }
        }
      }
    }
    return profileFrameAssetUrlFromPayload(widget.room.meta?['host_profile_frame']);
  }

  int? get _hostUserId {
    if (_isHost) {
      return Get.find<AuthService>().currentUser?.id;
    }
    final metaUserId = _safeInt(widget.room.meta?['host_user_id']);
    if (metaUserId != null && metaUserId > 0) {
      return metaUserId;
    }
    final room = _room;
    if (room != null) {
      for (final participant in room.remoteParticipants.values) {
        if (participant.identity.startsWith('host-')) {
          final metadata = _participantMetadata(participant);
          final participantUserId = _safeInt(metadata['user_id']);
          if (participantUserId != null && participantUserId > 0) {
            return participantUserId;
          }
        }
      }
    }
    return null;
  }

  String get _hostThemeKey {
    if (_isHost) {
      return Get.find<AppSettingsService>().activePremiumThemeVariant;
    }
    final room = _room;
    if (room != null) {
      for (final participant in room.remoteParticipants.values) {
        if (participant.identity.startsWith('host-')) {
          return _participantThemeKey(participant);
        }
      }
    }
    return 'midnight';
  }

  bool get _hostIsVip {
    if (_isHost) {
      final roles = Get.find<AuthService>().currentUser?.roles ?? const <String>[];
      return roles.any(
        (role) => role.toLowerCase() == 'vip' || role.toLowerCase() == 'premium',
      );
    }
    final room = _room;
    if (room != null) {
      for (final participant in room.remoteParticipants.values) {
        if (participant.identity.startsWith('host-')) {
          return _participantIsVip(participant);
        }
      }
    }
    return false;
  }

  int? get _hostLevel {
    if (_isHost) {
      return Get.find<AuthService>().currentUser?.level;
    }
    final room = _room;
    if (room != null) {
      for (final participant in room.remoteParticipants.values) {
        if (participant.identity.startsWith('host-')) {
          final metadata = _participantMetadata(participant);
          return _safeInt(metadata['level']);
        }
      }
    }
    return null;
  }

  Future<void> _openHostProfileFromPill() async {
    final hostUserId = _hostUserId;
    if (hostUserId == null || hostUserId <= 0) return;
    await _showParticipantProfileCard(
      userId: hostUserId,
      name: _hostDisplayName,
      subtitle: 'Host',
      themeKey: _hostThemeKey,
      isVip: _hostIsVip,
      isHost: true,
      speaking: _canPublishMedia ? _localSpeaking : false,
      level: _hostLevel,
      avatarUrl: _hostAvatarUrl,
    );
  }

  String? get _viewerStatusText {
    switch (_requestStatus) {
      case 'pending':
        return 'Request pending. Waiting for host approval.';
      case 'accepted':
        return 'You are now live.';
      case 'rejected':
        return 'Host declined your request.';
      case 'removed':
        return 'You were moved back to audience.';
      case 'cancelled':
        return 'Request cancelled.';
      default:
        return null;
    }
  }

  int? _safeInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  Future<void> _showParticipantProfileCard({
    required int userId,
    required String name,
    required String subtitle,
    required String themeKey,
    required bool isVip,
    required bool isHost,
    required bool speaking,
    int? level,
    String? avatarUrl,
  }) {
    return _showParticipantActionsSheet(
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
  }

  Future<void> _showParticipantActionsSheet({
    required int userId,
    required String name,
    required String subtitle,
    required String themeKey,
    required bool isVip,
    required bool isHost,
    required bool speaking,
    int? level,
    String? avatarUrl,
  }) async {
    final canModerate =
        _isHost && _myUserId != null && userId > 0 && userId != _myUserId;
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
      builder: (context) {
        return SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
            child: Container(
              padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: _tokens.cardGradient,
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(28),
                border: Border.all(color: _tokens.borderColor),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  _videoParticipantActionTile(
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
                  _videoParticipantActionTile(
                    icon: Icons.flag_rounded,
                    title: 'Report user',
                    subtitle: 'Send a moderation report',
                    onTap: userId == _myUserId ? null : () {
                      Navigator.of(context).pop();
                      _showReportSheet(
                        reportedUserId: userId,
                        reportedName: name,
                      );
                    },
                  ),
                  if (canModerate)
                    _videoParticipantActionTile(
                      icon: Icons.person_remove_rounded,
                      title: 'Kick from room',
                      subtitle: 'Remove this user from the current room only',
                      destructive: true,
                      onTap: () {
                        Navigator.of(context).pop();
                        _kickParticipant(userId, name);
                      },
                    ),
                  if (canModerate)
                    _videoParticipantActionTile(
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
                          _unblockParticipant(userId, name);
                        } else {
                          _blockParticipant(userId, name);
                        }
                      },
                    ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Future<void> _openParticipantProfile({
    required int userId,
    required String name,
    required String subtitle,
    required String themeKey,
    required bool isVip,
    required bool isHost,
    required bool speaking,
    int? level,
    String? avatarUrl,
  }) {
    return showPublicProfileCardSheet(
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
  }

  Future<void> _openPkSupporterProfile(_PkSupporterStanding supporter) {
    if (supporter.senderId <= 0) return Future<void>.value();
    return _openParticipantProfile(
      userId: supporter.senderId,
      name: supporter.senderName,
      subtitle: 'Top PK supporter',
      themeKey: 'midnight',
      isVip: false,
      isHost: false,
      speaking: false,
      avatarUrl: supporter.avatarUrl,
    );
  }

  Widget _videoParticipantActionTile({
    required IconData icon,
    required String title,
    required String subtitle,
    required VoidCallback? onTap,
    bool destructive = false,
  }) {
    final iconColor =
        destructive ? _tokens.dangerColor : _tokens.primaryButtonGradient.first;
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
              color: _tokens.glassColor.withOpacity(.14),
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: _tokens.borderColor.withOpacity(.20)),
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
                          color: _tokens.textPrimary,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        subtitle,
                        style: TextStyle(
                          color: _tokens.textSecondary,
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                ),
                Icon(
                  Icons.chevron_right_rounded,
                  color: _tokens.textSecondary.withOpacity(.72),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _buildChatTrailingActions() {
    final actions = <Widget>[];
    final showGiftInChatFooter = true;

    if (_isHost) {
      actions.add(
        _ExpandableFooterCluster(
          primaryIcon: Icons.tune_rounded,
          primaryAccent: const Color(0xFF5D8BFF),
          primaryBadgeCount: _pkCapable && _pkActive ? 0 : _pendingRequests.length,
          actions: [
            _FooterActionItem(
              icon: _micOn ? Icons.mic_rounded : Icons.mic_off_rounded,
              onTap: _toggleMic,
              active: _micOn,
            ),
            _FooterActionItem(
              icon: _camOn ? Icons.videocam_rounded : Icons.videocam_off_rounded,
              onTap: _toggleCam,
              active: _camOn,
            ),
            _FooterActionItem(
              icon: Icons.cameraswitch_rounded,
              onTap: _flipCamera,
            ),
            _FooterActionItem(
              icon: Icons.groups_rounded,
              onTap: _showHostModerationSheet,
              badgeCount: _pendingRequests.length,
              accent: const Color(0xFF5D8BFF),
            ),
            _FooterActionItem(
              icon:
                  _giftBusy ? Icons.hourglass_top_rounded : Icons.redeem_rounded,
              onTap: _giftBusy ? null : _openGiftSheet,
              accent: const Color(0xFFFF8BC2),
            ),
            if (_pkCapable)
              _FooterActionItem(
                icon:
                    _pkActive
                        ? Icons.stop_circle_outlined
                        : Icons.sports_martial_arts_rounded,
                onTap:
                    _pkActive ? _endPkBattle : _showPkInviteSheet,
                accent: const Color(0xFF7B50C5),
              ),
          ],
        ),
      );
      return actions;
    }

    if (_currentRole == 'speaker') {
      if (showGiftInChatFooter) {
        final giftAction = _FooterCircleAction(
          icon:
              _giftBusy ? Icons.hourglass_top_rounded : Icons.redeem_rounded,
          onTap: _giftBusy ? null : _openGiftSheet,
          accent: const Color(0xFFFF8BC2),
          busy: _giftBusy,
        );
        actions.add(
          _pkActive
              ? giftAction
              : KeyedSubtree(
                key: _giftAnchors.keyFor(GiftAnchorRegistry.giftButton),
                child: giftAction,
              ),
        );
      }
      actions.add(
        _ExpandableFooterCluster(
          primaryIcon: Icons.tune_rounded,
          primaryAccent: const Color(0xFF57E6B1),
          actions: [
            _FooterActionItem(
              icon: _micOn ? Icons.mic_rounded : Icons.mic_off_rounded,
              onTap: _toggleMic,
              active: _micOn,
            ),
            _FooterActionItem(
              icon: _camOn ? Icons.videocam_rounded : Icons.videocam_off_rounded,
              onTap: _toggleCam,
              active: _camOn,
            ),
            _FooterActionItem(
              icon: Icons.cameraswitch_rounded,
              onTap: _flipCamera,
            ),
          ],
        ),
      );
      return actions;
    }

    return actions;
  }

  List<Widget> _buildChatInputActions() {
    if (_isHost || _currentRole == 'speaker' || _pkActive) {
      return const <Widget>[];
    }

    const showGiftInChatFooter = true;
    final pending = _pendingRequestId != null && _requestStatus == 'pending';
    return <Widget>[
      if (showGiftInChatFooter)
        KeyedSubtree(
          key: _giftAnchors.keyFor(GiftAnchorRegistry.giftButton),
          child: _ResponsiveChatInputAction(
            icon:
                _giftBusy ? Icons.hourglass_top_rounded : Icons.redeem_rounded,
            label: 'Gift',
            onTap: _giftBusy ? null : _openGiftSheet,
            accent: const Color(0xFFFF8BC2),
            busy: _giftBusy,
            iconOnlyBelowWidth: 430,
          ),
        ),
      _ResponsiveChatInputAction(
        icon: pending ? Icons.close_rounded : Icons.video_call_rounded,
        label: pending ? 'Cancel Join Call Request' : 'Join Call',
        compactLabel: pending ? 'Cancel Join' : 'Join',
        onTap:
            _seatActionBusy
                ? null
                : (pending ? _cancelJoinRequest : _requestToJoinAsSpeaker),
        accent: const Color(0xFF5D8BFF),
        busy: _seatActionBusy,
        iconOnlyBelowWidth: 350,
      ),
    ];
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
                            side: BorderSide(
                              color: _tokens.borderColor,
                            ),
                            padding: const EdgeInsets.symmetric(vertical: 14),
                          ),
                          child: const Text('Cancel'),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: FilledButton(
                          onPressed: () => Navigator.of(context).pop(true),
                          style: FilledButton.styleFrom(
                            backgroundColor:
                                destructive
                                    ? _tokens.dangerColor
                                    : _tokens.primaryButtonGradient.first,
                            foregroundColor: _tokens.textPrimary,
                            padding: const EdgeInsets.symmetric(vertical: 14),
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

  Future<void> _kickParticipant(int userId, String name) async {
    final ok = await _showActionSheet(
      title: 'Kick $name?',
      message: 'This removes the user from the current video room only.',
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
            return SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                child: Container(
                  padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
                  decoration: BoxDecoration(
                    gradient: LinearGradient(colors: _tokens.cardGradient),
                    borderRadius: BorderRadius.circular(28),
                    border: Border.all(color: _tokens.borderColor),
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Report $reportedName',
                        style: TextStyle(
                          color: _tokens.textPrimary,
                          fontWeight: FontWeight.w900,
                          fontSize: 20,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          for (final reason in reasons)
                            ChoiceChip(
                              label: Text(reason.replaceAll('_', ' ')),
                              selected: selectedReason == reason,
                              onSelected:
                                  (_) => setModalState(() {
                                    selectedReason = reason;
                                  }),
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
                ),
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
        hostUserId:
            _safeInt(widget.room.meta?['host_user_id']) ??
            _safeInt(widget.room.meta?['host_id']),
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
      builder: (context) {
        return SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
            child: Container(
              padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: _tokens.cardGradient),
                borderRadius: BorderRadius.circular(28),
                border: Border.all(color: _tokens.borderColor),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: TextStyle(
                      color: _tokens.textPrimary,
                      fontWeight: FontWeight.w900,
                      fontSize: 20,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    description,
                    style: TextStyle(
                      color: _tokens.textSecondary,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 14),
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
                      onPressed:
                          () => Navigator.of(context).pop(controller.text.trim()),
                      child: Text(ctaLabel),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
    controller.dispose();
    return result;
  }

  Future<void> _showHostModerationSheet() async {
    Haptics.selection();
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (context) {
        var pendingRequests = List<Map<String, dynamic>>.from(_pendingRequests);
        var speakers = List<Map<String, dynamic>>.from(_speakers);
        var participants = _hostModerationParticipants();
        var speakerCount = _speakerCount;
        var maxSpeakers = _maxSpeakers;
        var busy = _seatActionBusy;

        void syncFromParent(StateSetter setModalState) {
          if (!mounted) return;
          setModalState(() {
            pendingRequests = List<Map<String, dynamic>>.from(_pendingRequests);
            speakers = List<Map<String, dynamic>>.from(_speakers);
            participants = _hostModerationParticipants();
            speakerCount = _speakerCount;
            maxSpeakers = _maxSpeakers;
            busy = _seatActionBusy;
          });
        }

        return StatefulBuilder(
          builder: (context, setModalState) {
            Future<void> handleAccept(int requestId) async {
              setModalState(() => busy = true);
              await _acceptSeatRequest(requestId);
              syncFromParent(setModalState);
            }

            Future<void> handleReject(int requestId) async {
              setModalState(() => busy = true);
              await _rejectSeatRequest(requestId);
              syncFromParent(setModalState);
            }

            Future<void> handleRemoveSpeaker(int userId) async {
              setModalState(() => busy = true);
              await _removeSpeaker(userId);
              syncFromParent(setModalState);
            }

            return SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                child: _HostModerationSheet(
                  pendingRequests: pendingRequests,
                  speakers: speakers,
                  participants: participants,
                  speakerCount: speakerCount,
                  maxSpeakers: maxSpeakers,
                  busy: busy,
                  onAccept: handleAccept,
                  onReject: handleReject,
                  onRemoveSpeaker: handleRemoveSpeaker,
                  onOpenParticipant: (participant) {
                    return _showParticipantActionsSheet(
                      userId: participant.userId,
                      name: participant.name,
                      subtitle: participant.subtitle,
                      themeKey: participant.themeKey,
                      isVip: participant.isVip,
                      isHost: participant.isHost,
                      speaking: participant.speaking,
                      level: participant.level,
                      avatarUrl: participant.avatarUrl,
                    );
                  },
                ),
              ),
            );
          },
        );
      },
    );
  }

  List<_HostModerationParticipant> _hostModerationParticipants() {
    final room = _room;
    if (room == null) return const <_HostModerationParticipant>[];

    final seen = <int>{};
    final participants = <_HostModerationParticipant>[];

    for (final participant in room.remoteParticipants.values) {
      final metadata = _participantMetadata(participant);
      final userId = _safeInt(metadata['user_id']);
      if (userId == null || userId <= 0 || userId == _myUserId) continue;
      if (!seen.add(userId)) continue;

      final isHost =
          participant.identity.startsWith('host-') || metadata['is_host'] == true;
      final isSpeaking = room.activeSpeakers.any(
        (speaker) => speaker.identity == participant.identity,
      );
      participants.add(
        _HostModerationParticipant(
          userId: userId,
          name:
              participant.name.isNotEmpty ? participant.name : participant.identity,
          subtitle: isHost ? 'Host' : 'Participant',
          themeKey: _participantThemeKey(participant),
          isVip: _participantIsVip(participant),
          isHost: isHost,
          speaking: isSpeaking,
          level: _safeInt(metadata['level']),
          avatarUrl:
              metadata['avatar_url']?.toString() ?? metadata['avatar']?.toString(),
          frameUrl: profileFrameAssetUrlFromPayload(metadata),
        ),
      );
    }

    participants.sort((a, b) {
      if (a.isHost != b.isHost) return a.isHost ? -1 : 1;
      if (a.speaking != b.speaking) return a.speaking ? -1 : 1;
      return a.name.toLowerCase().compareTo(b.name.toLowerCase());
    });
    return participants;
  }

  Future<void> _showViewerListSheet() async {
    final participants = _hostModerationParticipants();
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) {
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
                  colors: [
                    tokens.cardGradient.first.withOpacity(.98),
                    tokens.cardGradient.last.withOpacity(.94),
                    tokens.backgroundGradient.last.withOpacity(.96),
                  ],
                ),
                borderRadius: BorderRadius.circular(28),
                border: Border.all(color: tokens.borderColor.withOpacity(.32)),
                boxShadow: [
                  BoxShadow(
                    color: tokens.glowColor.withOpacity(.16),
                    blurRadius: 26,
                    offset: const Offset(0, 12),
                  ),
                ],
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
                        color: tokens.borderColor.withOpacity(.42),
                        borderRadius: BorderRadius.circular(999),
                      ),
                    ),
                  ),
                  const SizedBox(height: 14),
                  Text(
                    'Participants',
                    style: TextStyle(
                      color: tokens.textPrimary,
                      fontWeight: FontWeight.w900,
                      fontSize: 18,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '${participants.length} active participant${participants.length == 1 ? '' : 's'} visible here',
                    style: TextStyle(
                      color: tokens.textSecondary.withOpacity(.88),
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 14),
                  if (participants.isEmpty)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Text(
                        'No participants visible yet.',
                        style: TextStyle(
                          color: tokens.textSecondary.withOpacity(.74),
                        ),
                      ),
                    )
                  else
                    Flexible(
                      child: ListView.separated(
                        shrinkWrap: true,
                        itemCount: participants.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (_, i) {
                          final participant = participants[i];
                          return Material(
                            color: Colors.transparent,
                            child: InkWell(
                              onTap: () {
                                Navigator.of(context).pop();
                                _showParticipantProfileCard(
                                  userId: participant.userId,
                                  name: participant.name,
                                  subtitle: participant.subtitle,
                                  themeKey: participant.themeKey,
                                  isVip: participant.isVip,
                                  isHost: participant.isHost,
                                  speaking: participant.speaking,
                                  level: participant.level,
                                  avatarUrl: participant.avatarUrl,
                                );
                              },
                              borderRadius: BorderRadius.circular(18),
                              child: Container(
                                padding: const EdgeInsets.all(12),
                                decoration: BoxDecoration(
                                  color: tokens.glassColor.withOpacity(.08),
                                  borderRadius: BorderRadius.circular(18),
                                  border: Border.all(
                                    color: tokens.borderColor.withOpacity(.20),
                                  ),
                                ),
                                child: Row(
                                  children: [
                                    SizedBox(
                                      width: 46,
                                      height: 46,
                                      child: FramedAvatar(
                                        avatarUrl: participant.avatarUrl,
                                        frameUrl: participant.frameUrl,
                                        label: participant.name,
                                        size: 46,
                                        backgroundColor:
                                            tokens.primaryButtonGradient.first,
                                      ),
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          Text(
                                            participant.name,
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                            style: TextStyle(
                                              color: tokens.textPrimary,
                                              fontWeight: FontWeight.w900,
                                            ),
                                          ),
                                          const SizedBox(height: 3),
                                          Text(
                                            participant.userId > 0
                                                ? '${participant.subtitle} • ID: ${participant.userId}'
                                                : participant.subtitle,
                                            style: TextStyle(
                                              color: tokens.textSecondary
                                                  .withOpacity(.84),
                                              fontWeight: FontWeight.w700,
                                              fontSize: 12,
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                    Icon(
                                      Icons.chevron_right_rounded,
                                      color: tokens.textSecondary.withOpacity(
                                        .74,
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
              ),
            ),
          ),
        );
      },
    );
  }

  void _bindSeatEvents() {
    if (!Get.isRegistered<RoomsSocketService>()) return;
    _seatEventsSub?.cancel();
    _seatEventsSub = Get.find<RoomsSocketService>().seatEvents.listen((
      event,
    ) async {
      if (!mounted) return;
      if ((event['room_id'] ?? '').toString() != widget.room.roomId) return;
      final eventName = (event['event'] ?? '').toString();
      final userId = _safeInt(event['user_id']);
      final rawUpdatedAt = event['updated_at']?.toString();
      final eventUpdatedAt =
          rawUpdatedAt == null ? null : DateTime.tryParse(rawUpdatedAt);
      if (eventUpdatedAt != null &&
          _lastSeatEventAt != null &&
          eventUpdatedAt.isBefore(_lastSeatEventAt!)) {
        return;
      }
      if (eventUpdatedAt != null) {
        _lastSeatEventAt = eventUpdatedAt;
      }

      if (userId != null && userId == _myUserId) {
        if (eventName == 'seat:request_created') {
          Haptics.selection();
          setState(() {
            _pendingRequestId = _safeInt(event['request_id']);
            _requestStatus = 'pending';
            _seatError = null;
          });
        } else if (eventName == 'seat:request_rejected' ||
            eventName == 'seat:request_cancelled') {
          if (eventName == 'seat:request_rejected') {
            Haptics.warning();
          }
          setState(() {
            _pendingRequestId = null;
            _requestStatus =
                eventName == 'seat:request_rejected' ? 'rejected' : 'cancelled';
          });
        } else if (eventName == 'seat:request_accepted' ||
            eventName == 'speaker:added') {
          await _activateSpeakerMode();
        } else if (eventName == 'speaker:removed') {
          await _downgradeToViewerMode();
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
      final opponentRoomId = (event['opponent_room_id'] ?? '').toString();
      final touchesCurrentRoom =
          eventRoomId == widget.room.roomId || opponentRoomId == widget.room.roomId;
      if (!touchesCurrentRoom) return;
      if (eventRoomType.isNotEmpty && eventRoomType != expectedRoomType) return;
      final senderId = _safeInt(event['sender_user_id']);
      final senderName = (event['sender_name'] ?? 'Someone').toString();
      final giftName = (event['gift_name'] ?? 'a gift').toString();
      final quantity = _safeInt(event['quantity']) ?? 1;
      final inferredPkSide =
          _pkActive
              ? (_normalizePkGiftSide(event['pk_side'] ?? event['pkSide']) ??
                  _inferPkGiftSideFromRoomEvent(eventRoomId))
              : null;
      _recordPkGiftFromEvent(
        event,
        fallbackSide: inferredPkSide,
      );
      _giftAnimationOverlay.handleSocketGiftEvent(
        event,
        currentThemeKey:
            Get.find<AppSettingsService>().activePremiumThemeVariant,
        receiverFallbackId:
            _safeInt(widget.room.meta?['host_user_id']) ??
            _safeInt(widget.room.meta?['host_id']),
        currentUserId: _myUserId,
        inferredPkSide: inferredPkSide,
      );
      _recentGiftTimer?.cancel();
      setState(() {
        _recentGiftMessage = '$senderName sent $giftName x$quantity';
      });
      _recentGiftTimer = Timer(const Duration(seconds: 4), () {
        if (mounted) {
          setState(() => _recentGiftMessage = null);
        }
      });
      if (senderId == _myUserId) {
        Haptics.success();
      }
    });
  }

  String? _inferPkGiftSideFromRoomEvent(String eventRoomId) {
    final battle = _pkBattle;
    if (battle == null || !battle.isActive || eventRoomId.isEmpty) return null;
    final ownRoomId = battle.ownRoomFor(widget.room.roomId)?['id']?.toString();
    final opponentRoomId =
        battle.opponentRoomFor(widget.room.roomId)?['id']?.toString();
    if (eventRoomId == ownRoomId) return 'left';
    if (eventRoomId == opponentRoomId) return 'right';
    return null;
  }

  String _normalizeGiftRoomType(dynamic value) {
    final normalized = value?.toString().trim().toLowerCase() ?? '';
    if (normalized == 'audio' || normalized == 'audio_room') return 'audio';
    if (normalized == 'video' || normalized == 'video_room') return 'video';
    return normalized;
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
      final userId = _safeInt(event['user_id']);
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
        level: _safeInt(event['level']),
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

  void _bindRoomLifecycleEvents() {
    if (!Get.isRegistered<RoomsSocketService>()) return;
    _roomLifecycleSub?.cancel();
    _roomLifecycleSub = Get.find<RoomsSocketService>().roomLifecycleEvents
        .listen((event) async {
          if (!mounted || _exiting) return;
          if ((event['room_id'] ?? '').toString() != widget.room.roomId) return;

          final room = event['room'];
          final reason =
              room is Map
                  ? (room['end_reason']?.toString() ??
                      event['event']?.toString() ??
                      'ended')
                  : (event['event']?.toString() ?? 'ended');

          await _exitBecauseRoomEnded(reason);
        });
  }

  void _bindPkEvents() {
    if (!_pkCapable || !Get.isRegistered<RoomsSocketService>()) return;
    _pkEventsSub?.cancel();
    _pkEventsSub = Get.find<RoomsSocketService>().pkEvents.listen((
      event,
    ) async {
      if (!mounted) return;
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
      if (!touchesRoom) return;

      final eventName = (event['event'] ?? '').toString();
      final model = LivePkBattleModel.fromJson(
        Map<String, dynamic>.from(event),
      );

      final invitedRoomId = roomB['id']?.toString();
      final invitedHost =
          (event['host_b'] is Map)
              ? Map<String, dynamic>.from(event['host_b'] as Map)
              : const <String, dynamic>{};
      final invitedHostUserId = _safeInt(invitedHost['user_id']);

      if (eventName == 'pk:invite_received' &&
          _isHost &&
          _isIncomingPkInviteForThisHost(
            battle: model,
            invitedRoomId: invitedRoomId,
            invitedHostUserId: invitedHostUserId,
          )) {
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

  void _bindSocketConnectionEvents() {
    if (!_pkCapable || !Get.isRegistered<RoomsSocketService>()) return;
    _socketConnectionSub?.cancel();
    _socketConnectionSub = Get.find<RoomsSocketService>().connectionEvents
        .listen((event) async {
          final name = (event['event'] ?? '').toString();
          if (name == 'connect' || name == 'reconnect') {
            await _syncPkState();
          }
        });
  }

  Future<void> _exitBecauseRoomEnded(String reason) async {
    if (_exiting) return;
    _exiting = true;
    _giftAnimationOverlay.clear();
    await _leaveSessionOnce();
    _leaveSocketRoom();
    _hudTimer?.cancel();
    _heartbeatTimer?.cancel();
    _noFrameWatchdog?.cancel();
    _seatEventsSub?.cancel();
    _giftEventsSub?.cancel();
    _roomLifecycleSub?.cancel();
    _pkEventsSub?.cancel();
    _socketConnectionSub?.cancel();
    await _disconnectOpponentRoom();
    try {
      await _room?.disconnect();
    } catch (_) {}
    if (mounted) {
      Get.closeAllSnackbars();
      _closeTransientOverlays();
      Get.snackbar(
        'Live Room',
        _roomEndedMessage(reason),
        snackPosition: SnackPosition.BOTTOM,
        duration: const Duration(seconds: 3),
      );
      _popLivePage();
    }
  }

  Future<void> _handleModerationTargetExit({
    required bool blocked,
    required String message,
  }) async {
    if (_exiting) return;
    _exiting = true;
    _giftAnimationOverlay.clear();
    await _leaveSessionOnce();
    _leaveSocketRoom();
    _hudTimer?.cancel();
    _heartbeatTimer?.cancel();
    _noFrameWatchdog?.cancel();
    await _disconnectOpponentRoom();
    try {
      await _room?.disconnect();
    } catch (_) {}
    if (mounted) {
      Get.closeAllSnackbars();
      _closeTransientOverlays();
      Get.snackbar(
        'Moderation',
        message,
        snackPosition: SnackPosition.BOTTOM,
        duration: const Duration(seconds: 3),
      );
      _popLivePage();
    }
  }

  void _closeTransientOverlays() {
    while ((Get.isBottomSheetOpen ?? false) || (Get.isDialogOpen ?? false)) {
      Get.back();
    }
  }

  void _popLivePage() {
    if (!mounted) return;
    Get.offAllNamed(Routes.home);
  }

  String _roomEndedMessage(String reason) {
    switch (reason) {
      case 'host_joined_private_call':
        return 'Live room ended because the host joined a private call.';
      case 'host_ended':
        return 'The host ended the live room.';
      case 'host_disconnected':
        return 'The host disconnected. Live room ended.';
      case 'admin_force_end':
        return 'This live room was ended by admin.';
      default:
        return 'This live room has ended.';
    }
  }

  Future<void> _loadGiftCatalog() async {
    if (widget.devMode) {
      if (mounted) {
        setState(() {
          _availableGifts = LiveRoomDevFixtures.mockGiftCatalog();
          _giftError = null;
        });
      }
      return;
    }
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
      setState(() {
        _availableGifts = gifts;
        _giftError = null;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _giftError = e.toString().replaceFirst('Exception: ', ''));
    }
  }

  Future<void> _refreshWalletBalance() async {
    try {
      final balance = (await Get.find<WalletApi>().fetchSummary()).balance;
      if (!mounted) return;
      setState(() => _walletBalanceCoins = balance);
    } catch (_) {}
  }

  Future<void> _refreshSeatSnapshot() async {
    try {
      final snapshot = await widget.live.seatSnapshot(widget.room.roomId);
      final data = Map<String, dynamic>.from(
        snapshot['data'] ?? snapshot['snapshot'] ?? snapshot,
      );
      if (!mounted) return;

      final requests =
          (data['requests'] as List? ?? const [])
              .map((row) => Map<String, dynamic>.from(row as Map))
              .toList();
      final speakers =
          (data['speakers'] as List? ?? const [])
              .map((row) => Map<String, dynamic>.from(row as Map))
              .toList();

      final myRequest =
          _myUserId == null
              ? null
              : requests
                  .where((row) => _safeInt(row['user_id']) == _myUserId)
                  .cast<Map<String, dynamic>>()
                  .fold<Map<String, dynamic>?>(null, (latest, row) {
                    final latestId =
                        _safeInt(latest?['request_id'] ?? latest?['id']) ?? -1;
                    final rowId =
                        _safeInt(row['request_id'] ?? row['id']) ?? -1;
                    return rowId > latestId ? row : latest;
                  });
      final myRequestStatus = myRequest?['status']?.toString();
      final myPendingRequestId =
          myRequestStatus == 'pending'
              ? _safeInt(myRequest?['request_id'] ?? myRequest?['id'])
              : null;
      final amSpeaker =
          _myUserId != null &&
          speakers.any((row) => _safeInt(row['user_id']) == _myUserId);
      final shouldPromoteToSpeaker =
          !_isHost &&
          !_speakerTransitionBusy &&
          amSpeaker &&
          _currentRole != 'speaker';
      final shouldDowngradeToViewer =
          !_isHost &&
          !_speakerTransitionBusy &&
          !amSpeaker &&
          _currentRole == 'speaker';

      setState(() {
        _pendingRequests =
            requests
                .where((row) => (row['status'] ?? '') == 'pending')
                .toList();
        _speakers = speakers;
        _speakerCount =
            _safeInt(data['speaker_count']) ??
            speakers.length + (_isHost ? 1 : 0);
        _maxSpeakers =
            _safeInt(data['max_speakers']) ?? widget.room.maxSpeakers;
        _pendingRequestId = myPendingRequestId;
        _requestStatus = myRequestStatus;
        _seatError = null;
      });
      if (shouldPromoteToSpeaker) {
        await _activateSpeakerMode();
      } else if (shouldDowngradeToViewer) {
        await _downgradeToViewerMode();
      }
    } catch (e) {
      if (!mounted) return;
      setState(() => _seatError = e.toString().replaceFirst('Exception: ', ''));
    }
  }

  Future<void> _activateSpeakerMode() async {
    if (_speakerTransitionBusy || _currentRole == 'speaker') return;
    _speakerTransitionBusy = true;
    final room = _room;
    if (room == null) {
      if (!mounted) return;
      setState(() {
        _currentRole = 'speaker';
        _requestStatus = 'accepted';
        _pendingRequestId = null;
        _seatError = null;
      });
      _speakerTransitionBusy = false;
      return;
    }
    try {
      await room.localParticipant?.setCameraEnabled(
        true,
        cameraCaptureOptions: const CameraCaptureOptions(
          cameraPosition: CameraPosition.front,
        ),
      );
      await room.localParticipant?.setMicrophoneEnabled(true);
      await _waitForLocalTrack(room, timeoutMs: 1000);
      await _attachLocalPreview(room);
      if (!mounted) return;
      setState(() {
        _currentRole = 'speaker';
        _camOn = true;
        _micOn = true;
        _pendingRequestId = null;
        _requestStatus = 'accepted';
        _seatError = null;
      });
      Haptics.success();
    } catch (e) {
      if (!mounted) return;
      setState(() => _seatError = 'Unable to enable speaker media: $e');
    } finally {
      _speakerTransitionBusy = false;
    }
  }

  Future<void> _downgradeToViewerMode() async {
    if (_speakerTransitionBusy || _currentRole == 'viewer') return;
    _speakerTransitionBusy = true;
    final room = _room;
    if (room == null) {
      if (!mounted) return;
      setState(() {
        _currentRole = 'viewer';
        _camOn = false;
        _micOn = false;
        _pendingRequestId = null;
        _requestStatus = 'removed';
      });
      _speakerTransitionBusy = false;
      return;
    }
    try {
      await room.localParticipant?.setCameraEnabled(false);
      await room.localParticipant?.setMicrophoneEnabled(false);
      await _detachPreview();
    } catch (_) {}
    if (!mounted) return;
    setState(() {
      _currentRole = 'viewer';
      _camOn = false;
      _micOn = false;
      _pendingRequestId = null;
      _requestStatus = 'removed';
    });
    _speakerTransitionBusy = false;
  }

  Future<void> _requestToJoinAsSpeaker() async {
    if (_seatActionBusy || _pendingRequestId != null || !_isViewerOnly) return;
    setState(() {
      _seatActionBusy = true;
      _seatError = null;
    });
    try {
      final res = await widget.live.requestSpeaker(widget.room.roomId);
      final snapshot = Map<String, dynamic>.from(res['snapshot'] ?? const {});
      final requests =
          (snapshot['requests'] as List? ?? const [])
              .map((row) => Map<String, dynamic>.from(row as Map))
              .toList();
      final myRequest = requests
          .where((row) => _safeInt(row['user_id']) == _myUserId)
          .cast<Map<String, dynamic>?>()
          .fold<Map<String, dynamic>?>(null, (latest, row) {
            final latestId =
                _safeInt(latest?['request_id'] ?? latest?['id']) ?? -1;
            final rowId = _safeInt(row?['request_id'] ?? row?['id']) ?? -1;
            return rowId > latestId ? row : latest;
          });
      setState(() {
        _pendingRequestId = _safeInt(
          res['request_id'] ?? myRequest?['request_id'] ?? myRequest?['id'],
        );
        _requestStatus = 'pending';
      });
      Haptics.medium();
      await _refreshSeatSnapshot();
    } catch (e) {
      if (!mounted) return;
      setState(() => _seatError = e.toString().replaceFirst('Exception: ', ''));
    } finally {
      if (mounted) {
        setState(() => _seatActionBusy = false);
      }
    }
  }

  Future<void> _cancelJoinRequest() async {
    final requestId = _pendingRequestId;
    if (_seatActionBusy || requestId == null) return;
    setState(() {
      _seatActionBusy = true;
      _seatError = null;
    });
    try {
      await widget.live.cancelSpeakerRequest(widget.room.roomId, requestId);
      setState(() {
        _pendingRequestId = null;
        _requestStatus = 'cancelled';
      });
      Haptics.light();
      await _refreshSeatSnapshot();
    } catch (e) {
      if (!mounted) return;
      setState(() => _seatError = e.toString().replaceFirst('Exception: ', ''));
    } finally {
      if (mounted) {
        setState(() => _seatActionBusy = false);
      }
    }
  }

  Future<void> _acceptSeatRequest(int requestId) async {
    if (_seatActionBusy) return;
    setState(() {
      _seatActionBusy = true;
      _seatError = null;
    });
    try {
      await widget.live.acceptSpeakerRequest(widget.room.roomId, requestId);
      Haptics.success();
      await _refreshSeatSnapshot();
    } catch (e) {
      if (!mounted) return;
      setState(() => _seatError = e.toString().replaceFirst('Exception: ', ''));
    } finally {
      if (mounted) {
        setState(() => _seatActionBusy = false);
      }
    }
  }

  Future<void> _rejectSeatRequest(int requestId) async {
    if (_seatActionBusy) return;
    setState(() {
      _seatActionBusy = true;
      _seatError = null;
    });
    try {
      await widget.live.rejectSpeakerRequest(widget.room.roomId, requestId);
      Haptics.warning();
      await _refreshSeatSnapshot();
    } catch (e) {
      if (!mounted) return;
      setState(() => _seatError = e.toString().replaceFirst('Exception: ', ''));
    } finally {
      if (mounted) {
        setState(() => _seatActionBusy = false);
      }
    }
  }

  Future<void> _removeSpeaker(int userId) async {
    if (_seatActionBusy) return;
    setState(() {
      _seatActionBusy = true;
      _seatError = null;
    });
    try {
      await widget.live.removeSpeaker(widget.room.roomId, userId);
      Haptics.medium();
      await _refreshSeatSnapshot();
    } catch (e) {
      if (!mounted) return;
      setState(() => _seatError = e.toString().replaceFirst('Exception: ', ''));
    } finally {
      if (mounted) {
        setState(() => _seatActionBusy = false);
      }
    }
  }

  Future<void> _openGiftSheet() async {
    if (!Get.find<AppSettingsService>().giftsEnabled) {
      if (!mounted) return;
      setState(() => _giftError = 'Gifts are currently unavailable.');
      return;
    }
    if (_giftBusy) return;
    setState(() {
      _giftError = null;
    });

    try {
      if (_availableGifts.isEmpty) {
        await _loadGiftCatalog();
      }
      if (!mounted) return;
      if (_availableGifts.isEmpty) {
        setState(() => _giftError = 'No gifts available right now.');
        return;
      }

      final selection = await LiveRoomGiftSheet.show(
        context,
        gifts: _availableGifts,
        balanceCoins: _walletBalanceCoins,
      );
      if (selection == null) return;

      setState(() => _giftBusy = true);
      _giftAnimationOverlay.showLocalSenderFeedback(
        giftName: selection.gift.name,
        currentThemeKey: Get.find<AppSettingsService>().activePremiumThemeVariant,
      );
      if (widget.devMode) {
        _mockDevGiftToSide(_pkActive ? 'left' : 'left');
      } else {
        await widget.live.sendRoomGift(
          widget.room.roomId,
          giftId: selection.gift.id,
          quantity: selection.quantity,
        );
      }
      if (mounted) {
        setState(() {
          if (_walletBalanceCoins != null) {
            final spend = selection.gift.coins * selection.quantity;
            final nextBalance = _walletBalanceCoins! - spend;
            _walletBalanceCoins = nextBalance < 0 ? 0 : nextBalance;
          }
        });
      }
      Haptics.success();
      if (!mounted) return;
      setState(() => _giftError = null);
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
      if (mounted) {
        setState(() => _giftBusy = false);
      }
    }
  }

  Future<void> _syncPkState({Map<String, dynamic>? prefill}) async {
    if (!_pkCapable) return;

    LivePkBattleModel? battle;
    if (prefill != null && prefill.isNotEmpty && prefill['battle_id'] != null) {
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
      final endedBattle = _pkBattle;
      setState(() {
        _pkBattle = null;
        _incomingPkInvite =
            battle != null && _isIncomingPkInviteForThisHost(battle: battle)
                ? battle
                : null;
        _clearPkGiftLeaders();
      });
      _pkExpiryWatcher?.cancel();
      _pkExpiryActionInFlight = false;
      await _disconnectOpponentRoom();
      if (endedBattle != null && battle != null && battle.isTerminal) {
        _showPkResult(battle);
      }
      return;
    }

    setState(() {
      _pkBattle = battle;
      _incomingPkInvite = null;
      _primePkGiftLeadersForBattle(battle);
    });
    _ensurePkExpiryWatcher();
    await _ensureOpponentRoomConnected(forceRefresh: false);
  }

  void _ensurePkExpiryWatcher() {
    _pkExpiryWatcher ??= Timer.periodic(const Duration(seconds: 1), (_) async {
      final battle = _pkBattle;
      if (!mounted || battle == null || !battle.isActive) {
        _pkExpiryWatcher?.cancel();
        _pkExpiryWatcher = null;
        _pkExpiryActionInFlight = false;
        return;
      }
      if (battle.remainingSeconds > 0 || _pkExpiryActionInFlight || _pkBusy) {
        return;
      }
      _pkExpiryActionInFlight = true;
      try {
        if (_isHost) {
          await _endPkBattle();
        } else {
          await _syncPkState();
        }
      } finally {
        _pkExpiryActionInFlight = false;
      }
    });
  }

  void _primePkGiftLeadersForBattle(LivePkBattleModel? battle) {
    final battleId = battle?.battleId;
    if (battleId == null || battleId.isEmpty) {
      _clearPkGiftLeaders();
      return;
    }
    if (_pkGiftLeadersBattleId == battleId) return;
    _pkGiftLeadersBattleId = battleId;
    _pkGiftLeadersBySide = <String, Map<int, _PkSupporterStanding>>{
      'left': <int, _PkSupporterStanding>{},
      'right': <int, _PkSupporterStanding>{},
    };
  }

  void _clearPkGiftLeaders() {
    _pkGiftLeadersBattleId = null;
    _pkGiftLeadersBySide = <String, Map<int, _PkSupporterStanding>>{
      'left': <int, _PkSupporterStanding>{},
      'right': <int, _PkSupporterStanding>{},
    };
  }

  void _recordPkGiftFromEvent(
    Map<String, dynamic> event, {
    String? fallbackSide,
  }) {
    final battle = _pkBattle;
    if (battle == null || !battle.isActive) return;
    _primePkGiftLeadersForBattle(battle);
    final side = _normalizePkGiftSide(event['pk_side'] ?? event['pkSide'] ?? fallbackSide);
    if (side == null) return;
    final senderId =
        _safeInt(event['sender_user_id'] ?? event['senderId']) ??
        _safeInt(event['user_id']);
    if (senderId == null) return;
    final senderName = (event['sender_name'] ?? event['senderName'] ?? 'Someone')
        .toString()
        .trim();
    final avatarUrl =
        (event['sender_avatar'] ?? event['senderAvatar'])?.toString().trim();
    final quantity = (_safeInt(event['quantity']) ?? 1).clamp(1, 9999);
    final coinsPerUnit = _safeInt(event['coins_per_unit'] ?? event['coinsPerUnit']) ?? 0;
    final totalCoins =
        _safeInt(event['totalCoins'] ?? event['total_coins']) ??
        (coinsPerUnit * quantity);
    if (totalCoins <= 0) return;
    final sideMap = Map<int, _PkSupporterStanding>.from(
      _pkGiftLeadersBySide[side] ?? const <int, _PkSupporterStanding>{},
    );
    final current = sideMap[senderId];
    sideMap[senderId] = _PkSupporterStanding(
      senderId: senderId,
      senderName: senderName.isEmpty ? 'Someone' : senderName,
      totalCoins: (current?.totalCoins ?? 0) + totalCoins,
      avatarUrl:
          avatarUrl != null && avatarUrl.isNotEmpty
              ? avatarUrl
              : current?.avatarUrl,
    );
    if (!mounted) return;
    setState(() {
      _pkGiftLeadersBySide = <String, Map<int, _PkSupporterStanding>>{
        ..._pkGiftLeadersBySide,
        side: sideMap,
      };
    });
  }

  String? _normalizePkGiftSide(dynamic raw) {
    final normalized = raw?.toString().trim().toLowerCase() ?? '';
    if (normalized.isEmpty) return null;
    if (normalized == 'left' ||
        normalized == 'own' ||
        normalized == 'self' ||
        normalized == 'a' ||
        normalized == 'team_a') {
      return 'left';
    }
    if (normalized == 'right' ||
        normalized == 'opponent' ||
        normalized == 'other' ||
        normalized == 'b' ||
        normalized == 'team_b') {
      return 'right';
    }
    return null;
  }

  List<_PkSupporterStanding> _topPkSupportersFor(String side) {
    final items = (_pkGiftLeadersBySide[side] ?? const <int, _PkSupporterStanding>{})
        .values
        .toList()
      ..sort((a, b) {
        final byCoins = b.totalCoins.compareTo(a.totalCoins);
        if (byCoins != 0) return byCoins;
        return a.senderName.toLowerCase().compareTo(b.senderName.toLowerCase());
      });
    return items.take(3).toList(growable: false);
  }

  void _showPkResult(LivePkBattleModel battle) {
    final myRoomId = widget.room.roomId;
    String title;
    int winnerSide = 0;
    String? winnerName;
    String? winnerAvatarUrl;
    List<PkWinnerSupporter> topSupporters = const <PkWinnerSupporter>[];
    if (battle.winnerRoomId == null) {
      title = 'PK Draw';
    } else if (battle.winnerRoomId == myRoomId) {
      title = 'Your Side Won';
      winnerSide = 1;
      final winnerHost = battle.ownHostFor(widget.room.roomId);
      winnerName = winnerHost?['name']?.toString();
      winnerAvatarUrl =
          winnerHost?['avatar_url']?.toString() ?? winnerHost?['avatar']?.toString();
      topSupporters =
          _topPkSupportersFor('left')
              .map(
                (supporter) => PkWinnerSupporter(
                  userId: supporter.senderId,
                  name: supporter.senderName,
                  coins: supporter.totalCoins,
                  avatarUrl: supporter.avatarUrl,
                ),
              )
              .toList(growable: false);
    } else {
      title = 'Opponent Won';
      winnerSide = -1;
      final winnerHost = battle.opponentHostFor(widget.room.roomId);
      winnerName = winnerHost?['name']?.toString();
      winnerAvatarUrl =
          winnerHost?['avatar_url']?.toString() ?? winnerHost?['avatar']?.toString();
      topSupporters =
          _topPkSupportersFor('right')
              .map(
                (supporter) => PkWinnerSupporter(
                  userId: supporter.senderId,
                  name: supporter.senderName,
                  coins: supporter.totalCoins,
                  avatarUrl: supporter.avatarUrl,
                ),
              )
              .toList(growable: false);
    }
    final subtitle =
        battle.endReason == 'timer_expired'
            ? 'Battle finished when the PK timer ended.'
            : 'PK battle ended.';
    _pkOverlayTimer?.cancel();
    setState(() {
      _pkOverlayTitle = title;
      _pkOverlaySubtitle = subtitle;
      _pkOverlayWinnerSide = winnerSide;
      _pkOverlayWinnerName = winnerName;
      _pkOverlayWinnerAvatarUrl = winnerAvatarUrl;
      _pkOverlayTopSupporters = topSupporters;
    });
    _pkOverlayTimer = Timer(const Duration(seconds: 4), () {
      if (mounted) {
        setState(() {
          _pkOverlayTitle = null;
          _pkOverlaySubtitle = null;
          _pkOverlayWinnerSide = 0;
          _pkOverlayWinnerName = null;
          _pkOverlayWinnerAvatarUrl = null;
          _pkOverlayTopSupporters = const <PkWinnerSupporter>[];
        });
      }
    });
  }

  Future<void> _ensureOpponentRoomConnected({
    required bool forceRefresh,
  }) async {
    final battle = _pkBattle;
    if (battle == null || !battle.isActive || _opponentConnecting) return;
    if (widget.devMode) {
      if (mounted) {
        setState(() => _opponentMediaUnavailable = true);
      }
      return;
    }
    if (!forceRefresh && _opponentRoom != null) return;

    _opponentConnecting = true;
    try {
      await _disconnectOpponentRoom();
      final payload = await widget.live.pkMediaToken(
        widget.room.roomId,
        battle.battleId,
      );
      final token = payload['opponent_token']?.toString();
      final roomId = payload['opponent_room_id']?.toString();
      if (token == null || token.isEmpty || roomId == null || roomId.isEmpty) {
        if (mounted) setState(() => _opponentMediaUnavailable = true);
        return;
      }

      final wsUrl = widget.room.wsUrl?.trim();
      if (wsUrl == null || wsUrl.isEmpty) {
        if (mounted) setState(() => _opponentMediaUnavailable = true);
        return;
      }

      final room = Room(
        roomOptions: const RoomOptions(adaptiveStream: true, dynacast: true),
        connectOptions: const ConnectOptions(autoSubscribe: true),
      );
      final listener = room.createListener();
      listener.on<ParticipantConnectedEvent>((_) {
        if (mounted) setState(() {});
      });
      listener.on<ParticipantDisconnectedEvent>((_) {
        if (mounted) setState(() {});
      });
      listener.on<TrackSubscribedEvent>((_) {
        if (mounted) {
          setState(() => _opponentMediaUnavailable = false);
        }
      });
      listener.on<TrackUnsubscribedEvent>((_) {
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

  Participant? _primaryHostParticipant() {
    if (_isHost) return _room?.localParticipant;
    for (final participant
        in _room?.remoteParticipants.values ?? const <RemoteParticipant>[]) {
      if (participant.identity.startsWith('host-')) return participant;
    }
    return null;
  }

  Participant? _opponentHostParticipant() {
    final battle = _pkBattle;
    final opponentUserId =
        battle?.opponentHostFor(widget.room.roomId)?['user_id'];
    final normalized = _safeInt(opponentUserId);
    for (final participant
        in _opponentRoom?.remoteParticipants.values ??
            const <RemoteParticipant>[]) {
      int? userId;
      if (participant.identity.startsWith('host-')) {
        final parts = participant.identity.split('-');
        if (parts.length >= 2) {
          userId = int.tryParse(parts[1]);
        }
      }
      if (normalized != null && userId == normalized) return participant;
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

  Widget _buildPkVideoStage({required double topInset}) {
    final battle = _pkBattle!;
    final ownHost = battle.ownHostFor(widget.room.roomId);
    final opponentHost = battle.opponentHostFor(widget.room.roomId);
    final ownParticipant = _primaryHostParticipant();
    final opponentParticipant = _opponentHostParticipant();
    final opponentTrack =
        opponentParticipant == null
            ? null
            : _firstRemoteVideo(opponentParticipant, excludeScreenshare: true);
    final ownTrack =
        ownParticipant == null || ownParticipant is LocalParticipant
            ? null
            : _firstRemoteVideo(ownParticipant, excludeScreenshare: true);

    return LayoutBuilder(
      builder: (context, constraints) {
        final stageTop = topInset.clamp(0.0, constraints.maxHeight);
        final availableHeight = math.max(0.0, constraints.maxHeight - stageTop);
        final stageHeight = availableHeight * 0.4;
        final railHeight = math.min(52.0, availableHeight * 0.08);
        const railGap = 0.0;
        final leadersTop = stageTop + stageHeight + railHeight + railGap;
        final leadersHeight = availableHeight * 0.1;
        final showPkGiftRow = !_isHost;
        final giftRowTop = stageTop + stageHeight - 64;
        final chatStart = leadersTop + leadersHeight;
        final ownScore = battle.ownScoreFor(widget.room.roomId);
        final opponentScore = battle.opponentScoreFor(widget.room.roomId);
        final totalScore = math.max(1, ownScore + opponentScore);
        final ownFraction = ownScore / totalScore;
        final opponentFraction = opponentScore / totalScore;
        final leadSide = ownScore == opponentScore ? 0 : (ownScore > opponentScore ? 1 : -1);
        final pkDangerMode = battle.remainingSeconds > 0 && battle.remainingSeconds <= 10;
        return Stack(
          children: [
            Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [
                      const Color(0xFF111522),
                      const Color(0xFF0C1019),
                      const Color(0xFF070A11),
                    ],
                  ),
                ),
              ),
            ),
            Positioned(
              left: 0,
              right: 0,
              top: chatStart,
              bottom: 0,
              child: IgnorePointer(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [
                        const Color(0xFF0C1018).withOpacity(.84),
                        const Color(0xFF090C14).withOpacity(.94),
                        const Color(0xFF06080E),
                      ],
                    ),
                    borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
                  ),
                ),
              ),
            ),
            Positioned(
              left: 0,
              right: 0,
              top: stageTop,
              height: stageHeight,
              child: PkBattleOverlay(
                battle: battle,
                ownLabel:
                    (ownHost?['name']?.toString().isNotEmpty == true
                        ? ownHost!['name'].toString()
                        : _hostDisplayName),
                opponentLabel: opponentHost?['name']?.toString() ?? 'Opponent',
                ownAvatarUrl:
                    ownHost?['avatar_url']?.toString() ??
                    ownHost?['avatar']?.toString(),
                opponentAvatarUrl:
                    opponentHost?['avatar_url']?.toString() ??
                    opponentHost?['avatar']?.toString(),
                ownScore: ownScore,
                opponentScore: opponentScore,
                opponentUnavailable:
                    _opponentConnecting || _opponentMediaUnavailable,
                canEnd: _isHost,
                onEnd: _isHost ? _endPkBattle : null,
                showEmbeddedRail: false,
                ownChild: KeyedSubtree(
                  key: _giftAnchors.keyFor(GiftAnchorRegistry.pkLeft),
                  child: ThemedRoomFrame(
                    themeKey: _pkHostThemeKey(ownHost),
                    isHost: false,
                    isVip: false,
                    isSpeaking:
                        ownParticipant != null &&
                        (_isHost
                            ? _localSpeaking
                            : _room?.activeSpeakers.any(
                                  (speaker) =>
                                      speaker.identity == ownParticipant.identity,
                                ) ??
                                false),
                    isPkWinner: battle.winnerRoomId == widget.room.roomId,
                    borderRadius: 22,
                    child:
                        _isHost
                            ? (_renderer.srcObject != null
                                ? RTCVideoView(
                                  _renderer,
                                  mirror: _frontFacing,
                                  objectFit:
                                      RTCVideoViewObjectFit.RTCVideoViewObjectFitCover,
                                )
                                : _PkVideoFallback(
                                  name:
                                      ownHost?['name']?.toString() ??
                                      _hostDisplayName,
                                  subtitle: 'Your camera',
                                  showSubtitle: true,
                                ))
                            : (ownTrack != null
                                ? VideoTrackRenderer(
                                  ownTrack,
                                  fit: VideoViewFit.cover,
                                )
                                : _PkVideoFallback(
                                  name:
                                      ownHost?['name']?.toString() ??
                                      _hostDisplayName,
                                  subtitle: 'Host video unavailable',
                                )),
                  ),
                ),
                opponentChild: KeyedSubtree(
                  key: _giftAnchors.keyFor(GiftAnchorRegistry.pkRight),
                  child: ThemedRoomFrame(
                    themeKey: _pkHostThemeKey(opponentHost),
                    isHost: false,
                    isVip: false,
                    isSpeaking: _isOpponentSpeaking(),
                    isPkWinner:
                        battle.winnerRoomId != null &&
                        battle.winnerRoomId != widget.room.roomId,
                    borderRadius: 22,
                    child:
                        opponentTrack != null
                            ? VideoTrackRenderer(
                              opponentTrack,
                              fit: VideoViewFit.cover,
                            )
                            : _PkVideoFallback(
                              name:
                                  opponentHost?['name']?.toString() ?? 'Opponent',
                              subtitle:
                                  _opponentConnecting
                                      ? 'Connecting…'
                                      : 'Opponent video unavailable',
                            ),
                  ),
                ),
              ),
            ),
            Positioned(
              left: 0,
              right: 0,
              top: stageTop + stageHeight + railGap,
              height: railHeight,
              child: IgnorePointer(
                child: PkBattleRail(
                  ownScore: ownScore,
                  opponentScore: opponentScore,
                  ownFraction: ownFraction,
                  opponentFraction: opponentFraction,
                  leadSide: leadSide,
                  leadStreak: leadSide == 0 ? 0 : 1,
                  scoreBurstSide: leadSide,
                  scoreBurstValue: 0,
                  dangerMode: pkDangerMode,
                ),
              ),
            ),
            Positioned(
              left: 0,
              right: 0,
              top: leadersTop,
              height: leadersHeight,
              child: _PkTopSupportersBand(
                ownLabel:
                    (ownHost?['name']?.toString().isNotEmpty == true
                        ? ownHost!['name'].toString()
                        : _hostDisplayName),
                opponentLabel: opponentHost?['name']?.toString() ?? 'Opponent',
                ownSupporters: _topPkSupportersFor('left'),
                opponentSupporters: _topPkSupportersFor('right'),
                onSupporterTap: _openPkSupporterProfile,
              ),
            ),
            if (showPkGiftRow)
              Positioned(
                left: 0,
                right: 0,
                top: giftRowTop,
                child: IgnorePointer(
                  ignoring: false,
                  child: KeyedSubtree(
                    key: _giftAnchors.keyFor(GiftAnchorRegistry.giftButton),
                    child: _PkGiftActionRow(
                      busy: _giftBusy,
                      onTap: _giftBusy ? null : _openGiftSheet,
                    ),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  Future<void> _showPkInviteSheet() async {
    if (!_pkCapable || !_isHost || _pkBusy) return;
    setState(() => _pkBusy = true);
    try {
      final rooms = await widget.live.listLiveRooms();
      if (!mounted) return;
      final candidates =
          rooms
              .where(
                (room) =>
                    room.id != widget.room.roomId &&
                    room.status == 'live' &&
                    room.roomType == 'video',
              )
              .toList();
      await showModalBottomSheet<void>(
        context: context,
        backgroundColor: Colors.transparent,
        isScrollControlled: true,
        builder: (context) {
          return SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
              child: Container(
                padding: const EdgeInsets.fromLTRB(18, 16, 18, 18),
                decoration: BoxDecoration(
                  color: const Color(0xFF121118),
                  borderRadius: BorderRadius.circular(28),
                  border: Border.all(color: Colors.white.withOpacity(.10)),
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Start PK Battle',
                      style: TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w900,
                        fontSize: 20,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      candidates.isEmpty
                          ? 'No active host rooms available right now.'
                          : 'Invite another live host into a PK battle.',
                      style: TextStyle(
                        color: Colors.white.withOpacity(.70),
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    const SizedBox(height: 14),
                    if (candidates.isEmpty)
                      const SizedBox.shrink()
                    else
                      Flexible(
                        child: ListView.separated(
                          shrinkWrap: true,
                          itemCount: candidates.length,
                          separatorBuilder:
                              (_, __) => const SizedBox(height: 10),
                          itemBuilder: (_, i) {
                            final room = candidates[i];
                            final hostName =
                                room.hostName?.trim().isNotEmpty == true
                                    ? room.hostName!.trim()
                                    : 'Host';
                            final avatarUrl =
                                room.thumbnail?.trim().isNotEmpty == true
                                    ? room.thumbnail!.trim()
                                    : null;
                            final roomTitle =
                                room.title.trim().isNotEmpty
                                    ? room.title.trim()
                                    : room.id;
                            return InkWell(
                              onTap: () async {
                                Navigator.of(context).pop();
                                await _invitePk(room.id);
                              },
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
                                      backgroundImage:
                                          avatarUrl != null
                                              ? NetworkImage(avatarUrl)
                                              : null,
                                      child:
                                          avatarUrl == null
                                              ? Text(
                                                hostName.isNotEmpty
                                                    ? hostName.characters.first
                                                        .toUpperCase()
                                                    : 'H',
                                              )
                                              : null,
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: [
                                          Text(
                                            hostName,
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                            style: const TextStyle(
                                              color: Colors.white,
                                              fontWeight: FontWeight.w800,
                                            ),
                                          ),
                                          const SizedBox(height: 4),
                                          Text(
                                            '${room.roomType.toUpperCase()} • $roomTitle • ${room.participantCount} in room',
                                            style: TextStyle(
                                              color: Colors.white.withOpacity(
                                                .64,
                                              ),
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                    const Icon(
                                      Icons.sports_martial_arts_rounded,
                                      color: Colors.white70,
                                    ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
                      ),
                  ],
                ),
              ),
            ),
          );
        },
      );
    } catch (e) {
      if (mounted) {
        setState(
          () => _seatError = e.toString().replaceFirst('Exception: ', ''),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _pkBusy = false);
      }
    }
  }

  Future<void> _invitePk(String targetRoomId) async {
    try {
      final battle = await widget.live.invitePk(
        widget.room.roomId,
        targetRoomId: targetRoomId,
      );
      if (!mounted) return;
      setState(() => _incomingPkInvite = null);
      Get.snackbar(
        'PK Invite',
        'PK invite sent successfully.',
        snackPosition: SnackPosition.BOTTOM,
        duration: const Duration(seconds: 2),
      );
    } catch (e) {
      if (mounted) {
        setState(
          () => _seatError = e.toString().replaceFirst('Exception: ', ''),
        );
      }
    }
  }

  bool _isIncomingPkInviteForThisHost({
    required LivePkBattleModel battle,
    String? invitedRoomId,
    int? invitedHostUserId,
  }) {
    if (!_isHost || !battle.isPending) return false;
    final targetRoomId = invitedRoomId ?? battle.roomB?['id']?.toString();
    if (targetRoomId != widget.room.roomId) return false;
    final targetHostUserId =
        invitedHostUserId ?? _safeInt(battle.hostB?['user_id']);
    if (targetHostUserId != null && _myUserId != null) {
      return targetHostUserId == _myUserId;
    }
    return true;
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
      if (mounted) {
        setState(
          () => _seatError = e.toString().replaceFirst('Exception: ', ''),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _pkBusy = false);
      }
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
      if (mounted) {
        setState(
          () => _seatError = e.toString().replaceFirst('Exception: ', ''),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _pkBusy = false);
      }
    }
  }

  /* ===================== Remote helpers ===================== */

  VideoTrack? _firstRemoteVideo(
    Participant p, {
    bool excludeScreenshare = true,
  }) {
    for (final pub in p.trackPublications.values) {
      if (pub.muted) continue;
      final t = pub.track;
      if (t is VideoTrack) {
        if (excludeScreenshare && pub.source == TrackSource.screenShareVideo) {
          continue;
        }
        return t;
      }
    }
    return null;
  }

  List<_StageTileData> _stageTiles() {
    final tiles = <_StageTileData>[];
    final room = _room;
    if (room == null) return tiles;

    if (_canPublishMedia) {
      final localParticipant = room.localParticipant;
      final currentUser = Get.find<AuthService>().currentUser;
      final trimmedCurrentUserName = currentUser?.name.trim() ?? '';
      final localDisplayName =
          _isHost
              ? _hostDisplayName
              : trimmedCurrentUserName.isNotEmpty
              ? trimmedCurrentUserName
              : (_isHost ? _hostDisplayName : 'You');
      final localThemeKey =
          localParticipant != null
              ? _participantThemeKey(localParticipant)
              : Get.find<AppSettingsService>().activePremiumThemeVariant;
      tiles.add(
        _StageTileData(
          tileKey:
              _isHost
                  ? _giftAnchors.keyFor(GiftAnchorRegistry.videoHostTile)
                  : null,
          label: _isHost ? 'Host' : 'You',
          subtitle: _isHost ? 'Host' : 'Participant',
          isLocal: true,
          themeKey: localThemeKey,
          isHost: _isHost,
          isVip:
              localParticipant != null ? _participantIsVip(localParticipant) : false,
          isSpeaking: _localSpeaking,
          userId: _myUserId ?? currentUser?.id,
          avatarUrl: currentUser?.avatarUrl,
          level: currentUser?.level,
          onProfileTap:
              (_myUserId ?? currentUser?.id) == null
                  ? null
                  : () => _showParticipantProfileCard(
                    userId:
                        _myUserId ?? currentUser!.id,
                    name: localDisplayName,
                    subtitle: _isHost ? 'Host' : 'Participant',
                    themeKey: localThemeKey,
                    isVip:
                        localParticipant != null
                            ? _participantIsVip(localParticipant)
                            : false,
                    isHost: _isHost,
                    speaking: _localSpeaking,
                    level: currentUser?.level,
                    avatarUrl: currentUser?.avatarUrl,
                  ),
          child:
              _renderer.srcObject != null
                  ? RTCVideoView(
                    _renderer,
                    mirror: _frontFacing,
                    objectFit: RTCVideoViewObjectFit.RTCVideoViewObjectFitCover,
                  )
                  : _PkVideoFallback(
                    name: localDisplayName,
                    subtitle: _camOn ? 'Camera starting…' : 'Camera off',
                    avatarUrl: currentUser?.avatarUrl,
                    showSubtitle: true,
                  ),
        ),
      );
    }

    for (final participant in room.remoteParticipants.values) {
      final track = _firstRemoteVideo(participant, excludeScreenshare: true);
      final metadata = _participantMetadata(participant);
      final userId = _safeInt(metadata['user_id']);
      final role = metadata['role']?.toString().toLowerCase().trim() ?? '';
      final isHost =
          participant.identity.startsWith('host-') || metadata['is_host'] == true;
      final name =
          isHost
              ? _hostDisplayName
              : (participant.name.isNotEmpty
                  ? participant.name
                  : participant.identity);
      final isVip = _participantIsVip(participant);
      final isSpeaking = room.activeSpeakers.any(
        (speaker) => speaker.identity == participant.identity,
      );
      final isSpeaker = role == 'speaker';
      final isSeatSpeaker =
          userId != null &&
          _speakers.any((row) => _safeInt(row['user_id']) == userId);
      if (!isHost && !isSpeaker && !isSeatSpeaker && !isSpeaking) {
        continue;
      }
      final themeKey = _participantThemeKey(participant);
      final avatarUrl =
          metadata['avatar_url']?.toString() ?? metadata['avatar']?.toString();
      final level = _safeInt(metadata['level']);
      tiles.add(
        _StageTileData(
          tileKey:
              isHost
                  ? _giftAnchors.keyFor(GiftAnchorRegistry.videoHostTile)
                  : null,
          label: name,
          subtitle: isHost ? 'Host' : 'Speaker',
          isLocal: false,
          themeKey: themeKey,
          isHost: isHost,
          isVip: isVip,
          isSpeaking: isSpeaking,
          userId: userId,
          avatarUrl: avatarUrl,
          level: level,
          onProfileTap:
              userId == null
                  ? null
                  : () => _showParticipantProfileCard(
                    userId: userId,
                    name: name,
                    subtitle: isHost ? 'Host' : 'Guest',
                    themeKey: themeKey,
                    isVip: isVip,
                    isHost: isHost,
                    speaking: isSpeaking,
                    level: level,
                    avatarUrl: avatarUrl,
                  ),
          child:
              track != null
                  ? VideoTrackRenderer(track, fit: VideoViewFit.cover)
                  : _PkVideoFallback(
                    name: name,
                    subtitle: 'Camera off',
                    avatarUrl: avatarUrl,
                    showSubtitle: true,
                  ),
        ),
      );
    }

    return tiles;
  }

  List<_StageTileData> _devStageTiles() {
    final rawTiles =
        widget.room.meta?['dev_video_tiles'] as List<dynamic>? ??
        const <dynamic>[];
    if (rawTiles.isEmpty) return const <_StageTileData>[];

    return rawTiles.whereType<Map>().map((entry) {
      final data = Map<String, dynamic>.from(entry);
      final themeKey = normalizePremiumThemeVariant(
        data['theme_key']?.toString() ?? 'midnight',
      );
      final label = data['label']?.toString() ?? 'Guest';
      return _StageTileData(
        label: label,
        subtitle:
            data['is_host'] == true
                ? 'Host'
                : (data['subtitle']?.toString() ?? 'Guest'),
        isLocal: data['is_local'] == true,
        themeKey: themeKey,
        isHost: data['is_host'] == true,
        isVip: data['is_vip'] == true,
        isSpeaking: data['is_speaking'] == true,
        userId: _safeInt(data['user_id']),
        avatarUrl: data['avatar_url']?.toString() ?? data['avatar']?.toString(),
        level: _safeInt(data['level']),
        onProfileTap:
            _safeInt(data['user_id']) == null
                ? null
                : () => _showParticipantProfileCard(
                  userId: _safeInt(data['user_id'])!,
                  name: label,
                  subtitle:
                      data['is_host'] == true
                          ? 'Host'
                          : (data['subtitle']?.toString() ?? 'Guest'),
                  themeKey: themeKey,
                  isVip: data['is_vip'] == true,
                  isHost: data['is_host'] == true,
                  speaking: data['is_speaking'] == true,
                  level: _safeInt(data['level']),
                  avatarUrl:
                      data['avatar_url']?.toString() ??
                      data['avatar']?.toString(),
                ),
        child: _DevVideoTilePlaceholder(
          label: label,
          themeKey: themeKey,
        ),
      );
    }).toList(growable: false);
  }

  /* ===================== UI ===================== */

  @override
  Widget build(BuildContext context) {
    final title = widget.room.title ?? 'Live on Talkieo';
    final media = MediaQuery.of(context);
    final pad = media.padding;
    final isCompactDevice =
        media.size.width < 360 || media.size.height < 760;
    final stageTiles = widget.devMode && _room == null ? _devStageTiles() : _stageTiles();
    final inlineError =
        _seatError ?? _giftError ?? _pkOverlaySubtitle ?? _error;
    final hasTopTicker =
        _recentGiftMessage != null ||
        (_viewerStatusText != null && !_canModerate);
    final topRowHeight = isCompactDevice ? 42.0 : 46.0;
    final topRowGap = isCompactDevice ? 2.0 : 4.0;
    final topTickerHeight = hasTopTicker ? (isCompactDevice ? 38.0 : 42.0) : 0.0;
    final topTickerGap = hasTopTicker ? (isCompactDevice ? 8.0 : 10.0) : 0.0;
    final pkStageTopInset =
        pad.top + topRowGap + topRowHeight + topTickerGap + topTickerHeight;
    final pkAvailableHeight = math.max(0.0, media.size.height - pkStageTopInset);
    final pkStageHeight = pkAvailableHeight * 0.4;
    final pkRailHeight = math.min(52.0, pkAvailableHeight * 0.08);
    final pkLeadersHeight = pkAvailableHeight * 0.1;
    final pkChatTopOffset =
        pkStageTopInset + pkStageHeight + pkRailHeight + pkLeadersHeight;

    return KeepAwakeScope(
      child: Obx(
        () => PopScope(
          canPop: false,
          onPopInvoked: (didPop) {
            if (didPop || _handlingBackNavigation) return;
            unawaited(_handleBackNavigation());
          },
          child: Scaffold(
            backgroundColor: _tokens.backgroundGradient.first,
            body: Stack(
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
              top: -40,
              right: -30,
              child: Container(
                width: 180,
                height: 180,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: _tokens.primaryButtonGradient.first.withOpacity(.12),
                ),
              ),
            ),
            Positioned(
              left: -34,
              bottom: 84,
              child: Container(
                width: 150,
                height: 150,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: _tokens.glowColor.withOpacity(.10),
                ),
              ),
            ),
            Positioned.fill(
              child:
                  (_error != null && !_connecting)
                      ? Center(
                        child: Text(
                          _error!,
                          style: const TextStyle(color: Colors.white),
                        ),
                      )
                      : (_connecting)
                      ? const Center(
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                      : KeyedSubtree(
                        key: _giftAnchors.keyFor(GiftAnchorRegistry.stageCenter),
                        child:
                            _pkCapable && _pkActive
                                ? _buildPkVideoStage(
                                  topInset: pkStageTopInset,
                                )
                                : _DynamicStageGrid(tiles: stageTiles),
                      ),
            ),
            Positioned.fill(
              child: _AnimatedVeil(
                glow: _glow,
                speaking: _canPublishMedia ? _localSpeaking : false,
              ),
            ),
            SafeArea(
              child: Padding(
                padding: EdgeInsets.fromLTRB(
                  isCompactDevice ? 10 : 14,
                  isCompactDevice ? 2 : 4,
                  isCompactDevice ? 10 : 14,
                  0,
                ),
                child: Column(
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: ConstrainedBox(
                            constraints: BoxConstraints(
                              maxWidth:
                                  _pkCapable && _pkActive
                                      ? (isCompactDevice ? 240 : 300)
                                      : double.infinity,
                            ),
                            child: _LiveRoomInfoPill(
                              hostName: _hostDisplayName,
                              hostAvatarUrl: _hostAvatarUrl,
                              hostFrameUrl: _hostProfileFrameUrl,
                              liveLabel: _timerText,
                              participantCount: _viewerCount,
                              onHostTap: _openHostProfileFromPill,
                              onViewerTap: _showViewerListSheet,
                            ),
                          ),
                        ),
                        const SizedBox(width: 8),
                        _TopRightExitPill(
                          label: _isHost ? 'End Live' : 'Leave',
                          accent: _isHost ? Colors.redAccent : null,
                          onTap: () async {
                            if (_isHost) {
                              await _endSession();
                              return;
                            }
                            await _exitViewerSession();
                          },
                        ),
                      ],
                    ),
                    if (_recentGiftMessage != null) ...[
                      SizedBox(height: isCompactDevice ? 8 : 10),
                      _FloatingTickerBanner(
                        icon: Icons.redeem_rounded,
                        message: _recentGiftMessage!,
                        onDismiss:
                            () => setState(() => _recentGiftMessage = null),
                      ),
                    ] else if (_viewerStatusText != null && !_canModerate) ...[
                      SizedBox(height: isCompactDevice ? 8 : 10),
                      _FloatingTickerBanner(
                        icon:
                            _requestStatus == 'pending'
                                ? Icons.hourglass_top_rounded
                                : (_requestStatus == 'accepted'
                                    ? Icons.videocam_rounded
                                    : Icons.info_outline_rounded),
                        message: _viewerStatusText!,
                        accent:
                            _requestStatus == 'rejected' ||
                                    _requestStatus == 'removed'
                                ? Colors.orangeAccent
                                : const Color(0xFF7B50C5),
                        onDismiss: () => setState(() => _requestStatus = null),
                      ),
                    ],
                  ],
                ),
              ),
            ),
            if (inlineError != null && inlineError.isNotEmpty)
              Positioned(
                left: 16,
                right: 16,
                bottom: 82 + pad.bottom,
                child: _InlineErrorBanner(message: inlineError),
              ),
            Positioned.fill(
              child: Obx(() {
                final viewerThemeKey =
                    Get.find<AppSettingsService>().activePremiumThemeVariant;
                return LiveRoomChatOverlay(
                  key: ValueKey('video-room-chat-$viewerThemeKey'),
                  messagesListenable: _chatMessages,
                  viewerThemeKey: viewerThemeKey,
                  roomId: widget.room.roomId,
                  roomType: widget.room.roomType,
                  topOffset: _pkCapable && _pkActive ? pkChatTopOffset : 0,
                  bottomOffset:
                      (_pkCapable && _pkActive
                                  ? (isCompactDevice ? 8 : 14)
                                  : (isCompactDevice ? 10 : 18)) +
                              pad.bottom,
                  maxHeightFactor:
                      _pkCapable && _pkActive
                          ? (isCompactDevice ? 0.38 : 0.54)
                          : (isCompactDevice ? 0.28 : 0.4),
                  showEmptyPrompt: false,
                  stickMessagesToBottom: false,
                  compactBubbles: _pkCapable && _pkActive,
                  inputActions: _buildChatInputActions(),
                  showSendButton: false,
                  onSend: _sendChatMessage,
                  onMessageSenderTap: (message) {
                    if (message.isSystem || message.senderId <= 0) return;
                    _showParticipantProfileCard(
                      userId: message.senderId,
                      name: message.senderName,
                      subtitle: message.senderIsHost
                          ? 'Host'
                          : (message.senderIsVip ? 'VIP Participant' : 'Participant'),
                      themeKey: message.senderActiveThemeKey,
                      isVip: message.senderIsVip,
                      isHost: message.senderIsHost,
                      speaking: false,
                      level: message.senderLevel,
                      avatarUrl: message.senderAvatar,
                    );
                  },
                );
              }),
            ),
            Positioned.fill(
              child: IgnorePointer(
                child: RepaintBoundary(child: _EmojiBurst(key: _emojiKey)),
              ),
            ),
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
                    Get.find<AppSettingsService>().activePremiumThemeVariant,
                receiverAnchorName: GiftAnchorRegistry.videoHostTile,
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
                left: 16,
                right: 16,
                bottom: 116 + pad.bottom,
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
                winnerSide: _pkOverlayWinnerSide,
                winnerName: _pkOverlayWinnerName,
                winnerAvatarUrl: _pkOverlayWinnerAvatarUrl,
                topSupporters: _pkOverlayTopSupporters,
                onSupporterTap: (supporter) {
                  _openPkSupporterProfile(
                    _PkSupporterStanding(
                      senderId: supporter.userId,
                      senderName: supporter.name,
                      totalCoins: supporter.coins,
                      avatarUrl: supporter.avatarUrl,
                    ),
                  );
                },
              ),
            if (widget.devMode)
              Positioned(
                right: 16,
                bottom: 132 + pad.bottom,
                child: _DevPkControlPad(
                  pkActive: _pkActive,
                  onStart: _mockDevEnterPkBattle,
                  onReset: _mockDevExitPkBattle,
                  onLeftGift: () => _mockDevGiftToSide('left'),
                  onRightGift: () => _mockDevGiftToSide('right'),
                  onLeftWin: () => _mockDevResolvePk(1),
                  onRightWin: () => _mockDevResolvePk(-1),
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

class _StageTileData {
  final Key? tileKey;
  final String label;
  final String subtitle;
  final bool isLocal;
  final String themeKey;
  final bool isHost;
  final bool isVip;
  final bool isSpeaking;
  final int? userId;
  final String? avatarUrl;
  final int? level;
  final VoidCallback? onProfileTap;
  final Widget child;

  const _StageTileData({
    this.tileKey,
    required this.label,
    required this.subtitle,
    required this.isLocal,
    required this.themeKey,
    required this.isHost,
    required this.isVip,
    required this.isSpeaking,
    this.userId,
    this.avatarUrl,
    this.level,
    this.onProfileTap,
    required this.child,
  });
}

class _HostModerationParticipant {
  final int userId;
  final String name;
  final String subtitle;
  final String themeKey;
  final bool isVip;
  final bool isHost;
  final bool speaking;
  final int? level;
  final String? avatarUrl;
  final String? frameUrl;

  const _HostModerationParticipant({
    required this.userId,
    required this.name,
    required this.subtitle,
    required this.themeKey,
    required this.isVip,
    required this.isHost,
    required this.speaking,
    this.level,
    this.avatarUrl,
    this.frameUrl,
  });
}

class _DevVideoTilePlaceholder extends StatelessWidget {
  const _DevVideoTilePlaceholder({
    required this.label,
    required this.themeKey,
  });

  final String label;
  final String themeKey;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(themeKey);
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.backgroundGradient.first,
            tokens.cardGradient.first,
            tokens.backgroundGradient.last,
          ],
        ),
      ),
      child: Stack(
        fit: StackFit.expand,
        children: [
          Positioned(
            top: 18,
            right: 18,
            child: Container(
              width: 92,
              height: 92,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: tokens.glowColor.withOpacity(.16),
              ),
            ),
          ),
          Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                CircleAvatar(
                  radius: 34,
                  backgroundColor: tokens.primaryButtonGradient.first,
                  child: Text(
                    label.isNotEmpty ? label.characters.first.toUpperCase() : '?',
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w900,
                      fontSize: 24,
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  label,
                  style: TextStyle(
                    color: tokens.textPrimary,
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  'Dev preview tile',
                  style: TextStyle(
                    color: tokens.textSecondary.withOpacity(.92),
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
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

class _PkVideoFallback extends StatelessWidget {
  const _PkVideoFallback({
    required this.name,
    required this.subtitle,
    this.avatarUrl,
    this.showSubtitle = false,
  });

  final String name;
  final String subtitle;
  final String? avatarUrl;
  final bool showSubtitle;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return DecoratedBox(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFF121722), Color(0xFF0D121B)],
        ),
      ),
      child: Center(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircleAvatar(
                radius: 28,
                backgroundColor: tokens.primaryButtonGradient.first,
                backgroundImage:
                    avatarUrl?.trim().isNotEmpty == true
                        ? NetworkImage(avatarUrl!.trim())
                        : null,
                child:
                    avatarUrl?.trim().isNotEmpty == true
                        ? null
                        : Text(
                          name.isNotEmpty
                              ? name.characters.first.toUpperCase()
                              : '?',
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w900,
                            fontSize: 20,
                          ),
                        ),
              ),
              if (showSubtitle) ...[
                const SizedBox(height: 8),
                Text(
                  subtitle,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.white.withOpacity(.72),
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _VideoParticipantProfileFallbackSheet extends StatelessWidget {
  const _VideoParticipantProfileFallbackSheet({
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
    return SafeArea(
      top: false,
      child: Container(
        margin: const EdgeInsets.only(top: 28),
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
          borderRadius: const BorderRadius.vertical(top: Radius.circular(34)),
          border: Border.all(color: tokens.borderColor.withOpacity(.22)),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 14, 18, 22),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  width: 48,
                  height: 5,
                  decoration: BoxDecoration(
                    color: tokens.borderColor.withOpacity(.42),
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: tokens.glassColor.withOpacity(.16),
                  borderRadius: BorderRadius.circular(26),
                  border: Border.all(color: tokens.borderColor.withOpacity(.22)),
                ),
                child: Row(
                  children: [
                    ThemedRoomFrame(
                      themeKey: themeKey,
                      isHost: isHost,
                      isVip: isVip,
                      isSpeaking: speaking,
                      borderRadius: 26,
                      size: 80,
                      child: Container(
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(26),
                          gradient: LinearGradient(
                            colors: frameTokens.primaryButtonGradient,
                          ),
                        ),
                        child: avatarUrl?.trim().isNotEmpty == true
                            ? ClipRRect(
                                borderRadius: BorderRadius.circular(26),
                                child: Image.network(
                                  avatarUrl!.trim(),
                                  fit: BoxFit.cover,
                                ),
                              )
                            : Center(
                                child: Text(
                                  name.isNotEmpty
                                      ? name.characters.first.toUpperCase()
                                      : '?',
                                  style: TextStyle(
                                    color: frameTokens.textPrimary,
                                    fontWeight: FontWeight.w900,
                                    fontSize: 28,
                                  ),
                                ),
                              ),
                      ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
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
                            style: TextStyle(
                              color: tokens.textSecondary.withOpacity(.9),
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(height: 10),
                          Wrap(
                            spacing: 6,
                            runSpacing: 6,
                            children: [
                              if (isHost) _VideoProfileChip(label: 'Host'),
                              if (isVip) _VideoProfileChip(label: 'VIP'),
                              if (speaking) _VideoProfileChip(label: 'Speaking'),
                              if (level != null)
                                _VideoProfileChip(label: 'LV $level'),
                              if (userId != null)
                                _VideoProfileChip(label: 'ID $userId'),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 14),
              Text(
                'Dev profile preview. This route uses mock room users, so it stays local instead of fetching from the API.',
                style: TextStyle(
                  color: tokens.textSecondary.withOpacity(.86),
                  fontWeight: FontWeight.w600,
                  height: 1.3,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _VideoProfileChip extends StatelessWidget {
  const _VideoProfileChip({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: tokens.chipColor.withOpacity(.88),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: tokens.textPrimary,
          fontSize: 10.8,
          fontWeight: FontWeight.w800,
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

class _CircleGlassButton extends StatelessWidget {
  final IconData icon;
  final VoidCallback onTap;
  final int badgeCount;

  const _CircleGlassButton({
    required this.icon,
    required this.onTap,
    this.badgeCount = 0,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Material(
          color: Colors.transparent,
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(20),
            child: Ink(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: tokens.glassColor.withOpacity(.16),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: tokens.borderColor.withOpacity(.32)),
              ),
              child: Icon(icon, color: Colors.white),
            ),
          ),
        ),
        if (badgeCount > 0)
          Positioned(
            right: -2,
            top: -2,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
              decoration: BoxDecoration(
                color: Colors.redAccent,
                borderRadius: BorderRadius.circular(999),
              ),
              child: Text(
                '$badgeCount',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 10,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _FooterCircleAction extends StatelessWidget {
  const _FooterCircleAction({
    required this.icon,
    required this.onTap,
    this.badgeCount = 0,
    this.active = false,
    this.busy = false,
    this.disabled = false,
    this.accent,
  });

  final IconData icon;
  final VoidCallback? onTap;
  final int badgeCount;
  final bool active;
  final bool busy;
  final bool disabled;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final tint =
        accent ??
        (active
            ? tokens.primaryButtonGradient.first
            : tokens.chipColor.withOpacity(.92));
    final canTap = onTap != null && !disabled && !busy;

    return Stack(
      clipBehavior: Clip.none,
      children: [
        Material(
          color: Colors.transparent,
          child: InkWell(
            onTap: canTap ? onTap : null,
            borderRadius: BorderRadius.circular(18),
            child: Ink(
              width: 46,
              height: 46,
              decoration: BoxDecoration(
                color: tint.withOpacity(active || accent != null ? .18 : .12),
                borderRadius: BorderRadius.circular(18),
                border: Border.all(color: tint.withOpacity(.34)),
              ),
              child: Center(
                child:
                    busy
                        ? SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            valueColor: AlwaysStoppedAnimation<Color>(
                              tokens.textPrimary,
                            ),
                          ),
                        )
                        : Opacity(
                          opacity: disabled ? .42 : 1,
                          child: Icon(
                            icon,
                            size: 20,
                            color: tokens.textPrimary,
                          ),
                        ),
              ),
            ),
          ),
        ),
        if (badgeCount > 0)
          Positioned(
            right: -3,
            top: -3,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
              decoration: BoxDecoration(
                color: Colors.redAccent,
                borderRadius: BorderRadius.circular(999),
              ),
              child: Text(
                '$badgeCount',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 10,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _FooterPillAction extends StatelessWidget {
  const _FooterPillAction({
    required this.icon,
    required this.label,
    required this.onTap,
    this.busy = false,
    this.accent,
  });

  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final bool busy;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final tint = accent ?? tokens.primaryButtonGradient.first;
    final enabled = onTap != null && !busy;

    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: FilledButton.icon(
        onPressed: enabled ? onTap : null,
        style: FilledButton.styleFrom(
          backgroundColor: tint.withOpacity(.18),
          foregroundColor: tokens.textPrimary,
          disabledBackgroundColor: tint.withOpacity(.10),
          disabledForegroundColor: tokens.textSecondary.withOpacity(.72),
          side: BorderSide(color: tint.withOpacity(.34)),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(999),
          ),
          textStyle: const TextStyle(
            fontWeight: FontWeight.w800,
            fontSize: 12.5,
          ),
        ),
        icon:
            busy
                ? SizedBox(
                  width: 14,
                  height: 14,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    valueColor: AlwaysStoppedAnimation<Color>(
                      tokens.textPrimary,
                    ),
                  ),
                )
                : Icon(icon, size: 16),
        label: Text(label),
      ),
    );
  }
}

class _ResponsiveChatInputAction extends StatelessWidget {
  const _ResponsiveChatInputAction({
    required this.icon,
    required this.label,
    required this.onTap,
    this.compactLabel,
    this.busy = false,
    this.accent,
    this.iconOnlyBelowWidth = 0,
  });

  final IconData icon;
  final String label;
  final String? compactLabel;
  final VoidCallback? onTap;
  final bool busy;
  final Color? accent;
  final double iconOnlyBelowWidth;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final screenWidth = MediaQuery.of(context).size.width;
    final iconOnly = iconOnlyBelowWidth > 0 && screenWidth <= iconOnlyBelowWidth;
    final resolvedLabel =
        !iconOnly && compactLabel != null && screenWidth < 390
            ? compactLabel!
            : label;
    final tint = accent ?? tokens.primaryButtonGradient.first;
    final enabled = onTap != null && !busy;
    const controlSize = 46.0;

    return Padding(
      padding: const EdgeInsets.only(left: 6),
      child: Tooltip(
        message: label,
        child: InkWell(
          onTap: enabled ? onTap : null,
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
                    color: tint.withOpacity(.14),
                    blurRadius: 12,
                    offset: const Offset(0, 4),
                  ),
                ],
              ),
              child:
                  iconOnly
                      ? Center(
                        child:
                            busy
                                ? SizedBox(
                                  width: 16,
                                  height: 16,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    valueColor: AlwaysStoppedAnimation<Color>(
                                      tokens.textPrimary,
                                    ),
                                  ),
                                )
                                : Icon(icon, size: 18, color: tint),
                      )
                      : Row(
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
                          else
                            Icon(icon, size: 15, color: tint),
                          const SizedBox(width: 6),
                          Text(
                            resolvedLabel,
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

class _FooterActionItem {
  const _FooterActionItem({
    required this.icon,
    required this.onTap,
    this.badgeCount = 0,
    this.active = false,
    this.busy = false,
    this.accent,
  });

  final IconData icon;
  final VoidCallback? onTap;
  final int badgeCount;
  final bool active;
  final bool busy;
  final Color? accent;
}

class _ExpandableFooterCluster extends StatefulWidget {
  const _ExpandableFooterCluster({
    required this.primaryIcon,
    required this.actions,
    this.primaryAccent,
    this.primaryBusy = false,
    this.primaryBadgeCount = 0,
  });

  final IconData primaryIcon;
  final List<_FooterActionItem> actions;
  final Color? primaryAccent;
  final bool primaryBusy;
  final int primaryBadgeCount;

  @override
  State<_ExpandableFooterCluster> createState() =>
      _ExpandableFooterClusterState();
}

class _ExpandableFooterClusterState extends State<_ExpandableFooterCluster> {
  final LayerLink _layerLink = LayerLink();
  OverlayEntry? _entry;
  bool _expanded = false;

  void _toggle() {
    if (_expanded) {
      _close();
      return;
    }
    _open();
  }

  void _open() {
    if (!mounted || _entry != null) return;
    final overlay = Overlay.of(context, rootOverlay: true);
    _entry = OverlayEntry(
      builder: (context) {
        final tokens = getPremiumThemeTokens(
          Get.find<AppSettingsService>().activePremiumThemeVariant,
        );
        return Stack(
          children: [
            Positioned.fill(
              child: GestureDetector(
                behavior: HitTestBehavior.translucent,
                onTap: _close,
              ),
            ),
            CompositedTransformFollower(
              link: _layerLink,
              showWhenUnlinked: false,
              targetAnchor: Alignment.topRight,
              followerAnchor: Alignment.bottomRight,
              offset: const Offset(0, -6),
              child: Material(
                color: Colors.transparent,
                child: TweenAnimationBuilder<double>(
                  tween: Tween<double>(begin: 0.92, end: 1),
                  duration: const Duration(milliseconds: 220),
                  curve: Curves.easeOutCubic,
                  builder: (context, value, child) {
                    return Opacity(
                      opacity: value.clamp(0, 1),
                      child: Transform.scale(
                        scale: value,
                        alignment: Alignment.bottomRight,
                        child: child,
                      ),
                    );
                  },
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 6,
                      vertical: 8,
                    ),
                    decoration: BoxDecoration(
                      color: tokens.glassColor.withOpacity(.14),
                      borderRadius: BorderRadius.circular(20),
                      border: Border.all(
                        color: tokens.borderColor.withOpacity(.22),
                      ),
                    ),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        for (var i = 0; i < widget.actions.length; i++) ...[
                          _FooterCircleAction(
                            icon: widget.actions[i].icon,
                            onTap: () {
                              _close();
                              widget.actions[i].onTap?.call();
                            },
                            badgeCount: widget.actions[i].badgeCount,
                            active: widget.actions[i].active,
                            busy: widget.actions[i].busy,
                            accent: widget.actions[i].accent,
                          ),
                          if (i != widget.actions.length - 1)
                            const SizedBox(height: 6),
                        ],
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ],
        );
      },
    );
    overlay.insert(_entry!);
    if (!mounted) return;
    setState(() => _expanded = true);
  }

  void _close() {
    _entry?.remove();
    _entry = null;
    if (!mounted) return;
    setState(() => _expanded = false);
  }

  @override
  void dispose() {
    _entry?.remove();
    _entry = null;
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return CompositedTransformTarget(
      link: _layerLink,
      child: _FooterCircleAction(
        icon: _expanded ? Icons.close_rounded : widget.primaryIcon,
        onTap: _toggle,
        accent: widget.primaryAccent,
        busy: widget.primaryBusy,
        active: _expanded,
        badgeCount: _expanded ? 0 : widget.primaryBadgeCount,
      ),
    );
  }
}

class _PkGiftActionRow extends StatelessWidget {
  const _PkGiftActionRow({
    required this.busy,
    required this.onTap,
  });

  final bool busy;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return TweenAnimationBuilder<double>(
      tween: Tween<double>(begin: .97, end: 1),
      duration: const Duration(milliseconds: 900),
      curve: Curves.easeInOut,
      builder: (context, value, child) {
        return Transform.scale(scale: value, child: child);
      },
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(22),
          child: Ink(
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(22),
              gradient: const LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  Color(0xEE121622),
                  Color(0xF020172A),
                  Color(0xE90E111A),
                ],
              ),
              border: Border.all(color: Colors.white.withOpacity(.10)),
              boxShadow: [
                BoxShadow(
                  color: const Color(0xFFFF8BC2).withOpacity(.16),
                  blurRadius: 16,
                  spreadRadius: 1,
                ),
                BoxShadow(
                  color: Colors.black.withOpacity(.28),
                  blurRadius: 20,
                  offset: const Offset(0, 10),
                ),
              ],
            ),
            child: Row(
              children: [
                Container(
                  width: 42,
                  height: 42,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: const LinearGradient(
                      colors: [Color(0xFFFF73B8), Color(0xFFFFB15A)],
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: const Color(0xFFFF8BC2).withOpacity(.34),
                        blurRadius: 14,
                      ),
                    ],
                  ),
                  child: Center(
                    child:
                        busy
                            ? SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                valueColor: AlwaysStoppedAnimation<Color>(
                                  tokens.textPrimary,
                                ),
                              ),
                            )
                            : Icon(
                              Icons.card_giftcard_rounded,
                              color: tokens.textPrimary,
                              size: 20,
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
                        'Send Battle Gifts',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: Colors.white.withOpacity(.94),
                          fontWeight: FontWeight.w900,
                          fontSize: 12.2,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'Push your side to the top',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: Colors.white.withOpacity(.64),
                          fontWeight: FontWeight.w700,
                          fontSize: 10.2,
                        ),
                      ),
                    ],
                  ),
                ),
                Container(
                  width: 1,
                  height: 28,
                  margin: const EdgeInsets.symmetric(horizontal: 10),
                  color: Colors.white.withOpacity(.10),
                ),
                Text(
                  busy ? 'Sending…' : 'Tap to gift',
                  style: TextStyle(
                    color: Colors.white.withOpacity(.72),
                    fontWeight: FontWeight.w700,
                    fontSize: 10.4,
                  ),
                ),
                const SizedBox(width: 8),
                Icon(
                  Icons.chevron_right_rounded,
                  color: Colors.white.withOpacity(.82),
                  size: 22,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _PkSupporterStanding {
  const _PkSupporterStanding({
    required this.senderId,
    required this.senderName,
    required this.totalCoins,
    this.avatarUrl,
  });

  final int senderId;
  final String senderName;
  final int totalCoins;
  final String? avatarUrl;
}

class _PkTopSupportersBand extends StatelessWidget {
  const _PkTopSupportersBand({
    required this.ownLabel,
    required this.opponentLabel,
    required this.ownSupporters,
    required this.opponentSupporters,
    required this.onSupporterTap,
  });

  final String ownLabel;
  final String opponentLabel;
  final List<_PkSupporterStanding> ownSupporters;
  final List<_PkSupporterStanding> opponentSupporters;
  final ValueChanged<_PkSupporterStanding> onSupporterTap;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return DecoratedBox(
      decoration: BoxDecoration(
        color: const Color(0xFF0A0D14),
        border: Border(
          top: BorderSide(color: Colors.white.withOpacity(.05)),
          bottom: BorderSide(color: Colors.white.withOpacity(.05)),
        ),
      ),
      child: Padding(
        padding: EdgeInsets.zero,
        child: Row(
          children: [
            Expanded(
              child: _PkSupportersLane(
                title: ownLabel,
                accent: const [Color(0xFFFF5C8A), Color(0xFFFFA63D)],
                supporters: ownSupporters,
                emptyLabel: 'No gifts yet',
                textColor: tokens.textPrimary,
                onSupporterTap: onSupporterTap,
              ),
            ),
            Container(
              width: 14,
              alignment: Alignment.center,
              child: Container(
                width: 1,
                height: 38,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(999),
                  color: Colors.white.withOpacity(.08),
                ),
              ),
            ),
            Expanded(
              child: _PkSupportersLane(
                title: opponentLabel,
                accent: const [Color(0xFF5AB3FF), Color(0xFF8A63E8)],
                supporters: opponentSupporters,
                emptyLabel: 'No gifts yet',
                textColor: tokens.textPrimary,
                onSupporterTap: onSupporterTap,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PkSupportersLane extends StatelessWidget {
  const _PkSupportersLane({
    required this.title,
    required this.accent,
    required this.supporters,
    required this.emptyLabel,
    required this.textColor,
    required this.onSupporterTap,
  });

  final String title;
  final List<Color> accent;
  final List<_PkSupporterStanding> supporters;
  final String emptyLabel;
  final Color textColor;
  final ValueChanged<_PkSupporterStanding> onSupporterTap;

  @override
  Widget build(BuildContext context) {
    final rankSupporters = List<_PkSupporterStanding?>.generate(
      3,
      (index) => index < supporters.length ? supporters[index] : null,
    );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: textColor.withOpacity(.90),
                  fontWeight: FontWeight.w900,
                  fontSize: 10.8,
                  letterSpacing: .2,
                ),
              ),
            ),
            Text(
              '3',
              style: TextStyle(
                color: textColor.withOpacity(.34),
                fontWeight: FontWeight.w800,
                fontSize: 8.6,
                letterSpacing: .7,
              ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        SizedBox(
          height: 38,
          child: Row(
            children: [
              for (var i = 0; i < rankSupporters.length; i++) ...[
                Expanded(
                  child: AnimatedSwitcher(
                    duration: const Duration(milliseconds: 320),
                    switchInCurve: Curves.easeOutCubic,
                    switchOutCurve: Curves.easeInCubic,
                    transitionBuilder: (child, animation) {
                      return FadeTransition(
                        opacity: animation,
                        child: SlideTransition(
                          position: animation.drive(
                            Tween<Offset>(
                              begin: const Offset(0, .18),
                              end: Offset.zero,
                            ),
                          ),
                          child: child,
                        ),
                      );
                    },
                    child: _PkSupporterCard(
                      key: ValueKey(
                        'pk-card-$title-${i + 1}-${rankSupporters[i]?.senderId ?? 'empty'}-${rankSupporters[i]?.totalCoins ?? 0}',
                      ),
                      rank: i + 1,
                      supporter: rankSupporters[i],
                      accent: accent,
                      textColor: textColor,
                      crowned: i == 0 && rankSupporters[i] != null,
                      emptyLabel: i == 0 ? emptyLabel : null,
                      onTap: rankSupporters[i] == null ? null : () => onSupporterTap(rankSupporters[i]!),
                    ),
                  ),
                ),
                if (i != rankSupporters.length - 1) const SizedBox(width: 6),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _PkSupporterCard extends StatelessWidget {
  const _PkSupporterCard({
    super.key,
    required this.rank,
    required this.supporter,
    required this.accent,
    required this.textColor,
    required this.crowned,
    this.emptyLabel,
    this.onTap,
  });

  final int rank;
  final _PkSupporterStanding? supporter;
  final List<Color> accent;
  final Color textColor;
  final bool crowned;
  final String? emptyLabel;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final label = supporter?.senderName ?? (emptyLabel ?? '---');
    final coins = supporter?.totalCoins ?? 0;
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(12),
        child: DecoratedBox(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            color:
                crowned
                    ? Colors.white.withOpacity(.07)
                    : Colors.white.withOpacity(.04),
            border: Border.all(
              color:
                  crowned
                      ? accent.first.withOpacity(.34)
                      : Colors.white.withOpacity(.08),
            ),
          ),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(6, 4, 6, 4),
            child: Row(
              children: [
                Stack(
                  clipBehavior: Clip.none,
                  children: [
                    _PkSupporterAvatar(
                      supporter: supporter,
                      accent: accent,
                      crowned: crowned,
                      size: 22,
                    ),
                    Positioned(
                      left: -2,
                      bottom: -2,
                      child: Container(
                        width: 12,
                        height: 12,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: const Color(0xFF0A0D14),
                          shape: BoxShape.circle,
                          border: Border.all(
                            color:
                                crowned
                                    ? accent.first.withOpacity(.60)
                                    : Colors.white.withOpacity(.16),
                            width: .9,
                          ),
                        ),
                        child: Text(
                          '$rank',
                          style: TextStyle(
                            color:
                                crowned
                                    ? accent.first.withOpacity(.96)
                                    : Colors.white.withOpacity(.86),
                            fontWeight: FontWeight.w900,
                            fontSize: 7.2,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(width: 6),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: textColor.withOpacity(supporter != null ? .88 : .50),
                          fontWeight: FontWeight.w800,
                          fontSize: 9.1,
                        ),
                      ),
                      const SizedBox(height: 1),
                      Text(
                        supporter != null ? _formatCompactPkCoins(coins) : '',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color:
                              crowned
                                  ? accent.first.withOpacity(.84)
                                  : textColor.withOpacity(supporter != null ? .58 : .36),
                          fontWeight: FontWeight.w800,
                          fontSize: 8.1,
                        ),
                      ),
                    ],
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

class _PkSupporterAvatar extends StatelessWidget {
  const _PkSupporterAvatar({
    required this.supporter,
    required this.accent,
    required this.crowned,
    this.size = 22,
  });

  final _PkSupporterStanding? supporter;
  final List<Color> accent;
  final bool crowned;
  final double size;

  @override
  Widget build(BuildContext context) {
    final initials =
        supporter?.senderName.trim().isNotEmpty == true
            ? supporter!.senderName.trim().characters.first.toUpperCase()
            : '?';
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Container(
          width: size,
          height: size,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: LinearGradient(
              colors:
                  supporter == null
                      ? const [Color(0xFF39404C), Color(0xFF232A35)]
                      : accent,
            ),
            border: Border.all(color: Colors.white.withOpacity(.18), width: .8),
          ),
          child: ClipOval(
            child:
                supporter?.avatarUrl != null && supporter!.avatarUrl!.isNotEmpty
                    ? Image.network(
                      supporter!.avatarUrl!,
                      fit: BoxFit.cover,
                      errorBuilder:
                          (_, __, ___) => Center(
                            child: Text(
                              initials,
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: size * .42,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                          ),
                    )
                    : Center(
                      child: Text(
                        initials,
                        style: TextStyle(
                          color: Colors.white,
                          fontSize: size * .42,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ),
          ),
        ),
        if (crowned)
          Positioned(
            top: -7,
            right: -4,
            child: Icon(
              Icons.workspace_premium_rounded,
              size: 14,
              color: const Color(0xFFFFD86B),
              shadows: [
                Shadow(
                  color: Colors.black.withOpacity(.35),
                  blurRadius: 8,
                ),
              ],
            ),
          ),
      ],
    );
  }
}

String _formatCompactPkCoins(int value) {
  if (value >= 1000000) {
    final compact = (value / 1000000).toStringAsFixed(value >= 10000000 ? 0 : 1);
    return '${compact.replaceAll(RegExp(r'\\.0$'), '')}M';
  }
  if (value >= 1000) {
    final compact = (value / 1000).toStringAsFixed(value >= 10000 ? 0 : 1);
    return '${compact.replaceAll(RegExp(r'\\.0$'), '')}K';
  }
  return '$value';
}

class _TopRightExitPill extends StatelessWidget {
  const _TopRightExitPill({
    required this.label,
    required this.onTap,
    this.accent,
  });

  final String label;
  final VoidCallback onTap;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final tint = accent ?? tokens.chipColor.withOpacity(.92);
    final width = MediaQuery.sizeOf(context).width;
    final compact = width < 390;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(999),
        child: Ink(
          padding: EdgeInsets.symmetric(
            horizontal: compact ? 11 : 12,
            vertical: compact ? 8 : 9,
          ),
          decoration: BoxDecoration(
            color: tint.withOpacity(accent != null ? .18 : .14),
            borderRadius: BorderRadius.circular(999),
            border: Border.all(color: tint.withOpacity(.34)),
          ),
          child: Text(
            label,
            style: TextStyle(
              color: tokens.textPrimary,
              fontSize: compact ? 11.5 : 12,
              fontWeight: FontWeight.w900,
            ),
          ),
        ),
      ),
    );
  }
}

class _DevPkControlPad extends StatelessWidget {
  const _DevPkControlPad({
    required this.pkActive,
    required this.onStart,
    required this.onReset,
    required this.onLeftGift,
    required this.onRightGift,
    required this.onLeftWin,
    required this.onRightWin,
  });

  final bool pkActive;
  final VoidCallback onStart;
  final VoidCallback onReset;
  final VoidCallback onLeftGift;
  final VoidCallback onRightGift;
  final VoidCallback onLeftWin;
  final VoidCallback onRightWin;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return DecoratedBox(
      decoration: BoxDecoration(
        color: const Color(0xD90A0D13),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: Colors.white.withOpacity(.10)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(.24),
            blurRadius: 18,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(10, 10, 10, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'DEV PK',
              style: TextStyle(
                color: tokens.textPrimary.withOpacity(.92),
                fontWeight: FontWeight.w900,
                fontSize: 11,
                letterSpacing: .7,
              ),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              alignment: WrapAlignment.end,
              children: [
                _DevPkMiniButton(
                  label: pkActive ? 'Reset' : 'Start',
                  onTap: pkActive ? onReset : onStart,
                  accent: const Color(0xFF7B50C5),
                ),
                if (pkActive) ...[
                  _DevPkMiniButton(
                    label: 'L Gift',
                    onTap: onLeftGift,
                    accent: const Color(0xFFFF6C8C),
                  ),
                  _DevPkMiniButton(
                    label: 'R Gift',
                    onTap: onRightGift,
                    accent: const Color(0xFF5AB3FF),
                  ),
                  _DevPkMiniButton(
                    label: 'L Win',
                    onTap: onLeftWin,
                    accent: const Color(0xFFFFA63D),
                  ),
                  _DevPkMiniButton(
                    label: 'R Win',
                    onTap: onRightWin,
                    accent: const Color(0xFF8A63E8),
                  ),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _DevPkMiniButton extends StatelessWidget {
  const _DevPkMiniButton({
    required this.label,
    required this.onTap,
    required this.accent,
  });

  final String label;
  final VoidCallback onTap;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(999),
        child: Ink(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
          decoration: BoxDecoration(
            color: accent.withOpacity(.16),
            borderRadius: BorderRadius.circular(999),
            border: Border.all(color: accent.withOpacity(.34)),
          ),
          child: Text(
            label,
            style: TextStyle(
              color: Colors.white.withOpacity(.94),
              fontSize: 11,
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
      ),
    );
  }
}

class _LiveRoomInfoPill extends StatelessWidget {
  const _LiveRoomInfoPill({
    required this.hostName,
    this.hostAvatarUrl,
    this.hostFrameUrl,
    required this.liveLabel,
    required this.participantCount,
    this.onHostTap,
    this.onViewerTap,
  });

  final String hostName;
  final String? hostAvatarUrl;
  final String? hostFrameUrl;
  final String liveLabel;
  final int participantCount;
  final VoidCallback? onHostTap;
  final VoidCallback? onViewerTap;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final width = MediaQuery.sizeOf(context).width;
    final compact = width < 390;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Flexible(
          child: Material(
            color: Colors.transparent,
            child: InkWell(
              onTap: onHostTap,
              borderRadius: BorderRadius.circular(24),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(24),
                child: BackdropFilter(
                  filter: ui.ImageFilter.blur(sigmaX: 12, sigmaY: 12),
                  child: Container(
                    padding: EdgeInsets.fromLTRB(
                      compact ? 10 : 11,
                      compact ? 8 : 9,
                      compact ? 12 : 13,
                      compact ? 8 : 9,
                    ),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(24),
                      gradient: LinearGradient(
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                        colors: [
                          Colors.white.withOpacity(.14),
                          tokens.glassColor.withOpacity(.18),
                          Colors.black.withOpacity(.14),
                        ],
                      ),
                      border: Border.all(color: Colors.white.withOpacity(.12)),
                      boxShadow: [
                        BoxShadow(
                          color: tokens.glowColor.withOpacity(.14),
                          blurRadius: 18,
                          offset: const Offset(0, 8),
                        ),
                      ],
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Stack(
                          clipBehavior: Clip.none,
                          children: [
                            _LiveRoomPillAvatar(
                              name: hostName,
                              avatarUrl: hostAvatarUrl,
                              frameUrl: hostFrameUrl,
                              compact: compact,
                              tint: tokens.glowColor,
                            ),
                            Positioned(
                              right: -1,
                              bottom: -1,
                              child: Container(
                                width: compact ? 12 : 13,
                                height: compact ? 12 : 13,
                                decoration: BoxDecoration(
                                  color: const Color(0xFFFF355D),
                                  shape: BoxShape.circle,
                                  border: Border.all(
                                    color: Colors.white.withOpacity(.9),
                                    width: 1.4,
                                  ),
                                  boxShadow: [
                                    BoxShadow(
                                      color: const Color(0xFFFF355D).withOpacity(
                                        .34,
                                      ),
                                      blurRadius: 8,
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ],
                        ),
                        SizedBox(width: compact ? 10 : 12),
                        Flexible(
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                hostName,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                  color: Colors.white.withOpacity(.97),
                                  fontWeight: FontWeight.w900,
                                  fontSize: compact ? 11.6 : 12.3,
                                  letterSpacing: .05,
                                ),
                              ),
                              SizedBox(height: compact ? 4 : 5),
                              _LiveHudSubPill(
                                icon: Icons.schedule_rounded,
                                label: liveLabel,
                                compact: compact,
                                tint: const Color(0xFFFF6B8A),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
        SizedBox(width: compact ? 7 : 8),
        _LiveHudMetricPill(
          icon: Icons.visibility_rounded,
          label: '$participantCount',
          compact: compact,
          tint: const Color(0xFFFFC857),
          onTap: onViewerTap,
        ),
      ],
    );
  }
}

class _LiveRoomPillAvatar extends StatelessWidget {
  const _LiveRoomPillAvatar({
    required this.name,
    required this.avatarUrl,
    required this.frameUrl,
    required this.compact,
    required this.tint,
  });

  final String name;
  final String? avatarUrl;
  final String? frameUrl;
  final bool compact;
  final Color tint;

  @override
  Widget build(BuildContext context) {
    final size = compact ? 38.0 : 42.0;
    final initial = name.trim().isNotEmpty
        ? name.trim().characters.first.toUpperCase()
        : 'H';
    return SizedBox(
      width: size,
      height: size,
      child: FramedAvatar(
        avatarUrl: avatarUrl?.trim(),
        frameUrl: frameUrl?.trim(),
        size: size,
        label: initial,
        backgroundColor: Colors.transparent,
        avatarInset: 0.08,
        frameScale: 1.18,
      ),
    );
  }
}

class _LiveRoomPillAvatarFallback extends StatelessWidget {
  const _LiveRoomPillAvatarFallback({
    required this.initial,
    required this.tint,
  });

  final String initial;
  final Color tint;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tint.withOpacity(.94),
            tint.withOpacity(.54),
          ],
        ),
      ),
      child: Center(
        child: Text(
          initial,
          style: const TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.w900,
            fontSize: 15,
          ),
        ),
      ),
    );
  }
}

class _LiveHudSubPill extends StatelessWidget {
  const _LiveHudSubPill({
    required this.icon,
    required this.label,
    required this.compact,
    required this.tint,
  });

  final IconData icon;
  final String label;
  final bool compact;
  final Color tint;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 8 : 9,
        vertical: compact ? 4 : 4.5,
      ),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: Colors.black.withOpacity(.18),
        border: Border.all(color: Colors.white.withOpacity(.08)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            icon,
            size: compact ? 11.5 : 12,
            color: tint.withOpacity(.96),
          ),
          SizedBox(width: compact ? 4 : 5),
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: Colors.white.withOpacity(.90),
                fontSize: compact ? 9.8 : 10.2,
                fontWeight: FontWeight.w800,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _LiveHudMetricPill extends StatelessWidget {
  const _LiveHudMetricPill({
    required this.icon,
    required this.label,
    required this.compact,
    required this.tint,
    this.onTap,
  });

  final IconData icon;
  final String label;
  final bool compact;
  final Color tint;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(22),
        child: ConstrainedBox(
          constraints: BoxConstraints(minWidth: compact ? 70 : 78),
          child: Ink(
            padding: EdgeInsets.symmetric(
              horizontal: compact ? 10 : 11,
              vertical: compact ? 8 : 9,
            ),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(22),
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  Colors.black.withOpacity(.24),
                  tint.withOpacity(.16),
                ],
              ),
              border: Border.all(color: Colors.white.withOpacity(.10)),
              boxShadow: [
                BoxShadow(
                  color: tint.withOpacity(.14),
                  blurRadius: 12,
                  offset: const Offset(0, 5),
                ),
              ],
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      icon,
                      size: compact ? 13 : 14,
                      color: tint.withOpacity(.94),
                    ),
                    SizedBox(width: compact ? 5 : 6),
                    Text(
                      label,
                      style: TextStyle(
                        color: Colors.white.withOpacity(.96),
                        fontSize: compact ? 11.2 : 11.8,
                        fontWeight: FontWeight.w900,
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

class _MiniInfoDivider extends StatelessWidget {
  const _MiniInfoDivider({required this.color});

  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 1,
      height: 12,
      color: color,
    );
  }
}

class _InlineMetaText extends StatelessWidget {
  const _InlineMetaText({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final compact = MediaQuery.sizeOf(context).width < 390;
    return Text(
      label,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: TextStyle(
        color: tokens.textSecondary.withOpacity(.9),
        fontSize: compact ? 10.3 : 10.8,
        fontWeight: FontWeight.w800,
      ),
    );
  }
}

class _MetaPill extends StatelessWidget {
  final String label;
  const _MetaPill({required this.label});

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
          fontSize: 11,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}

class _FloatingTickerBanner extends StatelessWidget {
  final IconData icon;
  final String message;
  final Color accent;
  final VoidCallback? onDismiss;

  const _FloatingTickerBanner({
    super.key,
    required this.icon,
    required this.message,
    this.accent = Colors.pinkAccent,
    this.onDismiss,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.cardGradient.first.withOpacity(.95),
            accent.withOpacity(.18),
            tokens.cardGradient.last.withOpacity(.92),
          ],
        ),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: accent.withOpacity(.32)),
        boxShadow: [
          BoxShadow(
            color: accent.withOpacity(.14),
            blurRadius: 18,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: LinearGradient(
                colors: [
                  accent.withOpacity(.92),
                  tokens.primaryButtonGradient.last.withOpacity(.82),
                ],
              ),
              boxShadow: [
                BoxShadow(
                  color: accent.withOpacity(.22),
                  blurRadius: 12,
                  offset: const Offset(0, 6),
                ),
              ],
            ),
            child: Icon(icon, color: Colors.white, size: 18),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  icon == Icons.redeem_rounded ? 'ROOM GIFT' : 'LIVE UPDATE',
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
          Material(
            color: Colors.transparent,
            child: InkWell(
              onTap: onDismiss,
              borderRadius: BorderRadius.circular(999),
              child: Padding(
                padding: const EdgeInsets.all(4),
                child: Icon(
                  Icons.close_rounded,
                  color: tokens.textSecondary.withOpacity(.84),
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

class _DynamicStageGrid extends StatelessWidget {
  final List<_StageTileData> tiles;

  const _DynamicStageGrid({required this.tiles});

  @override
  Widget build(BuildContext context) {
    if (tiles.isEmpty) {
      return const Center(
        child: Text(
          'Waiting for live video…',
          style: TextStyle(color: Colors.white70, fontWeight: FontWeight.w700),
        ),
      );
    }

    if (tiles.length == 1) {
      return _StageTile(tile: tiles.first);
    }

    if (tiles.length == 2) {
      final ordered = List<_StageTileData>.from(tiles)
        ..sort((a, b) {
          if (a.isHost == b.isHost) return 0;
          return a.isHost ? -1 : 1;
        });
      return Padding(
        padding: const EdgeInsets.all(8),
        child: Column(
          children: [
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(4, 4, 4, 2),
                child: _StageTile(tile: ordered[0]),
              ),
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(4, 2, 4, 4),
                child: _StageTile(tile: ordered[1]),
              ),
            ),
          ],
        ),
      );
    }

    final rows = _buildRows(tiles);
    return Padding(
      padding: const EdgeInsets.all(8),
      child: Column(
        children: [
          for (var rowIndex = 0; rowIndex < rows.length; rowIndex++) ...[
            Expanded(
              child: Row(
                children: [
                  for (var tileIndex = 0; tileIndex < rows[rowIndex].length; tileIndex++) ...[
                    Expanded(
                      child: Padding(
                        padding: const EdgeInsets.all(4),
                        child: _StageTile(tile: rows[rowIndex][tileIndex]),
                      ),
                    ),
                    if (tileIndex != rows[rowIndex].length - 1)
                      const SizedBox(width: 0),
                  ],
                ],
              ),
            ),
            if (rowIndex != rows.length - 1) const SizedBox(height: 0),
          ],
        ],
      ),
    );
  }

  List<List<_StageTileData>> _buildRows(List<_StageTileData> items) {
    if (items.length == 3) {
      return <List<_StageTileData>>[
        items.take(2).toList(growable: false),
        <_StageTileData>[items[2]],
      ];
    }

    final rows = <List<_StageTileData>>[];
    var index = 0;
    while (index < items.length) {
      final remaining = items.length - index;
      if (remaining == 1) {
        rows.add(<_StageTileData>[items[index]]);
        index += 1;
      } else {
        rows.add(items.sublist(index, index + 2));
        index += 2;
      }
    }
    return rows;
  }
}

class _StageTile extends StatelessWidget {
  final _StageTileData tile;
  final bool featured;

  const _StageTile({required this.tile, this.featured = false});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(tile.themeKey);
    return KeyedSubtree(
      key: tile.tileKey,
      child: ThemedRoomFrame(
        themeKey: tile.themeKey,
        isHost: tile.isHost,
        isVip: tile.isVip,
        isSpeaking: tile.isSpeaking,
        borderRadius: featured ? 24 : 20,
        child: Stack(
          fit: StackFit.expand,
          children: [
            tile.child,
            DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    Colors.black.withOpacity(.08),
                    Colors.transparent,
                    Colors.black.withOpacity(.60),
                  ],
                ),
              ),
            ),
            if (!tile.isHost)
              Positioned(
                left: 10,
                top: 10,
                child: Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 6,
                  ),
                  decoration: BoxDecoration(
                    color: tokens.glassColor.withOpacity(.22),
                    borderRadius: BorderRadius.circular(999),
                    border: Border.all(
                      color: tokens.borderColor.withOpacity(.34),
                    ),
                  ),
                  child: Text(
                    tile.isLocal ? 'You' : tile.label,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                      fontSize: 11,
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

class _HostModerationPanel extends StatelessWidget {
  final List<Map<String, dynamic>> pendingRequests;
  final List<Map<String, dynamic>> speakers;
  final List<_HostModerationParticipant> participants;
  final int speakerCount;
  final int maxSpeakers;
  final bool busy;
  final Future<void> Function(int requestId) onAccept;
  final Future<void> Function(int requestId) onReject;
  final Future<void> Function(int userId) onRemoveSpeaker;
  final Future<void> Function(_HostModerationParticipant participant)
  onOpenParticipant;

  const _HostModerationPanel({
    required this.pendingRequests,
    required this.speakers,
    required this.participants,
    required this.speakerCount,
    required this.maxSpeakers,
    required this.busy,
    required this.onAccept,
    required this.onReject,
    required this.onRemoveSpeaker,
    required this.onOpenParticipant,
  });

  @override
  Widget build(BuildContext context) {
    return _GlassPad(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxHeight: 360),
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                'Room Controls',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  color: Colors.white,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 10),
              Text(
                'On camera: $speakerCount / $maxSpeakers',
                style: const TextStyle(
                  color: Colors.white70,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 12),
              if (pendingRequests.isEmpty)
                const Text(
                  'No pending requests',
                  style: TextStyle(color: Colors.white54),
                )
              else
                ...pendingRequests.take(4).map((row) {
                  final requestId =
                      int.tryParse('${row['request_id'] ?? row['id'] ?? ''}') ??
                      0;
                  final requestUserId =
                      int.tryParse(
                        '${(row['user'] as Map?)?['id'] ?? (row['user'] as Map?)?['user_id'] ?? ''}',
                      ) ??
                      0;
                  final name =
                      ((row['user'] as Map?)?['name'] ?? 'Viewer').toString();
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(.06),
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(
                          color: Colors.white.withOpacity(.08),
                        ),
                      ),
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
                          if (requestUserId > 0) ...[
                            const SizedBox(height: 4),
                            Text(
                              'ID: $requestUserId',
                              style: const TextStyle(
                                color: Colors.white60,
                                fontWeight: FontWeight.w700,
                                fontSize: 12,
                              ),
                            ),
                          ],
                          const SizedBox(height: 8),
                          Row(
                            children: [
                              Expanded(
                                child: FilledButton(
                                  onPressed:
                                      busy || requestId == 0
                                          ? null
                                          : () => onAccept(requestId),
                                  child: const Text('Accept'),
                                ),
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: OutlinedButton(
                                  onPressed:
                                      busy || requestId == 0
                                          ? null
                                          : () => onReject(requestId),
                                  child: const Text('Reject'),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  );
                }),
              const SizedBox(height: 12),
              Text(
                'Current speakers',
                style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  color: Colors.white,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 8),
              if (speakers.isEmpty)
                const Text(
                  'Only the host is on camera.',
                  style: TextStyle(color: Colors.white54),
                )
              else
                ...speakers.map((row) {
                  final userId = int.tryParse('${row['user_id'] ?? ''}') ?? 0;
                  final name = (row['name'] ?? 'Speaker').toString();
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            name,
                            style: const TextStyle(
                              color: Colors.white70,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                        TextButton(
                          onPressed:
                              busy || userId == 0
                                  ? null
                                  : () => onRemoveSpeaker(userId),
                          child: const Text('Remove'),
                        ),
                      ],
                    ),
                  );
                }),
            ],
          ),
        ),
      ),
    );
  }
}

class _HostModerationSheet extends StatelessWidget {
  final List<Map<String, dynamic>> pendingRequests;
  final List<Map<String, dynamic>> speakers;
  final List<_HostModerationParticipant> participants;
  final int speakerCount;
  final int maxSpeakers;
  final bool busy;
  final Future<void> Function(int requestId) onAccept;
  final Future<void> Function(int requestId) onReject;
  final Future<void> Function(int userId) onRemoveSpeaker;
  final Future<void> Function(_HostModerationParticipant participant)
  onOpenParticipant;

  const _HostModerationSheet({
    required this.pendingRequests,
    required this.speakers,
    required this.participants,
    required this.speakerCount,
    required this.maxSpeakers,
    required this.busy,
    required this.onAccept,
    required this.onReject,
    required this.onRemoveSpeaker,
    required this.onOpenParticipant,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final fillRatio =
        maxSpeakers <= 0 ? 0.0 : (speakerCount / maxSpeakers).clamp(0, 1).toDouble();
    return Container(
      padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.cardGradient.first.withOpacity(.98),
            tokens.cardGradient.last.withOpacity(.94),
            tokens.backgroundGradient.last.withOpacity(.96),
          ],
        ),
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: tokens.borderColor.withOpacity(.32)),
        boxShadow: [
          BoxShadow(
            color: tokens.glowColor.withOpacity(.16),
            blurRadius: 26,
            offset: const Offset(0, 12),
          ),
        ],
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
                color: tokens.borderColor.withOpacity(.42),
                borderRadius: BorderRadius.circular(999),
              ),
            ),
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: tokens.glassColor.withOpacity(.16),
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(color: tokens.borderColor.withOpacity(.18)),
                ),
                child: Text(
                  'MODERATION',
                  style: TextStyle(
                    color: tokens.textSecondary,
                    fontSize: 10.5,
                    fontWeight: FontWeight.w900,
                    letterSpacing: .8,
                  ),
                ),
              ),
              const Spacer(),
              if (busy)
                SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    valueColor: AlwaysStoppedAnimation<Color>(tokens.textPrimary),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            'Manage requests and current speakers',
            style: TextStyle(
              color: tokens.textSecondary.withOpacity(.92),
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 14),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: tokens.glassColor.withOpacity(.12),
              borderRadius: BorderRadius.circular(20),
              border: Border.all(color: tokens.borderColor.withOpacity(.18)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        'Current speakers',
                        style: TextStyle(
                          color: tokens.textPrimary,
                          fontWeight: FontWeight.w900,
                          fontSize: 15,
                        ),
                      ),
                    ),
                    Text(
                      '$speakerCount / $maxSpeakers',
                      style: TextStyle(
                        color: tokens.textSecondary.withOpacity(.86),
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                ClipRRect(
                  borderRadius: BorderRadius.circular(999),
                  child: LinearProgressIndicator(
                    value: fillRatio,
                    minHeight: 6,
                    backgroundColor: tokens.chipColor.withOpacity(.26),
                    valueColor: AlwaysStoppedAnimation<Color>(
                      tokens.primaryButtonGradient.first,
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                if (speakers.isEmpty)
                  Text(
                    'Only the host is on camera.',
                    style: TextStyle(color: tokens.textSecondary.withOpacity(.74)),
                  )
                else
                  SizedBox(
                    height: 146,
                    child: ListView.separated(
                      scrollDirection: Axis.horizontal,
                      itemCount: speakers.length,
                      separatorBuilder: (_, __) => const SizedBox(width: 10),
                      itemBuilder: (context, index) {
                        final row = speakers[index];
                        final userId = int.tryParse('${row['user_id'] ?? ''}') ?? 0;
                        final name = (row['name'] ?? 'Speaker').toString();
                        final themeKey = normalizePremiumThemeVariant(
                          row['active_theme_key']?.toString() ?? 'midnight',
                        );
                        final frameTokens = getPremiumThemeTokens(themeKey);
                        final isVip = row['is_vip'] == true;
                        return SizedBox(
                          width: 132,
                          child: Container(
                            padding: const EdgeInsets.all(10),
                            decoration: BoxDecoration(
                              color: tokens.chipColor.withOpacity(.14),
                              borderRadius: BorderRadius.circular(18),
                              border: Border.all(
                                color: tokens.borderColor.withOpacity(.16),
                              ),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(
                                  children: [
                                    SizedBox(
                                      width: 42,
                                      height: 42,
                                      child: FramedAvatar(
                                        avatarUrl:
                                            row['avatar_url']?.toString() ??
                                            row['avatar']?.toString(),
                                        frameUrl: profileFrameAssetUrlFromPayload(row),
                                        label: name,
                                        size: 42,
                                        backgroundColor: frameTokens.primaryButtonGradient.first,
                                      ),
                                    ),
                                    const Spacer(),
                                    if (isVip)
                                      Container(
                                        padding: const EdgeInsets.symmetric(
                                          horizontal: 6,
                                          vertical: 3,
                                        ),
                                        decoration: BoxDecoration(
                                          color: frameTokens.primaryButtonGradient.first
                                              .withOpacity(.24),
                                          borderRadius: BorderRadius.circular(999),
                                        ),
                                        child: const Text(
                                          'VIP',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontSize: 9,
                                            fontWeight: FontWeight.w900,
                                          ),
                                        ),
                                      ),
                                  ],
                                ),
                                const SizedBox(height: 10),
                                Text(
                                  name,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(
                                    color: tokens.textPrimary,
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                                if (userId > 0) ...[
                                  const SizedBox(height: 2),
                                  Text(
                                    'ID: $userId',
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: TextStyle(
                                      color: tokens.textSecondary.withOpacity(.82),
                                      fontSize: 11.5,
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                ],
                                const Spacer(),
                                SizedBox(
                                  width: double.infinity,
                                  child: OutlinedButton(
                                    onPressed:
                                        busy || userId == 0
                                            ? null
                                            : () => onRemoveSpeaker(userId),
                                    style: OutlinedButton.styleFrom(
                                      foregroundColor: tokens.textPrimary,
                                      side: BorderSide(
                                        color: tokens.dangerColor.withOpacity(.34),
                                      ),
                                      padding: const EdgeInsets.symmetric(vertical: 10),
                                    ),
                                    child: const Text('Remove'),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        );
                      },
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          Text(
            'Pending requests',
            style: TextStyle(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w900,
              fontSize: 15,
            ),
          ),
          const SizedBox(height: 10),
          if (pendingRequests.isEmpty)
            Text(
              'No pending requests',
              style: TextStyle(color: tokens.textSecondary.withOpacity(.74)),
            )
          else
            ConstrainedBox(
              constraints: const BoxConstraints(maxHeight: 280),
              child: ListView.separated(
                shrinkWrap: true,
                primary: false,
                itemCount: pendingRequests.length,
                separatorBuilder: (_, __) => const SizedBox(height: 10),
                itemBuilder: (context, index) {
                  final row = pendingRequests[index];
                  final requestId =
                      int.tryParse('${row['request_id'] ?? row['id'] ?? ''}') ?? 0;
                  final user = (row['user'] as Map?) ?? const {};
                  final name = (user['name'] ?? 'Viewer').toString();
                  final level =
                      int.tryParse('${user['level'] ?? row['level'] ?? ''}');
                  final isVip = user['is_vip'] == true || row['is_vip'] == true;
                  return Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: tokens.glassColor.withOpacity(.08),
                      borderRadius: BorderRadius.circular(18),
                      border: Border.all(
                        color: tokens.borderColor.withOpacity(.20),
                      ),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        SizedBox(
                          width: 44,
                          height: 44,
                          child: FramedAvatar(
                            avatarUrl: user['avatar_url']?.toString(),
                            frameUrl: profileFrameAssetUrlFromPayload(user),
                            label: name,
                            size: 44,
                            backgroundColor: tokens.primaryButtonGradient.first,
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                children: [
                                  Expanded(
                                    child: Text(
                                      name,
                                      style: TextStyle(
                                        color: tokens.textPrimary,
                                        fontWeight: FontWeight.w800,
                                      ),
                                    ),
                                  ),
                                  if (isVip)
                                    _MetaPill(label: 'VIP'),
                                  if (level != null) ...[
                                    const SizedBox(width: 6),
                                    _MetaPill(label: 'LV $level'),
                                  ],
                                ],
                              ),
                              const SizedBox(height: 10),
                              Row(
                                children: [
                                  Expanded(
                                    child: FilledButton(
                                      onPressed:
                                          busy || requestId == 0
                                              ? null
                                              : () => onAccept(requestId),
                                      style: FilledButton.styleFrom(
                                        backgroundColor:
                                            tokens.primaryButtonGradient.first,
                                        foregroundColor: tokens.textPrimary,
                                      ),
                                      child: const Text('Accept'),
                                    ),
                                  ),
                                  const SizedBox(width: 8),
                                  Expanded(
                                    child: OutlinedButton(
                                      onPressed:
                                          busy || requestId == 0
                                              ? null
                                              : () => onReject(requestId),
                                      style: OutlinedButton.styleFrom(
                                        foregroundColor: tokens.textPrimary,
                                        side: BorderSide(
                                          color: tokens.borderColor.withOpacity(.34),
                                        ),
                                      ),
                                      child: const Text('Reject'),
                                    ),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      ],
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

class _BottomActionBar extends StatelessWidget {
  final List<Widget> children;

  const _BottomActionBar({required this.children});

  @override
  Widget build(BuildContext context) {
    return _GlassPad(
      child: Row(mainAxisSize: MainAxisSize.max, children: children),
    );
  }
}

class _RoleActionDock extends StatelessWidget {
  final List<_DockAction> children;
  final bool compact;

  const _RoleActionDock({required this.children, required this.compact});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 10 : 12,
        vertical: compact ? 10 : 12,
      ),
      decoration: BoxDecoration(
        color: tokens.glassColor.withOpacity(.18),
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: tokens.borderColor.withOpacity(.28)),
      ),
      child: Row(
        children:
            children
                .map(
                  (action) => Expanded(
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      child: action,
                    ),
                  ),
                )
                .toList(),
      ),
    );
  }
}

class _DockAction extends StatelessWidget {
  final String label;
  final IconData icon;
  final VoidCallback? onTap;
  final bool busy;
  final bool active;
  final Color? accent;
  final int badgeCount;

  const _DockAction({
    required this.label,
    required this.icon,
    required this.onTap,
    this.busy = false,
    this.active = false,
    this.accent,
    this.badgeCount = 0,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    final tint =
        accent ??
        (active
            ? tokens.primaryButtonGradient.first
            : tokens.chipColor.withOpacity(.82));
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(18),
            onTap: onTap,
            child: Ink(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(18),
                color: tint.withOpacity(accent != null || active ? .22 : .10),
                border: Border.all(color: tint.withOpacity(.34)),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  busy
                      ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                      : Icon(icon, color: Colors.white, size: 20),
                  const SizedBox(height: 6),
                  Text(
                    label,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 12,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
        if (badgeCount > 0)
          Positioned(
            right: -2,
            top: -2,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
              decoration: BoxDecoration(
                color: Colors.redAccent,
                borderRadius: BorderRadius.circular(999),
              ),
              child: Text(
                '$badgeCount',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 10,
                  fontWeight: FontWeight.w900,
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _ActionButton extends StatelessWidget {
  final String label;
  final IconData icon;
  final Color color;
  final bool busy;
  final bool compact;
  final VoidCallback? onPressed;

  const _ActionButton({
    required this.label,
    required this.icon,
    required this.color,
    required this.onPressed,
    this.busy = false,
    this.compact = false,
  });

  @override
  Widget build(BuildContext context) {
    return FilledButton.icon(
      onPressed: onPressed,
      style: FilledButton.styleFrom(
        backgroundColor: color,
        foregroundColor: Colors.white,
        padding: EdgeInsets.symmetric(
          horizontal: compact ? 10 : 14,
          vertical: compact ? 12 : 14,
        ),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      ),
      icon:
          busy
              ? const SizedBox(
                width: 16,
                height: 16,
                child: CircularProgressIndicator(
                  strokeWidth: 2,
                  color: Colors.white,
                ),
              )
              : Icon(icon),
      label: Text(
        label,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(
          fontWeight: FontWeight.w800,
          fontSize: compact ? 13 : 14,
        ),
      ),
    );
  }
}

class _ViewerSpeakerRequestPanel extends StatelessWidget {
  final bool busy;
  final bool pendingRequest;
  final String? seatError;
  final Future<void> Function() onRequest;
  final Future<void> Function() onCancel;

  const _ViewerSpeakerRequestPanel({
    required this.busy,
    required this.pendingRequest,
    required this.seatError,
    required this.onRequest,
    required this.onCancel,
  });

  @override
  Widget build(BuildContext context) {
    return _GlassPad(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          FilledButton.icon(
            onPressed: busy ? null : (pendingRequest ? onCancel : onRequest),
            icon: Icon(
              pendingRequest ? Icons.close_rounded : Icons.video_call_rounded,
            ),
            label: Text(
              pendingRequest ? 'Cancel Join Call Request' : 'Join Call',
            ),
          ),
          if (pendingRequest)
            const Padding(
              padding: EdgeInsets.only(top: 8),
              child: Text(
                'Waiting for host approval',
                style: TextStyle(
                  color: Colors.white70,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          if (seatError != null && seatError!.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: SizedBox(
                width: 220,
                child: Text(
                  seatError!,
                  textAlign: TextAlign.right,
                  style: const TextStyle(
                    color: Colors.redAccent,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _InlineErrorBanner extends StatelessWidget {
  final String message;

  const _InlineErrorBanner({required this.message});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: tokens.dangerColor.withOpacity(.18),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: tokens.dangerColor.withOpacity(.34)),
      ),
      child: Text(
        message,
        style: const TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

/* ---------- Small chrome ---------- */

class _FrostedCapsule extends StatelessWidget {
  final Widget child;
  const _FrostedCapsule({required this.child});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      margin: const EdgeInsets.only(top: 10),
      decoration: BoxDecoration(
        color: tokens.glassColor.withOpacity(.18),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: tokens.borderColor.withOpacity(.34)),
      ),
      child: child,
    );
  }
}

class _GlassPad extends StatelessWidget {
  final Widget child;
  const _GlassPad({required this.child});
  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: tokens.glassColor.withOpacity(.18),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: tokens.borderColor.withOpacity(.34)),
      ),
      child: child,
    );
  }
}

class _LiveDot extends StatefulWidget {
  final Color color;
  final Color glowColor;

  const _LiveDot({
    this.color = Colors.redAccent,
    this.glowColor = Colors.redAccent,
  });

  @override
  State<_LiveDot> createState() => _LiveDotState();
}

class _LiveDotState extends State<_LiveDot>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 900),
  )..repeat();
  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final t = _c.value;
    final s = 1 + 0.25 * (1 - (t - .5) * (t - .5) * 4);
    return Stack(
      alignment: Alignment.center,
      children: [
        Transform.scale(
          scale: s,
          child: Container(
            width: 14,
            height: 14,
            decoration: BoxDecoration(
              color: widget.glowColor.withOpacity(.6),
              shape: BoxShape.circle,
            ),
          ),
        ),
        Container(
          width: 8,
          height: 8,
          decoration: BoxDecoration(
            color: widget.color,
            shape: BoxShape.circle,
          ),
        ),
      ],
    );
  }
}

/* ---------- Speaking glow ---------- */
class _AnimatedVeil extends StatelessWidget {
  final AnimationController glow;
  final bool speaking;
  const _AnimatedVeil({required this.glow, required this.speaking});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return AnimatedBuilder(
      animation: glow,
      builder: (_, __) {
        final pulse = speaking ? (0.25 + glow.value * 0.25) : 0.25;
        return Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [
                tokens.backgroundGradient.first.withOpacity(.18 + pulse * .14),
                Colors.transparent,
                tokens.backgroundGradient.last.withOpacity(.34),
              ],
              stops: const [0.0, 0.55, 1.0],
            ),
          ),
        );
      },
    );
  }
}

/* ---------- In-room banner ---------- */
class _LiveInRoomBanner extends StatefulWidget {
  const _LiveInRoomBanner();

  @override
  State<_LiveInRoomBanner> createState() => _LiveInRoomBannerState();
}

class _LiveInRoomBannerState extends State<_LiveInRoomBanner> {
  static const _placement = 'live';
  late final PageController _pc = PageController();
  Timer? _ticker;
  int _index = 0;
  bool _loading = true;
  bool _dismissed = false;
  List<BannerItem> _banners = const [];
  final Set<int> _impressed = <int>{};

  @override
  void initState() {
    super.initState();
    _fetch();
    _ticker = Timer.periodic(const Duration(seconds: 5), (_) {
      if (!mounted || !_pc.hasClients || _banners.length < 2) return;
      _index = (_index + 1) % _banners.length;
      _pc.animateToPage(
        _index,
        duration: const Duration(milliseconds: 380),
        curve: Curves.easeOutCubic,
      );
    });
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _pc.dispose();
    super.dispose();
  }

  Future<void> _fetch() async {
    final items = await Get.find<BannerService>().fetchBanners(
      placement: _placement,
      forceRefresh: true,
    );
    if (!mounted) return;
    final fallback = <BannerItem>[
      const BannerItem(
        id: -501,
        title: 'You are live',
        imageUrl: '',
        actionType: 'none',
        buttonText: 'Your room is visible to viewers now',
      ),
      const BannerItem(
        id: -502,
        title: 'Boost engagement',
        imageUrl: '',
        actionType: 'none',
        buttonText: 'Use reactions and shout-outs to retain audience',
      ),
    ];
    setState(() {
      _banners = items.isEmpty ? fallback : items;
      _loading = false;
      _dismissed = false;
      _index = 0;
      _impressed.clear();
    });
    await _trackImpression(0);
  }

  Future<void> _trackImpression(int index) async {
    if (index < 0 || index >= _banners.length) return;
    final b = _banners[index];
    if (b.id <= 0 || _impressed.contains(b.id)) return;
    _impressed.add(b.id);
    await Get.find<BannerService>().trackImpression(
      bannerId: b.id,
      placement: _placement,
      context: const {'screen': 'live_video', 'slot': 'in_room_bottom'},
    );
  }

  Future<void> _tap(BannerItem b) async {
    if (b.id > 0) {
      await Get.find<BannerService>().trackClick(
        bannerId: b.id,
        placement: _placement,
        context: const {'screen': 'live_video', 'slot': 'in_room_bottom'},
      );
    }
    final type = b.actionType.toLowerCase().trim();
    final value = b.actionValue?.trim();
    if (value == null || value.isEmpty || type == 'none') return;

    try {
      if (type == 'route' || (type == 'deeplink' && value.startsWith('/'))) {
        await Get.toNamed(value);
        return;
      }
      final uri = Uri.tryParse(value);
      if (uri == null) return;
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    if (_dismissed) {
      return const SizedBox.shrink();
    }
    if (_loading) {
      return const SizedBox(height: 84);
    }
    return SizedBox(
      height: 84,
      child: Stack(
        children: [
          PageView.builder(
            controller: _pc,
            itemCount: _banners.length,
            onPageChanged: (v) async {
              setState(() => _index = v);
              await _trackImpression(v);
            },
            itemBuilder: (_, i) {
              final b = _banners[i];
              return InkWell(
                borderRadius: BorderRadius.circular(22),
                onTap: () => _tap(b),
                child: _card(
                  b.title,
                  b.buttonText,
                  b.hasImage ? b.imageUrl : null,
                  hasAction:
                      (b.actionType.toLowerCase().trim() != 'none') &&
                      (b.actionValue?.trim().isNotEmpty ?? false),
                ),
              );
            },
          ),
          Positioned(
            top: 8,
            right: 8,
            child: Material(
              color: Colors.transparent,
              child: InkWell(
                onTap: () => setState(() => _dismissed = true),
                borderRadius: BorderRadius.circular(999),
                child: Container(
                  width: 24,
                  height: 24,
                  decoration: BoxDecoration(
                    color: Colors.black.withOpacity(.26),
                    borderRadius: BorderRadius.circular(999),
                    border: Border.all(color: Colors.white.withOpacity(.22)),
                  ),
                  child: const Icon(
                    Icons.close_rounded,
                    size: 13,
                    color: Colors.white,
                  ),
                ),
              ),
            ),
          ),
          if (_banners.length > 1)
            Positioned(
              bottom: 5,
              left: 0,
              right: 0,
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(
                  _banners.length,
                  (i) => AnimatedContainer(
                    duration: const Duration(milliseconds: 220),
                    margin: const EdgeInsets.symmetric(horizontal: 2),
                    width: i == _index ? 12 : 5,
                    height: 5,
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(i == _index ? .92 : .40),
                      borderRadius: BorderRadius.circular(999),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _card(
    String title,
    String? sub,
    String? imageUrl, {
    required bool hasAction,
  }) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(22),
      child: Stack(
        fit: StackFit.expand,
        children: [
          if (imageUrl != null)
            Image.network(
              imageUrl,
              fit: BoxFit.cover,
              errorBuilder: (_, __, ___) => const SizedBox.shrink(),
            ),
          DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors:
                    imageUrl == null
                        ? const [
                          Color(0xE2191231),
                          Color(0xE4362058),
                          Color(0xE2502F85),
                        ]
                        : const [
                          Color(0x6E12091F),
                          Color(0xAF1F1336),
                          Color(0xD2281850),
                        ],
              ),
              border: Border.all(color: Colors.white.withOpacity(.20)),
              borderRadius: BorderRadius.circular(22),
              boxShadow: [
                BoxShadow(color: Colors.black.withOpacity(.4), blurRadius: 18),
              ],
            ),
          ),
          Positioned(
            right: -18,
            top: -20,
            child: Container(
              width: 88,
              height: 88,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.white.withOpacity(.09),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            child: Row(
              children: [
                Container(
                  width: 32,
                  height: 32,
                  decoration: BoxDecoration(
                    color: Colors.white.withOpacity(.14),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(
                    Icons.campaign_rounded,
                    color: Colors.white,
                    size: 18,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        'LIVE UPDATE',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: Colors.white.withOpacity(.72),
                          fontWeight: FontWeight.w800,
                          letterSpacing: 1,
                          fontSize: 9.5,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w900,
                          fontSize: 14,
                        ),
                      ),
                      if (sub != null && sub.isNotEmpty)
                        Text(
                          sub,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: Colors.white70,
                            fontWeight: FontWeight.w700,
                            fontSize: 10.8,
                          ),
                        ),
                    ],
                  ),
                ),
                if (hasAction)
                  Container(
                    width: 28,
                    height: 28,
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(.12),
                      borderRadius: BorderRadius.circular(999),
                      border: Border.all(color: Colors.white.withOpacity(.24)),
                    ),
                    child: const Icon(
                      Icons.chevron_right_rounded,
                      color: Colors.white,
                      size: 16,
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

/* ---------------- Reaction RAIL (left-center, vertical) ---------------- */

class _ReactionRail extends StatefulWidget {
  const _ReactionRail({
    required this.onTapEmoji,
    this.emojis = const [
      '👏',
      '❤️',
      '🔥',
      '💎',
      '✨',
      '😂',
      '😮',
      '🥳',
      '🙌',
      '💖',
      '🌟',
      '⚡️',
      '🎉',
    ],
  });

  final void Function(String emoji) onTapEmoji;
  final List<String> emojis;

  @override
  State<_ReactionRail> createState() => _ReactionRailState();
}

class _ReactionRailState extends State<_ReactionRail>
    with TickerProviderStateMixin {
  bool _open =
      true; // default open so it's obvious; set false if you want collapsed by default
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 280),
  );

  @override
  void initState() {
    super.initState();
    if (_open) _c.value = 1; // start opened
  }

  void _toggle() {
    setState(() => _open = !_open);
    if (_open) {
      _c.forward();
    } else {
      _c.reverse();
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final quick = widget.emojis.take(2).toList();

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        // Toggle pill + quick emojis
        _GlassPad(
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              InkWell(
                onTap: _toggle,
                borderRadius: BorderRadius.circular(10),
                child: const Padding(
                  padding: EdgeInsets.symmetric(horizontal: 8, vertical: 6),
                  child: Icon(Icons.auto_awesome_rounded, color: Colors.white),
                ),
              ),
              const SizedBox(width: 6),
              for (final e in quick)
                InkWell(
                  onTap: () => widget.onTapEmoji(e),
                  borderRadius: BorderRadius.circular(8),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 6,
                      vertical: 4,
                    ),
                    child: Text(e, style: const TextStyle(fontSize: 18)),
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: 10),

        // Vertical expandable list
        SizeTransition(
          sizeFactor: CurvedAnimation(parent: _c, curve: Curves.easeOutCubic),
          axisAlignment: -1.0,
          child: ScaleTransition(
            scale: CurvedAnimation(parent: _c, curve: Curves.easeOutBack),
            child: _GlassPad(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxHeight: 280, minWidth: 64),
                child: ScrollConfiguration(
                  behavior: const _NoGlowScrollBehavior(),
                  child: ListView.separated(
                    shrinkWrap: true,
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    itemBuilder: (_, i) {
                      final e = widget.emojis[i];
                      return _ReactionBtnV(
                        emoji: e,
                        onTap: () => widget.onTapEmoji(e),
                      );
                    },
                    separatorBuilder: (_, __) => const SizedBox(height: 6),
                    itemCount: widget.emojis.length,
                  ),
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _ReactionBtnV extends StatefulWidget {
  const _ReactionBtnV({required this.emoji, required this.onTap});
  final String emoji;
  final VoidCallback onTap;

  @override
  State<_ReactionBtnV> createState() => _ReactionBtnVState();
}

class _ReactionBtnVState extends State<_ReactionBtnV>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 120),
  );
  late final Animation<double> _scale = Tween(
    begin: 1.0,
    end: 1.18,
  ).animate(CurvedAnimation(parent: _c, curve: Curves.easeOut));

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  Future<void> _tap() async {
    await _c.forward();
    await _c.reverse();
    widget.onTap();
  }

  @override
  Widget build(BuildContext context) {
    return ScaleTransition(
      scale: _scale,
      child: InkWell(
        onTap: _tap,
        borderRadius: BorderRadius.circular(12),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
          child: Center(
            child: Text(widget.emoji, style: const TextStyle(fontSize: 20)),
          ),
        ),
      ),
    );
  }
}

class _NoGlowScrollBehavior extends ScrollBehavior {
  const _NoGlowScrollBehavior();
  @override
  Widget buildOverscrollIndicator(
    BuildContext context,
    Widget child,
    ScrollableDetails details,
  ) => child;
}

/* ---------------- Tool RAIL (right-center) ---------------- */

class _ToolRail extends StatefulWidget {
  const _ToolRail({
    required this.micOn,
    required this.camOn,
    required this.onToggleMic,
    required this.onToggleCam,
    required this.onFlip,
  });

  final bool micOn;
  final bool camOn;
  final VoidCallback onToggleMic;
  final VoidCallback onToggleCam;
  final VoidCallback onFlip;

  @override
  State<_ToolRail> createState() => _ToolRailState();
}

class _ToolRailState extends State<_ToolRail>
    with SingleTickerProviderStateMixin {
  bool _open = true;
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 260),
  );

  @override
  void initState() {
    super.initState();
    if (_open) _c.value = 1;
  }

  void _toggle() {
    setState(() => _open = !_open);
    if (_open)
      _c.forward();
    else
      _c.reverse();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final micBg = widget.micOn ? Colors.greenAccent.shade400 : Colors.redAccent;
    final camBg = widget.camOn ? Colors.greenAccent.shade400 : Colors.redAccent;

    Widget entry(Widget child, int idx) {
      final curve = Interval(0.05 * idx, 1.0, curve: Curves.easeOutCubic);
      return SlideTransition(
        position: Tween<Offset>(
          begin: const Offset(0.4, 0),
          end: Offset.zero,
        ).animate(CurvedAnimation(parent: _c, curve: curve)),
        child: FadeTransition(
          opacity: CurvedAnimation(parent: _c, curve: curve),
          child: child,
        ),
      );
    }

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        entry(
          FloatingActionButton.small(
            heroTag: 'mic',
            backgroundColor: micBg,
            onPressed: widget.onToggleMic,
            child: const Icon(Icons.mic_rounded, color: Colors.black),
          ),
          1,
        ),
        const SizedBox(height: 10),
        entry(
          FloatingActionButton.small(
            heroTag: 'cam',
            backgroundColor: camBg,
            onPressed: widget.onToggleCam,
            child: const Icon(Icons.videocam_rounded, color: Colors.black),
          ),
          2,
        ),
        const SizedBox(height: 10),
        entry(
          FloatingActionButton.small(
            heroTag: 'flip',
            backgroundColor: Colors.white24,
            onPressed: widget.onFlip,
            child: const Icon(Icons.cameraswitch_rounded, color: Colors.white),
          ),
          3,
        ),
        const SizedBox(height: 10),
        _GlassPad(
          child: InkWell(
            onTap: _toggle,
            borderRadius: BorderRadius.circular(10),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
              child: Icon(
                _open ? Icons.close_fullscreen : Icons.open_in_full,
                color: Colors.white70,
                size: 18,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/* ---------------- Optimized emoji burst overlay ---------------- */

class _EmojiBurst extends StatefulWidget {
  const _EmojiBurst({super.key});
  @override
  State<_EmojiBurst> createState() => _EmojiBurstState();
}

class _EmojiBurstState extends State<_EmojiBurst>
    with SingleTickerProviderStateMixin {
  static final Paint _paint = Paint()..filterQuality = FilterQuality.low;
  final Map<String, ui.Image> _imgCache = {};
  static const int _maxBursts = 36;
  final List<_Burst> _bursts = <_Burst>[];

  AnimationController? _tick;

  @override
  void initState() {
    super.initState();
    _tick =
        AnimationController(vsync: this, duration: const Duration(hours: 1))
          ..addListener(() {
            final nowMs = DateTime.now().millisecondsSinceEpoch.toDouble();
            _bursts.removeWhere((b) => nowMs - b.t0Ms > b.lifeMs);
            if (mounted) setState(() {});
          })
          ..forward();
  }

  @override
  void dispose() {
    _tick?.dispose();
    _tick = null;
    super.dispose();
  }

  Future<ui.Image> _imageFor(String emoji) async {
    final cached = _imgCache[emoji];
    if (cached != null) return cached;

    final tp = TextPainter(
      text: TextSpan(text: emoji, style: const TextStyle(fontSize: 30)),
      textDirection: TextDirection.ltr,
    )..layout();
    final w = tp.width.ceil();
    final h = tp.height.ceil();
    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder);
    tp.paint(canvas, Offset.zero);
    final picture = recorder.endRecording();

    final img = await picture.toImage(w, h);
    _imgCache[emoji] = img;
    return img;
  }

  Future<void> burst(String emoji) async {
    final img = await _imageFor(emoji);
    final now = DateTime.now().millisecondsSinceEpoch.toDouble();
    final rnd = math.Random();

    if (_bursts.length >= _maxBursts) {
      _bursts.removeAt(0);
    }

    final startX = 0.2 + rnd.nextDouble() * 0.6;
    _bursts.add(
      _Burst(img: img, t0Ms: now, xN: startX, seed: rnd.nextDouble()),
    );
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    if (_bursts.isEmpty) return const SizedBox.shrink();
    return CustomPaint(
      painter: _BurstPainter(_bursts, _paint),
      size: Size.infinite,
      isComplex: true,
      willChange: true,
    );
  }
}

class _Burst {
  final ui.Image img;
  final double t0Ms;
  final double xN;
  final double seed;
  final double lifeMs = 1400;

  _Burst({
    required this.img,
    required this.t0Ms,
    required this.xN,
    required this.seed,
  });
}

class _BurstPainter extends CustomPainter {
  final List<_Burst> bursts;
  final Paint paintRef;
  _BurstPainter(this.bursts, this.paintRef);

  @override
  void paint(Canvas canvas, Size size) {
    if (bursts.isEmpty) return;
    final nowMs = DateTime.now().millisecondsSinceEpoch.toDouble();
    final h = size.height, w = size.width;
    final p = paintRef;

    for (final b in bursts) {
      final t = ((nowMs - b.t0Ms) / b.lifeMs).clamp(0.0, 1.0);
      final y = h - (h * 0.55 * Curves.easeOut.transform(t));
      final dx = math.sin((t * 7 + b.seed) * math.pi) * 44.0;

      final alpha = ((1.0 - t) * 255).toInt().clamp(0, 255);
      p.color = Color.fromARGB(alpha, 255, 255, 255);

      final imgW = b.img.width.toDouble();
      final imgH = b.img.height.toDouble();
      final x = (b.xN * w) + dx - imgW / 2;

      canvas.drawImageRect(
        b.img,
        Rect.fromLTWH(0, 0, imgW, imgH),
        Rect.fromLTWH(x, y, imgW, imgH),
        p,
      );
    }
  }

  @override
  bool shouldRepaint(covariant _BurstPainter oldDelegate) => true;
}
