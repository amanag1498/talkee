class PaymentOrderDto {
  final int id;
  final String orderId;
  final int totalCoins;
  final int bonusCoins;
  final num amountRupees;
  final String status;
  final String gateway;
  final String? gatewayOrderId;
  final PaymentCheckoutDto? checkout;
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
    this.gatewayOrderId,
    this.checkout,
    this.rechargePlanTitle,
    this.createdAt,
  });

  factory PaymentOrderDto.fromJson(Map<String, dynamic> json) {
    final rechargePlan = json['recharge_plan'] is Map
        ? Map<String, dynamic>.from(json['recharge_plan'] as Map)
        : const <String, dynamic>{};
    final checkout =
        json['checkout'] is Map
            ? Map<String, dynamic>.from(json['checkout'] as Map)
            : const <String, dynamic>{};

    return PaymentOrderDto(
      id: _asNum(json['id'])?.toInt() ?? 0,
      orderId: (json['order_id'] ?? '').toString(),
      totalCoins: _asNum(json['total_coins'])?.toInt() ?? 0,
      bonusCoins: _asNum(json['bonus_coins'])?.toInt() ?? 0,
      amountRupees: _asNum(json['amount_rupees']) ?? 0,
      status: (json['status'] ?? 'created').toString(),
      gateway: (json['gateway'] ?? 'mock').toString(),
      gatewayOrderId: json['gateway_order_id']?.toString(),
      checkout:
          checkout.isEmpty ? null : PaymentCheckoutDto.fromJson(checkout),
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

class PaymentCheckoutDto {
  final String gateway;
  final String key;
  final String gatewayOrderId;
  final int amount;
  final String currency;
  final String name;
  final String description;
  final Map<String, dynamic> prefill;

  const PaymentCheckoutDto({
    required this.gateway,
    required this.key,
    required this.gatewayOrderId,
    required this.amount,
    required this.currency,
    required this.name,
    required this.description,
    this.prefill = const <String, dynamic>{},
  });

  factory PaymentCheckoutDto.fromJson(Map<String, dynamic> json) {
    return PaymentCheckoutDto(
      gateway: (json['gateway'] ?? '').toString(),
      key: (json['key'] ?? '').toString(),
      gatewayOrderId: (json['order_id'] ?? '').toString(),
      amount: PaymentOrderDto._asNum(json['amount'])?.toInt() ?? 0,
      currency: (json['currency'] ?? 'INR').toString(),
      name: (json['name'] ?? 'Talkieo').toString(),
      description: (json['description'] ?? 'Wallet recharge').toString(),
      prefill:
          json['prefill'] is Map
              ? Map<String, dynamic>.from(json['prefill'] as Map)
              : const <String, dynamic>{},
    );
  }
}
