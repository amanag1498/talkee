import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:liveapp/services/device_id_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  const channel = MethodChannel('com.techybugs.talkee/device');

  tearDown(() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, null);
  });

  test('requests and trims the Talkieo platform device ID', () async {
    MethodCall? receivedCall;
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
          receivedCall = call;
          return '  ios:stable-device-id  ';
        });

    expect(await DeviceIdService.getDeviceId(), 'ios:stable-device-id');
    expect(receivedCall?.method, 'getDeviceId');
  });

  test('returns an empty ID when the platform channel fails', () async {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(channel, (call) async {
          throw PlatformException(code: 'DEVICE_ID_ERROR');
        });

    expect(await DeviceIdService.getDeviceId(), isEmpty);
  });
}
