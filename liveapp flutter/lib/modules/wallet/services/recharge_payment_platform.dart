enum RechargePaymentProvider { appleInAppPurchase, razorpay }

RechargePaymentProvider rechargePaymentProviderFor(String platform) {
  return platform.toLowerCase().trim() == 'ios'
      ? RechargePaymentProvider.appleInAppPurchase
      : RechargePaymentProvider.razorpay;
}
