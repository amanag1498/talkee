import 'package:flutter/material.dart';
import 'package:get/get.dart';
import '../theme/brand.dart';
import 'live_pill.dart';
import '../../services/app_settings_service.dart';

class StreamCard extends StatelessWidget {
  final String title;
  final String host;
  final int viewers;
  final VoidCallback? onTap;
  final Color tint; // gradient tint color
  final String? avatarUrl;

  const StreamCard({
    super.key,
    required this.title,
    required this.host,
    required this.viewers,
    this.onTap,
    this.tint = kTalkeePrimary,
    this.avatarUrl,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: onTap,
      child: Ink(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          gradient: LinearGradient(
            colors: [
              tint.withOpacity(.85),
              tokens.primaryButtonGradient.last.withValues(alpha: .92),
            ],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: Stack(
          children: [
            // vignette
            Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(16),
                  gradient: LinearGradient(
                    colors: [
                      Colors.black.withOpacity(.1),
                      Colors.black.withOpacity(.35),
                    ],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                ),
              ),
            ),

            // LIVE & viewers
            Positioned(
              top: 10,
              left: 10,
              child: LiveEqBadge(height: 18, color: const Color(0xFFFF2D55)),
            ),
            Positioned(
              top: 10,
              right: 10,
              child: _Chip(
                icon: Icons.visibility_rounded,
                label: _fmtViewers(viewers),
              ),
            ),

            // bottom title + host
            Positioned(
              left: 12,
              right: 12,
              bottom: 12,
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 16,
                    backgroundColor: Colors.white.withOpacity(.25),
                    backgroundImage:
                        (avatarUrl != null && avatarUrl!.isNotEmpty)
                            ? NetworkImage(avatarUrl!)
                            : null,
                    child:
                        (avatarUrl == null || avatarUrl!.isEmpty)
                            ? const Icon(
                              Icons.person,
                              size: 18,
                              color: Colors.white,
                            )
                            : null,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          title,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w800,
                            fontSize: 16,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'by $host',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            color: Colors.white.withOpacity(.9),
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  static String _fmtViewers(int v) {
    if (v >= 1000000) return '${(v / 1000000).toStringAsFixed(1)}M';
    if (v >= 1000) return '${(v / 1000).toStringAsFixed(1)}K';
    return '$v';
  }
}

class _Chip extends StatelessWidget {
  final IconData icon;
  final String label;
  const _Chip({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: tokens.glassColor.withValues(alpha: .18),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor.withValues(alpha: .68)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: Colors.white, size: 16),
          const SizedBox(width: 6),
          Text(
            label,
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
    );
  }
}
