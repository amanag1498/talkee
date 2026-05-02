// lib/services/push_service.dart
import 'dart:io' show Platform;
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import '../app/routes/app_routes.dart';
import 'api_client.dart';
// ⬇️ ADD: import the controller so we can refresh it
import '../modules/notifications/controllers/notification_controller.dart';

/// Top-level background handler (MUST be top-level or a static function).
/// Register this in main() with: FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  // Background isolate: you cannot touch UI or Get here.
  // Do light work only (analytics, logging).
}

class PushService {
  PushService._();
  static final PushService instance = PushService._();

  final FirebaseMessaging _fm = FirebaseMessaging.instance;
  final FlutterLocalNotificationsPlugin _fln = FlutterLocalNotificationsPlugin();

  AndroidNotificationChannel? _androidChannel;
  bool _initialized = false;
  late ApiClient _api;

  /// Call ONCE after login (and after Firebase.initializeApp()).
  Future<void> init({required ApiClient api}) async {
    if (_initialized) return;
    _api = api;

    // Android channel
    if (Platform.isAndroid) {
      _androidChannel ??= const AndroidNotificationChannel(
        'talkee_default_channel',
        'talkee_default_channel',
        description: 'General notifications',
        importance: Importance.max,
        playSound: true,
        enableVibration: true,
      );
      final androidPlugin =
      _fln.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
      await androidPlugin?.createNotificationChannel(_androidChannel!);
    }

    // Local notifications init
    const initSettings = InitializationSettings(
      android: AndroidInitializationSettings('@mipmap/ic_launcher'),
      iOS: DarwinInitializationSettings(),
      macOS: DarwinInitializationSettings(),
    );
    await _fln.initialize(
      initSettings,
      onDidReceiveNotificationResponse: (details) {
        // User tapped a local notif while app was foreground/background
        _openNotificationsScreen();
        _refreshNotificationsIfAny();
      },
    );

    // 🔔 Foreground FCM: show local + refresh list/badge
    FirebaseMessaging.onMessage.listen((RemoteMessage message) async {
      await _showLocal(message);
      _refreshNotificationsIfAny();
    });

    // 🔔 App opened via tapping push (background -> foreground)
    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
      _openNotificationsScreen();
      _refreshNotificationsIfAny();
    });

    // 🔔 App launched from a terminated state by tapping push
    final initialMsg = await _fm.getInitialMessage();
    if (initialMsg != null) {
      _openNotificationsScreen();
      _refreshNotificationsIfAny();
    }

    _initialized = true;
  }

  /// Ask for permission and register FCM token to backend
  Future<void> requestPermissionAndRegister() async {
    final settings = await _fm.requestPermission(
      alert: true, announcement: false, badge: true, carPlay: false,
      criticalAlert: false, provisional: false, sound: true,
    );

    if (settings.authorizationStatus == AuthorizationStatus.denied ||
        settings.authorizationStatus == AuthorizationStatus.notDetermined) {
      await _maybePromptOpenSettings();
    }

    final fcmToken = await _fm.getToken();
    if (fcmToken == null || fcmToken.isEmpty) return;

    try {
      await _api.post('push/register', data: {
        'token': fcmToken,
        'platform': Platform.isIOS ? 'ios' : 'android',
      });
    } catch (_) {}

    _fm.onTokenRefresh.listen((newToken) async {
      try {
        await _api.post('push/register', data: {
          'token': newToken,
          'platform': Platform.isIOS ? 'ios' : 'android',
        });
      } catch (_) {}
    });
  }

  Future<bool> areNotificationsEnabled() async {
    final s = await _fm.getNotificationSettings();
    return s.authorizationStatus == AuthorizationStatus.authorized ||
        s.authorizationStatus == AuthorizationStatus.provisional;
  }

  Future<void> unregisterToken() async {
    try {
      final token = await _fm.getToken();
      if (token == null) return;
      await _api.post('push/unregister', data: {'token': token});
    } catch (_) {}
  }

  // ───────────────────────────────
  // Internals
  // ───────────────────────────────

  Future<void> _showLocal(RemoteMessage message) async {
    final notif = message.notification;
    final title = notif?.title ?? (message.data['title']?.toString() ?? 'Notification');
    final body  = notif?.body  ?? (message.data['body']?.toString()  ?? '');

    final details = NotificationDetails(
      android: Platform.isAndroid
          ? AndroidNotificationDetails(
        _androidChannel?.id ?? 'high_importance',
        _androidChannel?.name ?? 'General',
        channelDescription: _androidChannel?.description,
        importance: Importance.max,
        priority: Priority.high,
        playSound: true,
        enableVibration: true,
        visibility: NotificationVisibility.public,
        icon: '@mipmap/ic_launcher',
      )
          : null,
      iOS: const DarwinNotificationDetails(
        presentAlert: true, presentBadge: true, presentSound: true,
      ),
    );

    await _fln.show(
      DateTime.now().millisecondsSinceEpoch ~/ 1000,
      title, body, details,
      payload: 'notifications',
    );
  }

  void _openNotificationsScreen() {
    if (Get.currentRoute != Routes.notifications) {
      Get.toNamed(Routes.notifications);
    }
  }

  /// ⬅️⬅️ THIS is the FCM hook: tries to find the NotificationsController and refresh it.
  void _refreshNotificationsIfAny() {
    if (Get.isRegistered<NotificationsController>()) {
      final c = Get.find<NotificationsController>();
      // Pull first page + update badge; it’s idempotent and cheap.
      c.refreshAll();
    }
  }

  Future<void> _maybePromptOpenSettings() async {
    if (Get.context == null) return;
    await showDialog(
      context: Get.context!,
      builder: (_) => AlertDialog(
        title: const Text('Enable notifications?'),
        content: const Text(
            'Notifications are currently turned off for this app. Open settings to enable them.'
        ),
        actions: [
          TextButton(onPressed: () => Get.back(), child: const Text('Later')),
          TextButton(
            onPressed: () async {
              Get.back();
              await openAppSettings();
            },
            child: const Text('Open settings', style: TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }
}
