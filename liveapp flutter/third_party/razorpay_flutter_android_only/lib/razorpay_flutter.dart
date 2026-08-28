import 'dart:async';

import 'package:eventify/eventify.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

class Razorpay {
  static const _CODE_PAYMENT_SUCCESS = 0;
  static const _CODE_PAYMENT_ERROR = 1;
  static const _CODE_PAYMENT_EXTERNAL_WALLET = 2;

  static const EVENT_PAYMENT_SUCCESS = 'payment.success';
  static const EVENT_PAYMENT_ERROR = 'payment.error';
  static const EVENT_EXTERNAL_WALLET = 'payment.external_wallet';

  static const NETWORK_ERROR = 0;
  static const INVALID_OPTIONS = 1;
  static const PAYMENT_CANCELLED = 2;
  static const TLS_ERROR = 3;
  static const INCOMPATIBLE_PLUGIN = 4;
  static const UNKNOWN_ERROR = 100;

  static const MethodChannel _channel = MethodChannel('razorpay_flutter');
  static const EventChannel _merchantEventChannel =
      EventChannel('razorpay_flutter/merchant_events');

  late EventEmitter _eventEmitter;
  List<String>? _subscribedAnalyticsEvents;
  void Function(String payloadJson)? _onMerchantEvent;
  StreamSubscription<dynamic>? _merchantEventSubscription;

  Razorpay() {
    _eventEmitter = EventEmitter();
  }

  void subscribeToAnalyticsEvents(
    List<String> events,
    void Function(String payloadJson) onEvent,
  ) {
    _subscribedAnalyticsEvents = List.from(events);
    _onMerchantEvent = onEvent;
    _channel.invokeMethod(
      'subscribeToAnalyticsEvents',
      <String, dynamic>{'events': events},
    );
    _merchantEventSubscription?.cancel();
    _merchantEventSubscription =
        _merchantEventChannel.receiveBroadcastStream().listen(
      (dynamic payload) {
        if (_onMerchantEvent != null && payload is String) {
          _onMerchantEvent!(payload);
        }
      },
      onError: (dynamic error) {
        debugPrint('[RazorpayFlutter] merchant event stream error: $error');
      },
    );
  }

  void open(Map<String, dynamic> options) async {
    final validationResult = _validateOptions(options);
    if (!validationResult['success']) {
      _handleResult({
        'type': _CODE_PAYMENT_ERROR,
        'data': {
          'code': INVALID_OPTIONS,
          'message': validationResult['message'],
        },
      });
      return;
    }

    final response = await _channel.invokeMethod('open', options);
    _handleResult(response);
  }

  void _handleResult(Map<dynamic, dynamic> response) {
    late String eventName;
    final data = response['data'] as Map<dynamic, dynamic>?;
    dynamic payload;

    switch (response['type']) {
      case _CODE_PAYMENT_SUCCESS:
        eventName = EVENT_PAYMENT_SUCCESS;
        payload = PaymentSuccessResponse.fromMap(data!);
        break;
      case _CODE_PAYMENT_ERROR:
        eventName = EVENT_PAYMENT_ERROR;
        payload = PaymentFailureResponse.fromMap(data!);
        break;
      case _CODE_PAYMENT_EXTERNAL_WALLET:
        eventName = EVENT_EXTERNAL_WALLET;
        payload = ExternalWalletResponse.fromMap(data!);
        break;
      default:
        eventName = 'error';
        payload = PaymentFailureResponse(
          UNKNOWN_ERROR,
          'An unknown error occurred.',
          null,
        );
    }

    _eventEmitter.emit(eventName, null, payload);
  }

  void on(String event, Function handler) {
    final EventCallback callback = (event, context) {
      handler(event.eventData);
    };
    _eventEmitter.on(event, null, callback);
    _resync();
  }

  void clear() {
    _merchantEventSubscription?.cancel();
    _merchantEventSubscription = null;
    _onMerchantEvent = null;
    _subscribedAnalyticsEvents = null;
    _eventEmitter.clear();
  }

  void _resync() async {
    final response = await _channel.invokeMethod('resync');
    if (response != null) {
      _handleResult(response);
    }
  }

  static Map<String, dynamic> _validateOptions(Map<String, dynamic> options) {
    if (options['key'] == null) {
      return {
        'success': false,
        'message':
            'Key is required. Please check if key is present in options.',
      };
    }
    return {'success': true};
  }
}

class PaymentSuccessResponse {
  String? paymentId;
  String? orderId;
  String? signature;
  Map<dynamic, dynamic>? data;

  PaymentSuccessResponse(
    this.paymentId,
    this.orderId,
    this.signature,
    this.data,
  );

  static PaymentSuccessResponse fromMap(Map<dynamic, dynamic> map) {
    return PaymentSuccessResponse(
      map['razorpay_payment_id'] as String?,
      map['razorpay_order_id'] as String?,
      map['razorpay_signature'] as String?,
      map,
    );
  }
}

class PaymentFailureResponse {
  int? code;
  String? message;
  Map<dynamic, dynamic>? error;

  PaymentFailureResponse(this.code, this.message, this.error);

  static PaymentFailureResponse fromMap(Map<dynamic, dynamic> map) {
    final rawBody = map['responseBody'];
    return PaymentFailureResponse(
      map['code'] as int?,
      map['message'] as String?,
      rawBody is Map<dynamic, dynamic> ? rawBody : null,
    );
  }
}

class ExternalWalletResponse {
  String? walletName;

  ExternalWalletResponse(this.walletName);

  static ExternalWalletResponse fromMap(Map<dynamic, dynamic> map) {
    return ExternalWalletResponse(map['external_wallet'] as String?);
  }
}
