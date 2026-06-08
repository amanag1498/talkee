import 'package:flutter/services.dart';

class ScreenAwakeService {
  ScreenAwakeService._();

  static const _channel = MethodChannel('com.gdlive/device');

  static Future<void> enable() async {
    try {
      await _channel.invokeMethod<bool>('startKeepScreenAwake');
    } catch (_) {}
  }

  static Future<void> disable() async {
    try {
      await _channel.invokeMethod<bool>('stopKeepScreenAwake');
    } catch (_) {}
  }
}
