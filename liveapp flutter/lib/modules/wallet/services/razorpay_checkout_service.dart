import 'dart:async';

import 'package:razorpay_flutter/razorpay_flutter.dart';

import '../../../data/models/user_model.dart';
import '../models/payment_order_dto.dart';

enum RechargeCheckoutResultType {
  success,
  failed,
  cancelled,
}

class RechargeCheckoutResult {
  final RechargeCheckoutResultType type;
  final String? paymentId;
  final String? orderId;
  final String? signature;
  final int? code;
  final String? message;
  final Map<String, dynamic> raw;

  const RechargeCheckoutResult({
    required this.type,
    this.paymentId,
    this.orderId,
    this.signature,
    this.code,
    this.message,
    this.raw = const <String, dynamic>{},
  });
}

class RazorpayCheckoutService {
  RazorpayCheckoutService() : _razorpay = Razorpay();

  final Razorpay _razorpay;

  Completer<RechargeCheckoutResult>? _completer;

  Future<RechargeCheckoutResult> openCheckout({
    required PaymentOrderDto order,
    UserModel? user,
  }) async {
    final checkout = order.checkout;
    if (checkout == null) {
      throw Exception('Payment gateway checkout payload is missing.');
    }
    if (checkout.gateway != 'razorpay') {
      throw Exception('Unsupported payment gateway: ${checkout.gateway}');
    }

    await _completePendingIfNeeded();

    final completer = Completer<RechargeCheckoutResult>();
    _completer = completer;

    _razorpay.on(
      Razorpay.EVENT_PAYMENT_SUCCESS,
      _handlePaymentSuccess,
    );
    _razorpay.on(
      Razorpay.EVENT_PAYMENT_ERROR,
      _handlePaymentError,
    );
    _razorpay.on(
      Razorpay.EVENT_EXTERNAL_WALLET,
      _handleExternalWallet,
    );

    final prefill = <String, dynamic>{
      ...checkout.prefill,
    };
    final phone = user?.hostProfile?.contactPhone?.trim();
    if (phone != null && phone.isNotEmpty && prefill['contact'] == null) {
      prefill['contact'] = phone;
    }

    final method = <String, dynamic>{
      'card': true,
      'netbanking': true,
      'wallet': true,
      'upi': true,
      ...checkout.method,
    };

    final options = <String, dynamic>{
      'key': checkout.key,
      'amount': checkout.amount,
      'currency': checkout.currency,
      'name': checkout.name,
      'description': checkout.description,
      'order_id': checkout.gatewayOrderId,
      'method': method,
      'retry': {
        'enabled': true,
        'max_count': 1,
      },
      'send_sms_hash': true,
      'theme': {
        'color': '#5B7CFF',
      },
      'prefill': prefill,
      'notes': {
        'app_order_id': order.orderId,
        'user_id': user?.id.toString() ?? '',
      },
    };

    _razorpay.open(options);

    try {
      return await completer.future.timeout(
        const Duration(minutes: 5),
        onTimeout: () => const RechargeCheckoutResult(
          type: RechargeCheckoutResultType.cancelled,
          message: 'Payment session timed out.',
        ),
      );
    } finally {
      _clearListeners();
      if (identical(_completer, completer)) {
        _completer = null;
      }
    }
  }

  void dispose() {
    _completePendingIfNeeded();
    _razorpay.clear();
  }

  Future<void> _completePendingIfNeeded() async {
    final completer = _completer;
    if (completer != null && !completer.isCompleted) {
      completer.complete(
        const RechargeCheckoutResult(
          type: RechargeCheckoutResultType.cancelled,
          message: 'Payment flow interrupted.',
        ),
      );
    }
    _clearListeners();
    _completer = null;
  }

  void _clearListeners() {
    _razorpay.clear();
  }

  void _handlePaymentSuccess(PaymentSuccessResponse response) {
    final completer = _completer;
    if (completer == null || completer.isCompleted) return;
    completer.complete(
      RechargeCheckoutResult(
        type: RechargeCheckoutResultType.success,
        paymentId: response.paymentId,
        orderId: response.orderId,
        signature: response.signature,
        raw: <String, dynamic>{
          'payment_id': response.paymentId,
          'order_id': response.orderId,
          'signature': response.signature,
        },
      ),
    );
  }

  void _handlePaymentError(PaymentFailureResponse response) {
    final completer = _completer;
    if (completer == null || completer.isCompleted) return;

    final cancelled = response.code == Razorpay.PAYMENT_CANCELLED;
    completer.complete(
      RechargeCheckoutResult(
        type:
            cancelled
                ? RechargeCheckoutResultType.cancelled
                : RechargeCheckoutResultType.failed,
        code: response.code,
        message: response.message,
        raw: <String, dynamic>{
          'code': response.code,
          'message': response.message,
          'error': response.error,
        },
      ),
    );
  }

  void _handleExternalWallet(ExternalWalletResponse response) {
    final completer = _completer;
    if (completer == null || completer.isCompleted) return;
    completer.complete(
      RechargeCheckoutResult(
        type: RechargeCheckoutResultType.failed,
        message: 'External wallet is not supported for this recharge.',
        raw: <String, dynamic>{
          'external_wallet': response.walletName,
        },
      ),
    );
  }
}
