class PaymentOrderDto {
  final int id;
  final String orderId;
  final int totalCoins;
  final int bonusCoins;
  final num amountRupees;
  final String status;
  final String gateway;
  final String? rechargePlanTitle;
  final DateTime? createdAt;

  const PaymentOrderDto({
    required this.id,
    required this.orderId,
    required this.totalCoins,
    required this.bonusCoins,
    required this.amountRupees,
    required this.status,
    required this.gateway,
    this.rechargePlanTitle,
    this.createdAt,
  });

  factory PaymentOrderDto.fromJson(Map<String, dynamic> json) {
    final rechargePlan = json['recharge_plan'] is Map
        ? Map<String, dynamic>.from(json['recharge_plan'] as Map)
        : const <String, dynamic>{};

    return PaymentOrderDto(
      id: _asNum(json['id'])?.toInt() ?? 0,
      orderId: (json['order_id'] ?? '').toString(),
      totalCoins: _asNum(json['total_coins'])?.toInt() ?? 0,
      bonusCoins: _asNum(json['bonus_coins'])?.toInt() ?? 0,
      amountRupees: _asNum(json['amount_rupees']) ?? 0,
      status: (json['status'] ?? 'created').toString(),
      gateway: (json['gateway'] ?? 'mock').toString(),
      rechargePlanTitle: rechargePlan['title']?.toString(),
      createdAt: DateTime.tryParse((json['created_at'] ?? '').toString()),
    );
  }

  static num? _asNum(dynamic value) {
    if (value is num) return value;
    if (value is String) return num.tryParse(value);
    return null;
  }
}
