import 'package:dio/dio.dart';
import 'package:get/get.dart';

import '../../../services/app_settings_service.dart';
import '../../../services/live_rooms_ws_service.dart';
import '../models/theme_access_dto.dart';
import '../services/themes_api.dart';

class ThemeCenterController extends GetxController {
  ThemeCenterController(this._api, this._settings);

  final ThemesApi _api;
  final AppSettingsService _settings;

  final RxBool loading = false.obs;
  final RxBool submitting = false.obs;
  final RxnString error = RxnString();
  final RxnString pendingThemeKey = RxnString();
  final Rxn<ThemeAccessCatalogDto> catalog = Rxn<ThemeAccessCatalogDto>();

  @override
  void onInit() {
    super.onInit();
    load();
  }

  Future<void> load() async {
    if (loading.value) return;
    loading.value = true;
    error.value = null;
    try {
      catalog.value = await _api.catalog();
    } on DioException catch (e) {
      error.value = _extractMessage(e);
    } catch (e) {
      error.value = e.toString();
    } finally {
      loading.value = false;
    }
  }

  Future<void> selectTheme(ThemeAccessItemDto theme) async {
    if (submitting.value || !theme.unlocked) return;
    submitting.value = true;
    pendingThemeKey.value = theme.key;
    try {
      final activeThemeKey = await _api.selectTheme(theme.key);
      _settings.applySelectedThemeKey(activeThemeKey);
      if (Get.isRegistered<RoomsSocketService>()) {
        Get.find<RoomsSocketService>().refreshUserProfile();
      }
      await _settings.refresh();
      await load();
      Get.snackbar('Theme updated', '${theme.name} is now active.');
    } on DioException catch (e) {
      await _settings.refresh();
      await load();
      final message = _extractMessage(e);
      Get.snackbar('Theme unavailable', message);
    } catch (e) {
      await _settings.refresh();
      await load();
      Get.snackbar('Theme unavailable', e.toString());
    } finally {
      pendingThemeKey.value = null;
      submitting.value = false;
    }
  }

  Future<void> purchaseTheme(ThemeAccessItemDto theme) async {
    if (submitting.value || theme.unlocked) return;
    submitting.value = true;
    pendingThemeKey.value = theme.key;
    try {
      final nextCatalog = await _api.purchaseTheme(theme.key);
      catalog.value = nextCatalog;
      await _settings.refresh();
      Get.snackbar('Theme unlocked', '${theme.name} was added to your account.');
    } on DioException catch (e) {
      final message = _extractMessage(e);
      Get.snackbar('Purchase failed', message);
    } catch (e) {
      Get.snackbar('Purchase failed', e.toString());
    } finally {
      pendingThemeKey.value = null;
      submitting.value = false;
    }
  }

  String _extractMessage(DioException e) {
    final data = e.response?.data;
    if (data is Map && data['message'] != null) {
      return data['message'].toString();
    }
    if (data is Map && data['errors'] is Map) {
      final errors = data['errors'] as Map;
      final first = errors.values.isNotEmpty ? errors.values.first : null;
      if (first is List && first.isNotEmpty) {
        return first.first.toString();
      }
    }
    return e.message ?? 'Unable to load themes.';
  }
}
