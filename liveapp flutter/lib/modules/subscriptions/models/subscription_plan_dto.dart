import 'dart:convert';

class SubscriptionPlanDto {
  final int id;
  final String name;
  final int priceCoins;
  final int durationDays;
  final List<String> perks;   // <- typed!
  final bool isActive;

  const SubscriptionPlanDto({
    required this.id,
    required this.name,
    required this.priceCoins,
    required this.durationDays,
    required this.perks,
    required this.isActive,
  });

  factory SubscriptionPlanDto.fromJson(Map<String, dynamic> j) {
    // perks may already be a List or a JSON string (depending on backend)
    List<String> parsedPerks = const [];
    final rawPerks = j['perks'];

    if (rawPerks is List) {
      parsedPerks = rawPerks.map((e) => e.toString()).toList();
    } else if (rawPerks is String && rawPerks.trim().isNotEmpty) {
      try {
        final decoded = jsonDecode(rawPerks);
        if (decoded is List) {
          parsedPerks = decoded.map((e) => e.toString()).toList();
        }
      } catch (_) {/* ignore */}
    }

    return SubscriptionPlanDto(
      id: int.parse(j['id'].toString()),
      name: (j['name'] ?? '').toString(),
      priceCoins: int.tryParse(j['price_coins'].toString()) ?? 0,
      durationDays: int.tryParse(j['duration_days'].toString()) ?? 0,
      perks: parsedPerks,
      // your /plans endpoint already filters active=true, but keep this robust:
      isActive: (j['is_active'] is bool)
          ? (j['is_active'] as bool)
          : (j['is_active']?.toString() == '1' || j['is_active']?.toString() == 'true'),
    );
  }
}
