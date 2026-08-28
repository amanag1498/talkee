import 'package:flutter/services.dart';

class DeviceIdService {
  static const _channel = MethodChannel('com.techybugs.talkee/device');

  static Future<String> getDeviceId() async {
    try {
      final id = await _channel.invokeMethod<String>('getDeviceId');
      return (id ?? '').trim();
    } catch (_) {
      return '';
    }
  }
}
