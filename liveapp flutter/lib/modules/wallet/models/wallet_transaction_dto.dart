class WalletTransactionDto {
  final int id;
  final String type;
  final String category;
  final int coins;
  final num? amount;
  final String? currency;
  final String? description;
  final String? reference;
  final DateTime? createdAt;

  const WalletTransactionDto({
    required this.id,
    required this.type,
    required this.category,
    required this.coins,
    this.amount,
    this.currency,
    this.description,
    this.reference,
    this.createdAt,
  });

  factory WalletTransactionDto.fromJson(Map<String, dynamic> json) {
    return WalletTransactionDto(
      id: (json['id'] as num?)?.toInt() ?? 0,
      type: (json['type'] ?? '').toString(),
      category: (json['category'] ?? '').toString(),
      coins: (json['coins'] as num?)?.toInt() ?? 0,
      amount: _asNum(json['amount']),
      currency: json['currency']?.toString(),
      description: json['description']?.toString(),
      reference: json['reference']?.toString(),
      createdAt: DateTime.tryParse((json['created_at'] ?? '').toString()),
    );
  }

  static num? _asNum(dynamic value) {
    if (value is num) return value;
    if (value is String) return num.tryParse(value);
    return null;
  }
}
