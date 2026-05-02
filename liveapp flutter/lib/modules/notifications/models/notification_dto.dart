// lib/modules/notifications/models/notification_dto.dart
import 'dart:convert';

/// Immutable DTO for a user notification.
///
/// Backend shape (Laravel):
/// {
///   "id": 123,                         // may be number or string
///   "type": "host_approved",           // nullable on backend, default ''
///   "title": "Congrats!",              // required
///   "body": "You are now a host",      // nullable on backend, default ''
///   "meta": { ... } | "{}" | null,     // may be Map, JSON string, or null
///   "read_at": "2025-10-03T10:11:12Z", // ISO or null
///   "created_at": "2025-10-03T10:10:00Z"
/// }
class NotificationDto {
  final String id;
  final String type;
  final String title;
  final String body;
  final Map<String, dynamic>? meta;
  final DateTime createdAt;
  final DateTime? readAt;

  const NotificationDto({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.meta,
    required this.createdAt,
    this.readAt,
  });

  /// Convenience: true if not read.
  bool get isUnread => readAt == null;

  /// Create a copy with selected fields changed.
  NotificationDto copyWith({
    String? id,
    String? type,
    String? title,
    String? body,
    Map<String, dynamic>? meta,
    DateTime? createdAt,
    DateTime? readAt,
  }) {
    return NotificationDto(
      id: id ?? this.id,
      type: type ?? this.type,
      title: title ?? this.title,
      body: body ?? this.body,
      meta: meta ?? this.meta,
      createdAt: createdAt ?? this.createdAt,
      readAt: readAt ?? this.readAt,
    );
  }

  /// Robust JSON factory – safely handles:
  /// - id as int or string
  /// - meta as Map / JSON string / null
  /// - dates as ISO strings or null
  factory NotificationDto.fromJson(Map<String, dynamic> json) {
    final rawId = json['id'];
    final id = rawId == null ? '' : rawId.toString();

    final type = (json['type'] ?? '').toString();
    final title = (json['title'] ?? '').toString();
    final body = (json['body'] ?? '').toString();

    final meta = _parseMeta(json['meta']);

    final createdAt = _parseDate(json['created_at']) ?? DateTime.fromMillisecondsSinceEpoch(0);
    final readAt = _parseDate(json['read_at']);

    return NotificationDto(
      id: id,
      type: type,
      title: title,
      body: body,
      meta: meta,
      createdAt: createdAt,
      readAt: readAt,
    );
  }

  /// Convert to JSON (useful for local caching).
  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'type': type.isEmpty ? null : type,
      'title': title,
      'body': body.isEmpty ? null : body,
      'meta': meta,
      'created_at': createdAt.toUtc().toIso8601String(),
      'read_at': readAt?.toUtc().toIso8601String(),
    };
  }

  // ────────────────────────────────────────────────────────────────────────────
  // Helpers
  // ────────────────────────────────────────────────────────────────────────────

  static DateTime? _parseDate(dynamic v) {
    if (v == null) return null;
    if (v is DateTime) return v.toUtc();
    if (v is String && v.trim().isEmpty) return null;
    if (v is String) {
      try {
        return DateTime.parse(v).toUtc();
      } catch (_) {
        return null;
      }
    }
    return null;
  }

  static Map<String, dynamic>? _parseMeta(dynamic v) {
    if (v == null) return null;
    if (v is Map<String, dynamic>) return v;
    if (v is Map) return Map<String, dynamic>.from(v);
    if (v is String) {
      final s = v.trim();
      if (s.isEmpty) return null;
      try {
        final decoded = json.decode(s);
        if (decoded is Map<String, dynamic>) return decoded;
        if (decoded is Map) return Map<String, dynamic>.from(decoded);
      } catch (_) {
        // ignore decode error, fallthrough
      }
    }
    return null;
  }
}
