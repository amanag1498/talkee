import 'package:flutter_test/flutter_test.dart';
import 'package:liveapp/services/app_settings_service.dart';

void main() {
  group('AppSettingsPayload premium theme variant parsing', () {
    test('reads valid premium_theme_variant from app-config payload', () {
      final payload = AppSettingsPayload.fromJson({
        'enable_premium_theme_variants': true,
        'premium_theme_variant': 'aurora',
        'maintenance_mode_enabled': false,
        'force_app_upgrade_enabled': false,
        'android_min_version_code': 1,
        'android_min_version_name': '1.0.0',
        'android_update_message': 'Update',
        'features': const <String, dynamic>{},
      });

      expect(payload.activePremiumThemeVariant, 'aurora');
    });

    test('accepts a dynamically configured theme key', () {
      final payload = AppSettingsPayload.fromJson({
        'enable_premium_theme_variants': true,
        'premium_theme_variant': 'neon',
        'maintenance_mode_enabled': false,
        'force_app_upgrade_enabled': false,
        'android_min_version_code': 1,
        'android_min_version_name': '1.0.0',
        'android_update_message': 'Update',
        'features': const <String, dynamic>{},
      });

      expect(payload.activePremiumThemeVariant, 'neon');
    });

    test('falls back to midnight when variant is missing', () {
      final payload = AppSettingsPayload.fromJson({
        'enable_premium_theme_variants': true,
        'maintenance_mode_enabled': false,
        'force_app_upgrade_enabled': false,
        'android_min_version_code': 1,
        'android_min_version_name': '1.0.0',
        'android_update_message': 'Update',
        'features': const <String, dynamic>{},
      });

      expect(payload.activePremiumThemeVariant, 'midnight');
    });

    test('forces midnight when premium theme variants are disabled', () {
      final payload = AppSettingsPayload.fromJson({
        'enable_premium_theme_variants': false,
        'premium_theme_variant': 'gold',
        'maintenance_mode_enabled': false,
        'force_app_upgrade_enabled': false,
        'android_min_version_code': 1,
        'android_min_version_name': '1.0.0',
        'android_update_message': 'Update',
        'features': const <String, dynamic>{},
      });

      expect(payload.activePremiumThemeVariant, 'midnight');
    });
  });

  group('Fortune Wheel feature parsing', () {
    test('keeps global availability separate from room-strip visibility', () {
      final payload = AppSettingsPayload.fromJson({
        'features': const <String, dynamic>{
          'fortune_wheel_enabled': true,
          'fortune_wheel_visible_in_video_room_strip': false,
        },
      });

      expect(payload.features.fortuneWheelEnabled, isTrue);
      expect(payload.features.fortuneWheelVisibleInVideoRoomStrip, isFalse);
    });

    test('defaults room-strip visibility on for older app-config payloads', () {
      final payload = AppSettingsPayload.fromJson({
        'features': const <String, dynamic>{'fortune_wheel_enabled': true},
      });

      expect(payload.features.fortuneWheelVisibleInVideoRoomStrip, isTrue);
    });
  });
}
