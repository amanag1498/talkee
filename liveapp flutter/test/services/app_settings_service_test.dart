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

    test('falls back to midnight for invalid variant', () {
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

      expect(payload.activePremiumThemeVariant, 'midnight');
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
}
