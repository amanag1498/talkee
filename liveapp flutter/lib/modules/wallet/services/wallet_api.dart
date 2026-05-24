import '../../../services/api_client.dart';
import '../models/payment_order_dto.dart';
import '../models/wallet_summary_dto.dart';
import '../models/wallet_transaction_dto.dart';

class WalletApi {
  final ApiClient _api;
  WalletApi(this._api);

  Future<WalletSummaryDto> fetchSummary() async {
    final responses = await Future.wait([
      _api.get<Map<String, dynamic>>('wallet/summary'),
      _api.get<Map<String, dynamic>>('recharge/plans'),
    ]);

    final summaryBody = responses[0].data is Map<String, dynamic>
        ? responses[0].data as Map<String, dynamic>
        : Map<String, dynamic>.from(responses[0].data as Map? ?? const <String, dynamic>{});
    final plansBody = responses[1].data is Map<String, dynamic>
        ? responses[1].data as Map<String, dynamic>
        : Map<String, dynamic>.from(responses[1].data as Map? ?? const <String, dynamic>{});

    final summary = summaryBody['data'] is Map<String, dynamic>
        ? Map<String, dynamic>.from(summaryBody['data'] as Map<String, dynamic>)
        : Map<String, dynamic>.from(summaryBody['data'] as Map? ?? const <String, dynamic>{});

    summary['quick_packs'] = plansBody['data'] is List ? plansBody['data'] : const <dynamic>[];
    return WalletSummaryDto.fromJson(summary);
  }

  Future<PaymentOrderDto> createRechargeOrder(int planId) async {
    final response = await _api.post<Map<String, dynamic>>(
      'recharge/orders',
      data: {
        'plan_id': planId,
        'gateway': 'razorpay',
      },
    );

    final body = response.data is Map<String, dynamic>
        ? response.data as Map<String, dynamic>
        : Map<String, dynamic>.from(response.data as Map? ?? const <String, dynamic>{});
    final data = body['data'] is Map<String, dynamic>
        ? body['data'] as Map<String, dynamic>
        : Map<String, dynamic>.from(body['data'] as Map? ?? const <String, dynamic>{});
    return PaymentOrderDto.fromJson(data);
  }

  Future<WalletSummaryDto> verifyRechargeOrder(
    String orderId, {
    required String result,
    String? gatewayPaymentId,
    String? gatewayOrderId,
    String? gatewaySignature,
    Map<String, dynamic>? gatewayResponse,
  }) async {
    final payload = <String, dynamic>{
      'result': result,
    };
    if (gatewayPaymentId != null && gatewayPaymentId.isNotEmpty) {
      payload['gateway_payment_id'] = gatewayPaymentId;
    }
    if (gatewayOrderId != null && gatewayOrderId.isNotEmpty) {
      payload['gateway_order_id'] = gatewayOrderId;
    }
    if (gatewaySignature != null && gatewaySignature.isNotEmpty) {
      payload['gateway_signature'] = gatewaySignature;
    }
    if (gatewayResponse != null && gatewayResponse.isNotEmpty) {
      payload['gateway_response'] = gatewayResponse;
    }

    await _api.post<Map<String, dynamic>>(
      'recharge/orders/$orderId/verify',
      data: payload,
    );

    return fetchSummary();
  }

  Future<List<WalletTransactionDto>> fetchTransactions({String filter = 'all'}) async {
    final response = await _api.get<Map<String, dynamic>>(
      'wallet/transactions',
      query: {'filter': filter},
    );
    final body = response.data is Map<String, dynamic>
        ? response.data as Map<String, dynamic>
        : Map<String, dynamic>.from(response.data as Map? ?? const <String, dynamic>{});
    final data = body['data'] is Map<String, dynamic>
        ? body['data'] as Map<String, dynamic>
        : Map<String, dynamic>.from(body['data'] as Map? ?? const <String, dynamic>{});
    final items = data['transactions'] is List ? data['transactions'] as List : const <dynamic>[];

    return items
        .map((item) => WalletTransactionDto.fromJson(Map<String, dynamic>.from(item as Map)))
        .toList();
  }

  Future<List<PaymentOrderDto>> fetchRechargeOrders() async {
    final response = await _api.get<Map<String, dynamic>>('recharge/orders');
    final body = response.data is Map<String, dynamic>
        ? response.data as Map<String, dynamic>
        : Map<String, dynamic>.from(response.data as Map? ?? const <String, dynamic>{});
    final data = body['data'] is Map<String, dynamic>
        ? body['data'] as Map<String, dynamic>
        : Map<String, dynamic>.from(body['data'] as Map? ?? const <String, dynamic>{});
    final items = data['orders'] is List ? data['orders'] as List : const <dynamic>[];

    return items
        .map((item) => PaymentOrderDto.fromJson(Map<String, dynamic>.from(item as Map)))
        .toList();
  }
}
