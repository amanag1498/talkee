class WalletSummaryDto {
  final int balance;
  final bool paymentReady;
  final String? message;
  final List<WalletPackDto> quickPacks;

  const WalletSummaryDto({
    required this.balance,
    required this.paymentReady,
    required this.quickPacks,
    this.message,
  });

  factory WalletSummaryDto.fromJson(Map<String, dynamic> json) {
    return WalletSummaryDto(
      balance: _asNum(json['balance'])?.toInt() ?? 0,
      paymentReady: json['payment_ready'] == true,
      message: json['message']?.toString(),
      quickPacks:
          (json['quick_packs'] as List?)
              ?.map(
                (e) =>
                    WalletPackDto.fromJson(Map<String, dynamic>.from(e as Map)),
              )
              .toList() ??
          const <WalletPackDto>[],
    );
  }

  WalletSummaryDto copyWith({
    int? balance,
    bool? paymentReady,
    String? message,
    List<WalletPackDto>? quickPacks,
  }) {
    return WalletSummaryDto(
      balance: balance ?? this.balance,
      paymentReady: paymentReady ?? this.paymentReady,
      message: message ?? this.message,
      quickPacks: quickPacks ?? this.quickPacks,
    );
  }
}

class WalletPackDto {
  final int id;
  final String title;
  final int baseCoins;
  final int bonusCoins;
  final int totalCoins;
  final int coins;
  final num? price;
  final String? appleProductId;
  final String? localizedStorePrice;
  final int sortOrder;

  const WalletPackDto({
    required this.id,
    required this.title,
    required this.baseCoins,
    required this.bonusCoins,
    required this.totalCoins,
    required this.coins,
    this.price,
    this.appleProductId,
    this.localizedStorePrice,
    required this.sortOrder,
  });

  factory WalletPackDto.fromJson(Map<String, dynamic> json) {
    final totalCoins =
        _asNum(json['total_coins'])?.toInt() ??
        _asNum(json['coins'])?.toInt() ??
        0;
    return WalletPackDto(
      id: _asNum(json['id'])?.toInt() ?? 0,
      title: (json['title'] ?? '').toString(),
      baseCoins: _asNum(json['coins'])?.toInt() ?? 0,
      bonusCoins: _asNum(json['bonus_coins'])?.toInt() ?? 0,
      totalCoins: totalCoins,
      coins: totalCoins,
      price: _asNum(json['amount_rupees']) ?? _asNum(json['price']),
      appleProductId: json['apple_product_id']?.toString(),
      sortOrder: _asNum(json['sort_order'])?.toInt() ?? 0,
    );
  }

  WalletPackDto copyWith({String? localizedStorePrice}) {
    return WalletPackDto(
      id: id,
      title: title,
      baseCoins: baseCoins,
      bonusCoins: bonusCoins,
      totalCoins: totalCoins,
      coins: coins,
      price: price,
      appleProductId: appleProductId,
      localizedStorePrice: localizedStorePrice ?? this.localizedStorePrice,
      sortOrder: sortOrder,
    );
  }
}

num? _asNum(dynamic value) {
  if (value is num) return value;
  if (value is String) return num.tryParse(value);
  return null;
}
