import 'package:get/get.dart';

import '../../../services/auth_service.dart';
import '../services/user_block_api.dart';

class UserBlockController extends GetxController {
  UserBlockController(this._api, this._auth);

  final UserBlockApi _api;
  final AuthService _auth;

  final RxSet<int> blockedUserIds = <int>{}.obs;
  final RxList<Map<String, dynamic>> blockedUsers =
      <Map<String, dynamic>>[].obs;
  final RxBool loading = false.obs;
  final RxnString error = RxnString();
  final RxInt revision = 0.obs;
  int _refreshGeneration = 0;

  @override
  void onInit() {
    super.onInit();
    refreshForCurrentAuth();
  }

  bool isBlocked(int? userId) =>
      userId != null && userId > 0 && blockedUserIds.contains(userId);

  Future<void> refreshForCurrentAuth() async {
    final generation = ++_refreshGeneration;
    final expectedUserId = _auth.currentUser?.id;
    if (!_auth.isLoggedIn) {
      blockedUserIds.clear();
      blockedUsers.clear();
      error.value = null;
      loading.value = false;
      revision.value++;
      return;
    }

    loading.value = true;
    error.value = null;
    try {
      final rows = await _api.fetchBlockedUsers();
      if (generation != _refreshGeneration ||
          !_auth.isLoggedIn ||
          _auth.currentUser?.id != expectedUserId) {
        return;
      }
      blockedUsers.assignAll(rows);
      blockedUserIds.assignAll(
        rows.map((row) => _asInt(row['user_id'])).whereType<int>(),
      );
      revision.value++;
    } catch (exception) {
      if (generation == _refreshGeneration) {
        error.value = exception.toString().replaceFirst('Exception: ', '');
      }
    } finally {
      if (generation == _refreshGeneration) loading.value = false;
    }
  }

  Future<void> block(int userId) async {
    if (userId <= 0 || isBlocked(userId)) return;
    final row = await _api.block(userId);
    _refreshGeneration++;
    loading.value = false;
    error.value = null;
    blockedUserIds.add(userId);
    blockedUsers.removeWhere((entry) => _asInt(entry['user_id']) == userId);
    blockedUsers.insert(0, <String, dynamic>{...row, 'user_id': userId});
    blockedUserIds.refresh();
    revision.value++;
  }

  Future<void> unblock(int userId) async {
    if (userId <= 0) return;
    await _api.unblock(userId);
    _refreshGeneration++;
    loading.value = false;
    error.value = null;
    blockedUserIds.remove(userId);
    blockedUsers.removeWhere((entry) => _asInt(entry['user_id']) == userId);
    blockedUserIds.refresh();
    revision.value++;
  }

  int? _asInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }
}
