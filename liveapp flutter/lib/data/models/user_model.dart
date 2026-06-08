// lib/app/models/user_model.dart
class UserModel {
  final int id;
  final String name;
  final String email;
  final String? avatarUrl;
  final UserProfileFrameSummary? profileFrame;
  final String provider;
  final bool emailVerified;
  final bool isBlocked;

  /// Spatie data
  final List<String> roles;        // e.g. ["user"], ["host"], ["admin"]
  final List<String> permissions;  // optional; keep if you’ll use fine-grained gates
  final bool canGoLive;            // convenient boolean for UI
  final int? level;
  final String? levelTitle;
  final String? badgeIcon;
  final String? badgeColor;
  final int? lifetimeSpendCoins;
  final int? nextLevel;
  final String? nextLevelTitle;
  final int? nextLevelRequiredSpend;
  final int? remainingSpendToNextLevel;
  final double? progressPercent;

  /// Optional host profile (when user is a host)
  final HostProfile? hostProfile;

  const UserModel({
    required this.id,
    required this.name,
    required this.email,
    required this.provider,
    required this.emailVerified,
    required this.isBlocked,
    this.avatarUrl,
    this.profileFrame,
    this.roles = const [],
    this.permissions = const [],
    this.canGoLive = false,
    this.level,
    this.levelTitle,
    this.badgeIcon,
    this.badgeColor,
    this.lifetimeSpendCoins,
    this.nextLevel,
    this.nextLevelTitle,
    this.nextLevelRequiredSpend,
    this.remainingSpendToNextLevel,
    this.progressPercent,
    this.hostProfile,
  });

  bool get isHost  => roles.contains('host');
  bool get isAdmin => roles.contains('admin');
  bool get isAgency => roles.contains('agency');
  bool get isNormalUser => !isHost && !isAdmin && !isAgency;

  factory UserModel.fromJson(Map<String, dynamic> j) {
    final roles = (j['roles'] as List?)
        ?.whereType<String>()
        .toList(growable: false) ??
        const <String>[];

    final perms = (j['permissions'] as List?)
        ?.whereType<String>()
        .toList(growable: false) ??
        const <String>[];

    return UserModel(
      id: j['id'] as int,
      name: (j['name'] ?? '') as String,
      email: (j['email'] ?? '') as String,
      avatarUrl: j['avatar_url'] as String?,
      profileFrame: j['profile_frame'] is Map
          ? UserProfileFrameSummary.fromJson(
              Map<String, dynamic>.from(j['profile_frame'] as Map),
            )
          : null,
      provider: (j['provider'] ?? '') as String,
      emailVerified: (j['email_verified'] ?? false) as bool,
      isBlocked: (j['is_blocked'] ?? false) as bool,
      roles: roles,
      permissions: perms,
      canGoLive: _asBool(j['can_go_live'] ?? j['canGoLive']),
      level: (j['level'] as num?)?.toInt(),
      levelTitle: j['level_title']?.toString(),
      badgeIcon: j['badge_icon']?.toString(),
      badgeColor: j['badge_color']?.toString(),
      lifetimeSpendCoins: (j['lifetime_spend_coins'] as num?)?.toInt(),
      nextLevel: (j['next_level'] as num?)?.toInt(),
      nextLevelTitle: j['next_level_title']?.toString(),
      nextLevelRequiredSpend: (j['next_level_required_spend'] as num?)?.toInt(),
      remainingSpendToNextLevel: (j['remaining_spend_to_next_level'] as num?)?.toInt(),
      progressPercent: (j['progress_percent'] as num?)?.toDouble(),
      hostProfile: j['host_profile'] == null
          ? null
          : HostProfile.fromJson(
          Map<String, dynamic>.from(j['host_profile'] as Map)),
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'email': email,
    'avatar_url': avatarUrl,
    'profile_frame': profileFrame?.toJson(),
    'provider': provider,
    'email_verified': emailVerified,
    'is_blocked': isBlocked,
    'roles': roles,
    'permissions': permissions,
    'can_go_live': canGoLive,
    'level': level,
    'level_title': levelTitle,
    'badge_icon': badgeIcon,
    'badge_color': badgeColor,
    'lifetime_spend_coins': lifetimeSpendCoins,
    'next_level': nextLevel,
    'next_level_title': nextLevelTitle,
    'next_level_required_spend': nextLevelRequiredSpend,
    'remaining_spend_to_next_level': remainingSpendToNextLevel,
    'progress_percent': progressPercent,
    'host_profile': hostProfile?.toJson(),
  };

  UserModel copyWith({
    int? id,
    String? name,
    String? email,
    String? avatarUrl,
    UserProfileFrameSummary? profileFrame,
    String? provider,
    bool? emailVerified,
    bool? isBlocked,
    List<String>? roles,
    List<String>? permissions,
    bool? canGoLive,
    int? level,
    String? levelTitle,
    String? badgeIcon,
    String? badgeColor,
    int? lifetimeSpendCoins,
    int? nextLevel,
    String? nextLevelTitle,
    int? nextLevelRequiredSpend,
    int? remainingSpendToNextLevel,
    double? progressPercent,
    HostProfile? hostProfile,
  }) {
    return UserModel(
      id: id ?? this.id,
      name: name ?? this.name,
      email: email ?? this.email,
      avatarUrl: avatarUrl ?? this.avatarUrl,
      profileFrame: profileFrame ?? this.profileFrame,
      provider: provider ?? this.provider,
      emailVerified: emailVerified ?? this.emailVerified,
      isBlocked: isBlocked ?? this.isBlocked,
      roles: roles ?? this.roles,
      permissions: permissions ?? this.permissions,
      canGoLive: canGoLive ?? this.canGoLive,
      level: level ?? this.level,
      levelTitle: levelTitle ?? this.levelTitle,
      badgeIcon: badgeIcon ?? this.badgeIcon,
      badgeColor: badgeColor ?? this.badgeColor,
      lifetimeSpendCoins: lifetimeSpendCoins ?? this.lifetimeSpendCoins,
      nextLevel: nextLevel ?? this.nextLevel,
      nextLevelTitle: nextLevelTitle ?? this.nextLevelTitle,
      nextLevelRequiredSpend: nextLevelRequiredSpend ?? this.nextLevelRequiredSpend,
      remainingSpendToNextLevel: remainingSpendToNextLevel ?? this.remainingSpendToNextLevel,
      progressPercent: progressPercent ?? this.progressPercent,
      hostProfile: hostProfile ?? this.hostProfile,
    );
  }
}

bool _asBool(dynamic value) {
  if (value is bool) return value;
  if (value is num) return value != 0;
  final normalized = value?.toString().trim().toLowerCase();
  return normalized == 'true' || normalized == '1' || normalized == 'yes';
}

class HostProfile {
  final String? stageName;
  final String? country;
  final String? city;
  final String? bio;
  final String? contactPhone;

  const HostProfile({
    this.stageName,
    this.country,
    this.city,
    this.bio,
    this.contactPhone,
  });

  factory HostProfile.fromJson(Map<String, dynamic> j) => HostProfile(
    stageName: j['stage_name'] as String?,
    country: j['country'] as String?,
    city: j['city'] as String?,
    bio: j['bio'] as String?,
    contactPhone: j['contact_phone'] as String?,
  );

  Map<String, dynamic> toJson() => {
    'stage_name': stageName,
    'country': country,
    'city': city,
    'bio': bio,
    'contact_phone': contactPhone,
  };
}

class UserProfileFrameSummary {
  final int id;
  final String name;
  final String slug;
  final String? assetUrl;
  final String? thumbnailUrl;
  final String rarity;
  final String category;
  final String unlockType;
  final bool owned;
  final bool canEquip;
  final bool isEquipped;
  final bool isExpired;

  const UserProfileFrameSummary({
    required this.id,
    required this.name,
    required this.slug,
    required this.rarity,
    required this.category,
    required this.unlockType,
    required this.owned,
    required this.canEquip,
    required this.isEquipped,
    required this.isExpired,
    this.assetUrl,
    this.thumbnailUrl,
  });

  factory UserProfileFrameSummary.fromJson(Map<String, dynamic> j) {
    return UserProfileFrameSummary(
      id: (j['id'] as num?)?.toInt() ?? 0,
      name: (j['name'] ?? '').toString(),
      slug: (j['slug'] ?? '').toString(),
      assetUrl: j['asset_url']?.toString(),
      thumbnailUrl: j['thumbnail_url']?.toString(),
      rarity: (j['rarity'] ?? 'rare').toString(),
      category: (j['category'] ?? 'general').toString(),
      unlockType: (j['unlock_type'] ?? 'free_catalog').toString(),
      owned: j['owned'] == true,
      canEquip: j['can_equip'] == true,
      isEquipped: j['is_equipped'] == true,
      isExpired: j['is_expired'] == true,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'slug': slug,
    'asset_url': assetUrl,
    'thumbnail_url': thumbnailUrl,
    'rarity': rarity,
    'category': category,
    'unlock_type': unlockType,
    'owned': owned,
    'can_equip': canEquip,
    'is_equipped': isEquipped,
    'is_expired': isExpired,
  };
}
