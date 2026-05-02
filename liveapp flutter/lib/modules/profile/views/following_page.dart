import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';
import '../../calls/controllers/call_controller.dart';
import '../controllers/host_follow_controller.dart';

PremiumThemeTokens _followingTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class FollowingPage extends StatefulWidget {
  const FollowingPage({super.key});

  @override
  State<FollowingPage> createState() => _FollowingPageState();
}

class _FollowingPageState extends State<FollowingPage> {
  late final HostFollowController controller;
  late final AppCallController callController;

  @override
  void initState() {
    super.initState();
    controller = Get.find<HostFollowController>();
    callController = Get.find<AppCallController>();
    controller.loadFollowing();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _followingTokens();
    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        title: Text(
          'Following',
          style: TextStyle(
            color: tokens.textPrimary,
            fontWeight: FontWeight.w800,
          ),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: tokens.textPrimary,
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                tokens.cardGradient.first.withOpacity(.84),
                tokens.cardGradient.last.withOpacity(.72),
              ],
            ),
            border: Border(
              bottom: BorderSide(color: tokens.borderColor.withOpacity(.72)),
            ),
          ),
        ),
      ),
      body: Obx(() {
        if (controller.listLoading.value && controller.followingHosts.isEmpty) {
          return Center(
            child: CircularProgressIndicator(
              color: tokens.primaryButtonGradient.first,
            ),
          );
        }

        if (controller.listError.value != null && controller.followingHosts.isEmpty) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(
                    Icons.error_outline_rounded,
                    color: tokens.textSecondary,
                    size: 34,
                  ),
                  const SizedBox(height: 12),
                  Text(
                    controller.listError.value!,
                    textAlign: TextAlign.center,
                    style: TextStyle(color: tokens.textPrimary),
                  ),
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: controller.loadFollowing,
                    style: FilledButton.styleFrom(
                      backgroundColor: tokens.primaryButtonGradient.first,
                      foregroundColor: tokens.textPrimary,
                    ),
                    child: const Text('Retry'),
                  ),
                ],
              ),
            ),
          );
        }

        if (controller.followingHosts.isEmpty) {
          return Center(
            child: Text(
              'No followed hosts yet.',
              style: TextStyle(color: tokens.textSecondary),
            ),
          );
        }

        return ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: controller.followingHosts.length,
          separatorBuilder: (_, __) => const SizedBox(height: 12),
          itemBuilder: (context, index) {
            final item = controller.followingHosts[index];
            final hostId = ((item['host_profile'] as Map?)?['host_id'] as num?)?.toInt() ?? (item['host_id'] as num?)?.toInt() ?? 0;
            final available = item['is_available'] == true || ((item['availability'] as Map?)?['is_available'] == true);
            final audioRate = item['audio_call_rate_per_minute']?.toString() ?? '0';
            final videoRate = item['video_call_rate_per_minute']?.toString() ?? '0';

            return Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: tokens.cardGradient,
                ),
                borderRadius: BorderRadius.circular(22),
                border: Border.all(color: tokens.borderColor),
              ),
              child: Column(
                children: [
                  Row(
                    children: [
                      CircleAvatar(
                        radius: 24,
                        backgroundColor: tokens.cardGradient.first,
                        backgroundImage: ((item['avatar_url'] ?? '').toString().isNotEmpty)
                            ? NetworkImage(item['avatar_url'].toString())
                            : null,
                        child: ((item['avatar_url'] ?? '').toString().isNotEmpty)
                            ? null
                            : Text((item['name'] ?? 'H').toString().substring(0, 1).toUpperCase()),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              (item['name'] ?? 'Host').toString(),
                              style: TextStyle(
                                color: tokens.textPrimary,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              available ? 'Available now' : 'Currently unavailable',
                              style: TextStyle(
                                color: available
                                    ? tokens.successColor
                                    : tokens.textSecondary,
                              ),
                            ),
                          ],
                        ),
                      ),
                      TextButton(
                        onPressed: controller.isBusy(hostId)
                            ? null
                            : () => controller.toggleForHost(
                                  hostId: hostId,
                                  current: true,
                                  currentCount: (item['follower_count'] as num?)?.toInt(),
                                ),
                        child: const Text('Unfollow'),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  Row(
                    children: [
                      Expanded(child: _RatePill(label: 'Audio', value: '$audioRate / min')),
                      const SizedBox(width: 10),
                      Expanded(child: _RatePill(label: 'Video', value: '$videoRate / min')),
                    ],
                  ),
                  const SizedBox(height: 14),
                  Row(
                    children: [
                      Expanded(
                        child: FilledButton.tonal(
                          onPressed: available ? () => callController.placeCall(receiverId: (item['id'] as num).toInt(), type: 'audio') : null,
                          style: FilledButton.styleFrom(
                            foregroundColor: tokens.textPrimary,
                            backgroundColor: tokens.chipColor.withOpacity(.82),
                          ),
                          child: const Text('Audio Call'),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: FilledButton(
                          onPressed: available ? () => callController.placeCall(receiverId: (item['id'] as num).toInt(), type: 'video') : null,
                          style: FilledButton.styleFrom(
                            backgroundColor: tokens.primaryButtonGradient.first,
                            foregroundColor: tokens.textPrimary,
                          ),
                          child: const Text('Video Call'),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            );
          },
        );
      }),
    );
  }
}

class _RatePill extends StatelessWidget {
  const _RatePill({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final tokens = _followingTokens();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        color: tokens.chipColor.withOpacity(.74),
        border: Border.all(color: tokens.borderColor),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: TextStyle(color: tokens.textSecondary, fontSize: 12)),
          const SizedBox(height: 4),
          Text(
            value,
            style: TextStyle(color: tokens.textPrimary, fontWeight: FontWeight.w700),
          ),
        ],
      ),
    );
  }
}
