import 'package:flutter_test/flutter_test.dart';
import 'package:liveapp/modules/wallet/services/recharge_payment_platform.dart';

void main() {
  test('uses StoreKit only for iOS', () {
    expect(
      rechargePaymentProviderFor('ios'),
      RechargePaymentProvider.appleInAppPurchase,
    );
    expect(
      rechargePaymentProviderFor('android'),
      RechargePaymentProvider.razorpay,
    );
    expect(
      rechargePaymentProviderFor('web'),
      RechargePaymentProvider.razorpay,
    );
  });
}
