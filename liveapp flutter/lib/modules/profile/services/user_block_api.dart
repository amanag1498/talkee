import 'package:dio/dio.dart';

import '../../../services/api_client.dart';

class UserBlockApi {
  UserBlockApi(this._api);

  final ApiClient _api;

  Future<List<Map<String, dynamic>>> fetchBlockedUsers() async {
    try {
      final response = await _api.get<Map<String, dynamic>>('me/blocked-users');
      return ((response.data?['data'] as List?) ?? const <dynamic>[])
          .map((row) => Map<String, dynamic>.from(row as Map))
          .toList();
    } on DioException catch (error) {
      throw Exception(_message(error, 'Unable to load blocked users.'));
    }
  }

  Future<Map<String, dynamic>> block(int userId) async {
    try {
      final response = await _api.post<Map<String, dynamic>>(
        'me/blocked-users/$userId',
      );
      return Map<String, dynamic>.from(
        response.data?['data'] as Map? ?? const <String, dynamic>{},
      );
    } on DioException catch (error) {
      throw Exception(_message(error, 'Unable to block this user.'));
    }
  }

  Future<void> unblock(int userId) async {
    try {
      await _api.delete<Map<String, dynamic>>('me/blocked-users/$userId');
    } on DioException catch (error) {
      throw Exception(_message(error, 'Unable to unblock this user.'));
    }
  }

  String _message(DioException error, String fallback) {
    final data = error.response?.data;
    if (data is Map) {
      final message = data['msg'] ?? data['message'];
      if (message?.toString().trim().isNotEmpty == true) {
        return message.toString().trim();
      }
    }
    return error.message?.trim().isNotEmpty == true
        ? error.message!.trim()
        : fallback;
  }
}
