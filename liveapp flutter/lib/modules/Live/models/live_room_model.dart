class LiveRoomModel {
  final String roomId;
  final String? title;
  final String roomType;
  final String status; // live|scheduled|ended
  final DateTime? scheduledAt;
  final DateTime? startedAt;
  final DateTime? endedAt;
  final int peakViewers;
  final int maxSpeakers;
  final int maxParticipants;
  final int participantCount;
  final int listenerCount;
  final int speakerCount;
  final List<Map<String, dynamic>> speakers;
  final bool isLocked;
  final String? topic;
  final String? language;
  final Map<String, dynamic>? meta;

  // NEW: connection props (filled by create/start/join responses)
  final String? wsUrl;      // e.g. ws://69.62.78.140:7880
  final String? token;      // LiveKit JWT
  final String? identity;   // server-provided identity (optional)
  final String? role;       // viewer|host|moderator (optional)
  final int? participantId; // from join endpoint (optional)
  final Map<String, dynamic>? entryEffect;
  final Map<String, dynamic>? pkActive;

  LiveRoomModel({
    required this.roomId,
    required this.status,
    this.roomType = 'video',
    this.title,
    this.scheduledAt,
    this.startedAt,
    this.endedAt,
    this.peakViewers = 0,
    this.maxSpeakers = 4,
    this.maxParticipants = 50,
    this.participantCount = 0,
    this.listenerCount = 0,
    this.speakerCount = 0,
    this.speakers = const [],
    this.isLocked = false,
    this.topic,
    this.language,
    this.meta,
    this.wsUrl,
    this.token,
    this.identity,
    this.role,
    this.participantId,
    this.entryEffect,
    this.pkActive,
  });

  /// Accepts either:
  ///  A) { ok, room: {...}, ws_url, token, identity, role, participant_id }
  ///  B) { ...room fields..., ws_url, token }  (flattened)
  ///  C) { ok, data: {...} } (wrapped)
  ///  D) join payloads where `room` is a string room id
  factory LiveRoomModel.fromResponse(Map<dynamic, dynamic> j) {
    final root = Map<String, dynamic>.from(j);
    final data = root['data'] is Map
        ? Map<String, dynamic>.from(root['data'] as Map)
        : root;
    final dynamic rawRoom = data['room'];

    final Map<String, dynamic> room = rawRoom is Map
        ? Map<String, dynamic>.from(rawRoom)
        : Map<String, dynamic>.from(data);

    final roomId = (rawRoom is String && rawRoom.trim().isNotEmpty
            ? rawRoom
            : room['room_id'] ?? room['id'] ?? data['room_id'] ?? data['id'] ?? root['room_id'] ?? root['id'] ?? '')
        .toString()
        .trim();
    final status = (room['status'] ?? data['status'] ?? root['status'] ?? 'live').toString();
    final title = (room['title'] ?? data['title'] ?? root['title'])?.toString();

    int toInt(dynamic v) {
      if (v is int) return v;
      if (v is num) return v.toInt();
      return int.tryParse(v?.toString() ?? '') ?? 0;
    }

    return LiveRoomModel(
      roomId: roomId,
      title: title,
      roomType: (room['room_type'] ?? data['room_type'] ?? root['room_type'] ?? 'video').toString(),
      status: status,
      scheduledAt: room['scheduled_at'] != null ? DateTime.tryParse(room['scheduled_at']) : null,
      startedAt:   room['started_at']   != null ? DateTime.tryParse(room['started_at'])   : null,
      endedAt:     room['ended_at']     != null ? DateTime.tryParse(room['ended_at'])     : null,
      peakViewers: toInt(room['peak_viewers'] ?? j['peak_viewers']),
      maxSpeakers: toInt(room['max_speakers'] ?? data['max_speakers'] ?? root['max_speakers']) == 0
          ? 4
          : toInt(room['max_speakers'] ?? data['max_speakers'] ?? root['max_speakers']),
      maxParticipants: toInt(room['max_participants'] ?? data['max_participants'] ?? root['max_participants']) == 0
          ? 50
          : toInt(room['max_participants'] ?? data['max_participants'] ?? root['max_participants']),
      participantCount: toInt(data['participant_count'] ?? room['participant_count']),
      listenerCount: toInt(data['listener_count'] ?? room['listener_count']),
      speakerCount: toInt(data['speaker_count'] ?? room['speaker_count']),
      speakers: ((data['speakers'] ?? room['speakers']) as List? ?? const [])
          .whereType<Map>()
          .map((e) => Map<String, dynamic>.from(e))
          .toList(),
      isLocked: data['is_locked'] == true || room['is_locked'] == true,
      topic: (room['topic'] ?? data['topic'])?.toString(),
      language: (room['language'] ?? data['language'])?.toString(),
      meta: (room['meta'] as Map?)?.cast<String, dynamic>(),
      wsUrl: (data['ws_url'] ?? root['ws_url'] ?? room['ws_url'])?.toString(),
      token: (data['token'] ?? root['token'] ?? room['token'])?.toString(),
      identity: (data['identity'] ?? root['identity'] ?? room['identity'])?.toString(),
      role: (data['role'] ?? root['role'] ?? room['role'])?.toString(),
      participantId: toInt(data['participant_id'] ?? root['participant_id']) == 0
          ? null
          : toInt(data['participant_id'] ?? root['participant_id']),
      entryEffect: (data['entry_effect'] ?? root['entry_effect']) is Map
          ? Map<String, dynamic>.from((data['entry_effect'] ?? root['entry_effect']) as Map)
          : null,
      pkActive: (data['pk_active'] ?? root['pk_active']) is Map
          ? Map<String, dynamic>.from((data['pk_active'] ?? root['pk_active']) as Map)
          : null,
    );
  }
}
