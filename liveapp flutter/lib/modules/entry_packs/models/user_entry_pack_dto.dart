import 'entry_pack_dto.dart';

class UserEntryPackDto {
  final int id;
  final int userId;
  final int entryPackId;
  final bool isActive;
  final DateTime? purchasedAt;
  final DateTime? expiresAt;
  final EntryPackDto? entryPack;

  const UserEntryPackDto({
    required this.id,
    required this.userId,
    required this.entryPackId,
    required this.isActive,
    this.purchasedAt,
    this.expiresAt,
    this.entryPack,
  });

  factory UserEntryPackDto.fromJson(Map<String, dynamic> json) {
    int toInt(dynamic value, int fallback) {
      if (value is int) return value;
      if (value is num) return value.toInt();
      return int.tryParse(value?.toString() ?? '') ?? fallback;
    }

    return UserEntryPackDto(
      id: toInt(json['id'], 0),
      userId: toInt(json['user_id'], 0),
      entryPackId: toInt(json['entry_pack_id'], 0),
      isActive: json['is_active'] == true,
      purchasedAt: DateTime.tryParse((json['purchased_at'] ?? '').toString()),
      expiresAt: DateTime.tryParse((json['expires_at'] ?? '').toString()),
      entryPack: json['entry_pack'] is Map
          ? EntryPackDto.fromJson(Map<String, dynamic>.from(json['entry_pack'] as Map))
          : null,
    );
  }

  bool get isExpired => expiresAt != null && expiresAt!.isBefore(DateTime.now());
}

class EntryPackStateDto {
  final UserEntryPackDto? active;
  final List<UserEntryPackDto> owned;

  const EntryPackStateDto({
    required this.active,
    required this.owned,
  });

  factory EntryPackStateDto.fromJson(Map<String, dynamic> json) {
    final ownedRaw = json['owned'] is List ? json['owned'] as List : const <dynamic>[];
    return EntryPackStateDto(
      active: json['active'] is Map
          ? UserEntryPackDto.fromJson(Map<String, dynamic>.from(json['active'] as Map))
          : null,
      owned: ownedRaw
          .whereType<Map>()
          .map((row) => UserEntryPackDto.fromJson(Map<String, dynamic>.from(row)))
          .toList(),
    );
  }
}
