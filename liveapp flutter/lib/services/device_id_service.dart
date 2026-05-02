import 'package:flutter/services.dart';

class DeviceIdService {
  static const _channel = MethodChannel('com.gdlive/device');

  static Future<String> getAndroidId() async {
    try {
      final id = await _channel.invokeMethod<String>('getDeviceId');
      return (id ?? '').trim();
    } catch (_) {
      return '';
    }
  }
}
