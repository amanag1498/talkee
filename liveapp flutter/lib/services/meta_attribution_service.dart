import 'dart:io' show Platform;

import 'package:app_tracking_transparency/app_tracking_transparency.dart';
import 'package:facebook_app_events/facebook_app_events.dart';
import 'package:flutter/foundation.dart';
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

    await _runMeta('configure debug logging', () async {
      await _meta.setDebugLoggingEnabled(kDebugMode);
    });

    // Android has no ATT prompt. Enable advertiser-ID collection before the
    // first activation so Meta can match a fresh Play install to the ad click.
    // On iOS, preserve the current ATT decision and never collect IDFA before
    // the user has authorized tracking.
    final trackingAllowed =
        Platform.isAndroid ||
        (Platform.isIOS &&
            await AppTrackingTransparency.trackingAuthorizationStatus ==
                TrackingStatus.authorized);
    await _runMeta('configure advertiser tracking', () async {
      await _meta.setAdvertiserTracking(
        enabled: trackingAllowed,
        collectId: trackingAllowed,
      );
    });
    await _runMeta('activate app', () async {
      await _meta.setAutoLogAppEventsEnabled(true);
      await _meta.activateApp();
      // Make first-open diagnostics deterministic instead of waiting for the
      // SDK's normal event batching window.
      await _meta.flush();
    });
    if (hasAuthenticatedSession) await logLifecycleEvent('app_launch');
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

    await _runMeta('update advertiser tracking consent', () async {
      await _meta.setAdvertiserTracking(enabled: allowed, collectId: allowed);
      await _meta.setAutoLogAppEventsEnabled(true);
      // Re-activate and flush after an ATT decision so queued iOS events carry
      // the latest advertiser-tracking state.
      await _meta.activateApp();
      await _meta.flush();
    });

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
    await _runMeta('log $eventName', () async {
      await _meta.logEvent(name: _metaName(eventName), parameters: properties);
      if (kDebugMode) await _meta.flush();
    });

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

    await _runMeta('log purchase', () async {
      await _meta.logPurchase(
        amount: amountInr,
        currency: 'INR',
        parameters: {'order_id': orderId},
      );
      if (kDebugMode) await _meta.flush();
    });
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

  Future<void> _runMeta(
    String operation,
    Future<void> Function() action,
  ) async {
    try {
      await action();
    } catch (error, stackTrace) {
      if (kDebugMode) {
        debugPrint('[meta] $operation failed: $error');
        debugPrintStack(stackTrace: stackTrace);
      }
    }
  }
}
