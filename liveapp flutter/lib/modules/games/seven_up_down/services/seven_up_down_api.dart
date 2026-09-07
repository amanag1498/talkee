import 'package:dio/dio.dart';

import '../../../../services/api_client.dart';
import '../models/seven_up_down_models.dart';

class SevenUpDownApi {
  SevenUpDownApi(this._api);

  final ApiClient _api;

  Future<SevenUpDownSnapshot> fetchSnapshot() async {
    try {
      final response = await _api.get<Map<String, dynamic>>(
        'games/seven-up-down',
      );
      final body = Map<String, dynamic>.from(response.data ?? const {});
      if (body['ok'] != true)
        throw Exception(body['message'] ?? 'Unable to load Lucky 7.');
      return SevenUpDownSnapshot.fromJson(
        Map<String, dynamic>.from(body['data'] as Map? ?? const {}),
      );
    } on DioException catch (error) {
      final data = error.response?.data;
      throw Exception(
        data is Map && data['message'] != null
            ? data['message'].toString()
            : error.message ?? 'Unable to load Lucky 7.',
      );
    }
  }

  Future<SevenUpDownSnapshot> placeBet({
    required String pot,
    required int amount,
    required String idempotencyKey,
  }) async {
    try {
      await _api.post<Map<String, dynamic>>(
        'games/seven-up-down/bets',
        data: {'pot': pot, 'amount': amount, 'idempotency_key': idempotencyKey},
      );
      return fetchSnapshot();
    } on DioException catch (error) {
      final data = error.response?.data;
      throw Exception(
        data is Map && data['message'] != null
            ? data['message'].toString()
            : error.message ?? 'Unable to place bet.',
      );
    }
  }
}
