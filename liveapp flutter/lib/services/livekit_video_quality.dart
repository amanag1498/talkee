import 'package:livekit_client/livekit_client.dart';

/// Centralized LiveKit video profiles so capture and subscription quality do
/// not silently change when SDK defaults change.
abstract final class LiveKitVideoQuality {
  static const callCamera = CameraCaptureOptions(
    params: VideoParametersPresets.h720_169,
  );

  static const callOptions = RoomOptions(
    adaptiveStream: false,
    dynacast: true,
    defaultCameraCaptureOptions: callCamera,
    defaultVideoPublishOptions: VideoPublishOptions(
      videoEncoding: VideoEncoding(maxBitrate: 1700 * 1000, maxFramerate: 30),
      simulcast: true,
    ),
  );
}
