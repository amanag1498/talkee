import 'package:flutter/foundation.dart';
import 'package:socket_io_client/socket_io_client.dart' as io;
import 'package:liveapp/services/device_id_service.dart';
import 'package:liveapp/services/app_settings_service.dart';

class CallSocketService {
  io.Socket? _socket;

  bool get isConnected => _socket?.connected ?? false;

  Future<void> start({
    required String url,
    required String bearerToken,
    void Function(String state)? onConnectionState,
    required void Function(Map<String, dynamic>) onIncomingCall,
    required void Function(Map<String, dynamic>) onAvailabilityUpdated,
    required void Function(Map<String, dynamic>) onCallAccepted,
    required void Function(Map<String, dynamic>) onCallRejected,
    required void Function(Map<String, dynamic>) onCallMissed,
    required void Function(Map<String, dynamic>) onCallEnded,
    required void Function(Map<String, dynamic>) onCallFailed,
    Future<void> Function(String reason)? onForceLogout,
  }) async {
    await stop();
    final deviceId = await DeviceIdService.getDeviceId();

    _socket = io.io(url, <String, dynamic>{
      'transports': ['websocket'],
      'auth': {
        'token': bearerToken,
        if (deviceId.isNotEmpty) 'device_id': deviceId,
        'platform': AppSettingsService.currentPlatform,
        'app_version': AppSettingsService.appVersionName,
        'app_version_code': AppSettingsService.appVersionCode,
      },
      'forceNew': true,
      'reconnection': true,
      'reconnectionDelay': 1000,
      'reconnectionDelayMax': 5000,
      'timeout': 8000,
    });

    _socket!.on('connect', (_) {
      debugPrint('[calls] connected ${_socket?.id}');
      onConnectionState?.call('connected');
    });
    _socket!.on('disconnect', (reason) {
      debugPrint('[calls] disconnected $reason');
      onConnectionState?.call('disconnected');
    });
    _socket!.on('reconnect_attempt', (_) {
      onConnectionState?.call('reconnecting');
    });
    _socket!.on('connect_error', (err) {
      debugPrint('[calls] connect_error $err');
      onConnectionState?.call('connect_error');
      final msg = err?.toString() ?? '';
      if (onForceLogout != null) {
        if (msg.contains('blocked')) {
          onForceLogout('blocked');
        } else if (msg.contains('unauthorized')) {
          onForceLogout('unauthorized');
        }
      }
    });
    _socket!.on('auth:logout', (data) async {
      if (onForceLogout == null) return;
      final reason =
          (data is Map && data['reason'] is String)
              ? data['reason'] as String
              : 'unauthorized';
      onConnectionState?.call('forced_logout');
      await onForceLogout(reason);
    });

    _socket!.on('incoming_call', (data) {
      final payload = _map(data);
      debugPrint(
        '[calls] incoming_call received call_id=${payload['call_id']} '
        'receiver_id=${payload['receiver_id']}',
      );
      onIncomingCall(payload);
    });
    _socket!.on(
      'user_availability_updated',
      (data) => onAvailabilityUpdated(_map(data)),
    );
    _socket!.on('call_accepted', (data) => onCallAccepted(_map(data)));
    _socket!.on('call_rejected', (data) => onCallRejected(_map(data)));
    _socket!.on('call_missed', (data) => onCallMissed(_map(data)));
    _socket!.on('call_ended', (data) => onCallEnded(_map(data)));
    _socket!.on('call_failed', (data) => onCallFailed(_map(data)));
  }

  Future<void> stop() async {
    try {
      _socket?.dispose();
    } catch (_) {}
    try {
      _socket?.disconnect();
    } catch (_) {}
    _socket = null;
  }

  Map<String, dynamic> _map(dynamic data) {
    if (data is Map) return Map<String, dynamic>.from(data);
    return <String, dynamic>{};
  }
}
