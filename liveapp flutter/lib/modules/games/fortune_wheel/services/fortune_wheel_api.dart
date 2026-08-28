import 'package:dio/dio.dart';

import '../../../../services/api_client.dart';
import '../models/fortune_wheel_models.dart';

class FortuneWheelApi {
  FortuneWheelApi(this._api);

  final ApiClient _api;

  Future<FortuneWheelSnapshot> fetchSnapshot() async {
    try {
      final res = await _api.get<Map<String, dynamic>>('games/fortune-wheel');
      final body = Map<String, dynamic>.from(res.data ?? const {});
      if (body['ok'] != true) {
        throw Exception(body['message'] ?? 'Failed to load Fortune Wheel.');
      }
      return FortuneWheelSnapshot.fromJson(
        Map<String, dynamic>.from(body['data'] as Map? ?? const {}),
      );
    } on DioException catch (e) {
      throw Exception(_messageFromDio(e, 'Failed to load Fortune Wheel.'));
    }
  }

  Future<FortuneWheelSpinResult> spin({required String idempotencyKey}) async {
    try {
      final res = await _api.post<Map<String, dynamic>>(
        'games/fortune-wheel/spin',
        data: {'idempotency_key': idempotencyKey},
      );
      final body = Map<String, dynamic>.from(res.data ?? const {});
      if (body['ok'] != true) {
        throw Exception(body['message'] ?? 'Unable to spin right now.');
      }
      return FortuneWheelSpinResult.fromJson(
        Map<String, dynamic>.from(body['data'] as Map? ?? const {}),
      );
    } on DioException catch (e) {
      throw Exception(_messageFromDio(e, 'Unable to spin right now.'));
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

class FortuneWheelSpinResult {
  const FortuneWheelSpinResult({
    required this.spin,
    required this.freeSpinsRemaining,
    required this.walletBalance,
    required this.segments,
  });

  final FortuneWheelSpin spin;
  final int freeSpinsRemaining;
  final int walletBalance;
  final List<FortuneWheelSegment> segments;

  factory FortuneWheelSpinResult.fromJson(Map<String, dynamic> json) {
    return FortuneWheelSpinResult(
      spin: FortuneWheelSpin.fromJson(
        Map<String, dynamic>.from(json['spin'] as Map? ?? const {}),
      ),
      freeSpinsRemaining: _toInt(json['free_spins_remaining'], 0),
      walletBalance: _toInt(json['wallet_balance'], 0),
      segments:
          (json['segments'] as List? ?? const [])
              .whereType<Map>()
              .map(
                (row) => FortuneWheelSegment.fromJson(
                  Map<String, dynamic>.from(row),
                ),
              )
              .toList(),
    );
  }
}

int _toInt(dynamic value, int fallback) {
  if (value is int) return value;
  if (value is double) return value.round();
  return int.tryParse(value?.toString() ?? '') ??
      double.tryParse(value?.toString() ?? '')?.round() ??
      fallback;
}
