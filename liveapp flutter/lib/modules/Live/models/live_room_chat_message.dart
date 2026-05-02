class LiveRoomChatMessage {
  const LiveRoomChatMessage({
    required this.id,
    required this.roomId,
    required this.roomType,
    required this.senderId,
    required this.senderName,
    required this.message,
    required this.messageType,
    required this.createdAt,
    this.senderAvatar,
    this.senderLevel,
    this.senderIsVip = false,
    this.senderIsHost = false,
    this.senderActiveThemeKey = 'midnight',
  });

  final String id;
  final String roomId;
  final String roomType;
  final int senderId;
  final String senderName;
  final String? senderAvatar;
  final int? senderLevel;
  final bool senderIsVip;
  final bool senderIsHost;
  final String senderActiveThemeKey;
  final String message;
  final String messageType;
  final DateTime createdAt;

  bool get isSystem => messageType == 'system';

  LiveRoomChatMessage copyWith({
    String? id,
    String? roomId,
    String? roomType,
    int? senderId,
    String? senderName,
    String? senderAvatar,
    int? senderLevel,
    bool? senderIsVip,
    bool? senderIsHost,
    String? senderActiveThemeKey,
    String? message,
    String? messageType,
    DateTime? createdAt,
  }) {
    return LiveRoomChatMessage(
      id: id ?? this.id,
      roomId: roomId ?? this.roomId,
      roomType: roomType ?? this.roomType,
      senderId: senderId ?? this.senderId,
      senderName: senderName ?? this.senderName,
      senderAvatar: senderAvatar ?? this.senderAvatar,
      senderLevel: senderLevel ?? this.senderLevel,
      senderIsVip: senderIsVip ?? this.senderIsVip,
      senderIsHost: senderIsHost ?? this.senderIsHost,
      senderActiveThemeKey: senderActiveThemeKey ?? this.senderActiveThemeKey,
      message: message ?? this.message,
      messageType: messageType ?? this.messageType,
      createdAt: createdAt ?? this.createdAt,
    );
  }

  factory LiveRoomChatMessage.fromSocketJson(Map<String, dynamic> json) {
    final createdAtRaw = json['created_at']?.toString();
    final createdAt =
        createdAtRaw != null
            ? DateTime.tryParse(createdAtRaw) ?? DateTime.now()
            : DateTime.now();
    final senderId = _toInt(json['sender_id']) ?? 0;
    final message = (json['message'] ?? '').toString();
    final senderName =
        (json['sender_name'] ?? '').toString().trim().isNotEmpty
            ? json['sender_name'].toString().trim()
            : (senderId > 0 ? 'User $senderId' : 'System');
    return LiveRoomChatMessage(
      id:
          (json['id']?.toString().trim().isNotEmpty ?? false)
              ? json['id'].toString().trim()
              : '${json['room_id'] ?? ''}|$senderId|${createdAt.toIso8601String()}|$message',
      roomId: (json['room_id'] ?? '').toString(),
      roomType: (json['room_type'] ?? 'video').toString(),
      senderId: senderId,
      senderName: senderName,
      senderAvatar: json['sender_avatar']?.toString(),
      senderLevel: _toInt(json['sender_level']),
      senderIsVip: json['sender_is_vip'] == true,
      senderIsHost: json['sender_is_host'] == true,
      senderActiveThemeKey:
          (json['sender_active_theme_key']?.toString().trim().isNotEmpty ?? false)
              ? json['sender_active_theme_key'].toString().trim()
              : 'midnight',
      message: message,
      messageType: (json['message_type'] ?? 'text').toString(),
      createdAt: createdAt,
    );
  }

  factory LiveRoomChatMessage.system({
    required String roomId,
    required String roomType,
    required String message,
  }) {
    final createdAt = DateTime.now();
    return LiveRoomChatMessage(
      id: 'system|$roomId|${createdAt.toIso8601String()}|$message',
      roomId: roomId,
      roomType: roomType,
      senderId: 0,
      senderName: 'System',
      message: message,
      messageType: 'system',
      createdAt: createdAt,
    );
  }

  static int? _toInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }
}
