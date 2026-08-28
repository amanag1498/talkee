import 'package:flutter/material.dart'; // 👈 add this
import 'package:get/get.dart';
import '../../../app/widgets/logout_and_blocked_dialog.dart';
import '../../../data/models/user_model.dart';
import '../../../services/auth_service.dart';
import '../../../services/auth_exception.dart';
import '../../../services/app_settings_service.dart';
import '../../../modules/calls/controllers/call_controller.dart';
import '../../../modules/home/controllers/live_room_controller.dart';
import '../../../app/routes/app_routes.dart';

class AuthController extends GetxController {
  final AuthService auth;
  AuthController(this.auth);

  final Rxn<UserModel> user = Rxn<UserModel>();
  final RxBool loading = false.obs;
  final RxString activeProvider = ''.obs;
  final RxString error = ''.obs;

  bool get isLoggedIn => auth.isLoggedIn;

  @override
  void onInit() {
    if (auth.isLoggedIn) {
      user.value = auth.currentUser;
    }
    super.onInit();
  }

  Future<void> loginWithGoogle() async {
    await _login('google', auth.signInWithGoogleAndBackend);
  }

  Future<void> loginWithApple() async {
    await _login('apple', auth.signInWithAppleAndBackend);
  }

  Future<void> _login(
    String provider,
    Future<UserModel> Function() signIn,
  ) async {
    if (loading.value) return;
    loading.value = true;
    activeProvider.value = provider;
    error.value = '';
    try {
      final u = await signIn();
      user.value = u;
      if (Get.isRegistered<AppSettingsService>()) {
        await Get.find<AppSettingsService>().refresh();
      }
      if (Get.isRegistered<AppCallController>()) {
        await Get.find<AppCallController>().restartSocket();
      }
      if (Get.isRegistered<LiveRoomsController>()) {
        await Get.find<LiveRoomsController>().refreshForCurrentAuth();
      }
      Get.offAllNamed(Routes.home);
    } on AuthSignInCancelledException {
      error.value = '';
    } catch (e) {
      if (e is AppUpgradeRequiredException ||
          (Get.isRegistered<AppSettingsService>() &&
              Get.find<AppSettingsService>().shouldForceUpgrade)) {
        error.value = '';
        return;
      }
      final msg = e.toString();
      error.value = msg;

      // 🎯 Nicer copy + themed colors if the user is blocked
      if (msg.toLowerCase().contains('blocked')) {
        await showDialog(
          context: Get.context!,
          barrierDismissible: false,
          builder: (_) => const BlockedDialog(),
        );
      } else {
        print(msg);
        //_showErrorSnack(msg);
      }
    } finally {
      loading.value = false;
      activeProvider.value = '';
    }
  }

  Future<void> logout() async {
    await auth.logout();
    if (Get.isRegistered<LiveRoomsController>()) {
      await Get.find<LiveRoomsController>().refreshForCurrentAuth();
    }
    user.value = null;
    Get.offAllNamed(Routes.login);
  }

  void _showErrorSnack(String msg) {
    final cs = Get.theme.colorScheme;
    Get.closeAllSnackbars();
    Get.snackbar(
      'Sign-in failed',
      msg,
      snackPosition: SnackPosition.BOTTOM,
      snackStyle: SnackStyle.FLOATING,
      backgroundColor: cs.surfaceVariant,
      colorText: cs.onSurfaceVariant,
      borderColor: cs.outline.withOpacity(.6),
      borderWidth: 1.0,
      margin: const EdgeInsets.all(12),
      icon: Icon(Icons.info_outline, color: cs.onSurfaceVariant),
      shouldIconPulse: false,
      duration: const Duration(seconds: 4),
    );
  }
}
