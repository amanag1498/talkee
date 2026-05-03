import '../../home/models/live_room_dto.dart' as home_dto;
import '../models/live_gift_item.dart';
import '../models/live_room_chat_message.dart';
import '../models/live_room_model.dart';

class LiveRoomDevFixtures {
  static const String mockFeedAudioId = 'mock-feed-audio-host-room';
  static const String mockFeedVideoId = 'mock-feed-video-host-room';
  static const String mockScheduledAudioId = 'mock-scheduled-audio-host-room';
  static const String mockScheduledVideoId = 'mock-scheduled-video-host-room';

  static List<home_dto.LiveRoomModel> mockFeedRooms() {
    final rooms = <home_dto.LiveRoomModel>[];
    rooms.addAll(_mockAudioFeedRooms());
    rooms.addAll(_mockVideoFeedRooms());
    return rooms;
  }

  static List<home_dto.LiveRoomModel> mockScheduledRooms() {
    final rooms = <home_dto.LiveRoomModel>[];
    rooms.addAll(_mockScheduledAudioRooms());
    rooms.addAll(_mockScheduledVideoRooms());
    return rooms;
  }

  static home_dto.LiveRoomModel mockFeedAudioRoom() {
    final now = DateTime.now();
    return home_dto.LiveRoomModel(
      id: mockFeedAudioId,
      title: 'Mock Audio Host Room',
      roomType: 'audio',
      status: 'live',
      hostId: 301,
      hostName: 'Host Aman',
      maxSpeakers: 8,
      maxParticipants: 120,
      participantCount: 1,
      listenerCount: 0,
      speakerCount: 1,
      audienceCount: 0,
      thumbnail: 'https://picsum.photos/seed/audiohostmain/420/620',
      topic: 'Gift animation test',
      language: 'English',
      startedAt: now.subtract(const Duration(minutes: 12)),
      updatedAt: now,
    );
  }

  static home_dto.LiveRoomModel mockFeedVideoRoom() {
    final now = DateTime.now();
    return home_dto.LiveRoomModel(
      id: mockFeedVideoId,
      title: 'Mock Video Host Room',
      roomType: 'video',
      status: 'live',
      hostId: 501,
      hostName: 'Host Aman',
      maxSpeakers: 4,
      maxParticipants: 200,
      participantCount: 1,
      viewerCount: 0,
      speakerCount: 1,
      audienceCount: 0,
      topic: 'Gift animation test',
      language: 'English',
      startedAt: now.subtract(const Duration(minutes: 18)),
      updatedAt: now,
    );
  }

  static home_dto.LiveRoomModel mockScheduledAudioRoom() {
    final now = DateTime.now();
    return home_dto.LiveRoomModel(
      id: mockScheduledAudioId,
      title: 'Mock Scheduled Audio Room',
      roomType: 'audio',
      status: 'scheduled',
      hostId: 901,
      hostName: 'Aashi',
      maxSpeakers: 8,
      maxParticipants: 120,
      thumbnail: 'https://picsum.photos/seed/scheduledaudiohostmain/420/620',
      topic: 'Night talk preview',
      language: 'English',
      scheduledAt: now.add(const Duration(minutes: 25)),
      updatedAt: now,
    );
  }

  static home_dto.LiveRoomModel mockScheduledVideoRoom() {
    final now = DateTime.now();
    return home_dto.LiveRoomModel(
      id: mockScheduledVideoId,
      title: 'Mock Scheduled Video Room',
      roomType: 'video',
      status: 'scheduled',
      hostId: 902,
      hostName: 'Misha',
      maxSpeakers: 4,
      maxParticipants: 200,
      thumbnail: 'https://picsum.photos/seed/scheduledvideohostmain/420/620',
      topic: 'Fashion drop soon',
      language: 'Hindi',
      scheduledAt: now.add(const Duration(minutes: 40)),
      updatedAt: now,
    );
  }

  static List<home_dto.LiveRoomModel> _mockAudioFeedRooms() {
    const hosts = <String>[
      'Aman',
      'Riya',
      'Kabir',
      'Zara',
      'Noah',
      'Kiara',
      'Ishaan',
      'Sara',
      'Abeer',
      'Mia',
      'Yash',
      'Meher',
    ];
    const topics = <String>[
      'Night lounge',
      'Open mic',
      'Music chat',
      'Dating talk',
      'Study room',
      'Late gossip',
      'Motivation',
      'Q and A',
      'Host battle',
      'Vibes only',
      'After hours',
      'Coffee club',
    ];
    const languages = <String>[
      'English',
      'Hindi',
      'Punjabi',
      'Urdu',
      'English',
      'Hindi',
      'Tamil',
      'English',
      'Bengali',
      'Hindi',
      'English',
      'Marathi',
    ];

    return List<home_dto.LiveRoomModel>.generate(hosts.length, (index) {
      final now = DateTime.now();
      return home_dto.LiveRoomModel(
        id: 'mock-feed-audio-${index + 1}',
        title: '${topics[index]} Room',
        roomType: 'audio',
        status: 'live',
        hostId: 600 + index,
        hostName: hosts[index],
        maxSpeakers: 8,
        maxParticipants: 120,
        participantCount: 20 + (index * 3),
        listenerCount: 14 + (index * 9),
        speakerCount: 2 + (index % 6),
        audienceCount: 18 + (index * 10),
        thumbnail: 'https://picsum.photos/seed/audiohost${index + 1}/420/620',
        topic: topics[index],
        language: languages[index],
        startedAt: now.subtract(Duration(minutes: 6 + (index * 4))),
        updatedAt: now,
      );
    });
  }

  static List<home_dto.LiveRoomModel> _mockVideoFeedRooms() {
    const hosts = <String>[
      'Dev',
      'Anaya',
      'Maya',
      'Rohan',
      'Tara',
      'Zoya',
      'Karan',
      'Aarav',
      'Sana',
      'Jatin',
      'Vik',
      'Neha',
    ];
    const titles = <String>[
      'Premium stage',
      'Dance night',
      'PK ready',
      'Just chatting',
      'Singer live',
      'Host vibes',
      'Gaming facecam',
      'Fashion talk',
      'Beauty room',
      'Fans hangout',
      'Travel stories',
      'Late live',
    ];
    const languages = <String>[
      'English',
      'Hindi',
      'English',
      'Urdu',
      'Hindi',
      'Punjabi',
      'English',
      'Tamil',
      'Hindi',
      'English',
      'Bengali',
      'English',
    ];

    return List<home_dto.LiveRoomModel>.generate(hosts.length, (index) {
      final now = DateTime.now();
      return home_dto.LiveRoomModel(
        id: 'mock-feed-video-${index + 1}',
        title: titles[index],
        roomType: 'video',
        status: 'live',
        hostId: 800 + index,
        hostName: hosts[index],
        maxSpeakers: 4,
        maxParticipants: 200,
        participantCount: 30 + (index * 4),
        viewerCount: 24 + (index * 17),
        speakerCount: 1 + (index % 4),
        audienceCount: 35 + (index * 18),
        topic: titles[index],
        language: languages[index],
        thumbnail: 'https://picsum.photos/seed/livehost${index + 1}/420/620',
        startedAt: now.subtract(Duration(minutes: 8 + (index * 5))),
        updatedAt: now,
      );
    });
  }

  static List<home_dto.LiveRoomModel> _mockScheduledAudioRooms() {
    const hosts = <String>['Aashi', 'Lina', 'Ruhani', 'Pihu'];
    const topics = <String>[
      'Night talk',
      'Slow songs',
      'Coffee chat',
      'Open advice',
    ];
    const languages = <String>['English', 'Hindi', 'Punjabi', 'Urdu'];

    return List<home_dto.LiveRoomModel>.generate(hosts.length, (index) {
      final now = DateTime.now();
      return home_dto.LiveRoomModel(
        id: 'mock-scheduled-audio-${index + 1}',
        title: '${topics[index]} Room',
        roomType: 'audio',
        status: 'scheduled',
        hostId: 950 + index,
        hostName: hosts[index],
        maxSpeakers: 8,
        maxParticipants: 120,
        thumbnail: 'https://picsum.photos/seed/scheduledaudio${index + 1}/420/620',
        topic: topics[index],
        language: languages[index],
        followerCount: 20 + (index * 7),
        scheduledAt: now.add(Duration(minutes: 20 + (index * 18))),
        updatedAt: now,
      );
    });
  }

  static List<home_dto.LiveRoomModel> _mockScheduledVideoRooms() {
    const hosts = <String>['Misha', 'Avni', 'Rhea', 'Tina'];
    const topics = <String>[
      'Style check',
      'Dance warmup',
      'Late night glam',
      'Fan catchup',
    ];
    const languages = <String>['Hindi', 'English', 'Hindi', 'English'];

    return List<home_dto.LiveRoomModel>.generate(hosts.length, (index) {
      final now = DateTime.now();
      return home_dto.LiveRoomModel(
        id: 'mock-scheduled-video-${index + 1}',
        title: topics[index],
        roomType: 'video',
        status: 'scheduled',
        hostId: 980 + index,
        hostName: hosts[index],
        maxSpeakers: 4,
        maxParticipants: 200,
        thumbnail: 'https://picsum.photos/seed/scheduledvideo${index + 1}/420/620',
        topic: topics[index],
        language: languages[index],
        followerCount: 35 + (index * 12),
        scheduledAt: now.add(Duration(minutes: 35 + (index * 22))),
        updatedAt: now,
      );
    });
  }

  static LiveRoomModel audioRoom() {
    return LiveRoomModel(
      roomId: 'dev-audio-room',
      title: 'Late Night Lounge',
      roomType: 'audio',
      status: 'live',
      role: 'listener',
      participantCount: 72,
      listenerCount: 64,
      speakerCount: 8,
      maxSpeakers: 8,
      maxParticipants: 120,
      topic: 'UI preview',
      language: 'English',
      speakers: const <Map<String, dynamic>>[
        {
          'user_id': 310,
          'name': 'Ariana',
          'active_theme_key': 'aurora',
          'is_vip': true,
        },
        {
          'user_id': 311,
          'name': 'Kabir',
          'active_theme_key': 'gold_black',
          'is_vip': true,
        },
        {
          'user_id': 312,
          'name': 'Neha',
          'active_theme_key': 'teal_rose',
          'is_vip': false,
        },
        {
          'user_id': 313,
          'name': 'Rohan',
          'active_theme_key': 'cyberpunk',
          'is_vip': true,
        },
        {
          'user_id': 314,
          'name': 'Anaya',
          'active_theme_key': 'royal_sapphire',
          'is_vip': true,
        },
        {
          'user_id': 315,
          'name': 'Ishaan',
          'active_theme_key': 'molten_pearl',
          'is_vip': false,
        },
        {
          'user_id': 316,
          'name': 'Tara',
          'active_theme_key': 'noir_opal',
          'is_vip': true,
        },
      ],
      meta: const <String, dynamic>{
        'host_user_id': 301,
        'host_name': 'Host Aman',
        'dev_listeners': <Map<String, dynamic>>[
          {
            'label': 'Mia',
            'theme_key': 'midnight',
            'is_vip': false,
            'speaking': false,
          },
          {
            'label': 'Jatin',
            'theme_key': 'emerald',
            'is_vip': true,
            'speaking': false,
          },
          {
            'label': 'Sana',
            'theme_key': 'ruby_sky',
            'is_vip': false,
            'speaking': true,
          },
          {
            'label': 'Dev',
            'theme_key': 'sunset_pop',
            'is_vip': false,
            'speaking': false,
            'is_me': true,
          },
          {
            'label': 'Zara',
            'theme_key': 'aurora',
            'is_vip': true,
            'speaking': false,
          },
          {
            'label': 'Abeer',
            'theme_key': 'violet_lime',
            'is_vip': false,
            'speaking': false,
          },
          {
            'label': 'Kiara',
            'theme_key': 'gold',
            'is_vip': true,
            'speaking': true,
          },
          {
            'label': 'Manav',
            'theme_key': 'obsidian_rose',
            'is_vip': false,
            'speaking': false,
          },
          {
            'label': 'Riya',
            'theme_key': 'amethyst_chrome',
            'is_vip': true,
            'speaking': false,
          },
          {
            'label': 'Vik',
            'theme_key': 'imperial_jade',
            'is_vip': false,
            'speaking': false,
          },
          {
            'label': 'Meher',
            'theme_key': 'crimson_velvet',
            'is_vip': true,
            'speaking': false,
          },
          {
            'label': 'Yash',
            'theme_key': 'ocean',
            'is_vip': false,
            'speaking': true,
          },
          {
            'label': 'Noah',
            'theme_key': 'ice',
            'is_vip': false,
            'speaking': false,
          },
          {
            'label': 'Sara',
            'theme_key': 'teal_rose',
            'is_vip': true,
            'speaking': false,
          },
        ],
      },
    );
  }

  static LiveRoomModel videoRoom() {
    return LiveRoomModel(
      roomId: 'dev-video-room',
      title: 'Premium Stage Preview',
      roomType: 'video',
      status: 'live',
      role: 'host',
      participantCount: 146,
      listenerCount: 140,
      speakerCount: 6,
      maxSpeakers: 4,
      maxParticipants: 200,
      topic: 'Video UI preview',
      language: 'English',
      meta: const <String, dynamic>{
        'host_name': 'Host Aman',
        'dev_video_tiles': <Map<String, dynamic>>[
          {
            'label': 'You',
            'theme_key': 'gold_black',
            'is_host': true,
            'is_vip': true,
            'is_speaking': true,
          },
          {
            'label': 'Maya',
            'theme_key': 'aurora',
            'is_host': false,
            'is_vip': true,
            'is_speaking': false,
          },
          {
            'label': 'Karan',
            'theme_key': 'inferno',
            'is_host': false,
            'is_vip': false,
            'is_speaking': true,
          },
          {
            'label': 'Zoya',
            'theme_key': 'ice',
            'is_host': false,
            'is_vip': true,
            'is_speaking': false,
          },
          {
            'label': 'Aarav',
            'theme_key': 'royal_sapphire',
            'is_host': false,
            'is_vip': true,
            'is_speaking': false,
          },
          {
            'label': 'Nina',
            'theme_key': 'crimson_velvet',
            'is_host': false,
            'is_vip': false,
            'is_speaking': true,
          },
        ],
      },
    );
  }

  static LiveRoomModel audioSoloRoom() {
    return LiveRoomModel(
      roomId: mockFeedAudioId,
      title: 'Mock Audio Host Room',
      roomType: 'audio',
      status: 'live',
      role: 'listener',
      participantCount: 1,
      listenerCount: 0,
      speakerCount: 1,
      maxSpeakers: 8,
      maxParticipants: 120,
      topic: 'Gift animation test',
      language: 'English',
      speakers: const <Map<String, dynamic>>[],
      meta: const <String, dynamic>{
        'host_user_id': 301,
        'host_name': 'Host Aman',
        'host_avatar': '',
        'mock_feed_room': true,
        'dev_listeners': <Map<String, dynamic>>[],
      },
    );
  }

  static LiveRoomModel videoSoloRoom() {
    return LiveRoomModel(
      roomId: mockFeedVideoId,
      title: 'Mock Video Host Room',
      roomType: 'video',
      status: 'live',
      role: 'viewer',
      participantCount: 1,
      listenerCount: 0,
      speakerCount: 1,
      maxSpeakers: 4,
      maxParticipants: 200,
      topic: 'Gift animation test',
      language: 'English',
      meta: const <String, dynamic>{
        'host_user_id': 501,
        'host_name': 'Host Aman',
        'host_avatar': '',
        'mock_feed_room': true,
        'dev_video_tiles': <Map<String, dynamic>>[
          {
            'user_id': 501,
            'label': 'Host Aman',
            'theme_key': 'gold_black',
            'is_host': true,
            'is_vip': true,
            'is_speaking': true,
            'level': 17,
            'avatar_url': '',
          },
        ],
      },
    );
  }

  static List<LiveGiftItem> mockGiftCatalog() {
    return const <LiveGiftItem>[
      LiveGiftItem(
        id: 101,
        name: 'Rose',
        coins: 25,
        giftUrl: 'https://example.com/gifts/rose.gif',
        giftType: 'gif',
        animationTier: 'small',
        animationDurationMs: 1400,
      ),
      LiveGiftItem(
        id: 102,
        name: 'Heart',
        coins: 199,
        giftUrl: 'https://example.com/gifts/heart.svg',
        giftType: 'svg',
        animationTier: 'medium',
        animationDurationMs: 2400,
      ),
      LiveGiftItem(
        id: 103,
        name: 'Rocket',
        coins: 1299,
        giftUrl: 'https://example.com/gifts/rocket.webp',
        giftType: 'image',
        animationTier: 'premium',
        animationDurationMs: 5000,
      ),
      LiveGiftItem(
        id: 104,
        name: 'Crown',
        coins: 12000,
        giftUrl: 'https://example.com/gifts/crown.gif',
        giftType: 'gif',
        animationTier: 'legendary',
        animationDurationMs: 6800,
      ),
    ];
  }

  static Map<String, dynamic> mockGiftPayload({
    required String roomId,
    required String roomType,
    required int receiverId,
    required String receiverName,
    String? receiverAvatar,
    required LiveGiftItem gift,
    required int quantity,
    String? pkSide,
  }) {
    final senderTheme =
        roomType == 'audio'
            ? 'aurora'
            : (pkSide == 'right' ? 'inferno' : 'cyberpunk');
    final receiverTheme = roomType == 'audio' ? 'gold_black' : 'gold_black';
    return <String, dynamic>{
      'event': 'room:gift',
      'room_id': roomId,
      'room_type': roomType,
      'host_user_id': receiverId,
      'sender_user_id': 90061,
      'sender_name': 'Gift Tester',
      'sender_avatar': '',
      'sender_level': 12,
      'sender_is_vip': true,
      'sender_active_theme_key': senderTheme,
      'receiver_active_theme_key': receiverTheme,
      'receiver_name': receiverName,
      'receiver_avatar': receiverAvatar ?? '',
      'pk_side': pkSide,
      'gift_id': gift.id,
      'gift_name': gift.name,
      'gift_url': gift.giftUrl ?? '',
      'gift_type': gift.giftType,
      'animation_tier': gift.animationTier,
      'animation_duration_ms': gift.animationDurationMs,
      'quantity': quantity,
      'coins_per_unit': gift.coins,
      'total_coins': gift.coins * quantity,
      'message': 'Testing premium gift overlay',
      'created_at': DateTime.now().toIso8601String(),
    };
  }

  static LiveRoomModel videoPkRoom() {
    final now = DateTime.now();
    final endsAt = now.add(const Duration(seconds: 82)).toIso8601String();
    final startedAt = now.subtract(const Duration(seconds: 38)).toIso8601String();
    return LiveRoomModel(
      roomId: 'dev-video-pk-room-a',
      title: 'PK Battle Preview',
      roomType: 'video',
      status: 'live',
      role: 'host',
      participantCount: 188,
      listenerCount: 182,
      speakerCount: 2,
      maxSpeakers: 4,
      maxParticipants: 200,
      topic: 'PK UI preview',
      language: 'English',
      pkActive: <String, dynamic>{
        'battle_id': 'dev-pk-battle-01',
        'status': 'active',
        'duration_seconds': 120,
        'score_a': 12400,
        'score_b': 9800,
        'started_at': startedAt,
        'ends_at': endsAt,
        'updated_at': now.toIso8601String(),
        'winner_room_id': null,
        'room_a': <String, dynamic>{
          'id': 'dev-video-pk-room-a',
          'name': 'Team Aman',
        },
        'room_b': <String, dynamic>{
          'id': 'dev-video-pk-room-b',
          'name': 'Team Zoya',
        },
        'host_a': <String, dynamic>{
          'user_id': 501,
          'name': 'Host Aman',
          'active_theme_key': 'gold_black',
          'is_vip': true,
        },
        'host_b': <String, dynamic>{
          'user_id': 502,
          'name': 'Zoya',
          'active_theme_key': 'aurora',
          'is_vip': true,
        },
      },
      meta: const <String, dynamic>{
        'host_name': 'Host Aman',
        'dev_video_tiles': <Map<String, dynamic>>[
          {
            'label': 'You',
            'theme_key': 'gold_black',
            'is_host': true,
            'is_vip': true,
            'is_speaking': true,
          },
        ],
      },
    );
  }

  static List<LiveRoomChatMessage> audioMessages() {
    return <LiveRoomChatMessage>[
      LiveRoomChatMessage(
        id: 'dev-audio-1',
        roomId: 'dev-audio-room',
        roomType: 'audio',
        senderId: 301,
        senderName: 'Host Aman',
        message: 'Welcome in. This route is for audio room UI work.',
        messageType: 'text',
        createdAt: DateTime.now().subtract(const Duration(minutes: 2)),
        senderIsHost: true,
        senderIsVip: true,
        senderLevel: 18,
        senderActiveThemeKey: 'gold_black',
      ),
      LiveRoomChatMessage(
        id: 'dev-audio-2',
        roomId: 'dev-audio-room',
        roomType: 'audio',
        senderId: 311,
        senderName: 'Kabir',
        message: 'The footer and chips are easy to test here.',
        messageType: 'text',
        createdAt: DateTime.now().subtract(const Duration(minutes: 1)),
        senderIsVip: true,
        senderLevel: 12,
        senderActiveThemeKey: 'aurora',
      ),
    ];
  }

  static List<LiveRoomChatMessage> videoMessages() {
    return <LiveRoomChatMessage>[
      LiveRoomChatMessage(
        id: 'dev-video-1',
        roomId: 'dev-video-room',
        roomType: 'video',
        senderId: 401,
        senderName: 'Maya',
        message: 'This route is for polishing the video stage.',
        messageType: 'text',
        createdAt: DateTime.now().subtract(const Duration(minutes: 3)),
        senderIsVip: true,
        senderLevel: 15,
        senderActiveThemeKey: 'aurora',
      ),
      LiveRoomChatMessage(
        id: 'dev-video-2',
        roomId: 'dev-video-room',
        roomType: 'video',
        senderId: 402,
        senderName: 'Karan',
        message: 'Tile spacing, frames, and overlays can be tuned safely here.',
        messageType: 'text',
        createdAt: DateTime.now().subtract(const Duration(minutes: 1)),
        senderIsVip: false,
        senderLevel: 9,
        senderActiveThemeKey: 'inferno',
      ),
    ];
  }
}
