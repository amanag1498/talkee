import 'dart:math';

import 'package:get_storage/get_storage.dart';

class StorageService {
  static const _kToken = 'auth_token';
  static const _kUser  = 'auth_user';
  static const _kSessionId = 'session_id';
  static const _kWelcomeTipAckPrefix = 'welcome_tip_ack_user_';

  final GetStorage _box = GetStorage();

  /// Call this once in main() before using StorageService:
  ///   await StorageService.init();
  static Future<void> init() async {
    await GetStorage.init();
  }

  String? get token {
    final t = _box.read(_kToken);
    return t is String ? t : null;
  }

  Map<String, dynamic>? get userJson {
    final raw = _box.read(_kUser);
    if (raw is Map) return Map<String, dynamic>.from(raw);
    return null;
  }

  /// Save both token and user payload
  Future<void> saveAuth(String token, Map<String, dynamic> user) async {
    await _box.write(_kToken, token);
    await _box.write(_kUser, user);
  }

  /// Overwrite only the user payload (keeps existing token)
  Future<void> saveUserJson(Map<String, dynamic> user) async {
    await _box.write(_kUser, user);
  }

  /// Read-modify-write helper for user payload
  Future<void> updateUserJson(void Function(Map<String, dynamic> json) mutate) async {
    final current = userJson ?? <String, dynamic>{};
    final copy = Map<String, dynamic>.from(current);
    mutate(copy);
    await _box.write(_kUser, copy);
  }

  /// Convenience setter for the go-live flag (writes both camel & snake case)
  Future<void> setCanGoLive(bool value) async {
    final current = userJson ?? <String, dynamic>{};
    final copy = Map<String, dynamic>.from(current);
    copy['canGoLive'] = value;
    copy['can_go_live'] = value;
    await _box.write(_kUser, copy);
  }

  /// Returns a stable session id for this app install.
  Future<String> getOrCreateSessionId() async {
    final existing = _box.read(_kSessionId);
    if (existing is String && existing.isNotEmpty) return existing;

    final now = DateTime.now().microsecondsSinceEpoch.toRadixString(16);
    final rnd = Random.secure().nextInt(1 << 32).toRadixString(16);
    final sid = 'sid_$now$rnd';
    await _box.write(_kSessionId, sid);
    return sid;
  }

  bool hasWelcomeTipAck(int userId) {
    final raw = _box.read('$_kWelcomeTipAckPrefix$userId');
    return raw == true || raw == 1 || raw == '1';
  }

  Future<void> markWelcomeTipAck(int userId) async {
    await _box.write('$_kWelcomeTipAckPrefix$userId', true);
  }

  Future<void> clear() async {
    await _box.remove(_kToken);
    await _box.remove(_kUser);
  }
}
