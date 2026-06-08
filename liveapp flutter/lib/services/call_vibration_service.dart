import 'package:flutter/services.dart';

class CallVibrationService {
  CallVibrationService._();

  static const _channel = MethodChannel('com.gdlive/device');

  static Future<void> startIncomingCallVibration() async {
    try {
      await _channel.invokeMethod<bool>('startCallVibration');
    } catch (_) {
      try {
        await HapticFeedback.vibrate();
      } catch (_) {}
    }
  }

  static Future<void> stopIncomingCallVibration() async {
    try {
      await _channel.invokeMethod<bool>('stopCallVibration');
    } catch (_) {}
  }
}
