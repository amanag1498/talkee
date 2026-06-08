// lib/modules/home/views/home_view.dart
import 'dart:ui' show ImageFilter;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import 'package:liveapp/services/live_eligibility_service.dart';
import 'package:liveapp/services/app_settings_service.dart';

import '../../../../app/theme/brand.dart';
import '../../../../app/widgets/animated_background.dart';
import '../../../../app/widgets/talkee_logo.dart';
import '../../../../app/routes/app_routes.dart';
import '../../../../app/widgets/haptics.dart';
import '../../Live/views/live_preflight_sheet.dart';
import '../../calls/controllers/live_users_controller.dart';
import '../../notifications/widgets/bell_badge_btn.dart';
import '../../calls/views/live_users_view.dart';
import '../../dashboard/views/dashboard_page.dart';
import '../widgets/capsule_orb_nav_bar.dart';
import '../pages/live_page.dart';
import '../pages/rooms_page.dart';
import '../pages/settings_page.dart';
import '../controllers/home_controller.dart';

class HomeView extends GetView<HomeController> {
  const HomeView({super.key});

  @override
  Widget build(BuildContext context) {
    final u = controller.auth.currentUser!;
    final mq = MediaQuery.of(context);
    final reduceMotion = mq.disableAnimations || mq.accessibleNavigation;

    return _HomeShell(
      userName: u.name,
      reduceMotion: reduceMotion,
      onLogout: () async {
        Haptics.medium();
        await controller.logout();
        Get.offAllNamed(Routes.login);
      },
      onGoLive: () async {
        Haptics.medium();
        await showLivePreflightSheet(context, initialTitle: 'Live on Talkieo');
      },
    );
  }
}

class _HomeShell extends StatefulWidget {
  final String userName;
  final bool reduceMotion;
  final Future<void> Function() onLogout;
  final Future<void> Function() onGoLive;

  const _HomeShell({
    required this.userName,
    required this.reduceMotion,
    required this.onLogout,
    required this.onGoLive,
  });

  @override
  State<_HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<_HomeShell> {
  final _page = PageController();
  int _index = 0;
  bool _handlingExitPrompt = false;

  PremiumThemeTokens _tokens() {
    final settings = Get.find<AppSettingsService>();
    return getPremiumThemeTokens(settings.activePremiumThemeVariant);
  }

  @override
  void dispose() {
    _page.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    final bgCtl = AnimatedBackgroundController();
    final appSettings = Get.find<AppSettingsService>();
    final tokens = _tokens();

    return Obx(() {
      final tabs = _buildTabs(appSettings, _index);
      final safeIndex = _index.clamp(0, tabs.length - 1);
      if (safeIndex != _index) {
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (!mounted) return;
          setState(() => _index = safeIndex);
          if (_page.hasClients) {
            _page.jumpToPage(safeIndex);
          }
        });
      }

      return PopScope(
        canPop: false,
        onPopInvokedWithResult: (didPop, _) async {
          if (didPop || _handlingExitPrompt) return;
          _handlingExitPrompt = true;
          try {
            final shouldExit = await _showExitPrompt(context);
            if (shouldExit == true) {
              await SystemNavigator.pop();
            }
          } finally {
            _handlingExitPrompt = false;
          }
        },
        child: Scaffold(
          extendBody: true,
          extendBodyBehindAppBar: true,
          backgroundColor: tokens.backgroundGradient.first,
          appBar: _GlassAppBar(
            userName: widget.userName,
            currentIndex: safeIndex,
            onLogout: widget.onLogout,
            onGoLive: widget.onGoLive,
          ),
          body: Stack(
            children: [
              Positioned.fill(child: AnimatedBackground(controller: bgCtl)),
              Positioned.fill(
                top: kToolbarHeight + mq.padding.top,
                bottom: 0,
                child: PageView(
                  controller: _page,
                  physics: const BouncingScrollPhysics(),
                  onPageChanged: (i) {
                    if (i != _index) Haptics.selection();
                    setState(() => _index = i);
                  },
                  children: tabs.map((tab) => tab.page).toList(growable: false),
                ),
              ),
              Positioned(
                left: 0,
                right: 0,
                bottom: mq.padding.bottom + 6,
                child: Obx(() {
                  final canLive =
                      Get.find<LiveEligibilityService>().canGoLive.value;
                  return Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 10),
                    child: CapsuleOrbNavBar(
                      items: tabs
                          .map(
                            (tab) =>
                                GlassTabItem(icon: tab.icon, label: tab.label),
                          )
                          .toList(growable: false),
                      currentIndex: safeIndex,
                      onChanged: (i) {
                        if (i != _index) HapticFeedback.selectionClick();
                        setState(() => _index = i);
                        _page.animateToPage(
                          i,
                          duration: const Duration(milliseconds: 420),
                          curve: Curves.easeOutCubic,
                        );
                      },
                      showGoLive: appSettings.anyLiveCreationEnabled,
                      activeAccent: tokens.primaryButtonGradient.first,
                      inactiveIcon: tokens.textSecondary.withValues(alpha: .86),
                      goLiveColor: tokens.dangerColor,
                      onGoLive:
                          canLive && appSettings.anyLiveCreationEnabled
                              ? widget.onGoLive
                              : null,
                    ),
                  );
                }),
              ),
            ],
          ),
        ),
      );
    });
  }

  Future<bool?> _showExitPrompt(BuildContext context) {
    final tokens = _tokens();
    return showDialog<bool>(
      context: context,
      barrierDismissible: true,
      builder: (context) {
        return Dialog(
          backgroundColor: Colors.transparent,
          insetPadding: const EdgeInsets.symmetric(horizontal: 22),
          child: ClipRRect(
            borderRadius: BorderRadius.circular(30),
            child: BackdropFilter(
              filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
              child: Container(
                padding: const EdgeInsets.fromLTRB(20, 20, 20, 18),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      tokens.cardGradient.first.withOpacity(.96),
                      tokens.cardGradient.last.withOpacity(.92),
                    ],
                  ),
                  borderRadius: BorderRadius.circular(30),
                  border: Border.all(
                    color: tokens.borderColor.withOpacity(.9),
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: tokens.glowColor.withOpacity(.18),
                      blurRadius: 28,
                      offset: const Offset(0, 18),
                    ),
                  ],
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 52,
                      height: 52,
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(18),
                        gradient: LinearGradient(
                          colors: tokens.primaryButtonGradient,
                        ),
                      ),
                      child: Icon(
                        Icons.exit_to_app_rounded,
                        color: tokens.textPrimary,
                        size: 26,
                      ),
                    ),
                    const SizedBox(height: 16),
                    Text(
                      'Exit Talkieo?',
                      style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        color: tokens.textPrimary,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'You are on the main screen. Do you want to close the app now?',
                      style: TextStyle(
                        color: tokens.textSecondary.withOpacity(.92),
                        fontWeight: FontWeight.w600,
                        height: 1.45,
                      ),
                    ),
                    const SizedBox(height: 18),
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton(
                            onPressed: () => Navigator.of(context).pop(false),
                            style: OutlinedButton.styleFrom(
                              foregroundColor: tokens.textPrimary,
                              side: BorderSide(
                                color: tokens.borderColor.withOpacity(.9),
                              ),
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(18),
                              ),
                            ),
                            child: const Text('Stay'),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: FilledButton(
                            onPressed: () => Navigator.of(context).pop(true),
                            style: FilledButton.styleFrom(
                              backgroundColor: tokens.dangerColor,
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(18),
                              ),
                            ),
                            child: const Text('Exit'),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  List<_HomeTabSpec> _buildTabs(AppSettingsService settings, int currentIndex) {
    final tabs = <_HomeTabSpec>[];

    void addTab({
      required IconData icon,
      required String label,
      required Widget Function(bool isActive) pageBuilder,
    }) {
      final tabIndex = tabs.length;
      tabs.add(
        _HomeTabSpec(
          icon: icon,
          label: label,
          page: pageBuilder(currentIndex == tabIndex),
        ),
      );
    }

    if (settings.videoRoomsEnabled) {
      addTab(
        icon: Icons.ondemand_video_rounded,
        label: 'Video Rooms',
        pageBuilder: (_) => const RoomsPage(bottomPadding: 120),
      );
    }
    if (settings.audioRoomsEnabled) {
      addTab(
        icon: Icons.graphic_eq_rounded,
        label: 'Audio Rooms',
        pageBuilder:
            (_) => const LivePage(bottomPadding: 120, bannerPlacement: 'home'),
      );
    }
    if (settings.hostCallingEnabled) {
      addTab(
        icon: Icons.people_alt_rounded,
        label: 'Live Users',
        pageBuilder: (_) => const LiveUsersView(),
      );
    }

    addTab(
      icon: Icons.workspace_premium_rounded,
      label: 'Dashboard',
      pageBuilder:
          (isActive) => DashboardPage(
            bottomPadding: 120,
            isActive: isActive,
          ),
    );

    addTab(
      icon: Icons.tune_rounded,
      label: 'Settings',
      pageBuilder: (_) => const SettingsPage(bottomPadding: 120),
    );

    return tabs;
  }
}

class _HomeTabSpec {
  const _HomeTabSpec({
    required this.icon,
    required this.label,
    required this.page,
  });

  final IconData icon;
  final String label;
  final Widget page;
}

/* ───────────────────────── AppBar ───────────────────────── */

class _GlassAppBar extends StatelessWidget implements PreferredSizeWidget {
  final String userName;
  final int currentIndex;
  final Future<void> Function() onLogout;
  final Future<void> Function() onGoLive;

  const _GlassAppBar({
    required this.userName,
    required this.currentIndex,
    required this.onLogout,
    required this.onGoLive,
  });

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  @override
  Widget build(BuildContext context) {
    final liveUsersController = Get.find<LiveUsersController>();

    return Obx(() {
      final tokens = getPremiumThemeTokens(
        Get.find<AppSettingsService>().activePremiumThemeVariant,
      );
      final canGoLive =
          Get.find<LiveEligibilityService>().canGoLive.value;
      final showAvailabilityToggle =
          canGoLive ||
          liveUsersController.isHost ||
          Get.find<HomeController>().canGoLive;

      return AppBar(
        automaticallyImplyLeading: false,
        backgroundColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        surfaceTintColor: Colors.transparent,
        titleSpacing: 12,
        title: Row(
          children: [
            Hero(
              tag: 'brand.logo',
              child: TalkeeLogo(
                size: 28,
                showWordmark: true,
                wordmarkBelow: false,
                wordmarkStyle:
                    Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: tokens.textPrimary,
                    ),
              ),
            ),
            const Spacer(),
          ],
        ),
        actions: [
          if (showAvailabilityToggle)
            Padding(
              padding: const EdgeInsets.only(right: 4),
              child: Center(
                child: Obx(() {
                  final online = liveUsersController.hostAppearsLive;
                  final busy = liveUsersController.togglingHostStatus.value;
                  return Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        online
                            ? Icons.wifi_tethering_rounded
                            : Icons.wifi_tethering_off_rounded,
                        color:
                            online
                                ? tokens.primaryButtonGradient.first
                                : tokens.textSecondary.withValues(alpha: .84),
                        size: 18,
                      ),
                      const SizedBox(width: 6),
                      Switch.adaptive(
                        value: online,
                        onChanged:
                            busy
                                ? null
                                : (_) => liveUsersController.toggleHostStatus(),
                        activeColor: tokens.primaryButtonGradient.first,
                      ),
                    ],
                  );
                }),
              ),
            ),
          const BellBadgeButton(),
        ],
        flexibleSpace: ClipRect(
          child: BackdropFilter(
            filter: ImageFilter.blur(sigmaX: 10, sigmaY: 10),
            child: Container(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [
                    tokens.cardGradient.first.withValues(alpha: .84),
                    tokens.cardGradient.last.withValues(alpha: .72),
                  ],
                ),
                border: Border(
                  bottom: BorderSide(
                    color: tokens.borderColor.withValues(alpha: .72),
                  ),
                ),
              ),
            ),
          ),
        ),
      );
    });
  }
}
