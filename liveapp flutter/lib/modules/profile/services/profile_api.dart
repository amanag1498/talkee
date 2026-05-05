import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../../services/api_client.dart';
import '../models/host_earnings_report_dto.dart';
import '../models/profile_dto.dart';

class ProfileApi {
  final ApiClient _api;
  ProfileApi(this._api);

  void _log(String msg) => debugPrint('[api][profile] $msg');

  Future<ProfileDto> fetchProfile() async {
    final res = await _api.get<Map<String, dynamic>>('profile');
    final body = _asMap(res.data);
    return ProfileDto.fromJson(_asMap(body['data']));
  }

  Future<ProfileDto> fetchPublicProfile(int userId) async {
    final res = await _api.get<Map<String, dynamic>>('profile/users/$userId');
    final body = _asMap(res.data);
    return ProfileDto.fromJson(_asMap(body['data']));
  }

  Future<HostEarningsReportDto> fetchHostEarningsReport() async {
    final res = await _api.get<Map<String, dynamic>>('profile/host-earnings-report');
    final body = _asMap(res.data);
    return HostEarningsReportDto.fromJson(_asMap(body['data']));
  }

  Future<ProfileDto> updateProfile({
    required String name,
    String? stageName,
    String? contactPhone,
    String? country,
    String? city,
    String? bio,
  }) async {
    final res = await _api.put<Map<String, dynamic>>(
      'profile',
      data: {
        'name': name,
        'stage_name': stageName,
        'contact_phone': contactPhone,
        'country': country,
        'city': city,
        'bio': bio,
      },
    );
    return ProfileDto.fromJson(_asMap(_asMap(res.data)['data']));
  }

  Future<ProfileDto> uploadAvatar(String path) async {
    final form = FormData.fromMap({
      'avatar': await MultipartFile.fromFile(path),
    });
    final res = await _api.post<Map<String, dynamic>>('profile/avatar', data: form);
    _log('avatar upload <- ${res.statusCode}');
    return ProfileDto.fromJson(_asMap(_asMap(res.data)['data']));
  }

  Future<List<ProfileFrameDto>> fetchProfileFrames() async {
    final res = await _api.get<Map<String, dynamic>>('profile/frames');
    final body = _asMap(res.data);
    final list = (body['data'] as List?) ?? const <dynamic>[];
    return list
        .map((item) => ProfileFrameDto.fromJson(_asMap(item)))
        .toList(growable: false);
  }

  Future<List<ProfileFrameDto>> fetchShopProfileFrames() async {
    final res = await _api.get<Map<String, dynamic>>('profile/frames/shop');
    final body = _asMap(res.data);
    final list = (body['data'] as List?) ?? const <dynamic>[];
    return list
        .map((item) => ProfileFrameDto.fromJson(_asMap(item)))
        .toList(growable: false);
  }

  Future<Map<String, dynamic>> equipProfileFrame(int profileFrameId) async {
    final res = await _api.post<Map<String, dynamic>>(
      'profile/frames/equip',
      data: {
        'profile_frame_id': profileFrameId,
      },
    );
    return _asMap(res.data);
  }

  Future<Map<String, dynamic>> purchaseProfileFrame(int profileFrameId) async {
    final res = await _api.post<Map<String, dynamic>>(
      'profile/frames/purchase',
      data: {
        'profile_frame_id': profileFrameId,
      },
    );
    return _asMap(res.data);
  }

  Map<String, dynamic> _asMap(dynamic value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return <String, dynamic>{};
  }
}
