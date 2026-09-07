import 'dart:async';

import 'package:socket_io_client/socket_io_client.dart' as io;

import '../../../../services/app_settings_service.dart';
import '../../../../services/device_id_service.dart';

class SevenUpDownSocketService {
  io.Socket? _socket;
  final _snapshots = StreamController<Map<String, dynamic>>.broadcast();
  final _events = StreamController<Map<String, dynamic>>.broadcast();

  Stream<Map<String, dynamic>> get snapshots => _snapshots.stream;
  Stream<Map<String, dynamic>> get events => _events.stream;

  Future<void> start({required String url, required String token}) async {
    await stop();
    final deviceId = await DeviceIdService.getDeviceId();
    _socket = io.io(url, {
      'transports': ['websocket'],
      'forceNew': true,
      'reconnection': true,
      'timeout': 8000,
      'auth': {
        'token': token,
        if (deviceId.isNotEmpty) 'device_id': deviceId,
        'platform': AppSettingsService.currentPlatform,
        'app_version': AppSettingsService.appVersionName,
        'app_version_code': AppSettingsService.appVersionCode,
      },
    });
    _socket!.on(
      'connect',
      (_) => _socket?.emit('games:seven_up_down:subscribe'),
    );
    _socket!.on('seven_up_down:snapshot', (data) => _add(_snapshots, data));
    for (final event in const [
      'seven_up_down:round_started',
      'seven_up_down:round_locked',
      'seven_up_down:round_settled',
      'seven_up_down:bet_placed',
      'seven_up_down:bet_refunded',
      'feature:error',
    ]) {
      _socket!.on(event, (data) => _add(_events, data, event: event));
    }
  }

  void _add(
    StreamController<Map<String, dynamic>> target,
    dynamic data, {
    String? event,
  }) {
    try {
      final value = Map<String, dynamic>.from(data as Map);
      if (event != null) value['event'] ??= event;
      target.add(value);
    } catch (_) {}
  }

  Future<void> stop() async {
    _socket?.emit('games:seven_up_down:unsubscribe');
    _socket?.dispose();
    _socket = null;
  }

  void dispose() {
    unawaited(stop());
    _snapshots.close();
    _events.close();
  }
}
