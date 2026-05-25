import 'package:dio/dio.dart';

import '../../../../services/api_client.dart';
import '../models/greedy_models.dart';

class GreedyApi {
  GreedyApi(this._api);

  final ApiClient _api;

  Future<GreedySnapshot> fetchSnapshot() async {
    try {
      final res = await _api.get<Map<String, dynamic>>('games/greedy');
      final data = Map<String, dynamic>.from(res.data ?? const {});
      if (data['ok'] != true) {
        throw Exception(data['message'] ?? 'Failed to load Greedy.');
      }
      return GreedySnapshot.fromJson(
        Map<String, dynamic>.from(data['data'] as Map? ?? const {}),
      );
    } on DioException catch (e) {
      throw Exception(_messageFromDio(e, 'Failed to load Greedy.'));
    }
  }

  Future<GreedySnapshot> placeBet({
    required String pot,
    required int amount,
    required String idempotencyKey,
  }) async {
    try {
      final res = await _api.post<Map<String, dynamic>>(
        'games/greedy/bets',
        data: {
          'pot': pot,
          'amount': amount,
          'idempotency_key': idempotencyKey,
        },
      );
      final data = Map<String, dynamic>.from(res.data ?? const {});
      if (data['ok'] != true) {
        throw Exception(data['message'] ?? 'Failed to place bet.');
      }
      return fetchSnapshot();
    } on DioException catch (e) {
      throw Exception(_messageFromDio(e, 'Failed to place bet.'));
    }
  }

  String _messageFromDio(DioException error, String fallback) {
    final data = error.response?.data;
    if (data is Map && data['message'] != null) {
      return data['message'].toString();
    }
    return error.message?.replaceFirst('Exception: ', '') ?? fallback;
  }
}
