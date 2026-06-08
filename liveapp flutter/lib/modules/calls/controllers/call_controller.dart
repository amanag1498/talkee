import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart' show Helper;
import 'package:get/get.dart';
import 'package:livekit_client/livekit_client.dart';

import '../../../app/routes/app_routes.dart';
import '../../../app/routes/app_urls.dart';
import '../../../app/theme/brand.dart';
import '../../../app/widgets/haptics.dart';
import '../../../app/widgets/keep_awake_scope.dart';
import '../../../services/auth_service.dart';
import '../../../services/app_settings_service.dart';
import '../../../services/call_vibration_service.dart';
import '../../../services/call_service.dart';
import '../../../services/call_socket_service.dart';
import '../../wallet/widgets/recharge_bottom_sheet.dart';
import '../views/call_ui.dart';

class AppCallController extends GetxController with WidgetsBindingObserver {
  AppCallController(this._auth, this._callService, this._socketService);

  final AuthService _auth;
  final CallService _callService;
  final CallSocketService _socketService;

  final Rxn<Map<String, dynamic>> incomingCall = Rxn<Map<String, dynamic>>();
  final Rxn<Map<String, dynamic>> activeCall = Rxn<Map<String, dynamic>>();
  final Rxn<Map<String, dynamic>> callToken = Rxn<Map<String, dynamic>>();
  final Rxn<Map<String, dynamic>> availabilityEvent = Rxn<Map<String, dynamic>>();
  final RxString callState = 'idle'.obs;
  final RxBool busy = false.obs;
  final RxBool reconnecting = false.obs;
  final RxBool roomConnecting = false.obs;
  final RxBool micOn = true.obs;
  final RxBool camOn = true.obs;
  final RxBool speakerOn = true.obs;
  final RxString roomError = ''.obs;
  final RxBool callMinimized = false.obs;
  final RxInt elapsedSeconds = 0.obs;
  final RxInt ringingSecondsLeft = 0.obs;
  final RxInt roomRevision = 0.obs;

  Timer? _ticker;
  Timer? _ringingTimer;
  DateTime? _callClockAnchor;
  bool _navigationBusy = false;
  bool _terminalHandling = false;
  bool _isExitingCall = false;
  bool _terminalStateHandled = false;
  bool _roomBusy = false;
  bool _callWakeLockAcquired = false;
  final Set<String> _handledEvents = <String>{};
  OverlayEntry? _callOverlay;
  OverlayEntry? _incomingOverlay;
  Room? _room;
  EventsListener<RoomEvent>? _roomListener;
  String? _lastHapticState;

  PremiumThemeTokens get _tokens => getPremiumThemeTokens(
    Get.find<AppSettingsService>().activePremiumThemeVariant,
  );

  Room? get room => _room;
  bool get hasActiveRoom => _room != null;
  bool get isVideoCall => currentCallType == 'video';
  bool get isAudioCall => currentCallType == 'audio';
  bool get hasActiveCall => activeCall.value != null;
  int get currentCallId =>
      (activeCall.value?['id'] as int?) ??
      (activeCall.value?['call_id'] as int?) ??
      0;

  String get currentCallType => (activeCall.value?['type'] ?? 'audio').toString();

  String get remoteDisplayName {
    final call = activeCall.value ?? incomingCall.value ?? <String, dynamic>{};
    final currentUserId = _auth.currentUser?.id;
    final callerId = (call['caller_id'] as num?)?.toInt();
    final receiverId = (call['receiver_id'] as num?)?.toInt();
    final caller = Map<String, dynamic>.from(call['caller'] as Map? ?? const {});
    final receiver = Map<String, dynamic>.from(call['receiver'] as Map? ?? const {});
    final host = Map<String, dynamic>.from(call['host'] as Map? ?? const {});

    if (currentUserId != null && receiverId == currentUserId) {
      if (call['caller_display_name'] != null &&
          call['caller_display_name'].toString().trim().isNotEmpty) {
        return call['caller_display_name'].toString();
      }
      if (caller['name'] != null && caller['name'].toString().trim().isNotEmpty) {
        return caller['name'].toString();
      }
      if (call['caller_name'] != null && call['caller_name'].toString().trim().isNotEmpty) {
        return call['caller_name'].toString();
      }
    }

    if (currentUserId != null && callerId == currentUserId) {
      if (host['stage_name'] != null && host['stage_name'].toString().trim().isNotEmpty) {
        return host['stage_name'].toString();
      }
      if (receiver['name'] != null && receiver['name'].toString().trim().isNotEmpty) {
        return receiver['name'].toString();
      }
    }

    if (host['stage_name'] != null && host['stage_name'].toString().trim().isNotEmpty) {
      return host['stage_name'].toString();
    }
    if (caller['name'] != null && caller['name'].toString().trim().isNotEmpty) {
      return caller['name'].toString();
    }
    if (receiver['name'] != null && receiver['name'].toString().trim().isNotEmpty) {
      return receiver['name'].toString();
    }
    if (call['caller_name'] != null && call['caller_name'].toString().trim().isNotEmpty) {
      return call['caller_name'].toString();
    }
    return 'Talkieo User';
  }

  String get remoteAvatarUrl {
    final call = activeCall.value ?? incomingCall.value ?? <String, dynamic>{};
    final currentUserId = _auth.currentUser?.id;
    final callerId = (call['caller_id'] as num?)?.toInt();
    final receiverId = (call['receiver_id'] as num?)?.toInt();
    final caller = Map<String, dynamic>.from(call['caller'] as Map? ?? const {});
    final receiver = Map<String, dynamic>.from(call['receiver'] as Map? ?? const {});
    if (currentUserId != null && receiverId == currentUserId) {
      return (call['caller_avatar_url'] ?? caller['avatar_url'] ?? '').toString();
    }
    if (currentUserId != null && callerId == currentUserId) {
      return (receiver['avatar_url'] ?? '').toString();
    }
    return (receiver['avatar_url'] ?? caller['avatar_url'] ?? call['caller_avatar_url'] ?? '').toString();
  }

  int get ratePerMinute => ((activeCall.value?['coin_rate_per_minute'] as num?) ?? 0).toInt();

  String get durationLabel {
    return _formatDuration(elapsedSeconds.value);
  }

  int get estimatedCoins {
    final rate = ratePerMinute;
    if (rate <= 0) return 0;
    final minutes = (elapsedSeconds.value / 60).ceil();
    return minutes * rate;
  }

  String _formatDuration(int elapsed) {
    final mins = (elapsed ~/ 60).toString().padLeft(2, '0');
    final secs = (elapsed % 60).toString().padLeft(2, '0');
    return '$mins:$secs';
  }

  VideoTrack? get primaryRemoteVideoTrack {
    final room = _room;
    if (room == null) return null;
    for (final participant in room.remoteParticipants.values) {
      for (final publication in participant.trackPublications.values) {
        if (publication.muted) continue;
        final track = publication.track;
        if (track is VideoTrack && publication.source != TrackSource.screenShareVideo) {
          return track as VideoTrack;
        }
      }
    }
    return null;
  }

  VideoTrack? get localVideoTrack {
    final room = _room;
    if (room == null) return null;
    final publications = room.localParticipant?.trackPublications.values.toList() ??
        const <TrackPublication<dynamic>>[];
    for (final publication in publications) {
      if (publication.muted) continue;
      final track = publication.track;
      if (track is VideoTrack && publication.source != TrackSource.screenShareVideo) {
        return track;
      }
    }
    return null;
  }

  @override
  void onInit() {
    super.onInit();
    WidgetsBinding.instance.addObserver(this);
    _startSocketIfPossible();
  }

  Future<void> restartSocket() async {
    await _startSocketIfPossible(force: true);
  }

  Future<void> _startSocketIfPossible({bool force = false}) async {
    final token = _auth.storage.token;
    if (token == null || token.isEmpty) return;
    if (_socketService.isConnected && !force) return;

    await _socketService.start(
      url: AppUrls.wsCalls,
      bearerToken: token,
      onConnectionState: (state) {
        reconnecting.value = state == 'reconnecting' || state == 'disconnected';
        if (state == 'reconnecting') {
          callState.value = 'reconnecting';
        }
      },
      onIncomingCall: (payload) {
        if (_seenEvent('incoming:${payload['call_id']}')) return;
        _prepareForNewCall();
        incomingCall.value = payload;
        callState.value = 'incoming_ringing';
        _startRingingTimeout(incoming: true);
        _startIncomingVibration();
        _emitStateHaptic('incoming');
        _showIncomingOverlay();
      },
      onAvailabilityUpdated: (payload) {
        availabilityEvent.value = payload;
      },
      onCallAccepted: (payload) async {
        if (_seenEvent('accepted:${payload['call_id']}')) return;
        if (_terminalStateHandled || _isExitingCall) return;
        _stopIncomingVibration();
        _removeIncomingOverlay();
        activeCall.value = {
          ...?activeCall.value,
          ...payload,
        };
        _acquireCallWakeLock();
        incomingCall.value = null;
        _stopRingingTimeout();
        callState.value = callToken.value == null ? 'connecting' : 'connected';
        _emitStateHaptic('accepted');
        if (callToken.value == null) {
          await loadTokenForCall(payload['call_id'] as int? ?? 0);
        } else {
          await ensureRoomConnected();
        }
        _navigateSafely(Routes.activeCall, replace: true, disallowIfCurrent: const [Routes.activeCall]);
      },
      onCallRejected: (payload) async {
        if (_seenEvent('rejected:${payload['call_id']}')) return;
        await _handleTerminalState(
          state: 'rejected',
          message: 'Call rejected.',
          payload: payload,
          destructive: true,
        );
      },
      onCallMissed: (payload) async {
        if (_seenEvent('missed:${payload['call_id']}')) return;
        await _handleTerminalState(
          state: 'timeout',
          message: 'Call timed out.',
          payload: payload,
          destructive: true,
        );
      },
      onCallEnded: (payload) async {
        if (_seenEvent('ended:${payload['call_id']}')) return;
        await _handleTerminalState(
          state: 'ended',
          message: 'Call ended.',
          payload: payload,
          destructive: true,
        );
      },
      onCallFailed: (payload) async {
        final key = 'failed:${payload['call_id']}:${payload['reason']}';
        if (_seenEvent(key)) return;
        final reason = payload['reason']?.toString() ?? 'Call failed.';
        await _handleTerminalState(
          state: 'failed',
          message: _friendlyFailure(reason),
          payload: payload,
          destructive: true,
          failure: true,
        );
      },
      onForceLogout: (reason) => _auth.forceLogout(reason),
    );
  }

  Future<void> placeCall({
    required int receiverId,
    required String type,
  }) async {
    if (busy.value) return;
    busy.value = true;
    roomError.value = '';
    try {
      _prepareForNewCall();
      final payload = await _callService.requestCall(receiverId: receiverId, type: type);
      activeCall.value = payload;
      _acquireCallWakeLock();
      callState.value = 'outgoing_ringing';
      _startRingingTimeout(incoming: false);
      _emitStateHaptic('outgoing');
      _navigateSafely(
        Routes.outgoingCall,
        replace: false,
        disallowIfCurrent: const [Routes.outgoingCall, Routes.activeCall],
      );
    } catch (e) {
      final message = _extractMessage(e);
      await Haptics.error();
      if (isInsufficientCoinsErrorMessage(message)) {
        await showRechargeWalletSheet(
          reasonTitle: 'Not enough coins',
          reasonMessage:
              'You need more coins to start this call. Recharge your wallet and try again.',
        );
      }
      _showMessage(message);
    } finally {
      busy.value = false;
    }
  }

  Future<void> acceptIncoming() async {
    final callId = incomingCall.value?['call_id'] as int? ?? 0;
    if (callId <= 0 || busy.value) return;
    busy.value = true;
    try {
      await Haptics.medium();
      _handledEvents.add('accepted:$callId');
      final incomingSnapshot = Map<String, dynamic>.from(incomingCall.value ?? const {});
      _removeIncomingOverlay();
      _stopRingingTimeout();
      final acceptedCall = await _callService.acceptCall(callId);
      activeCall.value = {
        ...incomingSnapshot,
        ...acceptedCall,
        if ((incomingSnapshot['caller_name'] ?? '').toString().trim().isNotEmpty)
          'caller_display_name': incomingSnapshot['caller_name'].toString(),
        if ((incomingSnapshot['caller_avatar_url'] ?? '').toString().trim().isNotEmpty)
          'caller_avatar_url': incomingSnapshot['caller_avatar_url'].toString(),
      };
      _acquireCallWakeLock();
      incomingCall.value = null;
      callState.value = 'connecting';
      _stopIncomingVibration();
      await loadTokenForCall(callId);
      _navigateSafely(Routes.activeCall, replace: true, disallowIfCurrent: const [Routes.activeCall]);
    } catch (e) {
      final message = _extractMessage(e);
      await Haptics.error();
      _showMessage(message);
    } finally {
      busy.value = false;
    }
  }

  Future<void> rejectIncoming() async {
    final callId = incomingCall.value?['call_id'] as int? ?? 0;
    if (callId <= 0 || busy.value) return;
    busy.value = true;
    try {
      await Haptics.heavy();
      _handledEvents.add('rejected:$callId');
      _stopIncomingVibration();
      _removeIncomingOverlay();
      _stopRingingTimeout();
      await _callService.rejectCall(callId);
      incomingCall.value = null;
      await _handleTerminalState(state: 'rejected', message: 'Call rejected.');
    } catch (e) {
      _showMessage(_extractMessage(e));
    } finally {
      busy.value = false;
    }
  }

  Future<void> cancelOutgoing() async {
    final callId = currentCallId;
    if (callId <= 0 || busy.value) return;
    busy.value = true;
    try {
      await Haptics.heavy();
      _handledEvents.add('ended:$callId');
      _stopRingingTimeout();
      await _callService.endCall(callId, reason: 'cancelled_by_caller');
      await _handleTerminalState(state: 'cancelled', message: 'Call cancelled.');
    } catch (e) {
      _showMessage(_extractMessage(e));
    } finally {
      busy.value = false;
    }
  }

  Future<void> endActiveCall() async {
    final callId = currentCallId;
    if (callId <= 0 || busy.value) return;
    busy.value = true;
    try {
      await Haptics.heavy();
      _handledEvents.add('ended:$callId');
      _stopRingingTimeout();
      callState.value = 'ending';
      final endedPayload = await _callService.endCall(
        callId,
        reason: 'ended_by_user',
      );
      await _handleTerminalState(
        state: 'ended',
        message: 'Call ended.',
        payload: endedPayload,
      );
    } catch (e) {
      _showMessage(_extractMessage(e));
    } finally {
      busy.value = false;
    }
  }

  Future<void> loadTokenForCall(int callId) async {
    if (callId <= 0) return;
    try {
      callState.value = 'connecting';
      callToken.value = await _callService.fetchCallToken(callId);
      reconnecting.value = false;
      _startTicker();
      await ensureRoomConnected();
    } catch (e) {
      _showMessage(_extractMessage(e));
    }
  }

  Future<void> ensureRoomConnected() async {
    if (_roomBusy) return;
    final tokenData = callToken.value;
    if (tokenData == null) return;
    if (_room != null) return;
    _roomBusy = true;
    roomConnecting.value = true;
    roomError.value = '';
    try {
      final room = Room(
        roomOptions: const RoomOptions(adaptiveStream: true, dynacast: true),
      );
      final listener = room.createListener();
      _bindRoomEvents(room, listener);

      await room.connect(
        tokenData['ws_url'].toString(),
        tokenData['token'].toString(),
        connectOptions: const ConnectOptions(autoSubscribe: true),
      );

      final shouldUseCamera = isVideoCall;
      camOn.value = shouldUseCamera;
      speakerOn.value = true;
      await room.localParticipant?.setMicrophoneEnabled(micOn.value);
      await room.localParticipant?.setCameraEnabled(shouldUseCamera);
      await Helper.setSpeakerphoneOn(speakerOn.value);

      _room = room;
      _roomListener = listener;
      callState.value = 'connected';
      reconnecting.value = false;
      roomRevision.value++;
    } catch (_) {
      roomError.value = 'Failed to connect call media.';
      callState.value = 'failed';
    } finally {
      roomConnecting.value = false;
      _roomBusy = false;
    }
  }

  void _bindRoomEvents(Room room, EventsListener<RoomEvent> listener) {
    listener
      ..on<RoomDisconnectedEvent>((event) {
        roomError.value = 'Disconnected: ${event.reason ?? 'unknown'}';
        reconnecting.value = true;
        roomRevision.value++;
      })
      ..on<RoomReconnectingEvent>((_) {
        reconnecting.value = true;
        roomRevision.value++;
      })
      ..on<RoomReconnectedEvent>((_) {
        reconnecting.value = false;
        roomError.value = '';
        roomRevision.value++;
      })
      ..on<ParticipantConnectedEvent>((_) => roomRevision.value++)
      ..on<ParticipantDisconnectedEvent>((_) => roomRevision.value++)
      ..on<TrackSubscribedEvent>((_) => roomRevision.value++)
      ..on<TrackUnsubscribedEvent>((_) => roomRevision.value++)
      ..on<LocalTrackPublishedEvent>((_) => roomRevision.value++)
      ..on<LocalTrackUnpublishedEvent>((_) => roomRevision.value++);
  }

  Future<void> toggleMute() async {
    final room = _room;
    if (room == null) return;
    final next = !micOn.value;
    await room.localParticipant?.setMicrophoneEnabled(next);
    micOn.value = next;
    roomRevision.value++;
    await Haptics.selection();
  }

  Future<void> toggleCamera() async {
    final room = _room;
    if (room == null || isAudioCall) return;
    final next = !camOn.value;
    await room.localParticipant?.setCameraEnabled(next);
    camOn.value = next;
    roomRevision.value++;
    await Haptics.selection();
  }

  Future<void> toggleSpeaker() async {
    final next = !speakerOn.value;
    await Helper.setSpeakerphoneOn(next);
    speakerOn.value = next;
    await Haptics.selection();
  }

  Future<void> minimizeCall() async {
    if (!hasActiveCall || callState.value == 'idle') return;
    callMinimized.value = true;
    _showOverlay();
    await _dismissCallPresentationForMinimize();
  }

  Future<void> restoreCall() async {
    if (!hasActiveCall) return;
    callMinimized.value = false;
    _removeOverlay();
    await _navigateSafely(
      Routes.activeCall,
      replace: false,
      disallowIfCurrent: const [Routes.activeCall],
    );
  }

  Future<void> openIncomingFullScreen() async {
    if (incomingCall.value == null) return;
    await Haptics.light();
    _removeIncomingOverlay();
    await _navigateSafely(
      Routes.incomingCall,
      replace: false,
      disallowIfCurrent: const [Routes.incomingCall, Routes.activeCall],
    );
  }

  Future<void> showBackOptions() async {
    if (!hasActiveCall) return;
    final tokens = _tokens;
    await Get.bottomSheet<void>(
      SafeArea(
        child: Container(
          padding: const EdgeInsets.fromLTRB(18, 16, 18, 18),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: tokens.cardGradient,
            ),
            borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
            border: Border.all(color: tokens.borderColor),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 46,
                height: 4,
                decoration: BoxDecoration(
                  color: tokens.borderColor.withValues(alpha: .72),
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
              const SizedBox(height: 18),
              Text(
                'Leave full-screen call?',
                style: TextStyle(
                  color: tokens.textPrimary,
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'You can minimize the call and keep it running, or end it now.',
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: tokens.textSecondary.withValues(alpha: .84),
                ),
              ),
              const SizedBox(height: 18),
              _sheetAction(
                label: 'Minimize Call',
                icon: Icons.picture_in_picture_alt_rounded,
                onTap: () async {
                  Get.back<void>();
                  await minimizeCall();
                },
              ),
              const SizedBox(height: 10),
              _sheetAction(
                label: 'End Call',
                icon: Icons.call_end_rounded,
                color: const Color(0xFFFF6B7A),
                onTap: () async {
                  Get.back<void>();
                  await endActiveCall();
                },
              ),
              const SizedBox(height: 10),
              _sheetAction(
                label: 'Cancel',
                icon: Icons.close_rounded,
                outlined: true,
                onTap: () => Get.back<void>(),
              ),
            ],
          ),
        ),
      ),
      isScrollControlled: false,
      backgroundColor: Colors.transparent,
    );
  }

  Widget _sheetAction({
    required String label,
    required IconData icon,
    required VoidCallback onTap,
    Color color = const Color(0xFF8F6BFF),
    bool outlined = false,
  }) {
    final tokens = _tokens;
    return SizedBox(
      width: double.infinity,
      child: FilledButton.icon(
        style: FilledButton.styleFrom(
          backgroundColor:
              outlined ? tokens.glassColor.withValues(alpha: .52) : color,
          foregroundColor: tokens.textPrimary,
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        ),
        onPressed: onTap,
        icon: Icon(icon),
        label: Text(label),
      ),
    );
  }

  void _showOverlay() {
    if (_callOverlay != null || Get.overlayContext == null) return;
    _callOverlay = OverlayEntry(
      builder: (context) {
        final media = MediaQuery.of(context);
        return Positioned(
          top: media.padding.top + 4,
          left: 8,
          right: 8,
          child: Material(
            color: Colors.transparent,
            child: Obx(() {
              if (!hasActiveCall) return const SizedBox.shrink();
              final tokens = _tokens;
              final elapsed = elapsedSeconds.value;
              final overlayDuration = _formatDuration(elapsed);
              return Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 460),
                  child: LayoutBuilder(
                    builder: (context, constraints) {
                      final compact = constraints.maxWidth < 390;
                      final avatarRadius = compact ? 18.0 : 22.0;
                      final horizontalPadding = compact ? 10.0 : 12.0;
                      final verticalPadding = compact ? 8.0 : 10.0;
                      final gap = compact ? 8.0 : 12.0;
                      final chipGap = compact ? 4.0 : 6.0;
                      final endSize = compact ? 36.0 : 40.0;
                      return GestureDetector(
                        onTap: restoreCall,
                        child: Container(
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              colors: tokens.cardGradient,
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                            ),
                            borderRadius: BorderRadius.circular(compact ? 18 : 22),
                            border: Border.all(color: tokens.borderColor),
                            boxShadow: [
                              BoxShadow(
                                color: tokens.glowColor.withValues(alpha: .24),
                                blurRadius: compact ? 16 : 20,
                                offset: const Offset(0, 12),
                              ),
                            ],
                          ),
                          padding: EdgeInsets.symmetric(
                            horizontal: horizontalPadding,
                            vertical: verticalPadding,
                          ),
                          child: Row(
                            children: [
                              CircleAvatar(
                                radius: avatarRadius,
                                backgroundColor: tokens.cardGradient.first,
                                backgroundImage: remoteAvatarUrl.isNotEmpty
                                    ? NetworkImage(remoteAvatarUrl)
                                    : null,
                                child: remoteAvatarUrl.isEmpty
                                    ? Icon(
                                        Icons.person_rounded,
                                        size: compact ? 18 : 22,
                                        color: tokens.textPrimary,
                                      )
                                    : null,
                              ),
                              SizedBox(width: gap),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Text(
                                      remoteDisplayName,
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: TextStyle(
                                        color: tokens.textPrimary,
                                        fontWeight: FontWeight.w800,
                                        fontSize: compact ? 13 : 14,
                                      ),
                                    ),
                                    SizedBox(height: compact ? 2 : 3),
                                    Text(
                                      '${isVideoCall ? 'Video' : 'Audio'} • $overlayDuration',
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: TextStyle(
                                        color: tokens.textSecondary.withValues(alpha: .82),
                                        fontWeight: FontWeight.w600,
                                        fontSize: compact ? 11.5 : 12.5,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              SizedBox(width: compact ? 6 : 8),
                              FittedBox(
                                fit: BoxFit.scaleDown,
                                alignment: Alignment.centerRight,
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    _overlayChip(
                                      icon: micOn.value
                                          ? Icons.mic_rounded
                                          : Icons.mic_off_rounded,
                                      compact: compact,
                                    ),
                                    if (isVideoCall) ...[
                                      SizedBox(width: chipGap),
                                      _overlayChip(
                                        icon: camOn.value
                                            ? Icons.videocam_rounded
                                            : Icons.videocam_off_rounded,
                                        compact: compact,
                                      ),
                                    ],
                                    SizedBox(width: compact ? 6 : 8),
                                    GestureDetector(
                                      onTap: endActiveCall,
                                      child: Container(
                                        width: endSize,
                                        height: endSize,
                                        decoration: const BoxDecoration(
                                          color: Color(0xFFFF6B7A),
                                          shape: BoxShape.circle,
                                        ),
                                        child: Icon(
                                          Icons.call_end_rounded,
                                          size: compact ? 18 : 20,
                                          color: tokens.textPrimary,
                                        ),
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
                  ),
                ),
              );
            }),
          ),
        );
      },
    );
    Overlay.of(Get.overlayContext!).insert(_callOverlay!);
  }

  void _showIncomingOverlay() {
    if (_incomingOverlay != null || Get.overlayContext == null) return;
    _incomingOverlay = OverlayEntry(
      builder: (context) => Positioned(
        top: MediaQuery.of(context).padding.top + 4,
        left: 12,
        right: 12,
        child: Material(
          color: Colors.transparent,
          child: Obx(() {
              final call = incomingCall.value;
              if (call == null) return const SizedBox.shrink();
              final tokens = _tokens;
              final type = (call['type'] ?? 'audio').toString();
              final callerName = (call['caller_name'] ?? 'Incoming Caller').toString();
              final callerAvatar = (call['caller_avatar_url'] ?? '').toString();

              return GestureDetector(
                onTap: () {
                  openIncomingFullScreen();
                },
                child: Container(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      colors: tokens.cardGradient,
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                    ),
                    borderRadius: BorderRadius.circular(24),
                    border: Border.all(color: tokens.borderColor),
                    boxShadow: [
                      BoxShadow(
                        color: tokens.glowColor.withValues(alpha: .24),
                        blurRadius: 28,
                        offset: const Offset(0, 14),
                      ),
                    ],
                  ),
                  padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
                  child: Row(
                    children: [
                      IncomingHeadsUpAvatar(
                        name: callerName,
                        avatarUrl: callerAvatar,
                        accent: type == 'video' ? const Color(0xFF7D9BFF) : const Color(0xFF8F6BFF),
                        icon: type == 'video' ? Icons.videocam_rounded : Icons.call_rounded,
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(
                              callerName,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 16,
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              type == 'video' ? 'Incoming video call' : 'Incoming audio call',
                              style: TextStyle(
                                color: tokens.textSecondary.withValues(alpha: .82),
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                            const SizedBox(height: 8),
                            Row(
                              children: [
                                const IncomingPulseDot(),
                                const SizedBox(width: 6),
                                Text(
                                  'Tap to open • ${ringingSecondsLeft.value}s',
                                  style: TextStyle(
                                    color: tokens.textSecondary.withValues(alpha: .9),
                                    fontSize: 12,
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 10),
                      _headsUpAction(
                        icon: Icons.call_end_rounded,
                        color: const Color(0xFFFF6B7A),
                        onTap: busy.value ? null : () { rejectIncoming(); },
                      ),
                      const SizedBox(width: 8),
                      _headsUpAction(
                        icon: type == 'video' ? Icons.videocam_rounded : Icons.call_rounded,
                        color: const Color(0xFF55D38A),
                        onTap: busy.value ? null : () { acceptIncoming(); },
                      ),
                    ],
                  ),
                ),
              );
            }),
        ),
      ),
    );
    Overlay.of(Get.overlayContext!).insert(_incomingOverlay!);
  }

  Widget _headsUpAction({
    required IconData icon,
    required Color color,
    required VoidCallback? onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: 44,
        height: 44,
        decoration: BoxDecoration(
          color: color,
          shape: BoxShape.circle,
          boxShadow: [
            BoxShadow(
              color: color.withValues(alpha: .26),
              blurRadius: 16,
              offset: const Offset(0, 8),
            ),
          ],
        ),
        child: Icon(icon, color: const Color(0xFF0B0716), size: 22),
      ),
    );
  }

  Widget _overlayChip({required IconData icon, bool compact = false}) {
    final tokens = _tokens;
    return Container(
      width: compact ? 28 : 32,
      height: compact ? 28 : 32,
      decoration: BoxDecoration(
        color: tokens.glassColor.withValues(alpha: .58),
        borderRadius: BorderRadius.circular(compact ? 9 : 10),
        border: Border.all(color: tokens.borderColor.withValues(alpha: .72)),
      ),
      child: Icon(icon, color: tokens.textPrimary, size: compact ? 14 : 16),
    );
  }

  Future<void> _handleTerminalState({
    required String state,
    String? message,
    Map<String, dynamic>? payload,
    bool destructive = false,
    bool failure = false,
  }) async {
    if (_terminalHandling || _terminalStateHandled || _isExitingCall) return;
    _terminalHandling = true;
    _terminalStateHandled = true;
    callState.value = state;
    _stopIncomingVibration();
    if (payload != null) {
      activeCall.value = {...?activeCall.value, ...payload};
    }
    if (failure) {
      await Haptics.error();
    } else if (destructive) {
      await Haptics.heavy();
    }
    final summaryPayload =
        payload != null
            ? <String, dynamic>{...payload}
            : (activeCall.value != null ? <String, dynamic>{...activeCall.value!} : null);
    await _disposeRoom();
    _stopTicker();
    incomingCall.value = null;
    activeCall.value = null;
    callToken.value = null;
    _releaseCallWakeLock();
    roomError.value = '';
    callMinimized.value = false;
    _stopRingingTimeout();
    _removeOverlay();
    _removeIncomingOverlay();
    if (message != null && message.isNotEmpty) {
      _showMessage(message);
    }
    if (_shouldShowUserCallSummary(summaryPayload)) {
      await _showUserCallSummary(summaryPayload!);
    }
    await _safeExitCallRoute();
    _terminalHandling = false;
  }

  Future<void> _disposeRoom() async {
    final room = _room;
    _room = null;
    roomRevision.value++;
    try {
      _roomListener?.dispose();
    } catch (_) {}
    _roomListener = null;
    try {
      await room?.disconnect();
    } catch (_) {}
    try {
      room?.dispose();
    } catch (_) {}
  }

  void _acquireCallWakeLock() {
    if (_callWakeLockAcquired) return;
    _callWakeLockAcquired = true;
    KeepAwakeController.acquire();
  }

  void _releaseCallWakeLock() {
    if (!_callWakeLockAcquired) return;
    _callWakeLockAcquired = false;
    KeepAwakeController.release();
  }

  void _removeOverlay() {
    _callOverlay?.remove();
    _callOverlay = null;
  }

  void _removeIncomingOverlay() {
    _incomingOverlay?.remove();
    _incomingOverlay = null;
  }

  void _startTicker() {
    _stopTicker();
    _callClockAnchor = _resolveCallClockAnchor(activeCall.value);
    elapsedSeconds.value = _currentElapsedFromAnchor();
    _ticker = Timer.periodic(const Duration(seconds: 1), (_) {
      elapsedSeconds.value = _currentElapsedFromAnchor();
    });
  }

  void _stopTicker() {
    _ticker?.cancel();
    _ticker = null;
    _callClockAnchor = null;
  }

  int _currentElapsedFromAnchor() {
    final anchor = _callClockAnchor;
    if (anchor == null) return elapsedSeconds.value;
    final delta = DateTime.now().difference(anchor).inSeconds;
    return delta < 0 ? 0 : delta;
  }

  DateTime? _resolveCallClockAnchor(Map<String, dynamic>? source) {
    if (source == null) return DateTime.now();

    DateTime? parse(String key) {
      final raw = source[key]?.toString().trim();
      if (raw == null || raw.isEmpty) return null;
      return DateTime.tryParse(raw)?.toLocal();
    }

    final startedAt = parse('started_at');
    if (startedAt != null) return startedAt;

    final acceptedAt = parse('accepted_at');
    if (acceptedAt != null) return acceptedAt;

    final durationSeconds = (source['duration_seconds'] as num?)?.toInt() ?? 0;
    if (durationSeconds > 0) {
      return DateTime.now().subtract(Duration(seconds: durationSeconds));
    }

    return DateTime.now();
  }

  int _resolvedRingingTimeoutSeconds({required bool incoming}) {
    final source = incoming ? incomingCall.value : activeCall.value;
    final seconds = (source?['ringing_timeout_seconds'] as num?)?.toInt() ?? 0;
    return seconds > 0 ? seconds : 0;
  }

  void _startRingingTimeout({required bool incoming}) {
    _stopRingingTimeout();
    final timeoutSeconds = _resolvedRingingTimeoutSeconds(incoming: incoming);
    if (timeoutSeconds <= 0) return;

    ringingSecondsLeft.value = timeoutSeconds;
    _ringingTimer = Timer.periodic(const Duration(seconds: 1), (timer) async {
      final next = ringingSecondsLeft.value - 1;
      ringingSecondsLeft.value = next;
      if (next > 0) return;
      timer.cancel();
      if (_terminalHandling) return;
      final callId = incoming
          ? (incomingCall.value?['call_id'] as int? ?? 0)
          : currentCallId;
      if (callId <= 0) return;

      try {
        if (incoming) {
          _removeIncomingOverlay();
          await _callService.rejectCall(callId);
        } else {
          await _callService.endCall(callId, reason: 'timeout');
        }
      } catch (_) {}

      await _handleTerminalState(
        state: 'timeout',
        message: 'Call timed out.',
        destructive: true,
      );
    });
  }

  void _stopRingingTimeout() {
    _ringingTimer?.cancel();
    _ringingTimer = null;
    ringingSecondsLeft.value = 0;
  }

  void _startIncomingVibration() {
    unawaited(CallVibrationService.startIncomingCallVibration());
  }

  void _stopIncomingVibration() {
    unawaited(CallVibrationService.stopIncomingCallVibration());
  }

  @override
  void onClose() {
    WidgetsBinding.instance.removeObserver(this);
    _removeOverlay();
    _removeIncomingOverlay();
    _stopRingingTimeout();
    _stopIncomingVibration();
    _stopTicker();
    _disposeRoom();
    _releaseCallWakeLock();
    _socketService.stop();
    super.onClose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      restartSocket();
      if (hasActiveCall && callToken.value != null) {
        KeepAwakeController.reassert();
        ensureRoomConnected();
      }
    } else if (state == AppLifecycleState.paused || state == AppLifecycleState.inactive) {
      reconnecting.value = hasActiveCall;
    }
  }

  void _showMessage(String message) {
    if (message.trim().isEmpty) return;
    Get.closeAllSnackbars();
    Get.snackbar('Calls', message, snackPosition: SnackPosition.BOTTOM);
  }

  String _extractMessage(Object error) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map && data['msg'] is String) {
        return data['msg'] as String;
      }
      return error.message ?? 'Request failed.';
    }
    return error.toString().replaceFirst('Exception: ', '');
  }

  bool _seenEvent(String key) {
    if (_handledEvents.contains(key)) {
      return true;
    }
    _handledEvents.add(key);
    if (_handledEvents.length > 50) {
      _handledEvents.remove(_handledEvents.first);
    }
    return false;
  }

  void _emitStateHaptic(String key) {
    if (_lastHapticState == key) return;
    _lastHapticState = key;
    switch (key) {
      case 'incoming':
        Haptics.medium();
        break;
      case 'accepted':
        Haptics.medium();
        break;
      case 'outgoing':
        Haptics.light();
        break;
      default:
        Haptics.light();
    }
  }

  String _friendlyFailure(String reason) {
    final raw = reason.toLowerCase();
    if (raw.contains('busy')) return 'Host is busy right now.';
    if (raw.contains('offline')) return 'Host is offline.';
    if (raw.contains('insufficient')) return 'Insufficient balance to start this call.';
    if (raw.contains('timeout')) return 'Call request timed out.';
    return reason;
  }

  void _prepareForNewCall() {
    _terminalHandling = false;
    _terminalStateHandled = false;
    _isExitingCall = false;
    roomError.value = '';
    reconnecting.value = false;
    _stopTicker();
    elapsedSeconds.value = 0;
    _stopRingingTimeout();
    _stopIncomingVibration();
    _removeOverlay();
    _removeIncomingOverlay();
  }

  Future<void> _navigateSafely(
    String route, {
    required bool replace,
    List<String> disallowIfCurrent = const [],
  }) async {
    if (_terminalStateHandled || _isExitingCall || _navigationBusy || disallowIfCurrent.contains(Get.currentRoute)) {
      return;
    }

    _navigationBusy = true;
    try {
      if (replace) {
        Get.offNamed(route);
      } else {
        Get.toNamed(route);
      }
      await Future<void>.delayed(const Duration(milliseconds: 180));
    } finally {
      _navigationBusy = false;
    }
  }

  Future<void> _safeExitCallRoute() async {
    if (_isExitingCall) return;
    _isExitingCall = true;
    final target =
        _auth.storage.token?.isNotEmpty == true
            ? Routes.home
            : Routes.login;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      try {
        Get.offAllNamed(target);
      } finally {
        _isExitingCall = false;
      }
    });
  }

  bool _shouldShowUserCallSummary(Map<String, dynamic>? payload) {
    if (payload == null) return false;
    final currentUserId = _auth.currentUser?.id;
    final callerId = (payload['caller_id'] as num?)?.toInt();
    if (currentUserId == null || callerId != currentUserId) return false;
    final durationSeconds = (payload['duration_seconds'] as num?)?.toInt() ?? 0;
    final billableMinutes = (payload['billable_minutes'] as num?)?.toInt() ?? 0;
    final chargedCoins = (payload['total_coins_charged'] as num?)?.toInt() ?? 0;
    return durationSeconds > 0 || billableMinutes > 0 || chargedCoins > 0;
  }

  Future<void> _showUserCallSummary(Map<String, dynamic> payload) async {
    final tokens = _tokens;
    final durationSeconds = (payload['duration_seconds'] as num?)?.toInt() ?? 0;
    final billableMinutes = (payload['billable_minutes'] as num?)?.toInt() ?? 0;
    final chargedCoins = (payload['total_coins_charged'] as num?)?.toInt() ?? 0;

    await Get.bottomSheet<void>(
      SafeArea(
        top: false,
        child: Container(
          padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: tokens.cardGradient,
            ),
            borderRadius: const BorderRadius.vertical(top: Radius.circular(30)),
            border: Border.all(color: tokens.borderColor),
            boxShadow: [
              BoxShadow(
                color: tokens.glowColor.withValues(alpha: .22),
                blurRadius: 28,
                offset: const Offset(0, -4),
              ),
            ],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 46,
                height: 4,
                decoration: BoxDecoration(
                  color: tokens.borderColor.withValues(alpha: .82),
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
              const SizedBox(height: 16),
              Container(
                width: 64,
                height: 64,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: LinearGradient(
                    colors: [
                      tokens.primaryButtonGradient.first.withValues(alpha: .96),
                      tokens.primaryButtonGradient.last.withValues(alpha: .92),
                    ],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: tokens.glowColor.withValues(alpha: .28),
                      blurRadius: 20,
                      offset: const Offset(0, 10),
                    ),
                  ],
                ),
                child: Icon(
                  Icons.receipt_long_rounded,
                  color: tokens.textPrimary,
                  size: 30,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'Call Summary',
                style: TextStyle(
                  color: tokens.textPrimary,
                  fontSize: 22,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                'Your call has ended. Final charges are shown below.',
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: tokens.textSecondary.withValues(alpha: .9),
                  fontWeight: FontWeight.w600,
                  height: 1.35,
                ),
              ),
              const SizedBox(height: 18),
              Row(
                children: [
                  Expanded(
                    child: _summaryStatCard(
                      label: 'Duration',
                      value: _formatDuration(durationSeconds),
                      icon: Icons.schedule_rounded,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _summaryStatCard(
                      label: 'Billable',
                      value: '$billableMinutes min',
                      icon: Icons.timer_outlined,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [
                      tokens.glassColor.withValues(alpha: .64),
                      tokens.glassColor.withValues(alpha: .42),
                    ],
                  ),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(
                    color: tokens.borderColor.withValues(alpha: .72),
                  ),
                ),
                child: Row(
                  children: [
                    Container(
                      width: 46,
                      height: 46,
                      decoration: BoxDecoration(
                        color: const Color(0xFFFFD66B).withValues(alpha: .16),
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: const Icon(
                        Icons.monetization_on_rounded,
                        color: Color(0xFFFFD66B),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Coins deducted',
                            style: TextStyle(
                              color: tokens.textSecondary,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            '$chargedCoins',
                            style: TextStyle(
                              color: tokens.textPrimary,
                              fontSize: 28,
                              fontWeight: FontWeight.w900,
                              letterSpacing: -.4,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 18),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  style: FilledButton.styleFrom(
                    backgroundColor: tokens.primaryButtonGradient.first,
                    foregroundColor: tokens.textPrimary,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(16),
                    ),
                  ),
                  onPressed: () => Get.back<void>(),
                  child: const Text('Done'),
                ),
              ),
            ],
          ),
        ),
      ),
      isScrollControlled: false,
      backgroundColor: Colors.transparent,
      isDismissible: false,
      enableDrag: false,
    );
  }

  Widget _summaryStatCard({
    required String label,
    required String value,
    required IconData icon,
  }) {
    final tokens = _tokens;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      decoration: BoxDecoration(
        color: tokens.glassColor.withValues(alpha: .46),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: tokens.borderColor.withValues(alpha: .65)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 34,
            height: 34,
            decoration: BoxDecoration(
              color: tokens.glassColor.withValues(alpha: .55),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: tokens.textPrimary, size: 18),
          ),
          const SizedBox(height: 12),
          Text(
            label,
            style: TextStyle(
              color: tokens.textSecondary,
              fontWeight: FontWeight.w700,
              fontSize: 12.5,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            value,
            style: TextStyle(
              color: tokens.textPrimary,
              fontWeight: FontWeight.w900,
              fontSize: 18,
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _dismissCallPresentationForMinimize() async {
    final target =
        _auth.storage.token?.isNotEmpty == true
            ? Routes.home
            : Routes.login;

    WidgetsBinding.instance.addPostFrameCallback((_) {
      Get.offAllNamed(target);
    });

    await Future<void>.delayed(const Duration(milliseconds: 260));
  }
}
