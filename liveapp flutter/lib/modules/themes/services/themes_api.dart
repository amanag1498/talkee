import '../../../services/api_client.dart';
import '../models/theme_access_dto.dart';

class ThemesApi {
  ThemesApi(this._api);

  final ApiClient _api;

  Future<ThemeAccessCatalogDto> catalog() async {
    final response = await _api.get<Map<String, dynamic>>('themes');
    final body = response.data ?? const <String, dynamic>{};
    final data =
        body['data'] is Map<String, dynamic>
            ? body['data'] as Map<String, dynamic>
            : Map<String, dynamic>.from(body['data'] as Map? ?? const {});
    return ThemeAccessCatalogDto.fromJson(data);
  }

  Future<String> selectTheme(String themeKey) async {
    final response = await _api.post<Map<String, dynamic>>(
      'themes/select',
      data: {'theme_key': themeKey},
    );
    final body = response.data ?? const <String, dynamic>{};
    final data =
        body['data'] is Map<String, dynamic>
            ? body['data'] as Map<String, dynamic>
            : Map<String, dynamic>.from(body['data'] as Map? ?? const {});
    return (data['active_theme_key'] ?? 'midnight').toString();
  }

  Future<ThemeAccessCatalogDto> purchaseTheme(String themeKey) async {
    final response = await _api.post<Map<String, dynamic>>(
      'themes/purchase',
      data: {'theme_key': themeKey},
    );
    final body = response.data ?? const <String, dynamic>{};
    final data =
        body['data'] is Map<String, dynamic>
            ? body['data'] as Map<String, dynamic>
            : Map<String, dynamic>.from(body['data'] as Map? ?? const {});
    final catalog =
        data['catalog'] is Map<String, dynamic>
            ? data['catalog'] as Map<String, dynamic>
            : Map<String, dynamic>.from(data['catalog'] as Map? ?? const {});
    return ThemeAccessCatalogDto.fromJson(catalog);
  }
}
