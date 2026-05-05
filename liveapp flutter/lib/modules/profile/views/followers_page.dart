import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import '../../../app/theme/brand.dart';
import '../../../app/utils/profile_frame_payload.dart';
import '../../../app/widgets/framed_avatar.dart';
import '../../../services/app_settings_service.dart';
import '../controllers/host_follow_controller.dart';

PremiumThemeTokens _followersTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class FollowersPage extends StatefulWidget {
  const FollowersPage({super.key});

  @override
  State<FollowersPage> createState() => _FollowersPageState();
}

class _FollowersPageState extends State<FollowersPage> {
  late final HostFollowController controller;

  @override
  void initState() {
    super.initState();
    controller = Get.find<HostFollowController>();
    controller.loadFollowers();
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _followersTokens();
    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        title: Text(
          'Followers',
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
        if (controller.listLoading.value && controller.followers.isEmpty) {
          return Center(
            child: CircularProgressIndicator(
              color: tokens.primaryButtonGradient.first,
            ),
          );
        }

        if (controller.listError.value != null && controller.followers.isEmpty) {
          return Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Text(
                controller.listError.value!,
                textAlign: TextAlign.center,
                style: TextStyle(color: tokens.textPrimary),
              ),
            ),
          );
        }

        if (controller.followers.isEmpty) {
          return Center(
            child: Text(
              'No followers yet.',
              style: TextStyle(color: tokens.textSecondary),
            ),
          );
        }

        return ListView.separated(
          padding: const EdgeInsets.all(16),
          itemCount: controller.followers.length,
          separatorBuilder: (_, __) => const SizedBox(height: 12),
          itemBuilder: (context, index) {
            final item = controller.followers[index];
            final followedAt = DateTime.tryParse((item['followed_at'] ?? '').toString());
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
              child: Row(
                children: [
                  SizedBox(
                    width: 48,
                    height: 48,
                    child: FramedAvatar(
                      avatarUrl: item['avatar_url']?.toString(),
                      frameUrl: profileFrameAssetUrlFromPayload(item),
                      label: (item['name'] ?? 'U').toString(),
                      size: 48,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          (item['name'] ?? 'User').toString(),
                          style: TextStyle(
                            color: tokens.textPrimary,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          followedAt == null ? 'Recently followed' : 'Followed ${DateFormat('dd MMM yyyy').format(followedAt)}',
                          style: TextStyle(color: tokens.textSecondary),
                        ),
                      ],
                    ),
                  ),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      _PrefChip(
                        label: item['notify_when_online'] == true ? 'Online alerts' : 'No online alerts',
                      ),
                      const SizedBox(height: 6),
                      _PrefChip(
                        label: item['notify_when_available'] == true ? 'Available alerts' : 'No available alerts',
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

class _PrefChip extends StatelessWidget {
  const _PrefChip({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final tokens = _followersTokens();
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: tokens.chipColor.withOpacity(.76),
        border: Border.all(color: tokens.borderColor),
      ),
      child: Text(
        label,
        style: TextStyle(color: tokens.textSecondary, fontSize: 12),
      ),
    );
  }
}
