import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import '../../../services/api_client.dart';
import '../models/notification_dto.dart';

class NotificationsApi {
  final ApiClient _api;
  NotificationsApi(this._api);

  void _log(String msg) => debugPrint('[api][notify] $msg');

  // Classic page (for first load/refresh)
  Future<List<NotificationDto>> fetchPage({int page = 1, int perPage = 20}) async {
    final res = await _api.get(
      'notifications',
      query: {'page': page, 'per_page': perPage},
    );
    return _parseList(res.data);
  }

  // Keyset: fetch older items before the given id (smooth infinite scroll)
  Future<List<NotificationDto>> fetchBefore({required String beforeId, int perPage = 20}) async {
    final res = await _api.get(
      'notifications',
      query: {'before_id': beforeId, 'per_page': perPage},
    );
    return _parseList(res.data);
  }

  // Optional: use If-None-Match to avoid re-downloading when nothing changed
  Future<List<NotificationDto>> fetchPageWithETag({
    required String ifNoneMatch,
    int page = 1,
    int perPage = 20,
  }) async {
    try {
      final res = await _api.get(
        'notifications',
        query: {'page': page, 'per_page': perPage},
        ifNoneMatch: ifNoneMatch,
      );
      return _parseList(res.data);
    } on Exception catch (e) {
      // If Dio throws on 304 you can handle it here; otherwise ignore.
      _log('fetchPageWithETag note: $e');
      return const <NotificationDto>[];
    }
  }

  Future<int> unreadCount() async {
    final res = await _api.get('notifications/unread-count');
    final data = _asMap(res.data);
    return (data['count'] as num?)?.toInt() ?? 0;
  }

  Future<void> markRead(String id) async {
    final res = await _api.post('notifications/$id/read');
    _log('markRead($id) <- ${res.statusCode}');
  }

  Future<int> markManyRead(List<String> ids) async {
    final res = await _api.post('notifications/read', data: {'ids': ids});
    final data = _asMap(res.data);
    return (data['updated'] as num?)?.toInt() ?? 0;
  }

  Future<void> markAllRead() async {
    final res = await _api.post('notifications/read-all');
    _log('markAllRead <- ${res.statusCode}');
  }

  // ────────────────────────────────────────────────────────────────────────────
  // Parsing helpers
  // ────────────────────────────────────────────────────────────────────────────

  List<NotificationDto> _parseList(dynamic body) {
    List raw;
    if (body is List) {
      raw = body;
    } else if (body is Map) {
      final m = _asMap(body);
      raw = (m['data'] is List) ? (m['data'] as List) : const [];
    } else {
      raw = const [];
    }

    return raw
        .whereType<Map>()
        .map((e) => NotificationDto.fromJson(_asMap(e)))
        .toList();
  }

  Map<String, dynamic> _asMap(dynamic v) {
    if (v is Map<String, dynamic>) return v;
    if (v is Map) return Map<String, dynamic>.from(v);
    return <String, dynamic>{};
  }
}
