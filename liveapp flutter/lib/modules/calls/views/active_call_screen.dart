import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:livekit_client/livekit_client.dart';

import '../../../app/theme/brand.dart';
import '../../../app/widgets/haptics.dart';
import '../../../services/app_settings_service.dart';
import '../../../services/auth_service.dart';
import '../../profile/controllers/host_follow_controller.dart';
import '../controllers/call_controller.dart';
import 'call_ui.dart';

class ActiveCallScreen extends StatefulWidget {
  const ActiveCallScreen({super.key});

  @override
  State<ActiveCallScreen> createState() => _ActiveCallScreenState();
}

class _ActiveCallScreenState extends State<ActiveCallScreen>
    with SingleTickerProviderStateMixin {
  final AppCallController controller = Get.find<AppCallController>();
  final AuthService auth = Get.find<AuthService>();

  late final AnimationController _pulseController;

  @override
  void initState() {
    super.initState();
    Haptics.light();
    controller.ensureRoomConnected();
    controller.callMinimized.value = false;

    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1800),
    )..repeat();
  }

  @override
  void dispose() {
    _pulseController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) {
          controller.showBackOptions();
        }
      },
      child: CallScaffold(
        child: Obx(() {
          controller.roomRevision.value;
          final call = controller.activeCall.value ?? <String, dynamic>{};
          final isAudio = controller.isAudioCall;
          final receiverId = (call['receiver_id'] as num?)?.toInt() ?? 0;
          final canFollow = receiverId > 0 && receiverId != auth.currentUser?.id;

          if (isAudio) {
            return _PremiumAudioCallView(
              pulseController: _pulseController,
              name: controller.remoteDisplayName,
              avatarUrl: controller.remoteAvatarUrl,
              statusText: _statusText(),
              durationLabel: controller.durationLabel,
              ratePerMinute: controller.ratePerMinute,
              estimatedCoins: controller.estimatedCoins,
              connectionLabel: controller.roomConnecting.value
                  ? 'Connecting'
                  : controller.reconnecting.value
                      ? 'Recovering'
                      : 'Stable',
              micOn: controller.micOn.value,
              speakerOn: controller.speakerOn.value,
              busy: controller.busy.value,
              canFollow: canFollow,
              receiverId: receiverId,
              onBack: controller.showBackOptions,
              onMinimize: controller.minimizeCall,
              onMute: controller.toggleMute,
              onSpeaker: controller.toggleSpeaker,
              onEnd: controller.endActiveCall,
            );
          }

          final localPreviewName = auth.currentUser?.name.trim().isNotEmpty == true
              ? auth.currentUser!.name.trim()
              : 'You';

          return _PremiumVideoCallView(
            remoteTrack: controller.primaryRemoteVideoTrack,
            localTrack: controller.localVideoTrack,
            name: controller.remoteDisplayName,
            avatarUrl: controller.remoteAvatarUrl,
            localPreviewName: localPreviewName,
            statusText: _statusText(),
            durationLabel: controller.durationLabel,
            micOn: controller.micOn.value,
            speakerOn: controller.speakerOn.value,
            camOn: controller.camOn.value,
            busy: controller.busy.value,
            canFollow: canFollow,
            receiverId: receiverId,
            roomError: controller.roomError.value,
            onBack: controller.showBackOptions,
            onMinimize: controller.minimizeCall,
            onMute: controller.toggleMute,
            onSpeaker: controller.toggleSpeaker,
            onCamera: controller.toggleCamera,
            onEnd: controller.endActiveCall,
          );
        }),
      ),
    );
  }

  String _statusText() {
    if (controller.roomConnecting.value) return 'Connecting secure media';
    if (controller.reconnecting.value) return 'Reconnecting to the call';
    switch (controller.callState.value) {
      case 'accepted':
        return 'Connected';
      case 'reconnecting':
        return 'Recovering network';
      default:
        return 'Connected';
    }
  }
}

class _PremiumAudioCallView extends StatelessWidget {
  const _PremiumAudioCallView({
    required this.pulseController,
    required this.name,
    required this.avatarUrl,
    required this.statusText,
    required this.durationLabel,
    required this.ratePerMinute,
    required this.estimatedCoins,
    required this.connectionLabel,
    required this.micOn,
    required this.speakerOn,
    required this.busy,
    required this.canFollow,
    required this.receiverId,
    required this.onBack,
    required this.onMinimize,
    required this.onMute,
    required this.onSpeaker,
    required this.onEnd,
  });

  final AnimationController pulseController;
  final String name;
  final String avatarUrl;
  final String statusText;
  final String durationLabel;
  final int ratePerMinute;
  final int estimatedCoins;
  final String connectionLabel;
  final bool micOn;
  final bool speakerOn;
  final bool busy;
  final bool canFollow;
  final int receiverId;
  final VoidCallback onBack;
  final VoidCallback onMinimize;
  final VoidCallback onMute;
  final VoidCallback onSpeaker;
  final VoidCallback onEnd;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final h = constraints.maxHeight;
        final w = constraints.maxWidth;
        final compact = h < 760 || w < 370;
        final topPadding = compact ? 8.0 : 10.0;
        final bottomPadding = compact ? 16.0 : 22.0;
        final heroGap = compact ? 12.0 : 18.0;
        final textGap = compact ? 12.0 : 22.0;
        final waveformGap = compact ? 12.0 : 20.0;
        final controlsGap = compact ? 14.0 : 18.0;
        return Stack(
          children: [
            const Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [
                      Color(0xFF06111F),
                      Color(0xFF17295A),
                      Color(0xFF090613),
                    ],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                ),
              ),
            ),
            const Positioned(
              top: -90,
              right: -80,
              child: _GlowBlob(color: Color(0xFF4F8CFF), size: 260),
            ),
            const Positioned(
              bottom: 80,
              left: -90,
              child: _GlowBlob(color: Color(0xFF8F6BFF), size: 260),
            ),
            SafeArea(
              child: Padding(
                padding: EdgeInsets.fromLTRB(18, topPadding, 18, bottomPadding),
                child: Column(
                  children: [
                    _PremiumTopBar(
                      title: 'Audio Call',
                      subtitle: '$durationLabel • $connectionLabel',
                      onBack: onBack,
                      onMinimize: onMinimize,
                      compact: compact,
                    ),
                    SizedBox(height: heroGap),
                    Expanded(
                      child: Column(
                        children: [
                          const Spacer(flex: 2),
                          _PremiumAudioAvatar(
                            pulseController: pulseController,
                            name: name,
                            avatarUrl: avatarUrl,
                            compact: compact,
                          ),
                          SizedBox(height: compact ? 12 : 18),
                          FittedBox(
                            fit: BoxFit.scaleDown,
                            child: Text(
                              name,
                              maxLines: 1,
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: compact ? 24 : 30,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            statusText,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: Colors.white.withValues(alpha: .68),
                              fontSize: compact ? 12 : 14,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          SizedBox(height: textGap),
                          FittedBox(
                            fit: BoxFit.scaleDown,
                            child: Text(
                              durationLabel,
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: compact ? 28 : 36,
                                fontWeight: FontWeight.w900,
                                letterSpacing: 1.2,
                              ),
                            ),
                          ),
                          SizedBox(height: waveformGap),
                          const AudioWaveform(accent: Color(0xFF8F6BFF)),
                          SizedBox(height: waveformGap),
                          _PremiumInfoCard(
                            ratePerMinute: ratePerMinute,
                            estimatedCoins: estimatedCoins,
                            connectionLabel: connectionLabel,
                            compact: compact,
                          ),
                          if (canFollow) ...[
                            SizedBox(height: compact ? 10 : 14),
                            _CallFollowButton(receiverId: receiverId),
                          ],
                          const Spacer(),
                        ],
                      ),
                    ),
                    Row(
                      children: [
                        Expanded(
                          child: _GlassControlButton(
                            icon: micOn ? Icons.mic_rounded : Icons.mic_off_rounded,
                            label: micOn ? 'Mute' : 'Unmute',
                            active: micOn,
                            compact: compact,
                            onTap: onMute,
                          ),
                        ),
                        SizedBox(width: compact ? 10 : 12),
                        Expanded(
                          child: _GlassControlButton(
                            icon: speakerOn
                                ? Icons.volume_up_rounded
                                : Icons.hearing_disabled_rounded,
                            label: speakerOn ? 'Speaker' : 'Earpiece',
                            active: speakerOn,
                            compact: compact,
                            onTap: onSpeaker,
                          ),
                        ),
                      ],
                    ),
                    SizedBox(height: controlsGap),
                    _EndCallButton(
                      busy: busy,
                      compact: compact,
                      onTap: onEnd,
                    ),
                  ],
                ),
              ),
            ),
          ],
        );
      },
    );
  }
}

class _PremiumVideoCallView extends StatelessWidget {
  const _PremiumVideoCallView({
    required this.remoteTrack,
    required this.localTrack,
    required this.name,
    required this.avatarUrl,
    required this.localPreviewName,
    required this.statusText,
    required this.durationLabel,
    required this.micOn,
    required this.speakerOn,
    required this.camOn,
    required this.busy,
    required this.canFollow,
    required this.receiverId,
    required this.roomError,
    required this.onBack,
    required this.onMinimize,
    required this.onMute,
    required this.onSpeaker,
    required this.onCamera,
    required this.onEnd,
  });

  final VideoTrack? remoteTrack;
  final VideoTrack? localTrack;
  final String name;
  final String avatarUrl;
  final String localPreviewName;
  final String statusText;
  final String durationLabel;
  final bool micOn;
  final bool speakerOn;
  final bool camOn;
  final bool busy;
  final bool canFollow;
  final int receiverId;
  final String roomError;
  final VoidCallback onBack;
  final VoidCallback onMinimize;
  final VoidCallback onMute;
  final VoidCallback onSpeaker;
  final VoidCallback onCamera;
  final VoidCallback onEnd;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final compact = constraints.maxHeight < 720 || constraints.maxWidth < 360;
        return Stack(
          children: [
            Positioned.fill(
              child: remoteTrack != null
                  ? VideoTrackRenderer(remoteTrack!, fit: VideoViewFit.cover)
                  : _VideoFallback(
                      name: name,
                      avatarUrl: avatarUrl,
                      statusText: statusText,
                    ),
            ),
            Positioned.fill(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [
                      Colors.black.withValues(alpha: .72),
                      Colors.black.withValues(alpha: .10),
                      Colors.black.withValues(alpha: .82),
                    ],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                ),
              ),
            ),
            SafeArea(
              child: Padding(
                padding: EdgeInsets.fromLTRB(16, compact ? 8 : 10, 16, compact ? 14 : 22),
                child: Column(
                  children: [
                    _PremiumTopBar(
                      title: name,
                      subtitle: '$durationLabel • $statusText',
                      onBack: onBack,
                      onMinimize: onMinimize,
                      compact: compact,
                      trailing: canFollow
                          ? _CallFollowButton(receiverId: receiverId, compact: true)
                          : null,
                    ),
                    if (roomError.isNotEmpty) ...[
                      SizedBox(height: compact ? 8 : 12),
                      _CallErrorBanner(text: roomError),
                    ],
                    const Spacer(),
                    Align(
                      alignment: Alignment.centerRight,
                      child: _LocalVideoPreview(
                        width: compact ? 88 : 108,
                        height: compact ? 128 : 156,
                        localTrack: localTrack,
                        name: localPreviewName,
                      ),
                    ),
                    SizedBox(height: compact ? 12 : 18),
                    _VideoBottomControls(
                      micOn: micOn,
                      speakerOn: speakerOn,
                      camOn: camOn,
                      busy: busy,
                      compact: compact,
                      onMute: onMute,
                      onSpeaker: onSpeaker,
                      onCamera: onCamera,
                      onEnd: onEnd,
                    ),
                  ],
                ),
              ),
            ),
          ],
        );
      },
    );
  }
}

class _PremiumTopBar extends StatelessWidget {
  const _PremiumTopBar({
    required this.title,
    required this.subtitle,
    required this.onBack,
    required this.onMinimize,
    this.trailing,
    this.compact = false,
  });

  final String title;
  final String subtitle;
  final VoidCallback onBack;
  final VoidCallback onMinimize;
  final Widget? trailing;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.all(compact ? 7 : 9),
      decoration: BoxDecoration(
        color: Colors.black.withValues(alpha: .28),
        borderRadius: BorderRadius.circular(compact ? 22 : 26),
        border: Border.all(color: Colors.white.withValues(alpha: .12)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: .24),
            blurRadius: 24,
            offset: const Offset(0, 14),
          ),
        ],
      ),
      child: Row(
        children: [
          _TopActionButton(
            icon: Icons.keyboard_arrow_down_rounded,
            compact: compact,
            onTap: onBack,
          ),
          SizedBox(width: compact ? 10 : 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title.isEmpty ? 'Active Call' : title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: compact ? 15 : 16,
                    fontWeight: FontWeight.w900,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  subtitle,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: .66),
                    fontSize: compact ? 11 : 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
          if (trailing != null) ...[
            const SizedBox(width: 8),
            trailing!,
          ],
          const SizedBox(width: 8),
          _TopActionButton(
            icon: Icons.picture_in_picture_alt_rounded,
            compact: compact,
            onTap: onMinimize,
          ),
        ],
      ),
    );
  }
}

class _VideoBottomControls extends StatelessWidget {
  const _VideoBottomControls({
    required this.micOn,
    required this.speakerOn,
    required this.camOn,
    required this.busy,
    required this.onMute,
    required this.onSpeaker,
    required this.onCamera,
    required this.onEnd,
    this.compact = false,
  });

  final bool micOn;
  final bool speakerOn;
  final bool camOn;
  final bool busy;
  final VoidCallback onMute;
  final VoidCallback onSpeaker;
  final VoidCallback onCamera;
  final VoidCallback onEnd;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.fromLTRB(compact ? 10 : 14, compact ? 10 : 14, compact ? 10 : 14, compact ? 10 : 12),
      decoration: BoxDecoration(
        color: Colors.black.withValues(alpha: .42),
        borderRadius: BorderRadius.circular(compact ? 28 : 34),
        border: Border.all(color: Colors.white.withValues(alpha: .12)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: .30),
            blurRadius: 26,
            offset: const Offset(0, 16),
          ),
        ],
      ),
      child: Row(
        children: [
          Expanded(
            child: _CircleControl(
              icon: micOn ? Icons.mic_rounded : Icons.mic_off_rounded,
              label: micOn ? 'Mute' : 'Unmute',
              active: micOn,
              compact: compact,
              onTap: onMute,
            ),
          ),
          Expanded(
            child: _CircleControl(
              icon: speakerOn ? Icons.volume_up_rounded : Icons.hearing_disabled_rounded,
              label: speakerOn ? 'Speaker' : 'Earpiece',
              active: speakerOn,
              compact: compact,
              onTap: onSpeaker,
            ),
          ),
          Expanded(
            child: _CircleControl(
              icon: camOn ? Icons.videocam_rounded : Icons.videocam_off_rounded,
              label: camOn ? 'Camera' : 'Cam Off',
              active: camOn,
              compact: compact,
              onTap: onCamera,
            ),
          ),
          Expanded(
            child: _CircleControl(
              icon: Icons.call_end_rounded,
              label: 'End',
              active: true,
              danger: true,
              compact: compact,
              onTap: busy ? null : onEnd,
            ),
          ),
        ],
      ),
    );
  }
}

class _PremiumAudioAvatar extends StatelessWidget {
  const _PremiumAudioAvatar({
    required this.pulseController,
    required this.name,
    required this.avatarUrl,
    this.compact = false,
  });

  final AnimationController pulseController;
  final String name;
  final String avatarUrl;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final outerSize = compact ? 160.0 : 190.0;
    final innerSize = compact ? 108.0 : 126.0;
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return SizedBox(
      width: outerSize,
      height: outerSize,
      child: AnimatedBuilder(
        animation: pulseController,
        builder: (_, child) {
          return Stack(
            alignment: Alignment.center,
            children: [
              _PulseRing(
                size: (compact ? 112 : 132) + pulseController.value * (compact ? 42 : 58),
                opacity: 1 - pulseController.value,
              ),
              _PulseRing(
                size: (compact ? 96 : 112) + pulseController.value * (compact ? 30 : 42),
                opacity: .7 - pulseController.value * .7,
              ),
              child!,
            ],
          );
        },
        child: Container(
          width: innerSize,
          height: innerSize,
          padding: const EdgeInsets.all(4),
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: LinearGradient(
              colors: [
                tokens.primaryButtonGradient.first,
                tokens.primaryButtonGradient.last,
              ],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            boxShadow: [
              BoxShadow(
                color: tokens.primaryButtonGradient.first.withValues(alpha: .36),
                blurRadius: 34,
                offset: const Offset(0, 18),
              ),
            ],
          ),
          child: CircleAvatar(
            backgroundColor: tokens.cardGradient.last,
            backgroundImage: avatarUrl.isNotEmpty ? NetworkImage(avatarUrl) : null,
            child: avatarUrl.isEmpty
                ? Text(
                    name.isNotEmpty ? name[0].toUpperCase() : '?',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: compact ? 34 : 42,
                      fontWeight: FontWeight.w900,
                    ),
                  )
                : null,
          ),
        ),
      ),
    );
  }
}

class _PulseRing extends StatelessWidget {
  const _PulseRing({
    required this.size,
    required this.opacity,
  });

  final double size;
  final double opacity;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(
          color: const Color(0xFF8F6BFF).withValues(alpha: opacity.clamp(0.0, 1.0) * .45),
          width: 2,
        ),
      ),
    );
  }
}

class _PremiumInfoCard extends StatelessWidget {
  const _PremiumInfoCard({
    required this.ratePerMinute,
    required this.estimatedCoins,
    required this.connectionLabel,
    this.compact = false,
  });

  final int ratePerMinute;
  final int estimatedCoins;
  final String connectionLabel;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(horizontal: compact ? 10 : 14, vertical: compact ? 12 : 16),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .09),
        borderRadius: BorderRadius.circular(compact ? 22 : 28),
        border: Border.all(color: Colors.white.withValues(alpha: .12)),
      ),
      child: Row(
        children: [
          Expanded(
            child: _InfoMini(
              icon: Icons.paid_rounded,
              label: 'Rate',
              value: '$ratePerMinute/min',
              compact: compact,
            ),
          ),
          const _VerticalDivider(),
          Expanded(
            child: _InfoMini(
              icon: Icons.account_balance_wallet_rounded,
              label: 'Estimate',
              value: '$estimatedCoins',
              compact: compact,
            ),
          ),
          const _VerticalDivider(),
          Expanded(
            child: _InfoMini(
              icon: Icons.wifi_tethering_rounded,
              label: 'Network',
              value: connectionLabel,
              compact: compact,
            ),
          ),
        ],
      ),
    );
  }
}

class _InfoMini extends StatelessWidget {
  const _InfoMini({
    required this.icon,
    required this.label,
    required this.value,
    this.compact = false,
  });

  final IconData icon;
  final String label;
  final String value;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Icon(icon, color: const Color(0xFFDAD2FF), size: compact ? 16 : 18),
        const SizedBox(height: 6),
        Text(
          label,
          style: TextStyle(
            color: Colors.white.withValues(alpha: .54),
            fontSize: compact ? 10 : 11,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 3),
        Text(
          value,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          textAlign: TextAlign.center,
          style: TextStyle(
            color: Colors.white,
            fontSize: compact ? 11 : 12,
            fontWeight: FontWeight.w800,
          ),
        ),
      ],
    );
  }
}

class _VerticalDivider extends StatelessWidget {
  const _VerticalDivider();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 1,
      height: 42,
      margin: const EdgeInsets.symmetric(horizontal: 8),
      color: Colors.white.withValues(alpha: .10),
    );
  }
}

class _GlassControlButton extends StatelessWidget {
  const _GlassControlButton({
    required this.icon,
    required this.label,
    required this.active,
    required this.onTap,
    this.compact = false,
  });

  final IconData icon;
  final String label;
  final bool active;
  final VoidCallback onTap;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(compact ? 20 : 24),
      onTap: onTap,
      child: Ink(
        padding: EdgeInsets.symmetric(horizontal: compact ? 10 : 14, vertical: compact ? 12 : 14),
        decoration: BoxDecoration(
          color: active ? Colors.white.withValues(alpha: .12) : Colors.white.withValues(alpha: .07),
          borderRadius: BorderRadius.circular(compact ? 20 : 24),
          border: Border.all(color: Colors.white.withValues(alpha: .10)),
        ),
        child: Column(
          children: [
            Icon(icon, color: Colors.white, size: compact ? 22 : 26),
            const SizedBox(height: 8),
            Text(
              label,
              style: TextStyle(
                color: Colors.white,
                fontSize: compact ? 12 : 13,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _EndCallButton extends StatelessWidget {
  const _EndCallButton({
    required this.busy,
    required this.onTap,
    this.compact = false,
  });

  final bool busy;
  final VoidCallback onTap;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: busy ? .55 : 1,
      child: GestureDetector(
        onTap: busy ? null : onTap,
        child: Container(
          width: compact ? 88 : 96,
          height: compact ? 88 : 96,
          decoration: BoxDecoration(
            color: const Color(0xFFFF4D67),
            shape: BoxShape.circle,
            boxShadow: [
              BoxShadow(
                color: const Color(0xFFFF4D67).withValues(alpha: .30),
                blurRadius: 28,
                offset: const Offset(0, 14),
              ),
            ],
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.call_end_rounded, color: Colors.white, size: 30),
              const SizedBox(height: 6),
              Text(
                'End',
                style: TextStyle(
                  color: Colors.white,
                  fontSize: compact ? 11 : 12,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _VideoFallback extends StatelessWidget {
  const _VideoFallback({
    required this.name,
    required this.avatarUrl,
    required this.statusText,
  });

  final String name;
  final String avatarUrl;
  final String statusText;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [Color(0xFF1A1330), Color(0xFF0D0A16)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      child: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            CallHero(
              name: name,
              subtitle: statusText,
              avatarUrl: avatarUrl,
              icon: Icons.videocam_rounded,
            ),
            const SizedBox(height: 12),
            const CallTypingDots(),
          ],
        ),
      ),
    );
  }
}

class _LocalVideoPreview extends StatelessWidget {
  const _LocalVideoPreview({
    required this.localTrack,
    required this.name,
    required this.width,
    required this.height,
  });

  final VideoTrack? localTrack;
  final String name;
  final double width;
  final double height;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: const Color(0xFF120F22),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: Colors.white.withValues(alpha: .14)),
      ),
      child: localTrack != null
          ? VideoTrackRenderer(localTrack!, fit: VideoViewFit.cover)
          : _MiniVideoFallback(name: name),
    );
  }
}

class _MiniVideoFallback extends StatelessWidget {
  const _MiniVideoFallback({required this.name});

  final String name;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: const Color(0xFF1A1330),
      child: Center(
        child: Text(
          name.isEmpty ? 'Y' : name.substring(0, 1).toUpperCase(),
          style: const TextStyle(
            color: Colors.white,
            fontSize: 28,
            fontWeight: FontWeight.w800,
          ),
        ),
      ),
    );
  }
}

class _TopActionButton extends StatelessWidget {
  const _TopActionButton({
    required this.icon,
    required this.onTap,
    this.compact = false,
  });

  final IconData icon;
  final VoidCallback onTap;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final size = compact ? 40.0 : 46.0;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Ink(
        width: size,
        height: size,
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: .06),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Colors.white.withValues(alpha: .09)),
        ),
        child: Icon(icon, color: Colors.white, size: compact ? 22 : 24),
      ),
    );
  }
}

class _CallFollowButton extends StatefulWidget {
  const _CallFollowButton({
    required this.receiverId,
    this.compact = false,
  });

  final int receiverId;
  final bool compact;

  @override
  State<_CallFollowButton> createState() => _CallFollowButtonState();
}

class _CallFollowButtonState extends State<_CallFollowButton> {
  late final HostFollowController follows;
  Map<String, dynamic>? state;

  @override
  void initState() {
    super.initState();
    follows = Get.find<HostFollowController>();
    _load();
  }

  Future<void> _load() async {
    final data = await follows.fetchStateByUserId(widget.receiverId);
    if (!mounted) return;
    setState(() => state = data);
  }

  @override
  Widget build(BuildContext context) {
    final hostId = (state?['host_id'] as num?)?.toInt() ??
        follows.hostIdForUser(widget.receiverId) ??
        0;
    if (hostId <= 0) return const SizedBox.shrink();

    return Obx(() {
      final isFollowing = follows.isFollowing(hostId, fallback: state?['is_following'] == true);
      final busy = follows.isBusy(hostId);
      final child = Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            isFollowing ? Icons.check_rounded : Icons.add_rounded,
            size: 16,
            color: Colors.white,
          ),
          const SizedBox(width: 6),
          Text(isFollowing ? 'Following' : 'Follow'),
        ],
      );

      return Container(
        decoration: BoxDecoration(
          color: widget.compact
              ? Colors.black.withValues(alpha: .26)
              : Colors.white.withValues(alpha: .05),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: Colors.white.withValues(alpha: .12)),
        ),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(999),
            onTap: busy
                ? null
                : () => follows.toggleForHost(
                      hostId: hostId,
                      current: isFollowing,
                      currentCount: (state?['follower_count'] as num?)?.toInt(),
                    ),
            child: Padding(
              padding: EdgeInsets.symmetric(
                horizontal: widget.compact ? 14 : 16,
                vertical: widget.compact ? 10 : 12,
              ),
              child: DefaultTextStyle(
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w800,
                  fontSize: widget.compact ? 12 : 13,
                ),
                child: child,
              ),
            ),
          ),
        ),
      );
    });
  }
}

class _CircleControl extends StatelessWidget {
  const _CircleControl({
    required this.icon,
    required this.label,
    required this.active,
    required this.onTap,
    this.danger = false,
    this.compact = false,
  });

  final IconData icon;
  final String label;
  final bool active;
  final VoidCallback? onTap;
  final bool danger;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final activeColor = danger ? const Color(0xFFFF6B7A) : const Color(0xFF7C5CFF);
    final baseColor = Colors.white.withValues(alpha: .08);
    final fillColor = active || danger ? activeColor.withValues(alpha: .18) : baseColor;
    final iconColor = danger
        ? const Color(0xFFFFC8D0)
        : active
            ? Colors.white
            : Colors.white.withValues(alpha: .78);

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(20),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: compact ? 46 : 54,
              height: compact ? 46 : 54,
              decoration: BoxDecoration(
                color: fillColor,
                shape: BoxShape.circle,
                border: Border.all(
                  color: danger
                      ? activeColor.withValues(alpha: .36)
                      : Colors.white.withValues(alpha: .12),
                ),
              ),
              child: Icon(icon, color: iconColor, size: compact ? 22 : 24),
            ),
            SizedBox(height: compact ? 6 : 8),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: Colors.white.withValues(alpha: .82),
                fontSize: compact ? 10 : 11,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CallErrorBanner extends StatelessWidget {
  const _CallErrorBanner({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: const Color(0xFFFF6B7A).withValues(alpha: .12),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFFF6B7A).withValues(alpha: .18)),
      ),
      child: Text(
        text,
        style: const TextStyle(
          color: Color(0xFFFFC5CF),
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

class _GlowBlob extends StatelessWidget {
  const _GlowBlob({
    required this.color,
    required this.size,
  });

  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          color: color,
          boxShadow: [
            BoxShadow(
              color: color,
              blurRadius: 90,
              spreadRadius: 50,
            ),
          ],
        ),
      ),
    );
  }
}
