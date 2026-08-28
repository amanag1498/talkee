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
import '../modules/profile/models/profile_dto.dart';
import 'api_client.dart';
import 'auth_exception.dart';
import 'app_settings_service.dart';
import 'live_eligibility_service.dart';
import 'meta_attribution_service.dart';
import 'storage_service.dart';

class AuthService {
  final ApiClient api;
  final StorageService storage;

  final String? iosClientId;
  late final GoogleSignIn _gsi;

  AuthService({required this.api, required this.storage, this.iosClientId}) {
    _gsi = GoogleSignIn(
      scopes: const ['email', 'profile'],
      clientId: (Platform.isIOS || Platform.isMacOS) ? iosClientId : null,
    );
  }

  bool get isLoggedIn => storage.token != null && storage.token!.isNotEmpty;

  UserModel? get currentUser {
    final j = storage.userJson;
    if (j == null) return null;
    try {
      return UserModel.fromJson(j);
    } catch (_) {
      return null;
    }
  }

  // --- helper: local-only cleanup (no API call, no navigation) ---
  Future<void> _signOutLocalOnly() async {
    try {
      await PresenceService.instance.stop();
    } catch (_) {}
    try {
      if (Get.isRegistered<CallSocketService>())
        await Get.find<CallSocketService>().stop();
    } catch (_) {}
    try {
      await _gsi.disconnect();
    } catch (_) {
      try {
        await _gsi.signOut();
      } catch (_) {}
    }
    try {
      await fb.FirebaseAuth.instance.signOut();
    } catch (_) {}
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
      final fb.UserCredential fbCred = await fb.FirebaseAuth.instance
          .signInWithCredential(credential);
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
        data: {'idToken': idToken, 'device_name': 'flutter-app'},
      );

      final status = res.statusCode ?? 0;
      final data = res.data ?? {};
      debugPrint(
        '[auth] Laravel login response status=$status ok=${data['ok']}',
      );
      if (status != 200 || data['ok'] != true) {
        final msg =
            (data['msg'] != null) ? data['msg'].toString() : 'Login failed';
        final code = (data['code'] ?? '').toString();
        throw Exception(_friendlyAuthMessage(status, msg, code));
      }

      final token = (data['token'] as String?) ?? '';
      final userMap = (data['user'] as Map<String, dynamic>);
      final model = UserModel.fromJson(userMap);

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
      if (Get.isRegistered<MetaAttributionService>()) {
        final attribution = Get.find<MetaAttributionService>();
        await attribution.requestTrackingConsent();
        await attribution.logLifecycleEvent(
          'login',
          provider: 'google',
          isNewUser: data['is_new_user'] == true,
        );
        if (data['is_new_user'] == true) {
          await attribution.logLifecycleEvent('complete_registration');
        }
      }
      // 4a) Ask for notification permission + register FCM token
      try {
        await PushService.instance.init(api: api);
        await PushService.instance.requestPermissionAndRegister();
      } catch (error) {
        debugPrint('[push] registration after Google login failed: $error');
      }

      // 5) Preflight verify (Sanctum): GET /api/ws/verify must be 200 & not blocked
      try {
        debugPrint('[auth] verifying socket auth via /api/ws/verify');
        final v = await api.get<Map<String, dynamic>>('ws/verify');
        final vStatus = v.statusCode ?? 0;
        final vData = v.data ?? {};
        if (vStatus != 200 || vData['blocked'] == true) {
          if (_isUpgradeRequiredResponse(vStatus, vData)) {
            throw AppUpgradeRequiredException(
              _upgradeRequiredMessageFromBody(vData),
            );
          }
          await _signOutLocalOnly();
          throw Exception('Your account has been blocked.');
        }
        debugPrint('[auth] ws verify succeeded');
      } on DioException catch (e) {
        final status = e.response?.statusCode ?? 0;
        final body = e.response?.data;
        if (_isUpgradeRequiredResponse(status, body)) {
          debugPrint('[auth] ws verify blocked by force-upgrade');
          return model;
        }
        debugPrint('[auth] ws verify failed: $e');
        await _signOutLocalOnly();
        rethrow;
      } catch (e) {
        // If verify fails for any reason, clean up and surface the error
        debugPrint('[auth] ws verify failed: $e');
        if (e is AppUpgradeRequiredException) {
          return model;
        }
        await _signOutLocalOnly();
        rethrow;
      }

      debugPrint('[auth] login flow completed successfully');
      return model;
    } on fb.FirebaseAuthException catch (e) {
      debugPrint(
        '[auth] FirebaseAuthException code=${e.code} message=${e.message}',
      );
      throw Exception('Firebase sign-in failed: ${e.message ?? e.code}');
    } on DioException catch (e) {
      // If backend actively signals block via 423 or payload, convert it
      final status = e.response?.statusCode;
      final body = e.response?.data;
      debugPrint(
        '[auth] Laravel login DioException status=$status body=$body message=${e.message}',
      );
      if (status == 423 ||
          body == 'blocked' ||
          (body is Map &&
              (body['blocked'] == true || body['error'] == 'blocked'))) {
        await _signOutLocalOnly();
        throw Exception('Your account has been blocked.');
      }
      if (_isUpgradeRequiredResponse(status, body)) {
        throw AppUpgradeRequiredException(
          _upgradeRequiredMessageFromBody(body),
        );
      }
      if (body is Map<String, dynamic>) {
        throw Exception(
          _friendlyAuthMessage(
            status ?? 0,
            (body['msg'] ?? e.message ?? 'Login failed').toString(),
            (body['code'] ?? '').toString(),
          ),
        );
      }
      throw Exception('Login failed. ${e.message ?? 'Please try again.'}');
    } catch (e) {
      debugPrint('[auth] unexpected auth error: $e');
      rethrow;
    }
  }

  Future<UserModel> signInWithAppleAndBackend() async {
    if (!isApplePlatform) {
      throw Exception('Sign in with Apple is only available on Apple devices.');
    }

    try {
      final provider =
          fb.AppleAuthProvider()
            ..addScope('email')
            ..addScope('name');
      final credential = await fb.FirebaseAuth.instance.signInWithProvider(
        provider,
      );
      final user = credential.user;
      if (user == null) throw Exception('Firebase user missing');
      return _completeAppleFirebaseLogin(user);
    } on fb.FirebaseAuthException catch (e) {
      if (isAppleSignInCancellation(e.code, e.message)) {
        throw const AuthSignInCancelledException();
      }
      throw Exception('Apple sign-in failed: ${e.message ?? e.code}');
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      final body = e.response?.data;
      if (status == 423 ||
          body == 'blocked' ||
          (body is Map &&
              (body['blocked'] == true || body['error'] == 'blocked'))) {
        await _signOutLocalOnly();
        throw Exception('Your account has been blocked.');
      }
      if (_isUpgradeRequiredResponse(status, body)) {
        throw AppUpgradeRequiredException(
          _upgradeRequiredMessageFromBody(body),
        );
      }
      if (body is Map<String, dynamic>) {
        throw Exception(
          _friendlyAuthMessage(
            status ?? 0,
            (body['msg'] ?? e.message ?? 'Login failed').toString(),
            (body['code'] ?? '').toString(),
          ),
        );
      }
      throw Exception('Login failed. ${e.message ?? 'Please try again.'}');
    }
  }

  Future<UserModel> _completeAppleFirebaseLogin(fb.User firebaseUser) async {
    final idToken = await firebaseUser.getIdToken(true);
    final res = await api.post<Map<String, dynamic>>(
      'auth/firebase/login',
      data: {'idToken': idToken, 'device_name': 'flutter-apple'},
    );
    final status = res.statusCode ?? 0;
    final data = res.data ?? {};
    if (status != 200 || data['ok'] != true) {
      throw Exception(
        _friendlyAuthMessage(
          status,
          (data['msg'] ?? 'Login failed').toString(),
          (data['code'] ?? '').toString(),
        ),
      );
    }

    final token = (data['token'] as String?) ?? '';
    final userMap = Map<String, dynamic>.from(data['user'] as Map);
    final model = UserModel.fromJson(userMap);
    if (userMap['is_blocked'] == true ||
        data['blocked'] == true ||
        data['error'] == 'blocked' ||
        status == 423) {
      await _signOutLocalOnly();
      throw Exception('Your account has been blocked.');
    }

    await storage.saveAuth(token, model.toJson());
    if (Get.isRegistered<MetaAttributionService>()) {
      final attribution = Get.find<MetaAttributionService>();
      await attribution.requestTrackingConsent();
      await attribution.logLifecycleEvent(
        'login',
        provider: 'apple',
        isNewUser: data['is_new_user'] == true,
      );
      if (data['is_new_user'] == true) {
        await attribution.logLifecycleEvent('complete_registration');
      }
    }
    try {
      await PushService.instance.init(api: api);
      await PushService.instance.requestPermissionAndRegister();
    } catch (error) {
      debugPrint('[push] registration after Apple login failed: $error');
    }

    try {
      final verification = await api.get<Map<String, dynamic>>('ws/verify');
      final verificationData = verification.data ?? {};
      if ((verification.statusCode ?? 0) != 200 ||
          verificationData['blocked'] == true) {
        if (_isUpgradeRequiredResponse(
          verification.statusCode,
          verificationData,
        )) {
          return model;
        }
        await _signOutLocalOnly();
        throw Exception('Your account has been blocked.');
      }
    } on DioException catch (e) {
      if (_isUpgradeRequiredResponse(
        e.response?.statusCode,
        e.response?.data,
      )) {
        return model;
      }
      await _signOutLocalOnly();
      rethrow;
    }
    return model;
  }

  Future<void> logout() async {
    await PushService.instance.unregisterToken();
    try {
      await api.post('auth/logout');
    } catch (_) {}
    try {
      await _gsi.disconnect();
    } catch (_) {
      await _gsi.signOut();
    }
    await fb.FirebaseAuth.instance.signOut();
    await PresenceService.instance.stop();
    try {
      if (Get.isRegistered<CallSocketService>())
        await Get.find<CallSocketService>().stop();
    } catch (_) {}
    await storage.clear();
    Get.offAllNamed(Routes.login);
  }

  Future<void> deleteAccount() async {
    final firebaseAuth = fb.FirebaseAuth.instance;
    final firebaseUser = firebaseAuth.currentUser;
    if (firebaseUser == null) {
      throw Exception('Please sign in again before deleting your account.');
    }
    final usesApple =
        isApplePlatform &&
        (currentUser?.provider.toLowerCase() == 'apple' ||
            firebaseUser.providerData.any(
              (provider) => provider.providerId == 'apple.com',
            ));
    final usesGoogle =
        currentUser?.provider.toLowerCase() == 'google' ||
        firebaseUser.providerData.any(
          (provider) => provider.providerId == 'google.com',
        );

    if (usesApple) {
      final provider =
          fb.AppleAuthProvider()
            ..addScope('email')
            ..addScope('name');
      final credential = await firebaseUser.reauthenticateWithProvider(
        provider,
      );
      final authorizationCode =
          credential.additionalUserInfo?.authorizationCode?.trim() ?? '';
      if (authorizationCode.isEmpty) {
        throw Exception(
          'Apple could not confirm account deletion. Please try again.',
        );
      }
      await firebaseAuth.revokeTokenWithAuthorizationCode(authorizationCode);
    } else if (usesGoogle) {
      final googleUser = await _gsi.signIn();
      if (googleUser == null) throw const AuthSignInCancelledException();
      final googleAuth = await googleUser.authentication;
      await firebaseUser.reauthenticateWithCredential(
        fb.GoogleAuthProvider.credential(
          idToken: googleAuth.idToken,
          accessToken: googleAuth.accessToken,
        ),
      );
    }

    await PushService.instance.unregisterToken();
    final response = await api.delete<Map<String, dynamic>>('account');
    if (response.data?['ok'] != true) {
      throw Exception(
        (response.data?['message'] ?? 'Account deletion failed.').toString(),
      );
    }
    try {
      await firebaseUser.delete();
    } catch (error) {
      debugPrint('[auth] Firebase identity cleanup deferred: $error');
    }
    await _signOutLocalOnly();
    Get.offAllNamed(Routes.login);
  }

  Future<void> forceLogout(String reason) async {
    await PushService.instance.unregisterToken();
    await _signOutLocalOnly();
    Get.offAllNamed(Routes.login);
  }

  Future<UserModel?> refreshCurrentUserFromProfile() async {
    final current = currentUser;
    if (current == null || !isLoggedIn) return current;

    try {
      final res = await api.get<Map<String, dynamic>>('profile');
      final body = _asMap(res.data);
      final data = _asMap(body['data']);
      final profile = ProfileDto.fromJson(data);

      final refreshed = current.copyWith(
        name: profile.name,
        avatarUrl: profile.avatarUrl,
        isBlocked: profile.isBlocked,
        profileFrame:
            profile.profileFrame == null
                ? null
                : UserProfileFrameSummary.fromJson(
                  profile.profileFrame!.toJson(),
                ),
        roles: profile.roles,
        canGoLive: profile.canGoLive,
        level: profile.level,
        levelTitle: profile.levelTitle,
        badgeIcon: profile.badgeIcon,
        badgeColor: profile.badgeColor,
        lifetimeSpendCoins: profile.lifetimeSpendCoins,
        nextLevel: profile.nextLevel,
        nextLevelTitle: profile.nextLevelTitle,
        nextLevelRequiredSpend: profile.nextLevelRequiredSpend,
        remainingSpendToNextLevel: profile.remainingSpendToNextLevel,
        progressPercent: profile.progressPercent,
        hostProfile:
            profile.hostProfile == null
                ? null
                : HostProfile(
                  stageName: profile.hostProfile?.stageName,
                  country: profile.hostProfile?.country,
                  city: profile.hostProfile?.city,
                  bio: profile.hostProfile?.bio,
                  contactPhone: profile.hostProfile?.contactPhone,
                  isBlocked: profile.hostProfile?.isBlocked ?? false,
                  videoRoomsEnabled:
                      profile.hostProfile?.videoRoomsEnabled ?? true,
                  audioRoomsEnabled:
                      profile.hostProfile?.audioRoomsEnabled ?? true,
                  videoCallsEnabled:
                      profile.hostProfile?.videoCallsEnabled ?? true,
                  audioCallsEnabled:
                      profile.hostProfile?.audioCallsEnabled ?? true,
                ),
      );

      await storage.saveUserJson(refreshed.toJson());
      if (Get.isRegistered<LiveEligibilityService>()) {
        Get.find<LiveEligibilityService>().setFromUser(refreshed);
      }
      return refreshed;
    } on DioException catch (e) {
      if (_isUpgradeRequiredResponse(
        e.response?.statusCode,
        e.response?.data,
      )) {
        return current;
      }
      rethrow;
    }
  }

  String _friendlyAuthMessage(int status, String msg, String code) {
    if (status == 503 && code == 'firebase_service_account_missing') {
      return 'Server login is not configured yet. Firebase admin credentials are missing.';
    }
    if (status == 503 && code == 'firebase_project_id_missing') {
      return 'Server login is not configured correctly. Firebase project id is missing.';
    }
    if (status == 401 && code == 'firebase_token_invalid') {
      return 'Your sign-in expired or is invalid. Please sign in again.';
    }
    if (status == 422 && code == 'firebase_email_missing') {
      return 'This sign-in account did not provide an email address.';
    }
    return msg;
  }

  Map<String, dynamic> _asMap(dynamic value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return <String, dynamic>{};
  }

  bool _isUpgradeRequiredResponse(int? status, dynamic body) {
    if (status != 426) return false;
    if (body is Map) {
      final map = Map<String, dynamic>.from(body);
      return (map['error'] ?? '').toString().trim().toUpperCase() ==
          'APP_UPGRADE_REQUIRED';
    }
    return false;
  }

  String _upgradeRequiredMessageFromBody(dynamic body) {
    if (body is Map) {
      final map = Map<String, dynamic>.from(body);
      final message = map['message']?.toString().trim();
      if (message != null && message.isNotEmpty) {
        return message;
      }
    }
    return Get.isRegistered<AppSettingsService>()
        ? Get.find<AppSettingsService>().forceUpgradeMessage
        : 'Please update Talkieo to continue using the app.';
  }
}

class AuthSignInCancelledException implements Exception {
  const AuthSignInCancelledException();
}

bool get isApplePlatform =>
    !kIsWeb &&
    (defaultTargetPlatform == TargetPlatform.iOS ||
        defaultTargetPlatform == TargetPlatform.macOS);

@visibleForTesting
bool isAppleSignInCancellation(String code, String? message) {
  final normalizedCode = code.toLowerCase();
  final normalizedMessage = (message ?? '').toLowerCase();
  return normalizedCode == 'web-context-canceled' ||
      normalizedCode == 'canceled' ||
      normalizedCode == 'cancelled' ||
      normalizedCode == 'popup-closed-by-user' ||
      normalizedMessage.contains('user canceled') ||
      normalizedMessage.contains('user cancelled') ||
      normalizedMessage.contains('canceled by the user') ||
      normalizedMessage.contains('cancelled by the user');
}
