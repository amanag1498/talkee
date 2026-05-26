import 'dart:async';

import 'package:get/get.dart';
import 'package:get_storage/get_storage.dart';
import 'package:flutter/widgets.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../app/theme/brand.dart';
import 'api_client.dart';
import 'storage_service.dart';

class AppSettingsService extends GetxService with WidgetsBindingObserver {
  AppSettingsService(this._api) {
    _hydrateCachedTheme();
  }

  static const String _kCachedThemeVariant = 'premium_theme_variant_cached';
  static const String _kThemeVariantOverride = 'premium_theme_variant_override';

  static const String androidPlatform = 'android';
  static String appVersionName = String.fromEnvironment(
    'APP_VERSION_NAME',
    defaultValue: '1.0.0',
  );
  static int appVersionCode = int.fromEnvironment(
    'APP_VERSION_CODE',
    defaultValue: 1,
  );

  final ApiClient _api;
  final GetStorage _box = GetStorage();

  final RxBool loading = false.obs;
  final RxBool loaded = false.obs;
  final RxnString error = RxnString();
  final Rxn<AppSettingsPayload> payload = Rxn<AppSettingsPayload>();

  bool _activitySyncInFlight = false;
  String? _registeredActiveRemoteThemeKey;

  Future<void> initialize() async {
    WidgetsBinding.instance.addObserver(this);
    _hydrateCachedTheme();
    await _hydrateRuntimeVersion();
    await refresh();
    await syncDailyActivity();
  }

  Future<void> _hydrateRuntimeVersion() async {
    try {
      final info = await PackageInfo.fromPlatform();
      final runtimeName = info.version.trim();
      final runtimeCode = int.tryParse(info.buildNumber.trim());
      if (runtimeName.isNotEmpty) {
        appVersionName = runtimeName;
      }
      if (runtimeCode != null && runtimeCode > 0) {
        appVersionCode = runtimeCode;
      }
    } catch (_) {
      // Fall back to compile-time values when platform package info is unavailable.
    }
  }

  Future<void> refresh() async {
    if (loading.value) return;
    loading.value = true;
    error.value = null;
    try {
      final res = await _api.get<Map<String, dynamic>>('app-config');
      final body = res.data ?? const <String, dynamic>{};
      final data =
          body['data'] is Map<String, dynamic>
              ? body['data'] as Map<String, dynamic>
              : Map<String, dynamic>.from(body['data'] as Map? ?? const {});
      payload.value = AppSettingsPayload.fromJson(data);
      _persistThemeVariant(payload.value?.activeThemeKey);
      _syncRemoteThemeRegistration(payload.value);
      loaded.value = true;
    } catch (e) {
      error.value = e.toString();
      loaded.value = true;
    } finally {
      loading.value = false;
    }
  }

  Future<void> syncDailyActivity() async {
    if (_activitySyncInFlight) return;
    final storage = Get.isRegistered<StorageService>()
        ? Get.find<StorageService>()
        : null;
    final token = storage?.token;
    if (token == null || token.isEmpty) {
      return;
    }

    _activitySyncInFlight = true;
    try {
      final res = await _api.post<Map<String, dynamic>>('app/activity');
      final body = res.data ?? const <String, dynamic>{};
      final data =
          body['data'] is Map<String, dynamic>
              ? body['data'] as Map<String, dynamic>
              : Map<String, dynamic>.from(body['data'] as Map? ?? const {});
      final updated = data['streak_updated'] == true;
      if (updated) {
        await refresh();
      }
    } catch (_) {
      // Keep startup/resume lightweight; streak sync should never block the app.
    } finally {
      _activitySyncInFlight = false;
    }
  }

  void applySelectedThemeKey(String themeKey) {
    final normalized = _normalizeThemeKeyLoose(themeKey);
    _persistThemeVariant(normalized);
    final current = payload.value;
    if (current == null) {
      payload.value = AppSettingsPayload.fromJson({
        'enable_premium_theme_variants': true,
        'maintenance_mode_enabled': false,
        'force_app_upgrade_enabled': false,
        'premium_theme_variant': normalized,
        'active_theme_key': normalized,
        'unlocked_theme_keys': <String>[normalized, 'midnight'],
        'available_theme_keys': kPremiumThemeVariants,
        'active_theme_token_source': 'local',
        'android_min_version_code': 1,
        'android_min_version_name': '1.0.0',
        'android_update_message': 'Please update Talkieo to continue using the app.',
        'features': const <String, dynamic>{},
      });
      _syncRemoteThemeRegistration(payload.value);
      loaded.value = true;
      return;
    }

    payload.value = current.copyWith(
      premiumThemeVariant: normalized,
      activeThemeKey: normalized,
      fallbackThemeKey: 'midnight',
      activeThemeTokenSource:
          hasLocalPremiumThemeVariant(normalized) ? 'local' : 'remote',
      clearActiveThemeTokens: true,
      unlockedThemeKeys: {
        ...current.unlockedThemeKeys,
        normalized,
        'midnight',
      }.toList(growable: false),
    );
    _syncRemoteThemeRegistration(payload.value);
  }

  bool get maintenanceModeEnabled =>
      payload.value?.maintenanceModeEnabled ?? false;

  bool get forceAppUpgradeEnabled =>
      payload.value?.forceAppUpgradeEnabled ?? false;

  bool get premiumThemeVariantsEnabled =>
      payload.value?.enablePremiumThemeVariants ?? false;

  bool get themeEnvironmentEffectsEnabled =>
      payload.value?.enableThemeEnvironmentEffects ?? true;

  String get configuredPremiumThemeVariant =>
      payload.value?.activeThemeKey ?? payload.value?.activePremiumThemeVariant ?? 'midnight';

  String get activePremiumThemeVariant =>
      premiumThemeVariantsEnabled ? configuredPremiumThemeVariant : 'midnight';

  String get premiumThemeVariant =>
      payload.value?.safeVisualThemeVariant ?? 'midnight';

  String get activeThemeKey => payload.value?.activeThemeKey ?? 'midnight';

  String get fallbackThemeKey => payload.value?.fallbackThemeKey ?? 'midnight';

  List<String> get unlockedThemeKeys =>
      payload.value?.unlockedThemeKeys ?? const <String>['midnight'];

  List<String> get availableThemeKeys =>
      payload.value?.availableThemeKeys ?? const <String>['midnight'];

  String? get localThemeVariantOverride {
    final raw = _box.read(_kThemeVariantOverride);
    if (raw is! String) return null;
    final normalized = _normalizeThemeKeyLoose(raw);
    return normalized.isEmpty ? null : normalized;
  }

  bool get hasThemeVariantOverride => localThemeVariantOverride != null;

  Future<void> setThemeVariantOverride(String? variant) async {
    final normalized =
        variant == null ? null : _normalizeThemeKeyLoose(variant);
    if (normalized == null || normalized.isEmpty || normalized == 'midnight') {
      await _box.remove(_kThemeVariantOverride);
      return;
    }
    await _box.write(_kThemeVariantOverride, normalized);
    _persistThemeVariant(normalized);
  }

  bool get shouldForceUpgrade {
    final config = payload.value;
    if (config == null || !config.forceAppUpgradeEnabled) {
      return false;
    }

    return appVersionCode < config.androidMinVersionCode;
  }

  String get forceUpgradeMessage =>
      payload.value?.androidUpdateMessage ??
      'Please update Talkieo to continue using the app.';

  bool get audioRoomsEnabled =>
      payload.value?.features.audioRoomsEnabled ?? true;
  bool get videoRoomsEnabled =>
      payload.value?.features.videoRoomsEnabled ?? true;
  bool get pkBattlesEnabled => payload.value?.features.pkBattlesEnabled ?? true;
  bool get giftsEnabled => payload.value?.features.giftsEnabled ?? true;
  bool get subscriptionsEnabled =>
      payload.value?.features.subscriptionsEnabled ?? true;
  bool get entryEffectsEnabled =>
      payload.value?.features.entryEffectsEnabled ?? true;
  bool get walletRechargeEnabled =>
      payload.value?.features.walletRechargeEnabled ?? true;
  bool get hostCallingEnabled =>
      payload.value?.features.hostCallingEnabled ?? true;
  bool get teenPattiEnabled =>
      payload.value?.features.teenPattiEnabled ?? false;
  bool get greedyEnabled =>
      payload.value?.features.greedyEnabled ?? false;
  bool get videoRoomGamesEnabled =>
      payload.value?.features.videoRoomGamesEnabled ?? false;
  AppHostGoalSettings get hostGoals =>
      payload.value?.hostGoals ?? const AppHostGoalSettings.defaults();
  bool get anyLiveCreationEnabled => audioRoomsEnabled || videoRoomsEnabled;

  void _syncRemoteThemeRegistration(AppSettingsPayload? next) {
    final previous = _registeredActiveRemoteThemeKey;
    if (previous != null) {
      unregisterRemotePremiumThemeTokens(previous);
      _registeredActiveRemoteThemeKey = null;
    }

    if (next == null) {
      return;
    }

    final tokens = next.activeThemeTokens;
    final source = next.activeThemeTokenSource;
    final themeKey = next.activeThemeKey;
    if (tokens == null || source == 'local') {
      return;
    }

    registerRemotePremiumThemeTokens(
      themeKey: themeKey,
      tokenSource: source,
      remoteTokens: tokens,
    );
    _registeredActiveRemoteThemeKey = themeKey;
  }

  void _hydrateCachedTheme() {
    final override = localThemeVariantOverride;
    final cached = _readCachedThemeVariant();
    final bootstrapTheme = override ?? cached;
    if (bootstrapTheme == null || bootstrapTheme.isEmpty) {
      return;
    }

    final current = payload.value;
    if (current != null && current.activeThemeKey.trim().isNotEmpty) {
      return;
    }

    payload.value = _bootstrapPayloadForTheme(bootstrapTheme);
    loaded.value = true;
  }

  String? _readCachedThemeVariant() {
    final raw = _box.read(_kCachedThemeVariant);
    if (raw is! String) return null;
    final normalized = _normalizeThemeKeyLoose(raw);
    return normalized.isEmpty ? null : normalized;
  }

  Future<void> _persistThemeVariant(String? themeKey) async {
    final normalized =
        themeKey == null ? 'midnight' : _normalizeThemeKeyLoose(themeKey);
    await _box.write(_kCachedThemeVariant, normalized);
  }

  AppSettingsPayload _bootstrapPayloadForTheme(String themeKey) {
    final normalized = _normalizeThemeKeyLoose(themeKey);
    return AppSettingsPayload.fromJson({
      'enable_premium_theme_variants': true,
      'enable_theme_environment_effects': true,
      'maintenance_mode_enabled': false,
      'force_app_upgrade_enabled': false,
      'premium_theme_variant': normalized,
      'active_theme_key': normalized,
      'fallback_theme_key': 'midnight',
      'active_theme_token_source': 'local',
      'unlocked_theme_keys': <String>[normalized, 'midnight'],
      'available_theme_keys': kPremiumThemeVariants,
      'android_min_version_code': 1,
      'android_min_version_name': '1.0.0',
      'android_update_message':
          'Please update Talkieo to continue using the app.',
      'host_goals': const <String, dynamic>{
        'followers': <int>[25, 50, 100, 250, 500, 1000, 2500],
        'weekly_live_minutes': <int>[60, 180, 300, 600, 900, 1200],
        'weekly_gifted_coins': <int>[500, 1000, 2500, 5000, 10000, 25000],
      },
      'features': const <String, dynamic>{
        'audio_rooms_enabled': true,
        'video_rooms_enabled': true,
        'pk_battles_enabled': true,
        'gifts_enabled': true,
        'subscriptions_enabled': true,
        'entry_effects_enabled': true,
        'wallet_recharge_enabled': true,
        'host_calling_enabled': true,
        'teen_patti_enabled': false,
        'greedy_enabled': false,
        'video_room_games_enabled': false,
      },
    });
  }

  String _normalizeThemeKeyLoose(String value) {
    final normalized = value.trim().toLowerCase();
    return normalized.isEmpty ? 'midnight' : normalized;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(syncDailyActivity());
    }
  }

}

class AppSettingsPayload {
  const AppSettingsPayload({
    required this.enablePremiumThemeVariants,
    required this.enableThemeEnvironmentEffects,
    required this.maintenanceModeEnabled,
    required this.forceAppUpgradeEnabled,
    required this.premiumThemeVariant,
    required this.activeThemeKey,
    required this.fallbackThemeKey,
    required this.activeThemeTokenSource,
    required this.activeThemeTokens,
    required this.unlockedThemeKeys,
    required this.availableThemeKeys,
    required this.androidMinVersionCode,
    required this.androidMinVersionName,
    required this.androidUpdateMessage,
    required this.hostGoals,
    required this.features,
  });

  final bool enablePremiumThemeVariants;
  final bool enableThemeEnvironmentEffects;
  final bool maintenanceModeEnabled;
  final bool forceAppUpgradeEnabled;
  final String premiumThemeVariant;
  final String activeThemeKey;
  final String fallbackThemeKey;
  final String activeThemeTokenSource;
  final Map<String, dynamic>? activeThemeTokens;
  final List<String> unlockedThemeKeys;
  final List<String> availableThemeKeys;
  final int androidMinVersionCode;
  final String androidMinVersionName;
  final String androidUpdateMessage;
  final AppHostGoalSettings hostGoals;
  final AppPlatformFeatureFlags features;

  AppSettingsPayload copyWith({
    bool? enablePremiumThemeVariants,
    bool? enableThemeEnvironmentEffects,
    bool? maintenanceModeEnabled,
    bool? forceAppUpgradeEnabled,
    String? premiumThemeVariant,
    String? activeThemeKey,
    String? fallbackThemeKey,
    String? activeThemeTokenSource,
    Map<String, dynamic>? activeThemeTokens,
    bool clearActiveThemeTokens = false,
    List<String>? unlockedThemeKeys,
    List<String>? availableThemeKeys,
    int? androidMinVersionCode,
    String? androidMinVersionName,
    String? androidUpdateMessage,
    AppHostGoalSettings? hostGoals,
    AppPlatformFeatureFlags? features,
  }) {
    return AppSettingsPayload(
      enablePremiumThemeVariants:
          enablePremiumThemeVariants ?? this.enablePremiumThemeVariants,
      enableThemeEnvironmentEffects:
          enableThemeEnvironmentEffects ?? this.enableThemeEnvironmentEffects,
      maintenanceModeEnabled:
          maintenanceModeEnabled ?? this.maintenanceModeEnabled,
      forceAppUpgradeEnabled:
          forceAppUpgradeEnabled ?? this.forceAppUpgradeEnabled,
      premiumThemeVariant: premiumThemeVariant ?? this.premiumThemeVariant,
      activeThemeKey: activeThemeKey ?? this.activeThemeKey,
      fallbackThemeKey: fallbackThemeKey ?? this.fallbackThemeKey,
      activeThemeTokenSource:
          activeThemeTokenSource ?? this.activeThemeTokenSource,
      activeThemeTokens:
          clearActiveThemeTokens
              ? null
              : (activeThemeTokens ?? this.activeThemeTokens),
      unlockedThemeKeys: unlockedThemeKeys ?? this.unlockedThemeKeys,
      availableThemeKeys: availableThemeKeys ?? this.availableThemeKeys,
      androidMinVersionCode:
          androidMinVersionCode ?? this.androidMinVersionCode,
      androidMinVersionName:
          androidMinVersionName ?? this.androidMinVersionName,
      androidUpdateMessage:
          androidUpdateMessage ?? this.androidUpdateMessage,
      hostGoals: hostGoals ?? this.hostGoals,
      features: features ?? this.features,
    );
  }

  String get activePremiumThemeVariant {
    final normalized = activeThemeKey.trim().toLowerCase();
    if (!enablePremiumThemeVariants) return 'midnight';
    return normalized.isEmpty ? 'midnight' : normalized;
  }

  String get safeVisualThemeVariant {
    return activePremiumThemeVariant;
  }

  factory AppSettingsPayload.fromJson(Map<String, dynamic> json) {
    return AppSettingsPayload(
      enablePremiumThemeVariants: _toBool(
        json['enable_premium_theme_variants'],
      ),
      enableThemeEnvironmentEffects: json.containsKey(
            'enable_theme_environment_effects',
          )
          ? _toBool(json['enable_theme_environment_effects'])
          : true,
      maintenanceModeEnabled: _toBool(json['maintenance_mode_enabled']),
      forceAppUpgradeEnabled: _toBool(json['force_app_upgrade_enabled']),
      premiumThemeVariant:
          (json['premium_theme_variant']?.toString().trim().isNotEmpty ?? false)
              ? json['premium_theme_variant'].toString().trim()
              : 'midnight',
      activeThemeKey:
          (json['active_theme_key']?.toString().trim().isNotEmpty ?? false)
              ? json['active_theme_key'].toString().trim()
              : ((json['premium_theme_variant']?.toString().trim().isNotEmpty ?? false)
                  ? json['premium_theme_variant'].toString().trim()
                  : 'midnight'),
      fallbackThemeKey:
          (json['fallback_theme_key']?.toString().trim().isNotEmpty ?? false)
              ? json['fallback_theme_key'].toString().trim()
              : 'midnight',
      activeThemeTokenSource:
          (json['active_theme_token_source']?.toString().trim().isNotEmpty ?? false)
              ? json['active_theme_token_source'].toString().trim().toLowerCase()
              : 'local',
      activeThemeTokens:
          json['active_theme_tokens'] is Map
              ? Map<String, dynamic>.from(json['active_theme_tokens'] as Map)
              : null,
      unlockedThemeKeys: _themeKeysFromJson(json['unlocked_theme_keys']),
      availableThemeKeys: _themeKeysFromJson(json['available_theme_keys']),
      androidMinVersionCode: _toInt(json['android_min_version_code'], 1),
      androidMinVersionName:
          (json['android_min_version_name']?.toString().trim().isNotEmpty ??
                  false)
              ? json['android_min_version_name'].toString().trim()
              : '1.0.0',
      androidUpdateMessage:
          (json['android_update_message']?.toString().trim().isNotEmpty ??
                  false)
              ? json['android_update_message'].toString().trim()
              : 'Please update Talkieo to continue using the app.',
      hostGoals: AppHostGoalSettings.fromJson(
        Map<String, dynamic>.from(json['host_goals'] as Map? ?? const {}),
      ),
      features: AppPlatformFeatureFlags.fromJson(
        Map<String, dynamic>.from(json['features'] as Map? ?? const {}),
      ),
    );
  }

  static bool _toBool(dynamic value) {
    if (value is bool) return value;
    final normalized = value?.toString().toLowerCase().trim();
    return normalized == '1' || normalized == 'true' || normalized == 'yes';
  }

  static int _toInt(dynamic value, int fallback) {
    if (value is int) return value;
    return int.tryParse(value?.toString() ?? '') ?? fallback;
  }

  static List<String> _themeKeysFromJson(dynamic raw) {
    final values =
        (raw is List ? raw : const <dynamic>[])
            .map((value) => value.toString().trim().toLowerCase())
            .where((value) => value.isNotEmpty)
            .toList(growable: false);
    return values.isEmpty ? const <String>['midnight'] : values;
  }
}

class AppHostGoalSettings {
  const AppHostGoalSettings({
    required this.followers,
    required this.weeklyLiveMinutes,
    required this.weeklyGiftedCoins,
  });

  const AppHostGoalSettings.defaults()
    : followers = const <int>[25, 50, 100, 250, 500, 1000, 2500],
      weeklyLiveMinutes = const <int>[60, 180, 300, 600, 900, 1200],
      weeklyGiftedCoins = const <int>[500, 1000, 2500, 5000, 10000, 25000];

  final List<int> followers;
  final List<int> weeklyLiveMinutes;
  final List<int> weeklyGiftedCoins;

  factory AppHostGoalSettings.fromJson(Map<String, dynamic> json) {
    const defaults = AppHostGoalSettings.defaults();
    return AppHostGoalSettings(
      followers: _intListFromJson(json['followers'], defaults.followers),
      weeklyLiveMinutes: _intListFromJson(
        json['weekly_live_minutes'],
        defaults.weeklyLiveMinutes,
      ),
      weeklyGiftedCoins: _intListFromJson(
        json['weekly_gifted_coins'],
        defaults.weeklyGiftedCoins,
      ),
    );
  }

  static List<int> _intListFromJson(dynamic raw, List<int> fallback) {
    final values =
        (raw is List ? raw : const <dynamic>[])
            .map((value) => int.tryParse(value.toString()) ?? 0)
            .where((value) => value > 0)
            .toSet()
            .toList()
          ..sort();
    return values.isEmpty ? fallback : List<int>.unmodifiable(values);
  }
}

class AppPlatformFeatureFlags {
  const AppPlatformFeatureFlags({
    required this.audioRoomsEnabled,
    required this.videoRoomsEnabled,
    required this.pkBattlesEnabled,
    required this.giftsEnabled,
    required this.subscriptionsEnabled,
    required this.entryEffectsEnabled,
    required this.walletRechargeEnabled,
    required this.hostCallingEnabled,
    required this.teenPattiEnabled,
    required this.greedyEnabled,
    required this.videoRoomGamesEnabled,
  });

  const AppPlatformFeatureFlags.enabled()
    : audioRoomsEnabled = true,
      videoRoomsEnabled = true,
      pkBattlesEnabled = true,
      giftsEnabled = true,
      subscriptionsEnabled = true,
      entryEffectsEnabled = true,
      walletRechargeEnabled = true,
      hostCallingEnabled = true,
      teenPattiEnabled = false,
      greedyEnabled = false,
      videoRoomGamesEnabled = false;

  final bool audioRoomsEnabled;
  final bool videoRoomsEnabled;
  final bool pkBattlesEnabled;
  final bool giftsEnabled;
  final bool subscriptionsEnabled;
  final bool entryEffectsEnabled;
  final bool walletRechargeEnabled;
  final bool hostCallingEnabled;
  final bool teenPattiEnabled;
  final bool greedyEnabled;
  final bool videoRoomGamesEnabled;

  factory AppPlatformFeatureFlags.fromJson(Map<String, dynamic> json) {
    bool toBool(dynamic value, {required bool fallback}) {
      if (value is bool) return value;
      if (value == null) return fallback;
      final normalized = value?.toString().toLowerCase().trim();
      return normalized == '1' || normalized == 'true' || normalized == 'yes';
    }

    return AppPlatformFeatureFlags(
      audioRoomsEnabled: toBool(
        json['audio_rooms_enabled'],
        fallback: true,
      ),
      videoRoomsEnabled: toBool(
        json['video_rooms_enabled'],
        fallback: true,
      ),
      pkBattlesEnabled: toBool(
        json['pk_battles_enabled'],
        fallback: true,
      ),
      giftsEnabled: toBool(json['gifts_enabled'], fallback: true),
      subscriptionsEnabled: toBool(
        json['subscriptions_enabled'],
        fallback: true,
      ),
      entryEffectsEnabled: toBool(
        json['entry_effects_enabled'],
        fallback: true,
      ),
      walletRechargeEnabled: toBool(
        json['wallet_recharge_enabled'],
        fallback: true,
      ),
      hostCallingEnabled: toBool(
        json['host_calling_enabled'],
        fallback: true,
      ),
      teenPattiEnabled: toBool(
        json['teen_patti_enabled'],
        fallback: false,
      ),
      greedyEnabled: toBool(
        json['greedy_enabled'],
        fallback: false,
      ),
      videoRoomGamesEnabled: toBool(
        json['video_room_games_enabled'],
        fallback: false,
      ),
    );
  }
}
