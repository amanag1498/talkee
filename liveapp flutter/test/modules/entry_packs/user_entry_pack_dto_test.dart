import 'package:flutter_test/flutter_test.dart';
import 'package:liveapp/modules/entry_packs/models/user_entry_pack_dto.dart';

void main() {
  final now = DateTime.parse('2026-08-29T12:00:00Z');

  UserEntryPackDto ownership({
    required int id,
    required DateTime purchasedAt,
    required DateTime expiresAt,
  }) {
    return UserEntryPackDto(
      id: id,
      userId: 4,
      entryPackId: 2,
      isActive: false,
      purchasedAt: purchasedAt,
      expiresAt: expiresAt,
    );
  }

  test('prefers a valid grant over a newer expired ownership row', () {
    final valid = ownership(
      id: 51,
      purchasedAt: DateTime.parse('2026-08-29T10:00:00Z'),
      expiresAt: DateTime.parse('2026-08-30T10:00:00Z'),
    );
    final newerExpired = ownership(
      id: 52,
      purchasedAt: DateTime.parse('2026-08-29T11:00:00Z'),
      expiresAt: DateTime.parse('2026-08-29T11:30:00Z'),
    );

    expect(
      preferredOwnedEntryPack([valid, newerExpired], 2, now: now)?.id,
      valid.id,
    );
  });

  test('prefers the longest valid expiry for duplicate pack rows', () {
    final shorter = ownership(
      id: 53,
      purchasedAt: DateTime.parse('2026-08-29T11:00:00Z'),
      expiresAt: DateTime.parse('2026-08-30T11:00:00Z'),
    );
    final longer = ownership(
      id: 44,
      purchasedAt: DateTime.parse('2026-08-29T09:00:00Z'),
      expiresAt: DateTime.parse('2026-09-01T09:00:00Z'),
    );

    expect(
      preferredOwnedEntryPack([shorter, longer], 2, now: now)?.id,
      longer.id,
    );
  });
}
