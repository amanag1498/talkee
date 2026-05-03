class HostEarningsReportDto {
  final HostEarningsPeriodDto today;
  final HostEarningsPeriodDto currentWeek;
  final HostEarningsPeriodDto lastWeek;

  const HostEarningsReportDto({
    required this.today,
    required this.currentWeek,
    required this.lastWeek,
  });

  factory HostEarningsReportDto.fromJson(Map<String, dynamic> json) {
    return HostEarningsReportDto(
      today: HostEarningsPeriodDto.fromJson(_asMap(json['today'])),
      currentWeek: HostEarningsPeriodDto.fromJson(_asMap(json['current_week'])),
      lastWeek: HostEarningsPeriodDto.fromJson(_asMap(json['last_week'])),
    );
  }

  static Map<String, dynamic> _asMap(dynamic value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return <String, dynamic>{};
  }
}

class HostEarningsPeriodDto {
  final String label;
  final DateTime? from;
  final DateTime? to;
  final HostEarningsSummaryDto summary;

  const HostEarningsPeriodDto({
    required this.label,
    required this.summary,
    this.from,
    this.to,
  });

  factory HostEarningsPeriodDto.fromJson(Map<String, dynamic> json) {
    return HostEarningsPeriodDto(
      label: (json['label'] ?? '').toString(),
      from: DateTime.tryParse((json['from'] ?? '').toString()),
      to: DateTime.tryParse((json['to'] ?? '').toString()),
      summary: HostEarningsSummaryDto.fromJson(
        json['summary'] is Map<String, dynamic>
            ? json['summary'] as Map<String, dynamic>
            : Map<String, dynamic>.from(json['summary'] as Map? ?? const <String, dynamic>{}),
      ),
    );
  }
}

class HostEarningsSummaryDto {
  final int totalVideoRoomMinutes;
  final int totalAudioRoomMinutes;
  final int totalGiftedCoins;
  final int totalRoomGiftsCoins;
  final int audioRoomGiftsCoins;
  final int videoRoomGiftsCoins;
  final int audioRoomGiftEarnings;
  final int videoRoomGiftEarnings;
  final int audioCallMinutes;
  final int audioCallEarnings;
  final int videoCallMinutes;
  final int videoCallEarnings;
  final int pkRoomCount;
  final int pkGiftCoins;
  final int pkEarnings;

  const HostEarningsSummaryDto({
    required this.totalVideoRoomMinutes,
    required this.totalAudioRoomMinutes,
    required this.totalGiftedCoins,
    required this.totalRoomGiftsCoins,
    required this.audioRoomGiftsCoins,
    required this.videoRoomGiftsCoins,
    required this.audioRoomGiftEarnings,
    required this.videoRoomGiftEarnings,
    required this.audioCallMinutes,
    required this.audioCallEarnings,
    required this.videoCallMinutes,
    required this.videoCallEarnings,
    required this.pkRoomCount,
    required this.pkGiftCoins,
    required this.pkEarnings,
  });

  factory HostEarningsSummaryDto.fromJson(Map<String, dynamic> json) {
    int asInt(dynamic value) => (value as num?)?.toInt() ?? int.tryParse('${value ?? 0}') ?? 0;

    return HostEarningsSummaryDto(
      totalVideoRoomMinutes: asInt(json['total_video_room_minutes']),
      totalAudioRoomMinutes: asInt(json['total_audio_room_minutes']),
      totalGiftedCoins: asInt(json['total_gifted_coins']),
      totalRoomGiftsCoins: asInt(json['total_room_gifts_coins']),
      audioRoomGiftsCoins: asInt(json['audio_room_gifts_coins']),
      videoRoomGiftsCoins: asInt(json['video_room_gifts_coins']),
      audioRoomGiftEarnings: asInt(json['audio_room_gift_earnings']),
      videoRoomGiftEarnings: asInt(json['video_room_gift_earnings']),
      audioCallMinutes: asInt(json['audio_call_minutes']),
      audioCallEarnings: asInt(json['audio_call_earnings']),
      videoCallMinutes: asInt(json['video_call_minutes']),
      videoCallEarnings: asInt(json['video_call_earnings']),
      pkRoomCount: asInt(json['pk_room_count']),
      pkGiftCoins: asInt(json['pk_gift_coins']),
      pkEarnings: asInt(json['pk_earnings']),
    );
  }
}
