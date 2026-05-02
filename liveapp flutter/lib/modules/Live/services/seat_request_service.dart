import 'package:liveapp/services/api_client.dart';

class SeatRequestService {
  final ApiClient api;
  SeatRequestService(this.api);

  Future<Map<String, dynamic>> snapshot(String roomId, {String? status}) async {
    final res = await api.get<Map<String, dynamic>>(
      'live/rooms/$roomId/seat-requests',
      query: {
        if (status != null && status.isNotEmpty) 'status': status,
      },
    );
    return Map<String, dynamic>.from((res.data ?? const {})['data'] ?? const {});
  }

  Future<void> requestSeat(String roomId) async {
    await api.post('live/rooms/$roomId/seat-requests');
  }

  Future<void> cancelRequest(String roomId, int requestId) async {
    await api.post('live/rooms/$roomId/seat-requests/$requestId/cancel');
  }

  Future<void> acceptRequest(String roomId, int requestId) async {
    await api.post('live/rooms/$roomId/seat-requests/$requestId/accept');
  }

  Future<void> rejectRequest(String roomId, int requestId) async {
    await api.post('live/rooms/$roomId/seat-requests/$requestId/reject');
  }

  Future<void> removeSpeaker(String roomId, int userId) async {
    await api.post('live/rooms/$roomId/speakers/$userId/remove');
  }
}
