import 'dart:async';
import 'dart:ui';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:permission_handler/permission_handler.dart';

import '../../../../app/theme/brand.dart';
import '../../../../app/widgets/animated_background.dart';
import '../../../../app/widgets/haptics.dart';
import '../../../../services/app_settings_service.dart';
import '../models/live_room_model.dart';
import '../services/live_service.dart';
import 'video_call_page.dart';

PremiumThemeTokens _backstageTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class BackstagePage extends StatefulWidget {
  final String? initialTitle;
  const BackstagePage({super.key, this.initialTitle});

  @override
  State<BackstagePage> createState() => _BackstagePageState();
}

enum _Phase { perm, create, ready, error, countingDown }

class _BackstagePageState extends State<BackstagePage>
    with TickerProviderStateMixin {
  final live = Get.find<LiveService>();
  final _bg = AnimatedBackgroundController();

  _Phase phase = _Phase.perm;
  LiveRoomModel? _room;
  String? _err;

  CameraController? _cam;
  bool _camReady = false;

  bool _micOn = true;
  bool _camOn = true;

  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1500),
  )..repeat(reverse: true);

  int _count = 0;
  bool _handoffToStage = false;
  bool _endCalled = false;

  @override
  void initState() {
    super.initState();
    _bg.setEnergy(.28);
    _bootstrap();
  }

  @override
  void dispose() {
    unawaited(_endIfAbandoned());
    _pulse.dispose();
    _bg.dispose();
    _cam?.dispose();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    try {
      setState(() {
        _err = null;
        phase = _Phase.perm;
      });

      final ok = await _ensurePermissions();
      if (!ok) throw 'Camera and microphone permissions are required.';

      unawaited(_startCameraBackground());

      setState(() => phase = _Phase.create);
      final created = await live.createOrStart(
        title: (widget.initialTitle ?? '').trim().isNotEmpty
            ? widget.initialTitle!.trim()
            : 'Live on Talkee',
      );

      if (!mounted) return;
      setState(() {
        _room = created;
        phase = _Phase.ready;
      });
      _bg.setEnergy(.18);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _err = e.toString();
        phase = _Phase.error;
      });
    }
  }

  Future<bool> _ensurePermissions() async {
    final cam = await Permission.camera.request();
    final mic = await Permission.microphone.request();
    return cam.isGranted && mic.isGranted;
  }

  Future<void> _startCameraBackground() async {
    try {
      final cams = await availableCameras();
      final front = cams.firstWhere(
        (c) => c.lensDirection == CameraLensDirection.front,
        orElse: () => cams.first,
      );
      final ctrl = CameraController(
        front,
        ResolutionPreset.low,
        enableAudio: false,
        imageFormatGroup: ImageFormatGroup.bgra8888,
      );
      await ctrl.initialize();
      if (!mounted) return;
      setState(() {
        _cam = ctrl;
        _camReady = true;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _camReady = false);
    }
  }

  Future<void> _endIfAbandoned() async {
    if (_endCalled) return;
    final r = _room;
    if (r != null && !_handoffToStage) {
      _endCalled = true;
      try {
        await live.end(r.roomId);
      } catch (_) {}
    }
  }

  Future<void> _closeStudio() async {
    await _endIfAbandoned();
    if (mounted) Get.back();
  }

  Future<void> _enterStage() async {
    if (_room == null) return;
    _handoffToStage = true;
    final ctrl = _cam;
    _cam = null;
    try {
      await ctrl?.dispose();
    } catch (_) {}
    if (!mounted) return;
    Get.off(
      () => VideoCallPage(room: _room!, live: live),
      transition: Transition.noTransition,
      opaque: true,
    );
  }

  void _startCountdownAndEnter() {
    if (_room == null) return;
    setState(() {
      phase = _Phase.countingDown;
      _count = 3;
    });
    Haptics.light();

    Timer.periodic(const Duration(seconds: 1), (tm) async {
      if (!mounted) {
        tm.cancel();
        return;
      }
      setState(() => _count--);
      if (_count <= 0) {
        tm.cancel();
        Haptics.success();
        await _enterStage();
      }
    });
  }

  Future<bool> _onWillPop() async {
    await _closeStudio();
    return false;
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _backstageTokens();
    return WillPopScope(
      onWillPop: _onWillPop,
      child: Scaffold(
        backgroundColor: tokens.backgroundGradient.first,
        body: Stack(
          children: [
            Positioned.fill(
              child: _camReady && _cam != null
                  ? CameraPreview(_cam!)
                  : AnimatedBackground(
                      controller: _bg,
                      visualScale: 1.2,
                      richness: 14,
                    ),
            ),
            Positioned.fill(
              child: BackdropFilter(
                filter: ImageFilter.blur(sigmaX: 10, sigmaY: 10),
                child: Container(
                  color: tokens.backgroundGradient.first.withOpacity(.42),
                ),
              ),
            ),
            Positioned.fill(
              child: IgnorePointer(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [
                        Colors.black.withOpacity(.36),
                        Colors.transparent,
                        Colors.black.withOpacity(.54),
                      ],
                      stops: const [0, .42, 1],
                    ),
                  ),
                ),
              ),
            ),
            SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  children: [
                    _TopBar(onClose: _closeStudio),
                    const SizedBox(height: 16),
                    Expanded(
                      child: Center(
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 760),
                          child: _StudioPanel(
                            room: _room,
                            phase: phase,
                            err: _err,
                            micOn: _micOn,
                            camOn: _camOn,
                            onRetry: _bootstrap,
                            onMicToggle: () => setState(() => _micOn = !_micOn),
                            onCamToggle: () => setState(() => _camOn = !_camOn),
                            onStart: _startCountdownAndEnter,
                            onQuickStart: _enterStage,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            if (phase == _Phase.countingDown)
              _CountdownOverlay(count: _count, pulse: _pulse),
          ],
        ),
      ),
    );
  }
}

class _TopBar extends StatelessWidget {
  final Future<void> Function() onClose;
  const _TopBar({required this.onClose});

  @override
  Widget build(BuildContext context) {
    final tokens = _backstageTokens();
    return Row(
      children: [
        IconButton(
          tooltip: 'Close',
          onPressed: onClose,
          style: IconButton.styleFrom(
            backgroundColor: tokens.glassColor.withOpacity(.62),
          ),
          icon: Icon(Icons.close_rounded, color: tokens.textPrimary),
        ),
        const SizedBox(width: 8),
        Text(
          'Live Studio',
          style: TextStyle(
            color: tokens.textPrimary,
            fontWeight: FontWeight.w800,
            fontSize: 18,
            letterSpacing: .2,
          ),
        ),
      ],
    );
  }
}

class _StudioPanel extends StatelessWidget {
  final LiveRoomModel? room;
  final _Phase phase;
  final String? err;
  final bool micOn;
  final bool camOn;
  final Future<void> Function() onRetry;
  final VoidCallback onMicToggle;
  final VoidCallback onCamToggle;
  final VoidCallback onStart;
  final Future<void> Function() onQuickStart;

  const _StudioPanel({
    required this.room,
    required this.phase,
    required this.err,
    required this.micOn,
    required this.camOn,
    required this.onRetry,
    required this.onMicToggle,
    required this.onCamToggle,
    required this.onStart,
    required this.onQuickStart,
  });

  @override
  Widget build(BuildContext context) {
    final tokens = _backstageTokens();
    final isReady = phase == _Phase.ready;
    return ClipRRect(
      borderRadius: BorderRadius.circular(30),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: Container(
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(30),
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                tokens.cardGradient.first.withOpacity(.86),
                tokens.cardGradient.last.withOpacity(.82),
              ],
            ),
            border: Border.all(color: tokens.borderColor),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withOpacity(.34),
                blurRadius: 28,
                offset: const Offset(0, 12),
              ),
            ],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const _Badge(),
              const SizedBox(height: 14),
              Text(
                'Pre-Live Checklist',
                style: TextStyle(
                  color: tokens.textPrimary,
                  fontWeight: FontWeight.w900,
                  fontSize: 25,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                room?.title ?? 'Setting up your room...',
                style: TextStyle(
                  color: tokens.textSecondary,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 8),
              _MetaRow(room: room, phase: phase),
              const SizedBox(height: 16),
              _PhaseRail(phase: phase),
              const SizedBox(height: 16),
              Wrap(
                spacing: 10,
                runSpacing: 10,
                children: [
                  _ToggleChip(
                    icon: micOn ? Icons.mic_rounded : Icons.mic_off_rounded,
                    label: micOn ? 'Mic on' : 'Mic off',
                    onTap: onMicToggle,
                  ),
                  _ToggleChip(
                    icon: camOn
                        ? Icons.videocam_rounded
                        : Icons.videocam_off_rounded,
                    label: camOn ? 'Camera on' : 'Camera off',
                    onTap: onCamToggle,
                  ),
                ],
              ),
              const SizedBox(height: 18),
              if (phase == _Phase.perm || phase == _Phase.create)
                const _LoadingPanel(label: 'Preparing studio...')
              else if (phase == _Phase.error)
                _ErrorBox(msg: err ?? 'Something went wrong', onRetry: onRetry)
              else if (isReady)
                Row(
                  children: [
                    Expanded(
                      child: FilledButton.icon(
                        style: FilledButton.styleFrom(
                          backgroundColor: tokens.primaryButtonGradient.first,
                          foregroundColor: tokens.textPrimary,
                          padding: const EdgeInsets.symmetric(vertical: 14),
                        ),
                        onPressed: onStart,
                        icon: const Icon(Icons.podcasts_rounded),
                        label: const Text('Start Live (3s)'),
                      ),
                    ),
                    const SizedBox(width: 10),
                    OutlinedButton.icon(
                      onPressed: onQuickStart,
                      style: OutlinedButton.styleFrom(
                        side: BorderSide(
                          color: tokens.borderColor.withOpacity(.82),
                        ),
                        foregroundColor: tokens.textPrimary,
                        padding: const EdgeInsets.symmetric(
                          horizontal: 14,
                          vertical: 14,
                        ),
                      ),
                      icon: const Icon(Icons.flash_on_rounded),
                      label: const Text('Quick'),
                    ),
                  ],
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        color: Colors.white.withOpacity(.10),
        border: Border.all(color: Colors.white.withOpacity(.20)),
      ),
      child: const Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.motion_photos_on_rounded, size: 16, color: Colors.white),
          SizedBox(width: 7),
          Text(
            'Studio Ready',
            style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800),
          ),
        ],
      ),
    );
  }
}

class _MetaRow extends StatelessWidget {
  final LiveRoomModel? room;
  final _Phase phase;
  const _MetaRow({required this.room, required this.phase});

  String _phaseText() {
    switch (phase) {
      case _Phase.perm:
        return 'Permissions';
      case _Phase.create:
        return 'Creating room';
      case _Phase.ready:
        return 'Ready';
      case _Phase.error:
        return 'Error';
      case _Phase.countingDown:
        return 'Going live';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        _MetaPill(label: 'Phase', value: _phaseText()),
        if (room != null) _MetaPill(label: 'Room', value: room!.roomId),
      ],
    );
  }
}

class _MetaPill extends StatelessWidget {
  final String label;
  final String value;
  const _MetaPill({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(.08),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.white.withOpacity(.16)),
      ),
      child: RichText(
        text: TextSpan(
          style: const TextStyle(color: Colors.white, fontSize: 12),
          children: [
            TextSpan(
              text: '$label: ',
              style: TextStyle(
                color: Colors.white.withOpacity(.68),
                fontWeight: FontWeight.w700,
              ),
            ),
            TextSpan(text: value, style: const TextStyle(fontWeight: FontWeight.w800)),
          ],
        ),
      ),
    );
  }
}

class _ToggleChip extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  const _ToggleChip({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(999),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
        decoration: BoxDecoration(
          color: Colors.white.withOpacity(.10),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: Colors.white.withOpacity(.20)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, color: Colors.white, size: 16),
            const SizedBox(width: 8),
            Text(
              label,
              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
            ),
          ],
        ),
      ),
    );
  }
}

class _LoadingPanel extends StatelessWidget {
  final String label;
  const _LoadingPanel({required this.label});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(.06),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withOpacity(.14)),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const SizedBox(
            width: 16,
            height: 16,
            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
          ),
          const SizedBox(width: 8),
          Text(
            label,
            style: const TextStyle(color: Colors.white70, fontWeight: FontWeight.w700),
          ),
        ],
      ),
    );
  }
}

class _PhaseRail extends StatelessWidget {
  final _Phase phase;
  const _PhaseRail({required this.phase});

  bool _active(int i) {
    switch (phase) {
      case _Phase.perm:
        return i <= 0;
      case _Phase.create:
        return i <= 1;
      case _Phase.ready:
        return i <= 2;
      case _Phase.countingDown:
        return i <= 3;
      case _Phase.error:
        return i <= 1;
    }
  }

  @override
  Widget build(BuildContext context) {
    const labels = ['Permissions', 'Create', 'Ready', 'Live'];
    return Row(
      children: List.generate(labels.length * 2 - 1, (i) {
        if (i.isOdd) {
          final on = _active((i / 2).floor() + 1);
          return Expanded(
            child: Container(
              height: 2,
              margin: const EdgeInsets.symmetric(horizontal: 6),
              color: on ? Colors.white.withOpacity(.52) : Colors.white.withOpacity(.15),
            ),
          );
        }
        final idx = i ~/ 2;
        final on = _active(idx);
        return AnimatedContainer(
          duration: const Duration(milliseconds: 260),
          width: on ? 25 : 21,
          height: on ? 25 : 21,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: on ? kTalkeePrimary : Colors.white.withOpacity(.10),
            border: Border.all(color: Colors.white.withOpacity(on ? .62 : .20)),
          ),
          alignment: Alignment.center,
          child: Text(
            '${idx + 1}',
            style: TextStyle(
              color: Colors.white.withOpacity(on ? 1 : .64),
              fontWeight: FontWeight.w800,
              fontSize: 11,
            ),
          ),
        );
      }),
    );
  }
}

class _ErrorBox extends StatelessWidget {
  final String msg;
  final Future<void> Function() onRetry;
  const _ErrorBox({required this.msg, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.red.withOpacity(.15),
        border: Border.all(color: Colors.red.withOpacity(.35)),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          const Icon(Icons.error_outline, color: Colors.white70),
          const SizedBox(width: 8),
          Expanded(child: Text(msg, style: const TextStyle(color: Colors.white))),
          TextButton(onPressed: onRetry, child: const Text('Retry')),
        ],
      ),
    );
  }
}

class _CountdownOverlay extends StatelessWidget {
  final int count;
  final AnimationController pulse;
  const _CountdownOverlay({required this.count, required this.pulse});

  @override
  Widget build(BuildContext context) {
    return Positioned.fill(
      child: Container(
        color: Colors.black.withOpacity(.66),
        child: Center(
          child: AnimatedBuilder(
            animation: pulse,
            builder: (_, __) {
              final s = 1 + pulse.value * .08;
              return Transform.scale(
                scale: s,
                child: Container(
                  width: 170,
                  height: 170,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: Colors.white.withOpacity(.08),
                    border: Border.all(color: Colors.white.withOpacity(.24)),
                  ),
                  alignment: Alignment.center,
                  child: Text(
                    '$count',
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w900,
                      fontSize: 80,
                    ),
                  ),
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}
