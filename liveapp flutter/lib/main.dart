import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:get_storage/get_storage.dart';
import 'package:firebase_core/firebase_core.dart';

import 'package:liveapp/app/routes/app_urls.dart';
import 'package:liveapp/services/push_service.dart';

import 'app/routes/app_pages.dart';
import 'app/routes/app_routes.dart';
import 'app/theme/brand.dart'; // <-- add this
import 'app/widgets/app_runtime_gate.dart';
import 'app/widgets/themed_environment.dart';
import 'services/app_settings_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await SystemChrome.setPreferredOrientations(const [
    DeviceOrientation.portraitUp,
  ]);
  await Firebase.initializeApp();
  FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
  await GetStorage.init();

  final box = GetStorage();
  final token = box.read<String>('auth_token');
  const devInitialRoute = '';
  final initialRoute =
      devInitialRoute.isNotEmpty
          ? devInitialRoute
          : (token != null && token.isNotEmpty)
              ? Routes.home
              : Routes.login;

  runApp(MyApp(apiBase: AppUrls.apiBase, initialRoute: initialRoute));
}

class MyApp extends StatelessWidget {
  final String apiBase;
  final String initialRoute;
  const MyApp({super.key, required this.apiBase, required this.initialRoute});

  @override
  Widget build(BuildContext context) {
    final pages = AppPages.pages(apiBase);
    final appSettings = Get.find<AppSettingsService>();

    return Obx(() {
      final variant = appSettings.activePremiumThemeVariant;

      return GetMaterialApp(
        title: 'Firebase + Laravel Auth',
        debugShowCheckedModeBanner: false,

        theme: talkeeLightTheme(variant: variant),
        darkTheme: talkeeDarkTheme(variant: variant),
        themeMode: ThemeMode.dark,

        initialRoute: initialRoute,
        getPages: pages,
        builder: (context, child) {
          return ThemedEnvironment(
            child: AppRuntimeGate(child: child ?? const SizedBox.shrink()),
          );
        },
      );
    });
  }
}
