import 'package:dio/dio.dart';

import '../../../services/api_client.dart';
import '../models/application_dto.dart';

class ApplicationsApi {
  final ApiClient _api;
  ApplicationsApi(this._api);

  Future<ApplicationSummaryDto> fetchSummary() async {
    final res = await _api.get<Map<String, dynamic>>('me/applications');
    return ApplicationSummaryDto.fromJson(_asMap(_asMap(res.data)['data']));
  }

  Future<String> applyAgency({
    required String agencyName,
    String? legalName,
    String? contactPhone,
    String? website,
    String? about,
  }) async {
    final res = await _api.post<Map<String, dynamic>>(
      'agency/apply',
      data: {
        'agency_name': agencyName,
        'legal_name': legalName,
        'contact_phone': contactPhone,
        'website': website,
        'about': about,
      },
    );
    return (_asMap(res.data)['message'] ?? 'Agency application submitted.').toString();
  }

  Future<String> applyHost({
    String? stageName,
    String? contactPhone,
    String? country,
    String? city,
    String? about,
  }) async {
    final res = await _api.post<Map<String, dynamic>>(
      'host/apply',
      data: {
        'stage_name': stageName,
        'contact_phone': contactPhone,
        'country': country,
        'city': city,
        'about': about,
      },
    );
    return (_asMap(res.data)['message'] ?? 'Host application submitted.').toString();
  }

  Future<String> enrollHost({
    required int agencyId,
    String? message,
  }) async {
    final res = await _api.post<Map<String, dynamic>>(
      'host/enroll',
      data: {
        'agency_id': agencyId,
        'message': message,
      },
    );
    return (_asMap(res.data)['message'] ?? 'Enroll request submitted.').toString();
  }

  String extractError(Object error) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map && data['message'] != null) return data['message'].toString();
      if (data is Map && data['msg'] != null) return data['msg'].toString();
    }
    return error.toString().replaceFirst('Exception: ', '');
  }

  Map<String, dynamic> _asMap(dynamic value) {
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return <String, dynamic>{};
  }
}
