enum RoomGiftAnimationTier { small, medium, premium, legendary }

enum RoomGiftAssetKind { svg, gif, image, unknown }

class RoomGiftAnimationEvent {
  const RoomGiftAnimationEvent({
    required this.roomId,
    required this.roomType,
    required this.receiverId,
    required this.senderId,
    required this.senderName,
    required this.giftId,
    required this.giftName,
    required this.giftAssetUrl,
    required this.quantity,
    required this.coinsPerUnit,
    required this.totalCoins,
    required this.createdAt,
    this.message,
    this.giftType,
    this.animationTier,
    this.animationDurationMs,
    this.senderAvatar,
    this.senderLevel,
    this.senderIsVip,
    this.senderActiveThemeKey,
    this.receiverActiveThemeKey,
    this.receiverName,
    this.receiverAvatar,
    this.pkSide,
    this.comboCount = 1,
    this.isLocalSender = false,
  });

  final String roomId;
  final String roomType;
  final int receiverId;
  final int senderId;
  final String senderName;
  final int giftId;
  final String giftName;
  final String giftAssetUrl;
  final int quantity;
  final int coinsPerUnit;
  final int totalCoins;
  final String? message;
  final DateTime createdAt;
  final String? giftType;
  final String? animationTier;
  final int? animationDurationMs;
  final String? senderAvatar;
  final int? senderLevel;
  final bool? senderIsVip;
  final String? senderActiveThemeKey;
  final String? receiverActiveThemeKey;
  final String? receiverName;
  final String? receiverAvatar;
  final String? pkSide;
  final int comboCount;
  final bool isLocalSender;

  factory RoomGiftAnimationEvent.fromJson(
    Map<String, dynamic> json, {
    int? receiverFallbackId,
  }) {
    final createdAtRaw = _safeTrimmedString(json['created_at']);
    final quantity = (_safeInt(json['quantity']) ?? 1).clamp(1, 9999);
    final coinsPerUnit = _safeInt(json['coins_per_unit']) ?? 0;
    final totalCoins =
        _safeInt(json['totalCoins']) ??
        _safeInt(json['total_coins']) ??
        (coinsPerUnit * quantity);
    return RoomGiftAnimationEvent(
      roomId: (json['room_id'] ?? '').toString(),
      roomType: _normalizeRoomType(json['room_type']),
      receiverId:
          _safeInt(json['receiverId']) ??
          _safeInt(json['host_user_id']) ??
          receiverFallbackId ??
          0,
      senderId:
          _safeInt(json['senderId']) ??
          _safeInt(json['sender_user_id']) ??
          0,
      senderName: (json['sender_name'] ?? 'Someone').toString(),
      giftId: _safeInt(json['gift_id']) ?? 0,
      giftName: (json['gift_name'] ?? 'Gift').toString(),
      giftAssetUrl:
          (json['giftAssetUrl'] ?? json['gift_url'] ?? '').toString().trim(),
      quantity: quantity,
      coinsPerUnit: coinsPerUnit,
      totalCoins: totalCoins,
      message: json['message']?.toString(),
      createdAt:
          DateTime.tryParse(createdAtRaw ?? '')?.toLocal() ?? DateTime.now(),
      giftType:
          _safeTrimmedString(json['gift_type'] ?? json['giftType'])
              ?.toLowerCase(),
      animationTier:
          _safeTrimmedString(
            json['animation_tier'] ?? json['animationTier'],
          )?.toLowerCase(),
      animationDurationMs: _safeInt(
        json['animation_duration_ms'] ?? json['animationDurationMs'],
      ),
      senderAvatar:
          _safeTrimmedString(json['sender_avatar'] ?? json['senderAvatar']),
      senderLevel: _safeInt(json['sender_level'] ?? json['senderLevel']),
      senderIsVip: _safeBool(json['sender_is_vip'] ?? json['senderIsVip']),
      senderActiveThemeKey: _safeTrimmedString(
        json['sender_active_theme_key'] ?? json['senderActiveThemeKey'],
      ),
      receiverActiveThemeKey: _safeTrimmedString(
        json['receiver_active_theme_key'] ?? json['receiverActiveThemeKey'],
      ),
      receiverName:
          _safeTrimmedString(json['receiver_name'] ?? json['receiverName']),
      receiverAvatar:
          _safeTrimmedString(json['receiver_avatar'] ?? json['receiverAvatar']),
      pkSide: _safeTrimmedString(json['pk_side'] ?? json['pkSide'])
          ?.toLowerCase(),
      comboCount: quantity,
    );
  }

  RoomGiftAnimationEvent copyWith({
    String? roomId,
    String? roomType,
    int? receiverId,
    int? senderId,
    String? senderName,
    int? giftId,
    String? giftName,
    String? giftAssetUrl,
    int? quantity,
    int? coinsPerUnit,
    int? totalCoins,
    String? message,
    DateTime? createdAt,
    String? giftType,
    Object? animationTier = _sentinel,
    Object? animationDurationMs = _sentinel,
    Object? senderAvatar = _sentinel,
    Object? senderLevel = _sentinel,
    Object? senderIsVip = _sentinel,
    Object? senderActiveThemeKey = _sentinel,
    Object? receiverActiveThemeKey = _sentinel,
    Object? receiverName = _sentinel,
    Object? receiverAvatar = _sentinel,
    Object? pkSide = _sentinel,
    int? comboCount,
    bool? isLocalSender,
  }) {
    return RoomGiftAnimationEvent(
      roomId: roomId ?? this.roomId,
      roomType: roomType ?? this.roomType,
      receiverId: receiverId ?? this.receiverId,
      senderId: senderId ?? this.senderId,
      senderName: senderName ?? this.senderName,
      giftId: giftId ?? this.giftId,
      giftName: giftName ?? this.giftName,
      giftAssetUrl: giftAssetUrl ?? this.giftAssetUrl,
      quantity: quantity ?? this.quantity,
      coinsPerUnit: coinsPerUnit ?? this.coinsPerUnit,
      totalCoins: totalCoins ?? this.totalCoins,
      message: message ?? this.message,
      createdAt: createdAt ?? this.createdAt,
      giftType: giftType ?? this.giftType,
      animationTier:
          identical(animationTier, _sentinel)
              ? this.animationTier
              : animationTier as String?,
      animationDurationMs:
          identical(animationDurationMs, _sentinel)
              ? this.animationDurationMs
              : animationDurationMs as int?,
      senderAvatar:
          identical(senderAvatar, _sentinel)
              ? this.senderAvatar
              : senderAvatar as String?,
      senderLevel:
          identical(senderLevel, _sentinel)
              ? this.senderLevel
              : senderLevel as int?,
      senderIsVip:
          identical(senderIsVip, _sentinel)
              ? this.senderIsVip
              : senderIsVip as bool?,
      senderActiveThemeKey:
          identical(senderActiveThemeKey, _sentinel)
              ? this.senderActiveThemeKey
              : senderActiveThemeKey as String?,
      receiverActiveThemeKey:
          identical(receiverActiveThemeKey, _sentinel)
              ? this.receiverActiveThemeKey
              : receiverActiveThemeKey as String?,
      receiverName:
          identical(receiverName, _sentinel)
              ? this.receiverName
              : receiverName as String?,
      receiverAvatar:
          identical(receiverAvatar, _sentinel)
              ? this.receiverAvatar
              : receiverAvatar as String?,
      pkSide:
          identical(pkSide, _sentinel) ? this.pkSide : pkSide as String?,
      comboCount: comboCount ?? this.comboCount,
      isLocalSender: isLocalSender ?? this.isLocalSender,
    );
  }

  RoomGiftAnimationTier get tier {
    switch (animationTier?.trim().toLowerCase()) {
      case 'small':
        return RoomGiftAnimationTier.small;
      case 'medium':
        return RoomGiftAnimationTier.medium;
      case 'premium':
        return RoomGiftAnimationTier.premium;
      case 'legendary':
        return RoomGiftAnimationTier.legendary;
    }
    if (totalCoins >= 10000) return RoomGiftAnimationTier.legendary;
    if (totalCoins >= 1000) return RoomGiftAnimationTier.premium;
    if (totalCoins >= 100) return RoomGiftAnimationTier.medium;
    return RoomGiftAnimationTier.small;
  }

  RoomGiftAssetKind get assetKind {
    final explicit = (giftType ?? '').trim().toLowerCase();
    if (explicit == 'svg') return RoomGiftAssetKind.svg;
    if (explicit == 'gif') return RoomGiftAssetKind.gif;
    if (explicit == 'png' ||
        explicit == 'jpg' ||
        explicit == 'jpeg' ||
        explicit == 'webp' ||
        explicit == 'image') {
      return RoomGiftAssetKind.image;
    }

    final url = giftAssetUrl.toLowerCase();
    if (url.endsWith('.svg')) return RoomGiftAssetKind.svg;
    if (url.endsWith('.gif')) return RoomGiftAssetKind.gif;
    if (url.endsWith('.png') ||
        url.endsWith('.jpg') ||
        url.endsWith('.jpeg') ||
        url.endsWith('.webp')) {
      return RoomGiftAssetKind.image;
    }
    return RoomGiftAssetKind.unknown;
  }

  String get comboKey => '$senderId:$receiverId:$giftId:$roomId';

  String get dedupeKey =>
      '$roomId:$senderId:$giftId:${createdAt.toUtc().toIso8601String()}:$quantity:$totalCoins';

  Duration get displayDuration {
    final override = animationDurationMs;
    if (override != null && override > 0) {
      return Duration(milliseconds: override.clamp(800, 12000));
    }
    switch (tier) {
      case RoomGiftAnimationTier.small:
        return const Duration(milliseconds: 1450);
      case RoomGiftAnimationTier.medium:
        return const Duration(milliseconds: 2600);
      case RoomGiftAnimationTier.premium:
        return const Duration(milliseconds: 5000);
      case RoomGiftAnimationTier.legendary:
        return const Duration(milliseconds: 6800);
    }
  }

  static int? _safeInt(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }

  static bool? _safeBool(Object? value) {
    if (value is bool) return value;
    final normalized = value?.toString().trim().toLowerCase();
    if (normalized == 'true' || normalized == '1') return true;
    if (normalized == 'false' || normalized == '0') return false;
    return null;
  }

  static String? _safeTrimmedString(Object? value) {
    final text = value?.toString();
    if (text == null) return null;
    final trimmed = text.trim();
    return trimmed.isEmpty ? null : trimmed;
  }
}

String _normalizeRoomType(dynamic value) {
  final normalized = value?.toString().trim().toLowerCase() ?? '';
  if (normalized == 'audio' || normalized == 'audio_room') return 'audio';
  if (normalized == 'video' || normalized == 'video_room') return 'video';
  return normalized.isEmpty ? 'video' : normalized;
}

const Object _sentinel = Object();
