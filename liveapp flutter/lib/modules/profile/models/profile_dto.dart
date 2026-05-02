class ProfileDto {
  final int id;
  final String name;
  final String? displayName;
  final String email;
  final String? avatarUrl;
  final String? bio;
  final String? city;
  final String? location;
  final List<String> roles;
  final bool isVip;
  final String? activeThemeKey;
  final bool canGoLive;
  final int walletBalance;
  final int? level;
  final String? levelTitle;
  final String? badgeIcon;
  final String? badgeColor;
  final double? progressPercent;
  final int? nextLevel;
  final String? nextLevelTitle;
  final int? nextLevelRequiredSpend;
  final int? remainingSpendToNextLevel;
  final int? followersCount;
  final int? followingCount;
  final int? hostId;
  final bool isFollowing;
  final bool notifyWhenOnline;
  final bool notifyWhenAvailable;
  final int? lifetimeSpendCoins;
  final DateTime? joinedAt;
  final ProfileHostDto? hostProfile;
  final ProfileStatusDto status;

  const ProfileDto({
    required this.id,
    required this.name,
    required this.email,
    required this.roles,
    required this.isVip,
    required this.canGoLive,
    required this.walletBalance,
    required this.status,
    this.displayName,
    this.activeThemeKey,
    this.avatarUrl,
    this.bio,
    this.city,
    this.location,
    this.level,
    this.levelTitle,
    this.badgeIcon,
    this.badgeColor,
    this.progressPercent,
    this.nextLevel,
    this.nextLevelTitle,
    this.nextLevelRequiredSpend,
    this.remainingSpendToNextLevel,
    this.followersCount,
    this.followingCount,
    this.hostId,
    this.isFollowing = false,
    this.notifyWhenOnline = true,
    this.notifyWhenAvailable = true,
    this.lifetimeSpendCoins,
    this.joinedAt,
    this.hostProfile,
  });

  bool get isHost => status.isHost || roles.contains('host');
  bool get isAgency => status.isAgency || roles.contains('agency');
  bool get isAdmin => status.isAdmin || roles.contains('admin');
  bool get isNormalUser => !isHost && !isAgency && !isAdmin;

  factory ProfileDto.fromJson(Map<String, dynamic> json) {
    return ProfileDto(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: (json['name'] ?? '').toString(),
      displayName: json['display_name']?.toString(),
      email: (json['email'] ?? '').toString(),
      avatarUrl: json['avatar_url']?.toString(),
      bio: json['bio']?.toString(),
      city: json['city']?.toString(),
      location: json['location']?.toString(),
      roles: (json['roles'] as List?)?.map((e) => e.toString()).toList() ?? const <String>[],
      isVip: json['is_vip'] == true,
      activeThemeKey: json['active_theme_key']?.toString(),
      canGoLive: json['can_go_live'] == true,
      walletBalance: (json['wallet_balance'] as num?)?.toInt() ?? 0,
      level: (json['level'] as num?)?.toInt(),
      levelTitle: json['level_title']?.toString(),
      badgeIcon: json['badge_icon']?.toString(),
      badgeColor: json['badge_color']?.toString(),
      progressPercent: (json['progress_percent'] as num?)?.toDouble(),
      nextLevel: (json['next_level'] as num?)?.toInt(),
      nextLevelTitle: json['next_level_title']?.toString(),
      nextLevelRequiredSpend: (json['next_level_required_spend'] as num?)?.toInt(),
      remainingSpendToNextLevel: (json['remaining_spend_to_next_level'] as num?)?.toInt(),
      followersCount: ((json['followers_count'] ?? json['follower_count']) as num?)?.toInt(),
      followingCount: (json['following_count'] as num?)?.toInt(),
      hostId: (json['host_id'] as num?)?.toInt(),
      isFollowing: json['is_following'] == true,
      notifyWhenOnline: json['notify_when_online'] != false,
      notifyWhenAvailable: json['notify_when_available'] != false,
      lifetimeSpendCoins: (json['lifetime_spend_coins'] as num?)?.toInt(),
      joinedAt: DateTime.tryParse((json['joined_at'] ?? '').toString()),
      hostProfile: json['host_profile'] is Map<String, dynamic>
          ? ProfileHostDto.fromJson(json['host_profile'] as Map<String, dynamic>)
          : (json['host_profile'] is Map
              ? ProfileHostDto.fromJson(Map<String, dynamic>.from(json['host_profile'] as Map))
              : null),
      status: ProfileStatusDto.fromJson(
        json['status'] is Map<String, dynamic>
            ? json['status'] as Map<String, dynamic>
            : Map<String, dynamic>.from(json['status'] as Map? ?? const <String, dynamic>{}),
      ),
    );
  }
}

class ProfileHostDto {
  final String? stageName;
  final String? contactPhone;
  final String? country;
  final String? city;
  final String? bio;
  final int? agencyId;
  final bool isBlocked;
  final ProfileAgencyDto? agency;

  const ProfileHostDto({
    this.stageName,
    this.contactPhone,
    this.country,
    this.city,
    this.bio,
    this.agencyId,
    required this.isBlocked,
    this.agency,
  });

  factory ProfileHostDto.fromJson(Map<String, dynamic> json) {
    return ProfileHostDto(
      stageName: json['stage_name']?.toString(),
      contactPhone: json['contact_phone']?.toString(),
      country: json['country']?.toString(),
      city: json['city']?.toString(),
      bio: json['bio']?.toString(),
      agencyId: (json['agency_id'] as num?)?.toInt(),
      isBlocked: json['is_blocked'] == true,
      agency: json['agency'] is Map<String, dynamic>
          ? ProfileAgencyDto.fromJson(json['agency'] as Map<String, dynamic>)
          : (json['agency'] is Map
              ? ProfileAgencyDto.fromJson(Map<String, dynamic>.from(json['agency'] as Map))
              : null),
    );
  }
}

class ProfileAgencyDto {
  final int id;
  final String? name;
  final String? legalName;
  final String? contactEmail;
  final String? contactPhone;
  final int? ownerUserId;
  final String? ownerName;
  final bool isBlocked;

  const ProfileAgencyDto({
    required this.id,
    this.name,
    this.legalName,
    this.contactEmail,
    this.contactPhone,
    this.ownerUserId,
    this.ownerName,
    required this.isBlocked,
  });

  factory ProfileAgencyDto.fromJson(Map<String, dynamic> json) {
    return ProfileAgencyDto(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name']?.toString(),
      legalName: json['legal_name']?.toString(),
      contactEmail: json['contact_email']?.toString(),
      contactPhone: json['contact_phone']?.toString(),
      ownerUserId: (json['owner_user_id'] as num?)?.toInt(),
      ownerName: json['owner_name']?.toString(),
      isBlocked: json['is_blocked'] == true,
    );
  }
}

class ProfileStatusDto {
  final bool isHost;
  final bool isAgency;
  final bool isAdmin;
  final bool agencyAttached;
  final bool hostBlocked;

  const ProfileStatusDto({
    required this.isHost,
    required this.isAgency,
    required this.isAdmin,
    required this.agencyAttached,
    required this.hostBlocked,
  });

  factory ProfileStatusDto.fromJson(Map<String, dynamic> json) {
    return ProfileStatusDto(
      isHost: json['is_host'] == true,
      isAgency: json['is_agency'] == true,
      isAdmin: json['is_admin'] == true,
      agencyAttached: json['agency_attached'] == true,
      hostBlocked: json['host_blocked'] == true,
    );
  }
}
