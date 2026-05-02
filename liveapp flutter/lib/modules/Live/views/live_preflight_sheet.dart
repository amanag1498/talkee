import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:permission_handler/permission_handler.dart';

import '../../../../app/routes/app_routes.dart';
import '../../../../app/theme/brand.dart';
import '../../../../app/widgets/haptics.dart';
import '../../../../services/app_settings_service.dart';
import '../services/live_service.dart';
import 'video_call_page.dart';

Future<void> showLivePreflightSheet(
  BuildContext context, {
  String initialTitle = 'Live on Talkee',
}) async {
  final live = Get.find<LiveService>();
  final appSettings = Get.find<AppSettingsService>();
  if (!appSettings.anyLiveCreationEnabled) {
    Get.snackbar(
      'Live unavailable',
      'Live creation is currently disabled by the platform.',
      snackPosition: SnackPosition.BOTTOM,
    );
    return;
  }

  String roomType = appSettings.videoRoomsEnabled ? 'video' : 'audio';
  bool micOn = true;
  bool camOn = true;
  bool loading = false;
  String? err;

  await showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (sheetContext) {
      return StatefulBuilder(
        builder: (context, setSheetState) {
          final tokens = getPremiumThemeTokens(
            Get.find<AppSettingsService>().activePremiumThemeVariant,
          );
          Future<void> startLive() async {
            if (loading) return;
            setSheetState(() {
              loading = true;
              err = null;
            });
            try {
              if (roomType == 'video' && !appSettings.videoRoomsEnabled) {
                throw Exception('Video live is currently unavailable.');
              }
              if (roomType == 'audio' && !appSettings.audioRoomsEnabled) {
                throw Exception('Audio rooms are currently unavailable.');
              }
              final mic = await Permission.microphone.request();
              if (!mic.isGranted) {
                throw Exception('Microphone permission is required.');
              }
              if (roomType == 'video') {
                final cam = await Permission.camera.request();
                if (!cam.isGranted) {
                  throw Exception(
                    'Camera permission is required for video live.',
                  );
                }
              }

              final room =
                  roomType == 'audio'
                      ? await live.createAudioRoom(title: initialTitle)
                      : await live.createOrStart(title: initialTitle);
              if (context.mounted) Navigator.of(sheetContext).pop();
              Haptics.success();
              if (roomType == 'audio') {
                await Get.toNamed(
                  Routes.liveAudio,
                  arguments: {'room': room, 'initial_mic_on': micOn},
                );
              } else {
                Get.to(
                  () => VideoCallPage(
                    room: room,
                    live: live,
                    initialMicOn: micOn,
                    initialCamOn: camOn,
                  ),
                  transition: Transition.cupertino,
                  duration: const Duration(milliseconds: 420),
                  curve: Curves.easeOutCubic,
                );
              }
            } catch (e) {
              setSheetState(() {
                loading = false;
                err = '$e';
              });
            }
          }

          return SafeArea(
            top: false,
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                12,
                0,
                12,
                12 + MediaQuery.of(context).viewInsets.bottom,
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(24),
                child: BackdropFilter(
                  filter: ImageFilter.blur(sigmaX: 12, sigmaY: 12),
                  child: Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(24),
                      gradient: LinearGradient(
                        begin: Alignment.topLeft,
                        end: Alignment.bottomRight,
                        colors: [
                          tokens.cardGradient.first.withOpacity(.94),
                          tokens.cardGradient.last.withOpacity(.92),
                        ],
                      ),
                      border: Border.all(color: tokens.borderColor),
                    ),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Quick Live Setup',
                          style: TextStyle(
                            color: tokens.textPrimary,
                            fontSize: 20,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Check your setup and start instantly.',
                          style: TextStyle(
                            color: tokens.textSecondary.withOpacity(.86),
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        const SizedBox(height: 14),
                        Row(
                          children: [
                            if (appSettings.videoRoomsEnabled)
                              Expanded(
                                child: _ModeChip(
                                  icon: Icons.videocam_rounded,
                                  label: 'Video Live',
                                  selected: roomType == 'video',
                                  onTap:
                                      () => setSheetState(
                                        () => roomType = 'video',
                                      ),
                                ),
                              ),
                            if (appSettings.videoRoomsEnabled &&
                                appSettings.audioRoomsEnabled)
                              const SizedBox(width: 8),
                            if (appSettings.audioRoomsEnabled)
                              Expanded(
                                child: _ModeChip(
                                  icon: Icons.graphic_eq_rounded,
                                  label: 'Audio Room',
                                  selected: roomType == 'audio',
                                  onTap:
                                      () => setSheetState(
                                        () => roomType = 'audio',
                                      ),
                                ),
                              ),
                          ],
                        ),
                        const SizedBox(height: 14),
                        Text(
                          roomType == 'audio'
                              ? 'Audio rooms use microphone access only.'
                              : 'Camera and microphone permissions will be requested before your live starts.',
                          style: TextStyle(
                            color: tokens.textSecondary.withOpacity(.82),
                            fontWeight: FontWeight.w600,
                            fontSize: 12,
                          ),
                        ),
                        if (err != null) ...[
                          const SizedBox(height: 12),
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.symmetric(
                              horizontal: 10,
                              vertical: 8,
                            ),
                            decoration: BoxDecoration(
                              color: tokens.dangerColor.withOpacity(.16),
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(
                                color: tokens.dangerColor.withOpacity(.34),
                              ),
                            ),
                            child: Text(
                              err!,
                              style: TextStyle(
                                color: tokens.textPrimary,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ),
                        ],
                        const SizedBox(height: 14),
                        Row(
                          children: [
                            Expanded(
                              child: FilledButton.icon(
                                onPressed: loading ? null : startLive,
                                style: FilledButton.styleFrom(
                                  backgroundColor:
                                      tokens.primaryButtonGradient.first,
                                  foregroundColor: tokens.textPrimary,
                                  padding: const EdgeInsets.symmetric(
                                    vertical: 13,
                                  ),
                                ),
                                icon:
                                    loading
                                        ? const SizedBox(
                                          width: 16,
                                          height: 16,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                            color: Colors.white,
                                          ),
                                        )
                                        : Icon(
                                          roomType == 'audio'
                                              ? Icons.graphic_eq_rounded
                                              : Icons.podcasts_rounded,
                                        ),
                                label: Text(
                                  loading
                                      ? 'Starting...'
                                      : (roomType == 'audio'
                                          ? 'Start Audio Room'
                                          : 'Start Live'),
                                ),
                              ),
                            ),
                            const SizedBox(width: 8),
                            OutlinedButton(
                              onPressed:
                                  loading
                                      ? null
                                      : () => Navigator.of(sheetContext).pop(),
                              style: OutlinedButton.styleFrom(
                                side: BorderSide(
                                  color: tokens.borderColor.withOpacity(.72),
                                ),
                                foregroundColor:
                                    tokens.textSecondary.withOpacity(.88),
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 14,
                                  vertical: 13,
                                ),
                              ),
                              child: const Text('Cancel'),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          );
        },
      );
    },
  );
}

class _ModeChip extends StatelessWidget {
  final IconData icon;
  final String label;
  final bool selected;
  final VoidCallback onTap;

  const _ModeChip({
    required this.icon,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color:
              selected
                  ? tokens.primaryButtonGradient.first.withOpacity(.22)
                  : tokens.glassColor.withOpacity(.52),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color:
                selected
                    ? tokens.primaryButtonGradient.first.withOpacity(.7)
                    : tokens.borderColor.withOpacity(.82),
          ),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 18, color: tokens.textPrimary),
            const SizedBox(width: 8),
            Text(
              label,
              style: TextStyle(
                color: tokens.textPrimary,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
