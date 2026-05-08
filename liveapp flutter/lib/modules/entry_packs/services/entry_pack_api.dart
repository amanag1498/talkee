import 'package:dio/dio.dart';

import '../../../services/api_client.dart';
import '../models/entry_pack_dto.dart';
import '../models/user_entry_pack_dto.dart';

class EntryPackApi {
  final ApiClient _api;

  EntryPackApi(this._api);

  Never _rethrowFriendlyError(DioException e) {
    final body = e.response?.data;
    if (body is Map) {
      final map = Map<String, dynamic>.from(body);
      final error = map['error']?.toString().trim();
      final message =
          map['message']?.toString().trim() ??
          map['data']?['message']?.toString().trim();

      if (error != null && error.isNotEmpty) {
        throw error;
      }
      if (message != null && message.isNotEmpty) {
        throw message;
      }
    }
    throw e.message ?? 'NETWORK_ERROR';
  }

  Future<List<EntryPackDto>> fetchPacks() async {
    final Response<Map<String, dynamic>> response =
        await _api.get<Map<String, dynamic>>('entry-packs');
    final body = response.data ?? const <String, dynamic>{};
    final raw = body['data'] is List ? body['data'] as List : const <dynamic>[];
    return raw
        .whereType<Map>()
        .map((row) => EntryPackDto.fromJson(Map<String, dynamic>.from(row)))
        .toList();
  }

  Future<EntryPackStateDto> fetchMine() async {
    final Response<Map<String, dynamic>> response =
        await _api.get<Map<String, dynamic>>('me/entry-pack');
    final body = response.data ?? const <String, dynamic>{};
    final data = body['data'] is Map<String, dynamic>
        ? body['data'] as Map<String, dynamic>
        : Map<String, dynamic>.from((body['data'] ?? const <String, dynamic>{}) as Map);
    return EntryPackStateDto.fromJson(data);
  }

  Future<void> purchase(int packId) async {
    try {
      await _api.post<Map<String, dynamic>>(
        'entry-packs/$packId/purchase',
        headers: <String, String>{
          'Idempotency-Key': 'entry-pack-$packId-${DateTime.now().millisecondsSinceEpoch}',
        },
      );
    } on DioException catch (e) {
      _rethrowFriendlyError(e);
    }
  }

  Future<void> activate(int packId) async {
    try {
      await _api.post<Map<String, dynamic>>('me/entry-pack/$packId/activate');
    } on DioException catch (e) {
      _rethrowFriendlyError(e);
    }
  }
}
