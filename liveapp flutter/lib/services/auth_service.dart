import 'dart:io' show Platform;
import 'package:flutter/foundation.dart';
import 'package:get/get.dart';
import 'package:firebase_auth/firebase_auth.dart' as fb;
import 'package:google_sign_in/google_sign_in.dart';
import 'package:dio/dio.dart';
import 'package:liveapp/services/presence_service.dart';
import 'package:liveapp/services/push_service.dart';
import 'package:liveapp/services/call_socket_service.dart';

import '../app/routes/app_routes.dart';
import '../data/models/user_model.dart';
import 'api_client.dart';
import 'storage_service.dart';

class AuthService {
  final ApiClient api;
  final StorageService storage;

  final String? iosClientId;
  late final GoogleSignIn _gsi;

  AuthService({
    required this.api,
    required this.storage,
    this.iosClientId,
  }) {
    _gsi = GoogleSignIn(
      scopes: const ['email', 'profile'],
      clientId: (Platform.isIOS || Platform.isMacOS) ? iosClientId : null,
    );
  }

  bool get isLoggedIn => storage.token != null && storage.token!.isNotEmpty;

  UserModel? get currentUser {
    final j = storage.userJson;
    if (j == null) return null;
    try { return UserModel.fromJson(j); } catch (_) { return null; }
  }

  // --- helper: local-only cleanup (no API call, no navigation) ---
  Future<void> _signOutLocalOnly() async {
    try { await PresenceService.instance.stop(); } catch (_) {}
    try { if (Get.isRegistered<CallSocketService>()) await Get.find<CallSocketService>().stop(); } catch (_) {}
    try { await _gsi.disconnect(); } catch (_) { try { await _gsi.signOut(); } catch (_) {} }
    try { await fb.FirebaseAuth.instance.signOut(); } catch (_) {}
    await storage.clear();
  }

  // Google -> Firebase -> Laravel (returns UserModel)
  Future<UserModel> signInWithGoogleAndBackend() async {
    try {
      debugPrint('[auth] starting Google sign-in');
      // 1) Google Sign-In
      final GoogleSignInAccount? gUser = await _gsi.signIn();
      if (gUser == null) {
        debugPrint('[auth] Google sign-in aborted by user');
        throw Exception('Sign-in aborted');
      }
      debugPrint('[auth] Google account selected: ${gUser.email}');

      final GoogleSignInAuthentication gAuth = await gUser.authentication;
      debugPrint(
        '[auth] Google auth received '
        'idToken=${gAuth.idToken != null && gAuth.idToken!.isNotEmpty} '
        'accessToken=${gAuth.accessToken != null && gAuth.accessToken!.isNotEmpty}',
      );

      // 2) Firebase sign-in to obtain a *fresh* ID token
      final credential = fb.GoogleAuthProvider.credential(
        idToken: gAuth.idToken,
        accessToken: gAuth.accessToken,
      );
      debugPrint('[auth] signing into Firebase with Google credential');
      final fb.UserCredential fbCred =
      await fb.FirebaseAuth.instance.signInWithCredential(credential);
      final fb.User? fUser = fbCred.user;
      if (fUser == null) {
        debugPrint('[auth] Firebase sign-in returned null user');
        throw Exception('Firebase user missing');
      }
      debugPrint('[auth] Firebase sign-in success uid=${fUser.uid}');
      final idToken = await fUser.getIdToken(true);
      debugPrint('[auth] Firebase ID token fetched successfully');

      // 3) Call Laravel to exchange Firebase ID token for Sanctum token
      debugPrint('[auth] calling Laravel auth/firebase/login');
      final res = await api.post<Map<String, dynamic>>(
        'auth/firebase/login', // baseUrl should be .../api
        data: {
          'idToken': idToken,
          'device_name': 'flutter-app',
        },
      );

      final status = res.statusCode ?? 0;
      final data = res.data ?? {};
      debugPrint('[auth] Laravel login response status=$status ok=${data['ok']}');
      if (status != 200 || data['ok'] != true) {
        final msg = (data['msg'] != null)
            ? data['msg'].toString()
            : 'Login failed';
        final code = (data['code'] ?? '').toString();
        throw Exception(_friendlyAuthMessage(status, msg, code));
      }

      final token   = (data['token'] as String?) ?? '';
      final userMap = (data['user'] as Map<String, dynamic>);
      final model   = UserModel.fromJson(userMap);

      // 3a) Blocked-at-login guard (server may include is_blocked or blocked)
      final blockedAtLogin =
          (userMap['is_blocked'] == true) ||
              (data['blocked'] == true) ||
              (data['error'] == 'blocked') ||
              (status == 423);
      if (blockedAtLogin) {
        await _signOutLocalOnly();
        throw Exception('Your account has been blocked.');
      }

      // 4) Persist locally FIRST so subsequent requests carry the token
      await storage.saveAuth(token, model.toJson());
      // 4a) Ask for notification permission + register FCM token
      await PushService.instance.init(api: api);
      await PushService.instance.requestPermissionAndRegister();

      // 5) Preflight verify (Sanctum): GET /api/ws/verify must be 200 & not blocked
      try {
        debugPrint('[auth] verifying socket auth via /api/ws/verify');
        final v = await api.get<Map<String, dynamic>>('ws/verify');
        final vStatus = v.statusCode ?? 0;
        final vData   = v.data ?? {};
        if (vStatus != 200 || vData['blocked'] == true) {
          await _signOutLocalOnly();
          throw Exception('Your account has been blocked.');
        }
        debugPrint('[auth] ws verify succeeded');
      } catch (e) {
        // If verify fails for any reason, clean up and surface the error
        debugPrint('[auth] ws verify failed: $e');
        await _signOutLocalOnly();
        rethrow;
      }

      debugPrint('[auth] login flow completed successfully');
      return model;
    } on fb.FirebaseAuthException catch (e) {
      debugPrint('[auth] FirebaseAuthException code=${e.code} message=${e.message}');
      throw Exception('Firebase sign-in failed: ${e.message ?? e.code}');
    } on DioException catch (e) {
      // If backend actively signals block via 423 or payload, convert it
      final status = e.response?.statusCode;
      final body   = e.response?.data;
      debugPrint('[auth] Laravel login DioException status=$status body=$body message=${e.message}');
      if (status == 423 ||
          body == 'blocked' ||
          (body is Map && (body['blocked'] == true || body['error'] == 'blocked'))) {
        await _signOutLocalOnly();
        throw Exception('Your account has been blocked.');
      }
      if (body is Map<String, dynamic>) {
        throw Exception(_friendlyAuthMessage(
          status ?? 0,
          (body['msg'] ?? e.message ?? 'Login failed').toString(),
          (body['code'] ?? '').toString(),
        ));
      }
      throw Exception('Login failed. ${e.message ?? 'Please try again.'}');
    } catch (e) {
      debugPrint('[auth] unexpected auth error: $e');
      rethrow;
    }
  }

  Future<void> logout() async {
    await PushService.instance.unregisterToken();
    try { await api.post('auth/logout'); } catch (_) {}
    try { await _gsi.disconnect(); } catch (_) { await _gsi.signOut(); }
    await fb.FirebaseAuth.instance.signOut();
    await PresenceService.instance.stop();
    try { if (Get.isRegistered<CallSocketService>()) await Get.find<CallSocketService>().stop(); } catch (_) {}
    await storage.clear();
    Get.offAllNamed(Routes.login);
  }

  Future<void> forceLogout(String reason) async {
    await PushService.instance.unregisterToken();
    await _signOutLocalOnly();
    Get.offAllNamed(Routes.login);
  }

  String _friendlyAuthMessage(int status, String msg, String code) {
    if (status == 503 && code == 'firebase_service_account_missing') {
      return 'Server login is not configured yet. Firebase admin credentials are missing.';
    }
    if (status == 503 && code == 'firebase_project_id_missing') {
      return 'Server login is not configured correctly. Firebase project id is missing.';
    }
    if (status == 401 && code == 'firebase_token_invalid') {
      return 'Google sign-in expired or is invalid. Please sign in again.';
    }
    if (status == 422 && code == 'firebase_email_missing') {
      return 'This Google account did not provide an email address.';
    }
    return msg;
  }
}
