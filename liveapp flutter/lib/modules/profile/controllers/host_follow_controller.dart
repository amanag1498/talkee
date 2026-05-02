import 'package:get/get.dart';

import '../services/host_follow_api.dart';

class HostFollowController extends GetxController {
  HostFollowController(this._api);

  final HostFollowApi _api;

  final RxMap<int, bool> followingByHostId = <int, bool>{}.obs;
  final RxMap<int, int> followerCountByHostId = <int, int>{}.obs;
  final RxMap<int, int> hostIdByUserId = <int, int>{}.obs;
  final RxSet<int> loadingHostIds = <int>{}.obs;

  final RxList<Map<String, dynamic>> followingHosts = <Map<String, dynamic>>[].obs;
  final RxList<Map<String, dynamic>> followers = <Map<String, dynamic>>[].obs;
  final RxBool listLoading = false.obs;
  final RxnString listError = RxnString();

  bool isFollowing(int hostId, {bool fallback = false}) => followingByHostId[hostId] ?? fallback;
  int followerCount(int hostId, {int fallback = 0}) => followerCountByHostId[hostId] ?? fallback;
  bool isBusy(int hostId) => loadingHostIds.contains(hostId);

  void hydrateFromHostCard(Map<String, dynamic> user) {
    final hostId = _hostIdOf(user);
    final userId = _userIdOf(user);
    if (hostId == null) return;
    followingByHostId[hostId] = user['is_following'] == true;
    followerCountByHostId[hostId] = _asInt(user['follower_count']);
    if (userId != null) {
      hostIdByUserId[userId] = hostId;
    }
  }

  void hydrateMany(Iterable<Map<String, dynamic>> users) {
    for (final user in users) {
      hydrateFromHostCard(user);
    }
  }

  Future<Map<String, dynamic>?> fetchStateByUserId(int userId) async {
    try {
      final data = await _api.fetchStateByUserId(userId);
      final hostId = _asInt(data['host_id']);
      if (hostId > 0) {
        followingByHostId[hostId] = data['is_following'] == true;
        followerCountByHostId[hostId] = _asInt(data['follower_count']);
        hostIdByUserId[userId] = hostId;
      }
      return data;
    } catch (_) {
      return null;
    }
  }

  Future<bool> toggleForHost({
    required int hostId,
    bool? current,
    int? currentCount,
  }) async {
    if (loadingHostIds.contains(hostId)) return false;
    loadingHostIds.add(hostId);

    final prevFollowing = current ?? followingByHostId[hostId] ?? false;
    final prevCount = currentCount ?? followerCountByHostId[hostId] ?? 0;
    final optimisticFollowing = !prevFollowing;
    final optimisticCount = optimisticFollowing ? prevCount + 1 : (prevCount > 0 ? prevCount - 1 : 0);

    followingByHostId[hostId] = optimisticFollowing;
    followerCountByHostId[hostId] = optimisticCount;

    try {
      final data = prevFollowing ? await _api.unfollow(hostId) : await _api.follow(hostId);
      followingByHostId[hostId] = data['is_following'] == true;
      followerCountByHostId[hostId] = _asInt(data['follower_count']);
      _syncFollowingList(hostId, data['is_following'] == true, _asInt(data['follower_count']));
      return true;
    } catch (_) {
      followingByHostId[hostId] = prevFollowing;
      followerCountByHostId[hostId] = prevCount;
      return false;
    } finally {
      loadingHostIds.remove(hostId);
    }
  }

  Future<void> loadFollowing() async {
    listLoading.value = true;
    listError.value = null;
    try {
      final items = await _api.fetchFollowing();
      followingHosts.assignAll(items);
      hydrateMany(items);
    } catch (e) {
      listError.value = e.toString().replaceFirst('Exception: ', '');
    } finally {
      listLoading.value = false;
    }
  }

  Future<void> loadFollowers() async {
    listLoading.value = true;
    listError.value = null;
    try {
      final items = await _api.fetchFollowers();
      followers.assignAll(items);
    } catch (e) {
      listError.value = e.toString().replaceFirst('Exception: ', '');
    } finally {
      listLoading.value = false;
    }
  }

  int? hostIdForUser(int userId) => hostIdByUserId[userId];

  int? _hostIdOf(Map<String, dynamic> user) {
    final hostProfile = Map<String, dynamic>.from(user['host_profile'] as Map? ?? const {});
    final hostId = _asInt(hostProfile['host_id'] ?? user['host_id']);
    return hostId > 0 ? hostId : null;
  }

  int? _userIdOf(Map<String, dynamic> user) {
    final userId = _asInt(user['id']);
    return userId > 0 ? userId : null;
  }

  void _syncFollowingList(int hostId, bool isFollowingNow, int followerCount) {
    final index = followingHosts.indexWhere((item) {
      final hostProfile = Map<String, dynamic>.from(item['host_profile'] as Map? ?? const {});
      return _asInt(hostProfile['host_id'] ?? item['host_id']) == hostId;
    });
    if (index < 0) return;

    if (!isFollowingNow) {
      followingHosts.removeAt(index);
      return;
    }

    followingHosts[index] = {
      ...followingHosts[index],
      'is_following': true,
      'follower_count': followerCount,
    };
  }

  int _asInt(dynamic value) {
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value) ?? 0;
    return 0;
  }
}
