import 'package:flutter/foundation.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart' show Helper;
import 'package:permission_handler/permission_handler.dart';

class CallAudioRoute {
  CallAudioRoute._();

  static Future<void>? _prepareFuture;

  static Future<void> prepare() async {
    if (kIsWeb || defaultTargetPlatform != TargetPlatform.android) return;

    return _prepareFuture ??= _requestBluetoothPermission();
  }

  static Future<void> _requestBluetoothPermission() async {
    try {
      final permission = Permission.bluetoothConnect;
      if (!await permission.isGranted) {
        await permission.request();
      }
    } catch (_) {
      // Calls still work through the handset or speaker if access is denied.
    }
  }

  static Future<void> preferBluetoothOrSpeaker() async {
    await prepare();
    try {
      await Helper.setSpeakerphoneOnButPreferBluetooth();
    } catch (_) {
      await Helper.setSpeakerphoneOn(true);
    }
  }

  static Future<void> useEarpiece() => Helper.setSpeakerphoneOn(false);
}
