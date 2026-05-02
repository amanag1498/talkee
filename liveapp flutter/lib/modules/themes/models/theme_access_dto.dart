class ThemeAccessCatalogDto {
  const ThemeAccessCatalogDto({
    required this.activeThemeKey,
    required this.fallbackThemeKey,
    required this.themes,
  });

  final String activeThemeKey;
  final String fallbackThemeKey;
  final List<ThemeAccessItemDto> themes;

  factory ThemeAccessCatalogDto.fromJson(Map<String, dynamic> json) {
    final rawThemes = json['themes'] is List ? json['themes'] as List : const [];
    return ThemeAccessCatalogDto(
      activeThemeKey: (json['active_theme_key'] ?? 'midnight').toString(),
      fallbackThemeKey: (json['fallback_theme_key'] ?? 'midnight').toString(),
      themes:
          rawThemes
              .whereType<Map>()
              .map((item) => ThemeAccessItemDto.fromJson(
                    Map<String, dynamic>.from(item),
                  ))
              .toList(growable: false),
    );
  }
}

class ThemeAccessItemDto {
  const ThemeAccessItemDto({
    required this.key,
    required this.name,
    required this.description,
    required this.unlockType,
    required this.unlocked,
    required this.lockedReason,
    required this.isActive,
    required this.isDefault,
    required this.isLimited,
    required this.startsAt,
    required this.endsAt,
    required this.price,
    required this.sortOrder,
    required this.expiresAt,
    required this.source,
    required this.tokenSource,
    required this.remoteTokens,
  });

  final String key;
  final String name;
  final String? description;
  final String unlockType;
  final bool unlocked;
  final String? lockedReason;
  final bool isActive;
  final bool isDefault;
  final bool isLimited;
  final DateTime? startsAt;
  final DateTime? endsAt;
  final double? price;
  final int sortOrder;
  final DateTime? expiresAt;
  final String? source;
  final String tokenSource;
  final Map<String, dynamic>? remoteTokens;

  factory ThemeAccessItemDto.fromJson(Map<String, dynamic> json) {
    DateTime? parseDate(dynamic value) {
      final raw = value?.toString();
      if (raw == null || raw.isEmpty) return null;
      return DateTime.tryParse(raw);
    }

    bool toBool(dynamic value) {
      if (value is bool) return value;
      final normalized = value?.toString().trim().toLowerCase();
      return normalized == '1' || normalized == 'true' || normalized == 'yes';
    }

    return ThemeAccessItemDto(
      key: (json['key'] ?? 'midnight').toString(),
      name: (json['name'] ?? 'Theme').toString(),
      description: json['description']?.toString(),
      unlockType: (json['unlock_type'] ?? 'free').toString(),
      unlocked: toBool(json['unlocked']),
      lockedReason: json['locked_reason']?.toString(),
      isActive: toBool(json['is_active']),
      isDefault: toBool(json['is_default']),
      isLimited: toBool(json['is_limited']),
      startsAt: parseDate(json['starts_at']),
      endsAt: parseDate(json['ends_at']),
      price: (json['price'] as num?)?.toDouble(),
      sortOrder: (json['sort_order'] as num?)?.toInt() ?? 0,
      expiresAt: parseDate(json['expires_at']),
      source: json['source']?.toString(),
      tokenSource: (json['token_source'] ?? 'local').toString().toLowerCase(),
      remoteTokens:
          json['remote_tokens'] is Map
              ? Map<String, dynamic>.from(json['remote_tokens'] as Map)
              : null,
    );
  }
}
