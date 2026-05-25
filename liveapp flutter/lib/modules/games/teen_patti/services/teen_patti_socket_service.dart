import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:socket_io_client/socket_io_client.dart' as io;

import '../../../../services/app_settings_service.dart';
import '../../../../services/device_id_service.dart';

class TeenPattiSocketService {
  io.Socket? _socket;
  final _snapshotEvents = StreamController<Map<String, dynamic>>.broadcast();
  final _eventStream = StreamController<Map<String, dynamic>>.broadcast();

  Stream<Map<String, dynamic>> get snapshotEvents => _snapshotEvents.stream;
  Stream<Map<String, dynamic>> get eventStream => _eventStream.stream;
  bool get isConnected => _socket?.connected ?? false;

  Future<void> start({
    required String wsGamesUrl,
    required String bearerToken,
  }) async {
    await stop();
    final deviceId = await DeviceIdService.getAndroidId();

    _socket = io.io(wsGamesUrl, <String, dynamic>{
      'transports': ['websocket'],
      'auth': {
        'token': bearerToken,
        if (deviceId.isNotEmpty) 'device_id': deviceId,
        'platform': AppSettingsService.androidPlatform,
        'app_version': AppSettingsService.appVersionName,
        'app_version_code': AppSettingsService.appVersionCode,
      },
      'forceNew': true,
      'reconnection': true,
      'timeout': 8000,
    });

    _socket!.on('connect', (_) {
      _log('connected id=${_socket?.id}');
      _socket?.emit('games:teen_patti:subscribe');
    });
    _socket!.on('disconnect', (reason) => _log('disconnect $reason'));
    _socket!.on('connect_error', (e) => _log('connect_error $e'));
    _socket!.on('feature:error', (data) => _emitEvent('feature:error', data));
    _socket!.on('games:event', (data) => _emitEvent('games:event', data));
    _socket!.on('teen_patti:bet_placed', (data) => _emitEvent('teen_patti:bet_placed', data));
    _socket!.on('teen_patti:round_locked', (data) => _emitEvent('teen_patti:round_locked', data));
    _socket!.on('teen_patti:round_settled', (data) => _emitEvent('teen_patti:round_settled', data));
    _socket!.on('teen_patti:round_started', (data) => _emitEvent('teen_patti:round_started', data));
    _socket!.on('teen_patti:snapshot', (data) {
      try {
        final payload = Map<String, dynamic>.from(data as Map);
        _snapshotEvents.add(payload);
      } catch (e) {
        _log('snapshot parse error $e');
      }
    });
  }

  Future<void> stop() async {
    try {
      _socket?.emit('games:teen_patti:unsubscribe');
    } catch (_) {}
    _socket?.dispose();
    _socket = null;
  }

  void dispose() {
    unawaited(stop());
    _snapshotEvents.close();
    _eventStream.close();
  }

  void _emitEvent(String event, dynamic data) {
    try {
      final payload = Map<String, dynamic>.from(data as Map);
      payload['event'] ??= event;
      _eventStream.add(payload);
    } catch (e) {
      _log('event parse error $event $e');
    }
  }

  void _log(String message) {
    debugPrint('[teen-patti][ws] $message');
  }
}
