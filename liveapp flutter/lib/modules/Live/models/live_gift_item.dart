class LiveGiftItem {
  final int id;
  final String name;
  final int coins;
  final String? giftUrl;
  final String? giftType;
  final String? animationTier;
  final int? animationDurationMs;
  final bool isActive;

  const LiveGiftItem({
    required this.id,
    required this.name,
    required this.coins,
    this.giftUrl,
    this.giftType,
    this.animationTier,
    this.animationDurationMs,
    this.isActive = true,
  });

  factory LiveGiftItem.fromJson(Map<String, dynamic> json) {
    int toInt(dynamic value) {
      if (value is int) return value;
      if (value is num) return value.toInt();
      return int.tryParse(value?.toString() ?? '') ?? 0;
    }

    return LiveGiftItem(
      id: toInt(json['id']),
      name: (json['name'] ?? '').toString(),
      coins: toInt(json['coins']),
      giftUrl: json['gift_url']?.toString(),
      giftType: json['gift_type']?.toString(),
      animationTier: json['animation_tier']?.toString(),
      animationDurationMs:
          json['animation_duration_ms'] == null
              ? null
              : toInt(json['animation_duration_ms']),
      isActive: json['is_active'] == null ? true : json['is_active'] == true,
    );
  }
}
