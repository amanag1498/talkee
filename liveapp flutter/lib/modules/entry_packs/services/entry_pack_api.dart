import 'package:dio/dio.dart';

import '../../../services/api_client.dart';
import '../models/entry_pack_dto.dart';
import '../models/user_entry_pack_dto.dart';

class EntryPackApi {
  final ApiClient _api;

  EntryPackApi(this._api);

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
    await _api.post<Map<String, dynamic>>(
      'entry-packs/$packId/purchase',
      headers: <String, String>{
        'Idempotency-Key': 'entry-pack-$packId-${DateTime.now().millisecondsSinceEpoch}',
      },
    );
  }

  Future<void> activate(int packId) async {
    await _api.post<Map<String, dynamic>>('me/entry-pack/$packId/activate');
  }
}
