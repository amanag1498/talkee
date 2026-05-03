import 'package:dio/dio.dart';
import 'package:get/get.dart';

import '../../../services/auth_service.dart';
import '../../../services/call_service.dart';
import '../../profile/controllers/host_follow_controller.dart';
import 'call_controller.dart';

class LiveUsersController extends GetxController {
  LiveUsersController(this._callService, this._auth, this._callController);

  final CallService _callService;
  final AuthService _auth;
  final AppCallController _callController;
  late final HostFollowController _follows;

  final RxList<Map<String, dynamic>> users = <Map<String, dynamic>>[].obs;
  final RxBool loading = false.obs;
  final RxBool loadingMore = false.obs;
  final RxnString errorMessage = RxnString();
  final RxInt viewerBalance = 0.obs;
  final RxInt minimumBalance = 0.obs;
  final RxString hostManualStatus = 'offline'.obs;
  final RxBool togglingHostStatus = false.obs;
  final RxBool hasMore = true.obs;
  final RxInt currentPage = 1.obs;
  final RxInt totalUsers = 0.obs;
  final RxInt perPage = 50.obs;
  bool _directoryActivated = false;
  bool _fetchInFlight = false;

  bool get isHost => _auth.currentUser?.roles.contains('host') ?? false;

  @override
  void onInit() {
    super.onInit();
    _follows = Get.find<HostFollowController>();
    _callController.restartSocket();
    if (isHost) {
      refreshHostStatus();
    }
    ever<Map<String, dynamic>?>(_callController.availabilityEvent, (event) async {
      if (event == null) return;
      final userId = (event['user_id'] as num?)?.toInt();
      if (userId == null) return;
      if (!_directoryActivated) return;
      final isOnline =
          event['manual_status'] == 'online' &&
          event['socket_status'] == 'online';
      final isAvailable = isOnline && event['call_status'] == 'available';
      final index = users.indexWhere((row) => (row['id'] as num?)?.toInt() == userId);
      if (!isOnline) {
        if (index >= 0) {
          users.removeAt(index);
        }
        return;
      }
      if (index < 0) {
        await fetch();
        return;
      }
      final updated = Map<String, dynamic>.from(users[index]);
      updated['availability'] = {
        ...Map<String, dynamic>.from(updated['availability'] as Map? ?? const {}),
        'manual_status': event['manual_status'],
        'socket_status': event['socket_status'],
        'call_status': event['call_status'],
        'current_call_session_id': event['current_call_session_id'],
        'is_online': isOnline,
        'is_available': isAvailable,
        'is_busy': isOnline && !isAvailable,
        'reason': event['reason'] ?? Map<String, dynamic>.from(updated['availability'] as Map? ?? const {})['reason'],
      };
      updated['is_online'] = isOnline;
      updated['is_available'] = isAvailable;
      updated['is_busy'] = isOnline && !isAvailable;
      updated['call_unavailable_reason'] = event['reason'] ?? updated['call_unavailable_reason'];
      updated['unavailable_reason'] = event['reason'] ?? updated['unavailable_reason'];
      updated['can_call'] = ((updated['availability'] as Map)['is_available'] == true) &&
          viewerBalance.value >= minimumBalance.value;
      users[index] = updated;
      _sortUsers();
    });
  }

  Future<void> activateDirectory() async {
    _directoryActivated = true;
    if (users.isEmpty && !_fetchInFlight) {
      await fetch(reset: true);
    }
  }

  Future<void> fetch({bool reset = true}) async {
    _directoryActivated = true;
    if (_fetchInFlight) return;
    _fetchInFlight = true;
    final targetPage = reset ? 1 : currentPage.value + 1;
    if (reset) {
      loading.value = true;
      errorMessage.value = null;
    } else {
      if (!hasMore.value) {
        _fetchInFlight = false;
        return;
      }
      loadingMore.value = true;
    }
    try {
      final response = await _callService.fetchLiveUsers(
        page: targetPage,
        perPage: perPage.value,
      );
      final data = Map<String, dynamic>.from(
        response['data'] as Map? ?? const <String, dynamic>{},
      );
      final meta = Map<String, dynamic>.from(
        response['meta'] as Map? ?? const <String, dynamic>{},
      );
      final list =
          (data['users'] as List?)
              ?.map((e) => Map<String, dynamic>.from(e as Map))
              .toList() ??
          <Map<String, dynamic>>[];
      if (reset) {
        users.assignAll(list);
      } else {
        users.addAll(list);
      }
      _follows.hydrateMany(list);
      _sortUsers();
      viewerBalance.value = _asInt(data['viewer_balance']);
      minimumBalance.value = _asInt(data['minimum_balance_to_start_call']);
      currentPage.value = _asInt(meta['current_page']) > 0 ? _asInt(meta['current_page']) : targetPage;
      hasMore.value = meta['has_more'] == true;
      totalUsers.value = _asInt(meta['total']);
      final appliedPerPage = _asInt(meta['per_page']);
      if (appliedPerPage > 0) {
        perPage.value = appliedPerPage;
      }
      if (isHost) {
        await refreshHostStatus();
      }
    } catch (e) {
      if (reset) {
        errorMessage.value = e.toString();
      } else {
        Get.snackbar(
          'Live users',
          'Could not load more hosts right now.',
          snackPosition: SnackPosition.BOTTOM,
        );
      }
    } finally {
      loading.value = false;
      loadingMore.value = false;
      _fetchInFlight = false;
    }
  }

  Future<void> loadMore() async {
    if (!_directoryActivated || loading.value || loadingMore.value || !hasMore.value) {
      return;
    }
    await fetch(reset: false);
  }

  Future<void> refreshHostStatus() async {
    if (!isHost) return;
    final status = await _callService.fetchHostStatus();
    hostManualStatus.value = (status['manual_status'] ?? 'offline').toString();
  }

  Future<void> toggleHostStatus() async {
    if (togglingHostStatus.value) {
      return;
    }

    final next = hostManualStatus.value == 'online' ? 'offline' : 'online';
    togglingHostStatus.value = true;
    try {
      final status = await _callService.toggleHostStatus(next);
      hostManualStatus.value = (status['manual_status'] ?? next).toString();
      await fetch(reset: true);
    } on DioException catch (e) {
      final statusCode = e.response?.statusCode;
      final data = e.response?.data;
      final message =
          data is Map && data['message'] != null
              ? data['message'].toString()
              : e.message ?? 'Unable to update host status right now.';
      Get.snackbar(
        'Host status',
        statusCode == 429
            ? 'You are toggling too fast. Please wait a moment.'
            : message,
        snackPosition: SnackPosition.BOTTOM,
      );
    } catch (e) {
      Get.snackbar(
        'Host status',
        e.toString(),
        snackPosition: SnackPosition.BOTTOM,
      );
    } finally {
      togglingHostStatus.value = false;
    }
  }

  Future<void> startCall(Map<String, dynamic> user, String type) async {
    if (!canStartCall(user, type)) {
      final message = unavailableMessage(user, type);
      Get.snackbar('Calls', message, snackPosition: SnackPosition.BOTTOM);
      return;
    }
    await _callController.placeCall(receiverId: (user['id'] as num).toInt(), type: type);
  }

  int rateFor(Map<String, dynamic> user, String type) {
    if (type == 'video') {
      return _asInt(user['video_call_rate_per_minute']);
    }

    return _asInt(user['audio_call_rate_per_minute']);
  }

  int requiredBalanceFor(Map<String, dynamic> user, String type) {
    final rate = rateFor(user, type);
    return rate > minimumBalance.value ? rate : minimumBalance.value;
  }

  bool canStartCall(Map<String, dynamic> user, String type) {
    final availability = Map<String, dynamic>.from(user['availability'] as Map? ?? const {});
    final isAvailable = availability['is_available'] == true;
    return isAvailable && viewerBalance.value >= requiredBalanceFor(user, type);
  }

  String availabilityLabel(Map<String, dynamic> user) {
    final availability = Map<String, dynamic>.from(user['availability'] as Map? ?? const {});
    final online = availability['is_online'] == true;
    final available = availability['is_available'] == true;
    if (!online) {
      return 'Offline';
    }
    return available ? 'Online • Available' : 'Online • Busy';
  }

  String unavailableMessage(Map<String, dynamic> user, String type) {
    final availability = Map<String, dynamic>.from(user['availability'] as Map? ?? const {});
    final reason = (user['call_unavailable_reason'] ?? user['unavailable_reason'] ?? availability['reason'] ?? '').toString();
    final required = requiredBalanceFor(user, type);
    if (viewerBalance.value < required) {
      return 'You need at least $required coins to start this ${type == 'video' ? 'video' : 'audio'} call.';
    }
    if (reason == 'manually_unavailable') {
      return 'This host is manually unavailable right now.';
    }
    if (reason == 'offline') {
      return 'This host is offline.';
    }
    if (reason == 'in_another_call' || reason == 'busy') {
      return 'This host is already in another call.';
    }
    return 'This host is unavailable right now.';
  }

  int _asInt(dynamic value) {
    if (value is num) {
      return value.toInt();
    }
    if (value is String) {
      return int.tryParse(value) ?? 0;
    }
    return 0;
  }

  void _sortUsers() {
    final sorted = users.toList()
      ..sort((a, b) {
        final scoreA = _sortScore(a);
        final scoreB = _sortScore(b);
        if (scoreA != scoreB) {
          return scoreA.compareTo(scoreB);
        }
        final nameA = (a['name'] ?? '').toString().toLowerCase();
        final nameB = (b['name'] ?? '').toString().toLowerCase();
        return nameA.compareTo(nameB);
      });
    users.assignAll(sorted);
  }

  int _sortScore(Map<String, dynamic> user) {
    final availability = Map<String, dynamic>.from(user['availability'] as Map? ?? const {});
    final online = user['is_online'] == true || availability['is_online'] == true;
    final available = user['is_available'] == true || availability['is_available'] == true;
    final busy = user['is_busy'] == true || availability['is_busy'] == true;
    if (online && available) return 0;
    if (online && busy) return 1;
    return 2;
  }
}
