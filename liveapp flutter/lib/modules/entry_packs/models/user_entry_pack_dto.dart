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

  bool get isExpired => isExpiredAt(DateTime.now());

  bool isExpiredAt(DateTime now) =>
      expiresAt != null && expiresAt!.isBefore(now);
}

UserEntryPackDto? preferredOwnedEntryPack(
  Iterable<UserEntryPackDto> ownerships,
  int packId, {
  DateTime? now,
}) {
  final referenceTime = now ?? DateTime.now();
  final matches = ownerships.where((owned) => owned.entryPackId == packId).toList()
    ..sort((a, b) {
      final aExpired = a.isExpiredAt(referenceTime);
      final bExpired = b.isExpiredAt(referenceTime);
      if (aExpired != bExpired) return aExpired ? 1 : -1;

      if (a.expiresAt == null && b.expiresAt != null) return -1;
      if (a.expiresAt != null && b.expiresAt == null) return 1;

      final expiryOrder = (b.expiresAt ?? DateTime(9999)).compareTo(
        a.expiresAt ?? DateTime(9999),
      );
      if (expiryOrder != 0) return expiryOrder;

      final aPurchased =
          a.purchasedAt ?? DateTime.fromMillisecondsSinceEpoch(0);
      final bPurchased =
          b.purchasedAt ?? DateTime.fromMillisecondsSinceEpoch(0);
      return bPurchased.compareTo(aPurchased);
    });

  return matches.isEmpty ? null : matches.first;
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
