import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:get/get.dart';

import '../../../services/api_client.dart';
import '../../../services/storage_service.dart';
import '../models/live_gift_item.dart';
import '../models/live_pk_battle_model.dart';
import '../models/live_room_model.dart';
import '../../home/models/live_room_dto.dart' as home_dto;

class LiveService {
  final ApiClient api;
  LiveService(this.api);

  static String? _cachedSid;

  Future<String?> _sessionId() async {
    if (_cachedSid != null) return _cachedSid!;
    final storage = Get.find<StorageService>();
    _cachedSid = await storage.getOrCreateSessionId();
    return _cachedSid;
  }

  Future<LiveRoomModel> createOrStart({
    String? roomId,
    String? title,
    bool startNow = true, // default true so server returns a host token
    DateTime? scheduledAt,
    Map<String, dynamic>? meta,
  }) async {
    try {
      final res = await api.post<Map<String, dynamic>>(
        'live/rooms',
        data: {
          if (roomId != null) 'room_id': roomId,
          if (title != null) 'title': title,
          'start_now': startNow,
          if (scheduledAt != null) 'scheduled_at': scheduledAt.toIso8601String(),
          if (meta != null) 'meta': meta,
        },
      );
      final data = Map<String, dynamic>.from(res.data ?? const {});
      if (data['ok'] != true) {
        debugPrint('live create/start failed: $data');
        throw Exception(data['error'] ?? data['message'] ?? 'Failed to start room');
      }
      return LiveRoomModel.fromResponse(data);
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to start room'));
    }
  }

  Future<void> end(String roomId) async {
    roomId = _requireRoomId(roomId, action: 'end live room');
    try {
      await api.post('live/rooms/$roomId/end');
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to end live room'));
    }
  }

  Future<void> heartbeat(String roomId) async {
    roomId = _requireRoomId(roomId, action: 'send live heartbeat');
    try {
      await api.post('live/rooms/$roomId/heartbeat');
    } on DioException catch (_) {}
  }

  Future<List<home_dto.LiveRoomModel>> listLiveRooms({bool includeScheduled = false}) async {
    return _listRooms('live/rooms', query: {
      if (includeScheduled) 'include_scheduled': 1,
    });
  }

  Future<List<home_dto.LiveRoomModel>> listAudioRooms({bool includeScheduled = false}) async {
    return _listRooms('live/audio-rooms', query: {
      if (includeScheduled) 'include_scheduled': 1,
    });
  }

  Future<List<home_dto.LiveRoomModel>> _listRooms(String path, {Map<String, dynamic>? query}) async {
    try {
      final res = await api.get<Map<String, dynamic>>(path, query: query);
      final status = res.statusCode ?? 0;
      final data = res.data ?? const {};
      if (status != 200 || data['ok'] != true) {
        throw Exception(data['message'] ?? 'Failed to load live rooms ($status)');
      }

      final items = (data['data'] as List? ?? const [])
          .map((row) => home_dto.LiveRoomModel.fromJson(Map<String, dynamic>.from(row as Map)))
          .toList();

      return items;
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to load live rooms'));
    }
  }

  Future<LiveRoomModel> createAudioRoom({
    required String title,
    String? topic,
    String? language,
    int maxParticipants = 50,
    int maxSpeakers = 8,
    bool startNow = true,
    DateTime? scheduledAt,
  }) async {
    try {
      final res = await api.post<Map<String, dynamic>>(
        'live/audio-rooms',
        data: {
          'title': title,
          'topic': topic,
          'language': language,
          'max_participants': maxParticipants,
          'max_speakers': maxSpeakers,
          'start_now': startNow,
          if (scheduledAt != null) 'scheduled_at': scheduledAt.toIso8601String(),
        },
      );
      final data = Map<String, dynamic>.from(res.data ?? const {});
      if (data['ok'] != true) {
        throw Exception(data['error'] ?? data['message'] ?? 'Failed to start audio room');
      }
      return LiveRoomModel.fromResponse(data);
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to start audio room'));
    }
  }

  Future<LiveRoomModel> join(String roomId, {String role = 'viewer'}) async {
    roomId = _requireRoomId(roomId, action: 'join live room');
    try {
      final sid = await _sessionId();
      final res = await api.post<Map<String, dynamic>>(
        'live/rooms/$roomId/join',
        data: {
          'role': role,
          'session_id': sid,
          'device': Platform.operatingSystem,
        },
      );

      final status = res.statusCode ?? 0;
      final data = res.data ?? const {};
      if (status != 200 || data['ok'] != true) {
        throw Exception(data['message'] ?? 'Failed to join room ($status)');
      }

      return LiveRoomModel.fromResponse(data);
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to join room'));
    }
  }

  Future<LiveRoomModel> joinAudioRoom(String roomId) {
    return join(roomId, role: 'listener');
  }

  Future<void> leave(String roomId) async {
    roomId = _requireRoomId(roomId, action: 'leave live room');
    final sid = await _sessionId();
    try {
      await api.post(
        'live/rooms/$roomId/leave',
        data: {'session_id': sid},
      );
    } catch (_) {}
  }

  Future<Map<String, dynamic>> requestSpeaker(String roomId) async {
    roomId = _requireRoomId(roomId, action: 'request speaker access');
    try {
      final res = await api.post<Map<String, dynamic>>('live/rooms/$roomId/seat-requests');
      return Map<String, dynamic>.from(res.data ?? const {});
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to request speaker access'));
    }
  }

  Future<Map<String, dynamic>> seatSnapshot(String roomId, {String? status}) async {
    roomId = _requireRoomId(roomId, action: 'load speaker requests');
    try {
      final res = await api.get<Map<String, dynamic>>(
        'live/rooms/$roomId/seat-requests',
        query: {
          if (status != null && status.isNotEmpty) 'status': status,
        },
      );
      return Map<String, dynamic>.from(res.data ?? const {});
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to load speaker requests'));
    }
  }

  Future<Map<String, dynamic>> speakers(String roomId) async {
    roomId = _requireRoomId(roomId, action: 'load speakers');
    try {
      final res = await api.get<Map<String, dynamic>>('live/rooms/$roomId/speakers');
      return Map<String, dynamic>.from(res.data ?? const {});
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to load speakers'));
    }
  }

  Future<Map<String, dynamic>> acceptSpeakerRequest(String roomId, int requestId) async {
    roomId = _requireRoomId(roomId, action: 'accept speaker request');
    try {
      final res = await api.post<Map<String, dynamic>>('live/rooms/$roomId/seat-requests/$requestId/accept');
      return Map<String, dynamic>.from(res.data ?? const {});
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to accept speaker request'));
    }
  }

  Future<Map<String, dynamic>> rejectSpeakerRequest(String roomId, int requestId, {String? reason}) async {
    roomId = _requireRoomId(roomId, action: 'reject speaker request');
    try {
      final res = await api.post<Map<String, dynamic>>(
        'live/rooms/$roomId/seat-requests/$requestId/reject',
        data: {
          if (reason != null && reason.isNotEmpty) 'reason': reason,
        },
      );
      return Map<String, dynamic>.from(res.data ?? const {});
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to reject speaker request'));
    }
  }

  Future<Map<String, dynamic>> cancelSpeakerRequest(String roomId, int requestId) async {
    roomId = _requireRoomId(roomId, action: 'cancel speaker request');
    try {
      final res = await api.post<Map<String, dynamic>>('live/rooms/$roomId/seat-requests/$requestId/cancel');
      return Map<String, dynamic>.from(res.data ?? const {});
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to cancel speaker request'));
    }
  }

  Future<Map<String, dynamic>> removeSpeaker(String roomId, int userId, {String? reason}) async {
    roomId = _requireRoomId(roomId, action: 'remove speaker');
    try {
      final res = await api.post<Map<String, dynamic>>(
        'live/rooms/$roomId/speakers/$userId/remove',
        data: {
          if (reason != null && reason.isNotEmpty) 'reason': reason,
        },
      );
      return Map<String, dynamic>.from(res.data ?? const {});
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to remove speaker'));
    }
  }

  Future<Map<String, dynamic>> muteSpeaker(String roomId, int userId) async {
    roomId = _requireRoomId(roomId, action: 'mute speaker');
    try {
      final res = await api.post<Map<String, dynamic>>('live/rooms/$roomId/speakers/$userId/mute');
      return Map<String, dynamic>.from(res.data ?? const {});
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to mute speaker'));
    }
  }

  Future<List<LiveGiftItem>> listGifts() async {
    try {
      final res = await api.get<Map<String, dynamic>>('gifts');
      final status = res.statusCode ?? 0;
      final data = res.data ?? const {};
      if (status != 200 || data['ok'] != true) {
        throw Exception('Failed to load gifts ($status)');
      }

      return (data['data'] as List? ?? const [])
          .map((row) => LiveGiftItem.fromJson(Map<String, dynamic>.from(row as Map)))
          .toList();
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to load gifts'));
    }
  }

  Future<Map<String, dynamic>> sendRoomGift(
    String roomId, {
    required int giftId,
    int quantity = 1,
    String? message,
  }) async {
    roomId = _requireRoomId(roomId, action: 'send room gift');
    try {
      final res = await api.post<Map<String, dynamic>>(
        'live/rooms/$roomId/gifts',
        data: {
          'gift_id': giftId,
          'quantity': quantity,
          if (message != null && message.isNotEmpty) 'message': message,
        },
      );
      return Map<String, dynamic>.from(res.data ?? const {});
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to send gift'));
    }
  }

  Future<bool> setRoomReminder(String roomId) async {
    roomId = _requireRoomId(roomId, action: 'set room reminder');
    try {
      final res = await api.post<Map<String, dynamic>>('live/rooms/$roomId/reminder');
      return res.data?['data']?['has_reminder'] == true;
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to set reminder'));
    }
  }

  Future<bool> clearRoomReminder(String roomId) async {
    roomId = _requireRoomId(roomId, action: 'clear room reminder');
    try {
      final res = await api.delete<Map<String, dynamic>>('live/rooms/$roomId/reminder');
      return res.data?['data']?['has_reminder'] == true;
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to clear reminder'));
    }
  }

  Future<List<Map<String, dynamic>>> fetchHostBlockedUsers({
    int page = 1,
  }) async {
    try {
      final res = await api.get<Map<String, dynamic>>(
        'host/blocked-users',
        query: {'page': page},
      );
      return _extractListData(res.data, fallback: 'Failed to load blocked users');
    } on DioException catch (e) {
      throw Exception(
        _extractError(e, fallback: 'Failed to load blocked users'),
      );
    }
  }

  Future<List<Map<String, dynamic>>> fetchHostUnblockRequests({
    int page = 1,
  }) async {
    try {
      final res = await api.get<Map<String, dynamic>>(
        'host/unblock-requests',
        query: {'page': page},
      );
      return _extractListData(
        res.data,
        fallback: 'Failed to load unblock requests',
      );
    } on DioException catch (e) {
      throw Exception(
        _extractError(e, fallback: 'Failed to load unblock requests'),
      );
    }
  }

  Future<List<Map<String, dynamic>>> fetchHostModerationHistory({
    String? actionType,
    int? userId,
  }) async {
    try {
      final res = await api.get<Map<String, dynamic>>(
        'host/moderation-history',
        query: {
          if (actionType != null && actionType.isNotEmpty)
            'action_type': actionType,
          if (userId != null) 'user_id': userId,
        },
      );
      return _extractListData(
        res.data,
        fallback: 'Failed to load moderation history',
      );
    } on DioException catch (e) {
      throw Exception(
        _extractError(e, fallback: 'Failed to load moderation history'),
      );
    }
  }

  Future<Map<String, dynamic>> blockUser({
    required int userId,
    String? reason,
  }) async {
    try {
      final res = await api.post<Map<String, dynamic>>(
        'host/block-user',
        data: {
          'user_id': userId,
          if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim(),
        },
      );
      return _extractMapData(res.data, fallback: 'Failed to block user');
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to block user'));
    }
  }

  Future<Map<String, dynamic>> unblockUser({
    required int userId,
  }) async {
    try {
      final res = await api.post<Map<String, dynamic>>(
        'host/unblock-user',
        data: {'user_id': userId},
      );
      return _extractMapData(res.data, fallback: 'Failed to unblock user');
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to unblock user'));
    }
  }

  Future<Map<String, dynamic>> kickUser({
    required String roomId,
    required String roomType,
    required int userId,
    String? reason,
  }) async {
    roomId = _requireRoomId(roomId, action: 'kick user');
    try {
      final res = await api.post<Map<String, dynamic>>(
        'host/kick-user',
        data: {
          'room_id': roomId,
          'room_type': roomType.trim(),
          'user_id': userId,
          if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim(),
        },
      );
      return _extractMapData(res.data, fallback: 'Failed to kick user');
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to kick user'));
    }
  }

  Future<Map<String, dynamic>> submitReport({
    required int reportedUserId,
    int? hostUserId,
    String? roomId,
    String? roomType,
    required String reasonType,
    String? description,
  }) async {
    try {
      final res = await api.post<Map<String, dynamic>>(
        'reports',
        data: {
          'reported_user_id': reportedUserId,
          if (hostUserId != null) 'host_user_id': hostUserId,
          if (roomId != null && roomId.trim().isNotEmpty) 'room_id': roomId.trim(),
          if (roomType != null && roomType.trim().isNotEmpty)
            'room_type': roomType.trim(),
          'reason_type': reasonType.trim(),
          if (description != null && description.trim().isNotEmpty)
            'description': description.trim(),
        },
      );
      return _extractMapData(res.data, fallback: 'Failed to submit report');
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to submit report'));
    }
  }

  Future<Map<String, dynamic>> requestUnblock({
    required int hostUserId,
    String? message,
  }) async {
    try {
      final res = await api.post<Map<String, dynamic>>(
        'unblock-requests',
        data: {
          'host_user_id': hostUserId,
          if (message != null && message.trim().isNotEmpty)
            'message': message.trim(),
        },
      );
      return _extractMapData(
        res.data,
        fallback: 'Failed to request unblock',
      );
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to request unblock'));
    }
  }

  Future<List<Map<String, dynamic>>> fetchMyUnblockRequests({
    int? hostUserId,
    String? status,
  }) async {
    try {
      final res = await api.get<Map<String, dynamic>>(
        'unblock-requests/my',
        query: {
          if (hostUserId != null) 'host_user_id': hostUserId,
          if (status != null && status.trim().isNotEmpty) 'status': status.trim(),
        },
      );
      return _extractListData(
        res.data,
        fallback: 'Failed to load unblock requests',
      );
    } on DioException catch (e) {
      throw Exception(
        _extractError(e, fallback: 'Failed to load unblock requests'),
      );
    }
  }

  Future<Map<String, dynamic>> approveUnblockRequest(int requestId) async {
    try {
      final res = await api.post<Map<String, dynamic>>(
        'host/unblock-requests/$requestId/approve',
      );
      return _extractMapData(
        res.data,
        fallback: 'Failed to approve unblock request',
      );
    } on DioException catch (e) {
      throw Exception(
        _extractError(e, fallback: 'Failed to approve unblock request'),
      );
    }
  }

  Future<Map<String, dynamic>> rejectUnblockRequest(
    int requestId, {
    String? notes,
  }) async {
    try {
      final res = await api.post<Map<String, dynamic>>(
        'host/unblock-requests/$requestId/reject',
        data: {
          if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim(),
        },
      );
      return _extractMapData(
        res.data,
        fallback: 'Failed to reject unblock request',
      );
    } on DioException catch (e) {
      throw Exception(
        _extractError(e, fallback: 'Failed to reject unblock request'),
      );
    }
  }

  Future<LivePkBattleModel> invitePk(
    String roomId, {
    required String targetRoomId,
    int? durationSeconds,
  }) async {
    roomId = _requireRoomId(roomId, action: 'invite PK battle');
    try {
      final res = await api.post<Map<String, dynamic>>(
        'live/rooms/$roomId/pk/invite',
        data: {
          'target_room_id': targetRoomId,
          if (durationSeconds != null) 'duration_seconds': durationSeconds,
        },
      );
      return _parsePkResponse(res.data, fallback: 'Failed to send PK invite');
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to send PK invite'));
    }
  }

  Future<LivePkBattleModel> acceptPk(String roomId, String battleId) async {
    return _pkAction(roomId, battleId, 'accept', 'accept PK invite');
  }

  Future<LivePkBattleModel> rejectPk(String roomId, String battleId) async {
    return _pkAction(roomId, battleId, 'reject', 'reject PK invite');
  }

  Future<LivePkBattleModel> cancelPk(String roomId, String battleId) async {
    return _pkAction(roomId, battleId, 'cancel', 'cancel PK invite');
  }

  Future<LivePkBattleModel> endPk(String roomId, String battleId) async {
    return _pkAction(roomId, battleId, 'end', 'end PK battle');
  }

  Future<LivePkBattleModel?> activePk(String roomId) async {
    roomId = _requireRoomId(roomId, action: 'load active PK battle');
    try {
      final res = await api.get<Map<String, dynamic>>('live/rooms/$roomId/pk/active');
      final data = Map<String, dynamic>.from(res.data ?? const {});
      final payload = data['data'];
      if (payload is! Map) return null;
      return LivePkBattleModel.fromJson(Map<String, dynamic>.from(payload));
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to load active PK battle'));
    }
  }

  Future<List<LivePkBattleModel>> pkHistory(String roomId) async {
    roomId = _requireRoomId(roomId, action: 'load PK history');
    try {
      final res = await api.get<Map<String, dynamic>>('live/rooms/$roomId/pk/history');
      final data = Map<String, dynamic>.from(res.data ?? const {});
      if (data['ok'] != true) {
        throw Exception(data['message'] ?? 'Failed to load PK history');
      }
      return (data['data'] as List? ?? const [])
          .whereType<Map>()
          .map((row) => LivePkBattleModel.fromJson(Map<String, dynamic>.from(row)))
          .toList();
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to load PK history'));
    }
  }

  Future<Map<String, dynamic>> pkMediaToken(String roomId, String battleId) async {
    roomId = _requireRoomId(roomId, action: 'load PK media token');
    try {
      final res = await api.get<Map<String, dynamic>>('live/rooms/$roomId/pk/$battleId/media-token');
      final data = Map<String, dynamic>.from(res.data ?? const {});
      if (data['ok'] != true || data['data'] is! Map) {
        throw Exception(data['message'] ?? 'Failed to load PK media token');
      }
      return Map<String, dynamic>.from(data['data'] as Map);
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to load PK media token'));
    }
  }

  Future<LivePkBattleModel> _pkAction(String roomId, String battleId, String action, String fallback) async {
    roomId = _requireRoomId(roomId, action: fallback);
    try {
      final res = await api.post<Map<String, dynamic>>('live/rooms/$roomId/pk/$battleId/$action');
      return _parsePkResponse(res.data, fallback: 'Failed to $action PK battle');
    } on DioException catch (e) {
      throw Exception(_extractError(e, fallback: 'Failed to $action PK battle'));
    }
  }

  LivePkBattleModel _parsePkResponse(Map<String, dynamic>? raw, {required String fallback}) {
    final data = Map<String, dynamic>.from(raw ?? const {});
    if (data['ok'] != true || data['data'] is! Map) {
      throw Exception(data['message'] ?? fallback);
    }
    return LivePkBattleModel.fromJson(Map<String, dynamic>.from(data['data'] as Map));
  }

  String _extractError(DioException e, {required String fallback}) {
    final data = e.response?.data;
    if (data is Map) {
      final message = data['message']?.toString();
      if (message != null && message.isNotEmpty) {
        return message;
      }
      final error = data['error']?.toString();
      if (error == 'INSUFFICIENT_FUNDS') {
        return 'Not enough coins for this action.';
      }
      if (error != null && error.isNotEmpty) {
        return error;
      }
    }
    return fallback;
  }

  List<Map<String, dynamic>> _extractListData(
    Map<String, dynamic>? raw, {
    required String fallback,
  }) {
    final data = Map<String, dynamic>.from(raw ?? const {});
    if (data['ok'] != true) {
      throw Exception(data['message'] ?? fallback);
    }
    final payload = data['data'];
    if (payload is List) {
      return payload
          .whereType<Map>()
          .map((row) => Map<String, dynamic>.from(row))
          .toList();
    }
    if (payload is Map) {
      final nested = payload['data'];
      if (nested is List) {
        return nested
            .whereType<Map>()
            .map((row) => Map<String, dynamic>.from(row))
            .toList();
      }
    }
    return const <Map<String, dynamic>>[];
  }

  Map<String, dynamic> _extractMapData(
    Map<String, dynamic>? raw, {
    required String fallback,
  }) {
    final data = Map<String, dynamic>.from(raw ?? const {});
    if (data['ok'] != true) {
      throw Exception(data['message'] ?? fallback);
    }
    final payload = data['data'];
    if (payload is Map<String, dynamic>) return payload;
    if (payload is Map) return Map<String, dynamic>.from(payload);
    return data;
  }

  String _requireRoomId(String roomId, {required String action}) {
    final normalized = roomId.trim();
    if (normalized.isEmpty) {
      throw Exception('Missing live room id. Unable to $action.');
    }
    return normalized;
  }
}
