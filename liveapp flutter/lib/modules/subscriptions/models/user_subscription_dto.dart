// lib/modules/subscriptions/models/user_subscription_dto.dart
class UserSubscriptionDto {
  final int id;
  final String status; // 'active' | 'expired' | 'cancelled' | etc
  final DateTime? startsAt;
  final DateTime? endsAt;
  final DateTime? lastPurchasedAt;
  final int planId;
  final String? planName;

  // Prefer server-computed flag if provided (via model accessor on backend).
  final bool? _isActiveNowFromApi;

  UserSubscriptionDto({
    required this.id,
    required this.status,
    required this.planId,
    this.startsAt,
    this.endsAt,
    this.lastPurchasedAt,
    this.planName,
    bool? isActiveNowFromApi,
  }) : _isActiveNowFromApi = isActiveNowFromApi;

  // ---------- parsing helpers ----------
  static int _toInt(dynamic v) {
    if (v is int) return v;
    return int.tryParse(v?.toString() ?? '') ?? 0;
  }

  static DateTime? _toDate(dynamic v) {
    if (v == null) return null;
    try {
      // Accepts "2025-01-01T12:00:00+00:00" etc.
      return DateTime.parse(v.toString());
    } catch (_) {
      return null;
    }
  }

  static bool? _toBoolOrNull(dynamic v) {
    if (v == null) return null;
    if (v is bool) return v;
    final s = v.toString().toLowerCase().trim();
    if (s == '1' || s == 'true' || s == 'yes') return true;
    if (s == '0' || s == 'false' || s == 'no') return false;
    return null;
  }

  factory UserSubscriptionDto.fromJson(Map<String, dynamic> j) {
    final plan = (j['plan'] is Map) ? Map<String, dynamic>.from(j['plan']) : null;

    return UserSubscriptionDto(
      id: _toInt(j['id']),
      status: (j['status'] ?? 'inactive').toString(),
      planId: _toInt(j['subscription_plan_id'] ?? j['plan_id']),
      startsAt: _toDate(j['starts_at']),
      endsAt: _toDate(j['ends_at']),
      lastPurchasedAt: _toDate(j['last_purchased_at']),
      planName: (plan?['name'] ?? j['plan_name'])?.toString(),
      isActiveNowFromApi: _toBoolOrNull(j['is_active_now']),
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'status': status,
    'subscription_plan_id': planId,
    'starts_at': startsAt?.toIso8601String(),
    'ends_at': endsAt?.toIso8601String(),
    'last_purchased_at': lastPurchasedAt?.toIso8601String(),
    'plan_name': planName,
    // You typically don’t send this back, but harmless if present:
    'is_active_now': _isActiveNowFromApi ?? isActiveNow,
  };

  /// Handy placeholder when the API doesn't return a subscription object.
  factory UserSubscriptionDto.empty() => UserSubscriptionDto(
    id: 0,
    status: 'inactive',
    planId: 0,
    startsAt: null,
    endsAt: null,
    lastPurchasedAt: null,
    planName: null,
    isActiveNowFromApi: false,
  );

  // ---------- computed flags (UTC safe) ----------
  /// Prefer the server’s `is_active_now` if provided; otherwise compute.
  bool get isActiveNow {
    if (_isActiveNowFromApi != null) return _isActiveNowFromApi!;
    final now = DateTime.now().toUtc();
    final startOk = (startsAt == null) || !now.isBefore(startsAt!.toUtc());
    final endOk = (endsAt != null) && now.isBefore(endsAt!.toUtc());
    return status.toLowerCase() == 'active' && startOk && endOk;
  }

  bool get isExpired {
    final now = DateTime.now().toUtc();
    if (endsAt == null) return false;
    return now.isAfter(endsAt!.toUtc());
  }

  bool get isUpcoming {
    final now = DateTime.now().toUtc();
    if (startsAt == null) return false;
    return now.isBefore(startsAt!.toUtc());
  }

  Duration? get remaining {
    if (endsAt == null) return null;
    final diff = endsAt!.toUtc().difference(DateTime.now().toUtc());
    return diff.isNegative ? Duration.zero : diff;
  }

  // Optional ergonomics
  UserSubscriptionDto copyWith({
    int? id,
    String? status,
    DateTime? startsAt,
    DateTime? endsAt,
    DateTime? lastPurchasedAt,
    int? planId,
    String? planName,
    bool? isActiveNowFromApi,
  }) {
    return UserSubscriptionDto(
      id: id ?? this.id,
      status: status ?? this.status,
      planId: planId ?? this.planId,
      startsAt: startsAt ?? this.startsAt,
      endsAt: endsAt ?? this.endsAt,
      lastPurchasedAt: lastPurchasedAt ?? this.lastPurchasedAt,
      planName: planName ?? this.planName,
      isActiveNowFromApi: isActiveNowFromApi ?? _isActiveNowFromApi,
    );
  }
}
