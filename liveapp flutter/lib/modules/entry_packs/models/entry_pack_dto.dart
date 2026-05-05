import '../../../app/models/remote_media_kind.dart';

class EntryPackDto {
  final int id;
  final String name;
  final int priceCoins;
  final String? svgUrl;
  final String? assetType;
  final String animationStyle;
  final int priority;
  final int durationMs;
  final int durationDays;
  final bool isActive;
  final bool owned;
  final bool active;

  const EntryPackDto({
    required this.id,
    required this.name,
    required this.priceCoins,
    required this.animationStyle,
    required this.priority,
    required this.durationMs,
    required this.durationDays,
    required this.isActive,
    required this.owned,
    required this.active,
    this.svgUrl,
    this.assetType,
  });

  factory EntryPackDto.fromJson(Map<String, dynamic> json) {
    int toInt(dynamic value, int fallback) {
      if (value is int) return value;
      if (value is num) return value.toInt();
      return int.tryParse(value?.toString() ?? '') ?? fallback;
    }

    return EntryPackDto(
      id: toInt(json['id'], 0),
      name: (json['name'] ?? 'Entry Pack').toString(),
      priceCoins: toInt(json['price_coins'], 0),
      svgUrl: json['svg_url']?.toString(),
      assetType: json['asset_type']?.toString(),
      animationStyle: (json['animation_style'] ?? 'banner').toString(),
      priority: toInt(json['priority'], 1),
      durationMs: toInt(json['duration_ms'], 3000),
      durationDays: toInt(json['duration_days'], 30),
      isActive: json['is_active'] == true,
      owned: json['owned'] == true,
      active: json['active'] == true,
    );
  }

  EntryPackDto copyWith({
    bool? owned,
    bool? active,
  }) {
    return EntryPackDto(
      id: id,
      name: name,
      priceCoins: priceCoins,
      svgUrl: svgUrl,
      assetType: assetType,
      animationStyle: animationStyle,
      priority: priority,
      durationMs: durationMs,
      durationDays: durationDays,
      isActive: isActive,
      owned: owned ?? this.owned,
      active: active ?? this.active,
    );
  }

  RemoteMediaKind get mediaKind =>
      detectRemoteMediaKind(explicitType: assetType, url: svgUrl);
}
