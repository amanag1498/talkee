import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:get/get.dart';

import '../../../app/utils/profile_frame_payload.dart';
import '../../../services/auth_service.dart';
import '../../../services/call_service.dart';
import '../../../services/presence_service.dart';
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
  bool get hostAppearsLive => hostManualStatus.value == 'online';
  bool get hostVideoRoomsEnabled =>
      _auth.currentUser?.hostProfile?.videoRoomsEnabled ?? true;
  bool get hostAudioRoomsEnabled =>
      _auth.currentUser?.hostProfile?.audioRoomsEnabled ?? true;
  bool get hostVideoCallsEnabled =>
      _auth.currentUser?.hostProfile?.videoCallsEnabled ?? true;
  bool get hostAudioCallsEnabled =>
      _auth.currentUser?.hostProfile?.audioCallsEnabled ?? true;
  bool get hostAccountBlocked =>
      (_auth.currentUser?.isBlocked ?? false) ||
      (_auth.currentUser?.hostProfile?.isBlocked ?? false);
  bool get hostHasAnyRoomEnabled => hostVideoRoomsEnabled || hostAudioRoomsEnabled;
  bool get hostHasAnyCallEnabled => hostVideoCallsEnabled || hostAudioCallsEnabled;
  bool get canToggleHostAvailability =>
      isHost && !hostAccountBlocked && hostHasAnyCallEnabled;

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
      if (userId == _auth.currentUser?.id) {
        _applyHostStatus(event);
      }
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
      updated['can_call'] =
          canStartCall(updated, 'audio') || canStartCall(updated, 'video');
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
              .where((row) => isCallTypeEnabled(row, 'audio') || isCallTypeEnabled(row, 'video'))
              .toList() ??
          <Map<String, dynamic>>[];
      final firstUser = list.isNotEmpty ? list.first : null;
      debugPrint(
        '[live-users][payload] first.avatar=${firstUser?['avatar_url']} '
        'first.rawProfileFrame=${firstUser?['profile_frame']} '
        'first.frame=${profileFrameAssetUrlFromPayload(firstUser)}',
      );
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
    _applyHostStatus(status);
  }

  Future<void> toggleHostStatus() async {
    if (togglingHostStatus.value) {
      return;
    }
    if (!canToggleHostAvailability) {
      Get.snackbar(
        'Host status',
        hostAccountBlocked
            ? 'Blocked hosts cannot go online.'
            : 'Calls are disabled for this host.',
        snackPosition: SnackPosition.BOTTOM,
      );
      await refreshHostStatus();
      return;
    }

    final next = hostManualStatus.value == 'online' ? 'offline' : 'online';
    togglingHostStatus.value = true;
    try {
      if (next == 'online') {
        await PresenceService.instance.resumeOnline();
        await _callController.restartSocket();
      }
      final status = await _callService.toggleHostStatus(next);
      _applyHostStatus(status);
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
    return isCallTypeEnabled(user, type) &&
        isAvailable &&
        viewerBalance.value >= requiredBalanceFor(user, type);
  }

  bool isCallTypeEnabled(Map<String, dynamic> user, String type) {
    final key = type == 'video' ? 'video_calls_enabled' : 'audio_calls_enabled';
    final hostProfile = Map<String, dynamic>.from(user['host_profile'] as Map? ?? const {});
    final raw = user[key] ?? hostProfile[key] ?? true;
    return _asBool(raw);
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
    if (!isCallTypeEnabled(user, type)) {
      return 'This host is not accepting ${type == 'video' ? 'video' : 'audio'} calls right now.';
    }
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
    if (reason == 'host_calls_disabled') {
      return 'This host is not accepting calls right now.';
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

  void _applyHostStatus(Map<String, dynamic> status) {
    final userBlocked = _asBool(status['is_blocked'], fallback: _auth.currentUser?.isBlocked);
    final hostBlocked = _asBool(
      status['host_is_blocked'],
      fallback: _auth.currentUser?.hostProfile?.isBlocked,
    );
    final manualStatus = (status['manual_status'] ?? 'offline').toString();
    final socketStatus = (status['socket_status'] ?? 'offline').toString();
    unawaited(
      _auth.storage.updateUserJson((json) {
        json['is_blocked'] = userBlocked;
        final currentHostProfile = Map<String, dynamic>.from(
          json['host_profile'] as Map? ?? const <String, dynamic>{},
        );
        currentHostProfile['is_blocked'] = hostBlocked;
        currentHostProfile['video_rooms_enabled'] = _asBool(
          status['video_rooms_enabled'],
          fallback: currentHostProfile['video_rooms_enabled'],
        );
        currentHostProfile['audio_rooms_enabled'] = _asBool(
          status['audio_rooms_enabled'],
          fallback: currentHostProfile['audio_rooms_enabled'],
        );
        currentHostProfile['video_calls_enabled'] = _asBool(
          status['video_calls_enabled'],
          fallback: currentHostProfile['video_calls_enabled'],
        );
        currentHostProfile['audio_calls_enabled'] = _asBool(
          status['audio_calls_enabled'],
          fallback: currentHostProfile['audio_calls_enabled'],
        );
        json['host_profile'] = currentHostProfile;
      }),
    );
    hostManualStatus.value =
        !userBlocked &&
                !hostBlocked &&
                manualStatus == 'online' &&
                socketStatus == 'online'
            ? 'online'
            : 'offline';
  }

  bool _asBool(dynamic value, {dynamic fallback}) {
    final resolved = value ?? fallback;
    if (resolved is bool) return resolved;
    if (resolved is num) return resolved != 0;
    if (resolved == null) return true;
    return resolved.toString().trim().toLowerCase() == 'true';
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
