import '../../../app/routes/app_urls.dart';

class LiveRoomModel {
  final String id;            // room_id
  final String title;
  final String roomType;
  final String status;        // 'live' | 'scheduled' | 'ended'
  final int? hostId;
  final int? hostProfileId;
  final String? hostName;     // 👈 new
  final int capacity;
  final int maxSpeakers;
  final int maxParticipants;
  final String? thumbnail;    // avatar_url from server
  final String? hostProfileFrameUrl;
  final int participantCount;
  final int viewerCount;
  final int listenerCount;
  final int audienceCount;
  final int speakerCount;
  final int pendingSeatRequestCount;
  final int peakViewers;
  final int followerCount;
  final String? topic;
  final String? language;
  final bool isLocked;
  final bool isFollowingHost;
  final bool hasReminder;
  final DateTime? scheduledAt;
  final DateTime? startedAt;
  final DateTime? updatedAt;

  const LiveRoomModel({
    required this.id,
    required this.title,
    this.roomType = 'video',
    required this.status,
    this.hostId,
    this.hostProfileId,
    this.hostName,
    this.capacity = 0,
    this.maxSpeakers = 4,
    this.maxParticipants = 50,
    this.thumbnail,
    this.hostProfileFrameUrl,
    this.participantCount = 0,
    this.viewerCount = 0,
    this.listenerCount = 0,
    this.audienceCount = 0,
    this.speakerCount = 0,
    this.pendingSeatRequestCount = 0,
    this.peakViewers = 0,
    this.followerCount = 0,
    this.topic,
    this.language,
    this.isLocked = false,
    this.isFollowingHost = false,
    this.hasReminder = false,
    this.scheduledAt,
    this.startedAt,
    this.updatedAt,
  });

  bool get isAudioRoom => roomType == 'audio';
  bool get isVideoRoom => !isAudioRoom;

  int get liveAudience {
    if (roomType == 'audio') {
      if (listenerCount > 0) return listenerCount;
      if (audienceCount > 0) return audienceCount;
    }
    if (viewerCount > 0) return viewerCount;
    if (audienceCount > 0) return audienceCount;
    if (participantCount > 0) return participantCount;
    return maxParticipants > 0 ? maxParticipants : capacity;
  }

  factory LiveRoomModel.fromJson(Map<String, dynamic> j) {
    final id = (j['room_id'] ?? j['id'] ?? '').toString().trim();
    final title = (j['title'] ?? '').toString();
    final roomType = _normalizeRoomType(
      j['room_type'] ?? j['type'] ?? j['media_type'],
    );
    final status = (j['status'] ?? 'scheduled').toString();

    final hostId = j['host_id'] == null ? null : int.tryParse(j['host_id'].toString());
    final hostProfileId = j['host_profile_id'] == null ? null : int.tryParse(j['host_profile_id'].toString());
    final hostName = j['host_name']?.toString();             // 👈 map new field
    final capacity = j['capacity'] == null ? 0 : (int.tryParse(j['capacity'].toString()) ?? 0);
    final maxSpeakers = j['max_speakers'] == null ? 4 : (int.tryParse(j['max_speakers'].toString()) ?? 4);
    final maxParticipants = j['max_participants'] == null ? 50 : (int.tryParse(j['max_participants'].toString()) ?? 50);
    final participantCount = j['participant_count'] == null
        ? 0
        : (int.tryParse(j['participant_count'].toString()) ?? 0);
    final viewerCount = j['viewer_count'] == null
        ? 0
        : (int.tryParse(j['viewer_count'].toString()) ?? 0);
    final listenerCount = j['listener_count'] == null
        ? 0
        : (int.tryParse(j['listener_count'].toString()) ?? 0);
    final audienceCount = j['audience_count'] == null
        ? 0
        : (int.tryParse(j['audience_count'].toString()) ?? 0);
    final speakerCount = j['speaker_count'] == null
        ? 0
        : (int.tryParse(j['speaker_count'].toString()) ?? 0);
    final pendingSeatRequestCount = j['pending_seat_request_count'] == null
        ? 0
        : (int.tryParse(j['pending_seat_request_count'].toString()) ?? 0);
    final peakViewers = j['peak_viewers'] == null
        ? 0
        : (int.tryParse(j['peak_viewers'].toString()) ?? 0);
    final followerCount = j['follower_count'] == null
        ? 0
        : (int.tryParse(j['follower_count'].toString()) ?? 0);
    final hostProfileFrameUrl =
        j['host_profile_frame'] is Map
            ? (Map<String, dynamic>.from(j['host_profile_frame'] as Map)['asset_url']?.toString())
            : null;

    final rawThumb = j['thumbnail']?.toString();
    final thumb = _normalizeThumb(rawThumb);
    final topic = j['topic']?.toString();
    final language = j['language']?.toString();
    final isLocked = j['is_locked'] == true || j['is_locked']?.toString() == '1';

    DateTime? started;
    final rawStarted = j['started_at'];
    if (rawStarted != null) started = DateTime.tryParse(rawStarted.toString());
    DateTime? scheduled;
    final rawScheduled = j['scheduled_at'];
    if (rawScheduled != null) scheduled = DateTime.tryParse(rawScheduled.toString());
    DateTime? updated;
    final rawUpdated = j['updated_at'];
    if (rawUpdated != null) updated = DateTime.tryParse(rawUpdated.toString());

    return LiveRoomModel(
      id: id,
      title: title,
      roomType: roomType,
      status: status,
      hostId: hostId,
      hostProfileId: hostProfileId,
      hostName: hostName,
      capacity: capacity,
      maxSpeakers: maxSpeakers,
      maxParticipants: maxParticipants,
      thumbnail: thumb,
      hostProfileFrameUrl: hostProfileFrameUrl,
      participantCount: participantCount,
      viewerCount: viewerCount,
      listenerCount: listenerCount,
      audienceCount: audienceCount,
      speakerCount: speakerCount,
      pendingSeatRequestCount: pendingSeatRequestCount,
      peakViewers: peakViewers,
      followerCount: followerCount,
      topic: topic,
      language: language,
      isLocked: isLocked,
      isFollowingHost: j['is_following_host'] == true,
      hasReminder: j['has_reminder'] == true,
      scheduledAt: scheduled,
      startedAt: started,
      updatedAt: updated,
    );
  }

  Map<String, dynamic> toJson() => {
    'room_id': id,
    'title': title,
    'room_type': roomType,
    'status': status,
    'host_id': hostId,
    'host_profile_id': hostProfileId,
    'host_name': hostName,
    'capacity': capacity,
    'max_speakers': maxSpeakers,
    'max_participants': maxParticipants,
    'thumbnail': thumbnail,
    'host_profile_frame_url': hostProfileFrameUrl,
    'participant_count': participantCount,
    'viewer_count': viewerCount,
    'listener_count': listenerCount,
    'audience_count': audienceCount,
    'speaker_count': speakerCount,
    'pending_seat_request_count': pendingSeatRequestCount,
    'peak_viewers': peakViewers,
    'follower_count': followerCount,
    'topic': topic,
    'language': language,
    'is_locked': isLocked,
    'is_following_host': isFollowingHost,
    'has_reminder': hasReminder,
    'scheduled_at': scheduledAt?.toIso8601String(),
    'started_at': startedAt?.toIso8601String(),
    'updated_at': updatedAt?.toIso8601String(),
  };

  static String? _normalizeThumb(String? v) {
    if (v == null || v.trim().isEmpty) return null;
    final t = v.trim();
    if (t.startsWith('http://') || t.startsWith('https://')) return t;
    if (t.startsWith('/')) {
      return '${AppUrls.apiOrigin}$t';
    }
    return '${AppUrls.apiOrigin}/$t';
  }

  static String _normalizeRoomType(dynamic value) {
    final normalized = value?.toString().trim().toLowerCase() ?? '';
    if (normalized == 'audio' || normalized == 'audio_room') return 'audio';
    if (normalized == 'video' || normalized == 'video_room') return 'video';
    return 'video';
  }
}
