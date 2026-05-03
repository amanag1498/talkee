import 'api_client.dart';

class CallService {
  final ApiClient api;

  CallService(this.api);

  Future<Map<String, dynamic>> fetchLiveUsers({
    int page = 1,
    int perPage = 50,
  }) async {
    final res = await api.get<Map<String, dynamic>>(
      'live-users',
      query: {
        'page': page,
        'per_page': perPage,
      },
    );
    return {
      'data': Map<String, dynamic>.from(
        res.data?['data'] as Map? ?? <String, dynamic>{},
      ),
      'meta': Map<String, dynamic>.from(
        res.data?['meta'] as Map? ?? <String, dynamic>{},
      ),
    };
  }

  Future<Map<String, dynamic>> toggleHostStatus(String manualStatus) async {
    final res = await api.post<Map<String, dynamic>>(
      'host/status/toggle',
      data: {'manual_status': manualStatus},
    );
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? <String, dynamic>{});
  }

  Future<Map<String, dynamic>> fetchHostStatus() async {
    final res = await api.get<Map<String, dynamic>>('host/status');
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? <String, dynamic>{});
  }

  Future<Map<String, dynamic>> requestCall({
    required int receiverId,
    required String type,
  }) async {
    final res = await api.post<Map<String, dynamic>>(
      'calls/request',
      data: {'receiver_id': receiverId, 'type': type},
    );
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? <String, dynamic>{});
  }

  Future<Map<String, dynamic>> acceptCall(int callId) async {
    final res = await api.post<Map<String, dynamic>>('calls/$callId/accept');
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? <String, dynamic>{});
  }

  Future<Map<String, dynamic>> rejectCall(int callId) async {
    final res = await api.post<Map<String, dynamic>>('calls/$callId/reject');
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? <String, dynamic>{});
  }

  Future<Map<String, dynamic>> endCall(int callId, {String? reason}) async {
    final res = await api.post<Map<String, dynamic>>(
      'calls/$callId/end',
      data: {'reason': reason},
    );
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? <String, dynamic>{});
  }

  Future<Map<String, dynamic>> fetchCallToken(int callId) async {
    final res = await api.get<Map<String, dynamic>>('calls/$callId/token');
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? <String, dynamic>{});
  }

  Future<Map<String, dynamic>> fetchHistory({int page = 1}) async {
    final res = await api.get<Map<String, dynamic>>(
      'calls/history',
      query: {'page': page},
    );
    return Map<String, dynamic>.from(res.data?['data'] as Map? ?? <String, dynamic>{});
  }
}
