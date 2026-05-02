class LivePkBattleModel {
  const LivePkBattleModel({
    required this.battleId,
    required this.status,
    required this.durationSeconds,
    required this.scoreA,
    required this.scoreB,
    required this.startedAt,
    required this.endedAt,
    required this.endsAt,
    required this.winnerRoomId,
    required this.endReason,
    required this.roomA,
    required this.roomB,
    required this.hostA,
    required this.hostB,
    required this.updatedAt,
  });

  final String battleId;
  final String status;
  final int durationSeconds;
  final int scoreA;
  final int scoreB;
  final DateTime? startedAt;
  final DateTime? endedAt;
  final DateTime? endsAt;
  final String? winnerRoomId;
  final String? endReason;
  final Map<String, dynamic>? roomA;
  final Map<String, dynamic>? roomB;
  final Map<String, dynamic>? hostA;
  final Map<String, dynamic>? hostB;
  final DateTime? updatedAt;

  bool get isPending => status == 'pending';
  bool get isActive => status == 'active';
  bool get isTerminal => const {'completed', 'cancelled', 'rejected', 'expired', 'failed'}.contains(status);

  int get remainingSeconds {
    final end = endsAt;
    if (end == null) return durationSeconds;
    final diff = end.difference(DateTime.now()).inSeconds;
    return diff < 0 ? 0 : diff;
  }

  String? sideForRoom(String roomId) {
    if ((roomA?['id']?.toString() ?? '') == roomId) return 'a';
    if ((roomB?['id']?.toString() ?? '') == roomId) return 'b';
    return null;
  }

  Map<String, dynamic>? ownRoomFor(String roomId) {
    return sideForRoom(roomId) == 'a' ? roomA : roomB;
  }

  Map<String, dynamic>? opponentRoomFor(String roomId) {
    return sideForRoom(roomId) == 'a' ? roomB : roomA;
  }

  Map<String, dynamic>? ownHostFor(String roomId) {
    return sideForRoom(roomId) == 'a' ? hostA : hostB;
  }

  Map<String, dynamic>? opponentHostFor(String roomId) {
    return sideForRoom(roomId) == 'a' ? hostB : hostA;
  }

  int ownScoreFor(String roomId) {
    return sideForRoom(roomId) == 'a' ? scoreA : scoreB;
  }

  int opponentScoreFor(String roomId) {
    return sideForRoom(roomId) == 'a' ? scoreB : scoreA;
  }

  factory LivePkBattleModel.fromJson(Map<String, dynamic> json) {
    DateTime? parseDate(dynamic value) {
      final raw = value?.toString();
      if (raw == null || raw.isEmpty) return null;
      return DateTime.tryParse(raw)?.toLocal();
    }

    int parseInt(dynamic value) {
      if (value is int) return value;
      if (value is num) return value.toInt();
      return int.tryParse(value?.toString() ?? '') ?? 0;
    }

    Map<String, dynamic>? parseMap(dynamic value) {
      return value is Map ? Map<String, dynamic>.from(value) : null;
    }

    return LivePkBattleModel(
      battleId: (json['battle_id'] ?? '').toString(),
      status: (json['status'] ?? '').toString(),
      durationSeconds: parseInt(json['duration_seconds']),
      scoreA: parseInt(json['score_a']),
      scoreB: parseInt(json['score_b']),
      startedAt: parseDate(json['started_at']),
      endedAt: parseDate(json['ended_at']),
      endsAt: parseDate(json['ends_at']),
      winnerRoomId: json['winner_room_id']?.toString(),
      endReason: json['end_reason']?.toString(),
      roomA: parseMap(json['room_a']),
      roomB: parseMap(json['room_b']),
      hostA: parseMap(json['host_a']),
      hostB: parseMap(json['host_b']),
      updatedAt: parseDate(json['updated_at']),
    );
  }
}
