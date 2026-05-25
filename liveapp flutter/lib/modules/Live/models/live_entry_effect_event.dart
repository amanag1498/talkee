import '../../../app/models/remote_media_kind.dart';

class LiveEntryEffectEvent {
  final String roomId;
  final String roomType;
  final int userId;
  final String userName;
  final String? avatarUrl;
  final int entryPackId;
  final String entryPackName;
  final String? svgUrl;
  final String? assetType;
  final String animationStyle;
  final int priority;
  final int durationMs;
  final DateTime triggeredAt;
  final int maxAgeMs;

  const LiveEntryEffectEvent({
    required this.roomId,
    required this.roomType,
    required this.userId,
    required this.userName,
    required this.entryPackId,
    required this.entryPackName,
    required this.animationStyle,
    required this.priority,
    required this.durationMs,
    required this.triggeredAt,
    this.avatarUrl,
    this.svgUrl,
    this.assetType,
    this.maxAgeMs = 8000,
  });

  factory LiveEntryEffectEvent.fromJson(Map<String, dynamic> json) {
    int toInt(dynamic value, int fallback) {
      if (value is int) return value;
      if (value is num) return value.toInt();
      return int.tryParse(value?.toString() ?? '') ?? fallback;
    }

    return LiveEntryEffectEvent(
      roomId: (json['room_id'] ?? '').toString(),
      roomType: (json['room_type'] ?? 'video').toString(),
      userId: toInt(json['user_id'], 0),
      userName: (json['user_name'] ?? 'User').toString(),
      avatarUrl: json['avatar_url']?.toString(),
      entryPackId: toInt(json['entry_pack_id'], 0),
      entryPackName: (json['entry_pack_name'] ?? 'Entry Pack').toString(),
      svgUrl: json['svg_url']?.toString(),
      assetType: json['asset_type']?.toString(),
      animationStyle: (json['animation_style'] ?? 'banner').toString(),
      priority: toInt(json['priority'], 1),
      durationMs: toInt(json['duration_ms'], 3000).clamp(2000, 2147483647),
      triggeredAt: DateTime.tryParse((json['triggered_at'] ?? '').toString()) ?? DateTime.now(),
      maxAgeMs: toInt(json['max_age_ms'], 8000),
    );
  }

  String get dedupeKey => '$roomId|$userId|$entryPackId|${triggeredAt.toIso8601String()}';

  bool get isExpired => DateTime.now().difference(triggeredAt).inMilliseconds > maxAgeMs;

  RemoteMediaKind get mediaKind =>
      detectRemoteMediaKind(explicitType: assetType, url: svgUrl);
}
