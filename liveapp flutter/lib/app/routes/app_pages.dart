import 'dart:async';

import 'package:get/get.dart';

import '../../modules/Live/services/live_service.dart';
import '../../modules/Live/dev/live_room_dev_fixtures.dart';
import '../../modules/Live/views/start_live_page.dart';
import '../../modules/Live/views/audio_room_page.dart';
import '../../modules/Live/views/video_call_page.dart';
import '../../modules/applications/controllers/applications_controller.dart';
import '../../modules/applications/services/applications_api.dart';
import '../../modules/applications/views/apply_agency_page.dart';
import '../../modules/applications/views/apply_host_page.dart';
import '../../modules/applications/views/enroll_agency_page.dart';
import '../../modules/applications/views/my_applications_page.dart';
import '../../modules/auth/controllers/auth_controller.dart';
import '../../modules/auth/views/login_view.dart';
import '../../modules/banners/services/banner_service.dart';
import '../../modules/home/controllers/home_controller.dart';
import '../../modules/home/controllers/live_room_controller.dart';
import '../../modules/home/views/home_view.dart';
import '../../modules/profile/controllers/profile_controller.dart';
import '../../modules/profile/controllers/host_follow_controller.dart';
import '../../modules/profile/services/profile_api.dart';
import '../../modules/profile/services/host_follow_api.dart';
import '../../modules/profile/views/edit_profile_page.dart';
import '../../modules/profile/views/blocked_users_page.dart';
import '../../modules/profile/views/followers_page.dart';
import '../../modules/profile/views/following_page.dart';
import '../../modules/profile/views/host_scheduled_lives_page.dart';
import '../../modules/profile/views/moderation_history_page.dart';
import '../../modules/profile/views/profile_page.dart';
import '../../modules/profile/views/unblock_requests_page.dart';

import '../../modules/notifications/controllers/notification_controller.dart';
import '../../modules/notifications/views/notification_page.dart';
import '../../modules/entry_packs/services/entry_pack_api.dart';
import '../../modules/entry_packs/views/entry_pack_catalog_page.dart';
import '../../modules/games/teen_patti/services/teen_patti_api.dart';
import '../../modules/games/teen_patti/services/teen_patti_socket_service.dart';
import '../../modules/subscriptions/controllers/viewer_gate_controller.dart';
import '../../modules/subscriptions/views/subscriptions_page.dart';
import '../../modules/themes/controllers/theme_center_controller.dart';
import '../../modules/themes/services/themes_api.dart';
import '../../modules/themes/views/theme_center_page.dart';
import '../../modules/calls/controllers/call_controller.dart';
import '../../modules/calls/controllers/live_users_controller.dart';
import '../../modules/calls/views/active_call_screen.dart';
import '../../modules/calls/views/call_history_view.dart';
import '../../modules/calls/views/incoming_call_screen.dart';
import '../../modules/calls/views/live_users_view.dart';
import '../../modules/calls/views/outgoing_call_screen.dart';
import '../../modules/dashboard/controllers/dashboard_controller.dart';
import '../../modules/dashboard/services/dashboard_api.dart';

import '../../services/api_client.dart';
import '../../services/app_settings_service.dart';
import '../../services/auth_service.dart';
import '../../services/call_service.dart';
import '../../services/call_socket_service.dart';
import '../../services/live_rooms_ws_service.dart';
import '../../services/storage_service.dart';
import '../../services/live_eligibility_service.dart'; // 👈 add
import '../../modules/wallet/services/wallet_api.dart';
import '../../modules/wallet/services/razorpay_checkout_service.dart';
import '../../modules/wallet/views/wallet_history_page.dart';

import '../middleware/auth_middleware.dart';
import 'app_routes.dart';

class AppPages {
  static List<GetPage> pages(String baseUrl) {
    // DI singletons
    final storage = StorageService();
    final api = ApiClient(baseUrl: baseUrl, storage: storage);
    final authService = AuthService(api: api, storage: storage);

    // Register for global access
    Get.put<StorageService>(storage, permanent: true);
    Get.put<ApiClient>(api, permanent: true);
    Get.put<AuthService>(authService, permanent: true);
    final appSettings = Get.put<AppSettingsService>(
      AppSettingsService(api),
      permanent: true,
    );
    unawaited(appSettings.initialize());
    Get.put<CallService>(CallService(api), permanent: true);
    Get.put<CallSocketService>(CallSocketService(), permanent: true);
    Get.put<ProfileApi>(ProfileApi(api), permanent: true);
    Get.put<HostFollowApi>(HostFollowApi(api), permanent: true);
    Get.put<ApplicationsApi>(ApplicationsApi(api), permanent: true);
    Get.put<WalletApi>(WalletApi(api), permanent: true);
    Get.put<TeenPattiApi>(TeenPattiApi(api), permanent: true);
    Get.put<RazorpayCheckoutService>(
      RazorpayCheckoutService(),
      permanent: true,
    );
    Get.put<EntryPackApi>(EntryPackApi(api), permanent: true);
    Get.put<DashboardApi>(DashboardApi(api), permanent: true);
    Get.put<ThemesApi>(ThemesApi(api), permanent: true);
    Get.put<BannerService>(
      BannerService(api: api, auth: authService),
      permanent: true,
    );
    Get.put<AppCallController>(
      AppCallController(
        Get.find<AuthService>(),
        Get.find<CallService>(),
        Get.find<CallSocketService>(),
      ),
      permanent: true,
    );

    // Live eligibility service (GLOBAL) seeded from current user (if any)
    final liveElig =
        Get.isRegistered<LiveEligibilityService>()
            ? Get.find<LiveEligibilityService>()
            : Get.put<LiveEligibilityService>(
              LiveEligibilityService(),
              permanent: true,
            );
    final current = authService.currentUser;
    if (current != null) {
      liveElig.setFromUser(current);
    }

    // Other globals / lazies
    Get.lazyPut<LiveService>(
      () => LiveService(Get.find<ApiClient>()),
      fenix: true,
    );
    Get.lazyPut<RoomsSocketService>(() => RoomsSocketService(), fenix: true);
    Get.lazyPut<TeenPattiSocketService>(
      () => TeenPattiSocketService(),
      fenix: true,
    );
    Get.lazyPut<ProfileController>(
      () => ProfileController(
        api: Get.find<ProfileApi>(),
        auth: Get.find<AuthService>(),
        storage: Get.find<StorageService>(),
      ),
      fenix: true,
    );
    Get.lazyPut<HostFollowController>(
      () => HostFollowController(Get.find<HostFollowApi>()),
      fenix: true,
    );
    Get.lazyPut<DashboardController>(
      () => DashboardController(Get.find<DashboardApi>()),
      fenix: true,
    );
    Get.lazyPut<ApplicationsController>(
      () => ApplicationsController(
        api: Get.find<ApplicationsApi>(),
        auth: Get.find<AuthService>(),
      ),
      fenix: true,
    );
    Get.lazyPut<LiveUsersController>(
      () => LiveUsersController(
        Get.find<CallService>(),
        Get.find<AuthService>(),
        Get.find<AppCallController>(),
      ),
      fenix: true,
    );
    Get.put(ViewerGateController(), permanent: true);
    Get.put<LiveRoomsController>(
      LiveRoomsController(
        Get.find<AuthService>(),
        Get.find<RoomsSocketService>(),
        Get.find<LiveService>(),
      ),
      permanent: true,
    );

    return [
      GetPage(
        name: Routes.login,
        page: () => const LoginView(),
        binding: BindingsBuilder(() {
          Get.put<AuthController>(AuthController(Get.find<AuthService>()));
        }),
      ),
      GetPage(
        name: Routes.home,
        page: () => const HomeView(),
        binding: BindingsBuilder(() {
          // HomeController reads canGoLive via LiveEligibilityService
          Get.put<HomeController>(HomeController(Get.find<AuthService>()));
          // room services (if you want them scoped here too)
          if (!Get.isRegistered<RoomsSocketService>()) {
            Get.lazyPut<RoomsSocketService>(
              () => RoomsSocketService(),
              fenix: true,
            );
          }
          if (!Get.isRegistered<LiveRoomsController>()) {
            Get.put<LiveRoomsController>(
              LiveRoomsController(
                Get.find<AuthService>(),
                Get.find<RoomsSocketService>(),
                Get.find<LiveService>(),
              ),
            );
          }
        }),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.liveWaiting,
        page: () => const LiveWaitingPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.liveVideo,
        page: () {
          final args = Get.arguments as Map<String, dynamic>? ?? {};
          return VideoCallPage(
            room: args['room'],
            live: Get.find<LiveService>(),
            viewerOnly: args['viewer_only'] == true,
            devMode: args['dev_mode'] == true,
          );
        },
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.liveAudio,
        page: () {
          final args = Get.arguments as Map<String, dynamic>? ?? {};
          return AudioRoomPage(
            room: args['room'],
            live: Get.find<LiveService>(),
            initialMicOn: args['initial_mic_on'] != false,
            devMode: args['dev_mode'] == true,
          );
        },
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.devLiveVideo,
        page: () => VideoCallPage(
          room: LiveRoomDevFixtures.videoRoom(),
          live: Get.find<LiveService>(),
          devMode: true,
        ),
      ),
      GetPage(
        name: Routes.devLiveVideoPk,
        page: () => VideoCallPage(
          room: LiveRoomDevFixtures.videoPkRoom(),
          live: Get.find<LiveService>(),
          devMode: true,
        ),
      ),
      GetPage(
        name: Routes.devLiveAudio,
        page: () => AudioRoomPage(
          room: LiveRoomDevFixtures.audioRoom(),
          live: Get.find<LiveService>(),
          devMode: true,
        ),
      ),
      GetPage(
        name: Routes.entryCatalog,
        page: () => const EntryPackCatalogPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.notifications,
        page: () => const NotificationsPage(),
        binding: BindingsBuilder(() {
          // NotificationsController will recompute canGoLive after refresh
          if (!Get.isRegistered<NotificationsController>()) {
            Get.put<NotificationsController>(
              NotificationsController(Get.find<ApiClient>()),
            );
          }
        }),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.liveUsers,
        page: () => const LiveUsersView(),
        binding: BindingsBuilder(() {
          if (!Get.isRegistered<LiveUsersController>()) {
            Get.put<LiveUsersController>(
              LiveUsersController(
                Get.find<CallService>(),
                Get.find<AuthService>(),
                Get.find<AppCallController>(),
              ),
            );
          }
        }),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.incomingCall,
        page: () => const IncomingCallScreen(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.outgoingCall,
        page: () => const OutgoingCallScreen(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.activeCall,
        page: () => const ActiveCallScreen(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.callHistory,
        page: () => const CallHistoryView(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.profile,
        page: () => const ProfilePage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.editProfile,
        page: () => const EditProfilePage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.profileBlockedUsers,
        page: () => const BlockedUsersPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.profileUnblockRequests,
        page: () => const UnblockRequestsPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.profileModerationHistory,
        page: () => const ModerationHistoryPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.profileScheduledLives,
        page: () => const HostScheduledLivesPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.following,
        page: () => const FollowingPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.followers,
        page: () => const FollowersPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.walletHistory,
        page: () => const WalletHistoryPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.subscriptions,
        page: () => const SubscriptionsPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.themeCenter,
        page: () => const ThemeCenterPage(),
        binding: BindingsBuilder(() {
          Get.lazyPut<ThemeCenterController>(
            () => ThemeCenterController(
              Get.find<ThemesApi>(),
              Get.find<AppSettingsService>(),
            ),
          );
        }),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.myApplications,
        page: () => const MyApplicationsPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.applyAgency,
        page: () => const ApplyAgencyPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.applyHost,
        page: () => const ApplyHostPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
      GetPage(
        name: Routes.enrollAgency,
        page: () => const EnrollAgencyPage(),
        middlewares: [AuthMiddleware(Get.find<AuthService>())],
      ),
    ];
  }
}
