import 'dart:io' show Platform;

import 'package:app_tracking_transparency/app_tracking_transparency.dart';
import 'package:facebook_app_events/facebook_app_events.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:uuid/uuid.dart';

import 'api_client.dart';

/// Sends privacy-aware Meta App Events and mirrors safe diagnostics to Laravel.
class MetaAttributionService {
  MetaAttributionService(this._api);

  static const _enabled = bool.fromEnvironment(
    'META_APP_EVENTS_ENABLED',
    defaultValue: true,
  );

  final ApiClient _api;
  final FacebookAppEvents _meta = FacebookAppEvents();
  final Uuid _uuid = const Uuid();
  String? _version;
  bool _initialized = false;

  Future<void> initialize({bool hasAuthenticatedSession = false}) async {
    if (_initialized) return;
    _initialized = true;
    _version = (await PackageInfo.fromPlatform()).version;
    if (!_enabled) return;

    try {
      await _meta.setAutoLogAppEventsEnabled(true);
      await _meta.activateApp();
      if (hasAuthenticatedSession) {
        await logLifecycleEvent('app_launch');
      }
    } catch (_) {}
  }

  Future<void> requestTrackingConsent() async {
    if (!_enabled) return;

    var allowed = !Platform.isIOS;
    if (Platform.isIOS) {
      final current = await AppTrackingTransparency.trackingAuthorizationStatus;
      final status =
          current == TrackingStatus.notDetermined
              ? await AppTrackingTransparency.requestTrackingAuthorization()
              : current;
      allowed = status == TrackingStatus.authorized;
    }

    try {
      await _meta.setAdvertiserTracking(enabled: allowed, collectId: allowed);
      await _meta.setAutoLogAppEventsEnabled(true);
    } catch (_) {}

    await _audit(
      'advertiser_tracking_consent',
      trackingAllowed: allowed,
      properties: {'consent_status': allowed ? 'authorized' : 'declined'},
    );
  }

  Future<void> logLifecycleEvent(
    String eventName, {
    String? provider,
    bool? isNewUser,
  }) async {
    if (!_enabled) return;

    final properties = <String, String>{
      if (provider != null) 'login_provider': provider,
      if (isNewUser == true) 'is_new_user': '1',
    };
    try {
      await _meta.logEvent(name: _metaName(eventName), parameters: properties);
    } catch (_) {}

    // Laravel writes registration from the user-creation path so the app
    // notification cannot create a duplicate conversion in the audit trail.
    if (eventName != 'complete_registration') {
      await _audit(eventName, properties: properties);
    }
  }

  Future<void> logVerifiedPurchase({
    required double amountInr,
    required String orderId,
  }) async {
    if (!_enabled) return;

    try {
      await _meta.logPurchase(
        amount: amountInr,
        currency: 'INR',
        parameters: {'order_id': orderId},
      );
    } catch (_) {}
  }

  Future<void> _audit(
    String eventName, {
    bool? trackingAllowed,
    Map<String, String>? properties,
  }) async {
    try {
      await _api.post<Map<String, dynamic>>(
        'marketing/meta-events',
        data: {
          'event_id': _uuid.v4(),
          'event_name': eventName,
          'platform': Platform.isIOS ? 'ios' : 'android',
          'app_version': _version,
          if (trackingAllowed != null)
            'advertiser_tracking_enabled': trackingAllowed,
          if (properties != null && properties.isNotEmpty)
            'properties': properties,
        },
      );
    } catch (_) {}
  }

  String _metaName(String eventName) => switch (eventName) {
    'complete_registration' => 'fb_mobile_complete_registration',
    'login' => 'fb_mobile_login',
    _ => eventName,
  };
}
