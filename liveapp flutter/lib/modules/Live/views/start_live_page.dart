// lib/modules/live/views/live_waiting_page.dart
import 'dart:async';
import 'dart:math' as math;
import 'dart:ui' show ImageFilter, lerpDouble;
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:camera/camera.dart';

import '../../../../app/theme/brand.dart';
import '../../../../app/widgets/haptics.dart';
import '../../../../services/app_settings_service.dart';
import '../services/live_service.dart';
import '../models/live_room_model.dart';
import 'video_call_page.dart';

PremiumThemeTokens _startLiveTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);


class LiveWaitingPage extends StatefulWidget {
  final String? initialTitle;
  const LiveWaitingPage({super.key, this.initialTitle});

  @override
  State<LiveWaitingPage> createState() => _LiveWaitingPageState();
}

enum _Phase { perm, create, connect, ready, error }

class _LiveWaitingPageState extends State<LiveWaitingPage>
    with TickerProviderStateMixin {
  _Phase phase = _Phase.perm;
  String? errorMsg;
  LiveRoomModel? room;

  late final LiveService live;

  // ambience
  late final AnimationController _ring;
  late final AnimationController _pulse;
  late final AnimationController _ellipsis;
  late final AnimationController _aurora;
  late final AnimationController _bokeh;

  // parallax for glass card
  double _parallaxX = 0, _parallaxY = 0;

  // camera background
  CameraController? _cam;
  bool _camReady = false;

  // preflight toggles (visual)
  bool _micOn = true;
  bool _camOn = true;

  @override
  void initState() {
    super.initState();
    live = Get.find<LiveService>();

    _ring     = AnimationController(vsync: this, duration: const Duration(seconds: 4))..repeat();
    _pulse    = AnimationController(vsync: this, duration: const Duration(milliseconds: 1700))..repeat(reverse: true);
    _ellipsis = AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..repeat();
    _aurora   = AnimationController(vsync: this, duration: const Duration(seconds: 12))..repeat();
    _bokeh    = AnimationController(vsync: this, duration: const Duration(seconds: 8))..repeat();

    _run();
  }

  @override
  void dispose() {
    _ring.dispose();
    _pulse.dispose();
    _ellipsis.dispose();
    _aurora.dispose();
    _bokeh.dispose();
    _cam?.dispose();
    super.dispose();
  }

  Future<void> _run() async {
    try {
      setState(() => phase = _Phase.perm);
      final ok = await _ensurePermissions();
      if (!ok) throw 'Camera & Microphone permissions are required.';

      // camera bg (non-blocking)
      unawaited(_startCameraBackground());
      await Future.delayed(const Duration(milliseconds: 420));

      setState(() => phase = _Phase.create);
      final created = await live.createOrStart(
        title: (widget.initialTitle ?? '').trim().isNotEmpty
            ? widget.initialTitle!.trim()
            : 'Live on Talkieo',
      );
      // // Pretty-print what we got back from the server
      // final pretty = const JsonEncoder.withIndent('  ').convert({
      //   'ok': true,
      //   'room': {
      //     'room_id': created.roomId,
      //     'title'  : created.title,
      //     'status' : created.status,
      //     'scheduled_at': created.scheduledAt?.toIso8601String(),
      //     'started_at'  : created.startedAt?.toIso8601String(),
      //     'ended_at'    : created.endedAt?.toIso8601String(),
      //     'peak_viewers': created.peakViewers,
      //     'meta'        : created.meta,
      //   },
      //   'ws_url'      : created.wsUrl,
      //   // print full token if you want; here I truncate to keep logs readable:
      //   'token'       : created.token == null
      //       ? null
      //       : '${created.token!.substring(0, 24)}...${created.token!.substring(created.token!.length - 12)}',
      //   'identity'    : created.identity,
      //   'role'        : created.role,
      //   'participant_id': created.participantId,
      // });
      // debugPrint('LIVE START RESPONSE:\n$pretty');

      await Future.delayed(const Duration(milliseconds: 520));
      setState(() => phase = _Phase.connect);
      await Future.delayed(const Duration(milliseconds: 680));

      Haptics.success();
      setState(() { room = created; phase = _Phase.ready; });

      await Future.delayed(const Duration(milliseconds: 420));
      if (!mounted) return;
      Get.off(
            () => VideoCallPage(room: room!, live: live,),
        transition: Transition.cupertino,
        duration: const Duration(milliseconds: 480),
      );
    } catch (e) {
      Haptics.error();
      setState(() { errorMsg = e.toString(); phase = _Phase.error; });
    }
  }

  Future<bool> _ensurePermissions() async {
    final cam = await Permission.camera.status;
    final mic = await Permission.microphone.status;

    if (!cam.isGranted) {
      final r = await Permission.camera.request();
      if (!r.isGranted) return false;
    }
    if (!mic.isGranted) {
      final r = await Permission.microphone.request();
      if (!r.isGranted) return false;
    }
    return true;
  }

  Future<void> _startCameraBackground() async {
    try {
      final cams = await availableCameras();
      final front = cams.firstWhere(
            (c) => c.lensDirection == CameraLensDirection.front,
        orElse: () => cams.isNotEmpty ? cams.first : throw 'No camera found',
      );
      final ctrl = CameraController(
        front,
        ResolutionPreset.low, // background only
        enableAudio: false,
        imageFormatGroup: ImageFormatGroup.bgra8888,
      );
      await ctrl.initialize();
      if (!mounted) return;
      setState(() { _cam = ctrl; _camReady = true; });
    } catch (_) {
      if (mounted) setState(() => _camReady = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    final tokens = _startLiveTokens();

    return Scaffold(
      extendBodyBehindAppBar: true,
      backgroundColor: Colors.black,
      appBar: AppBar(
        elevation: 0,
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        scrolledUnderElevation: 0,
      ),
      body: Listener(
        onPointerHover: (e) {
          final size = MediaQuery.of(context).size;
          setState(() {
            _parallaxX = ((e.position.dx / size.width) - .5) * 10;  // -5..5
            _parallaxY = ((e.position.dy / size.height) - .5) * 10; // -5..5
          });
        },
        onPointerMove: (e) {
          final size = MediaQuery.of(context).size;
          setState(() {
            _parallaxX = ((e.position.dx / size.width) - .5) * 10;
            _parallaxY = ((e.position.dy / size.height) - .5) * 10;
          });
        },
        child: Stack(
          children: [
            // ===== 1) Live blurred camera (fallback → gradient aurora) =====
            if (_camReady && _cam != null) ...[
              Positioned.fill(child: CameraPreview(_cam!)),
              Positioned.fill(
                child: ClipRect(
                  child: BackdropFilter(
                    filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
                    child: Container(
                      color: tokens.backgroundGradient.first.withOpacity(.35),
                    ),
                  ),
                ),
              ),
            ] else
              const _GradientBackdrop(), // branded animated gradient

            // Aurora ribbon overlay
            Positioned.fill(child: _AuroraLayer(controller: _aurora)),

            // Floating bokeh specks
            Positioned.fill(child: _BokehLayer(controller: _bokeh)),

            // Soft vignette
            Positioned.fill(
              child: IgnorePointer(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: RadialGradient(
                      center: const Alignment(0, -0.2),
                      radius: 1.1,
                      colors: [Colors.transparent, Colors.black.withOpacity(.45)],
                      stops: const [.65, 1.0],
                    ),
                  ),
                ),
              ),
            ),

            // ===== 2) Foreground content =====
            SafeArea(
              child: Center(
                child: Padding(
                  padding: EdgeInsets.fromLTRB(16, 12, 16, 16 + mq.padding.bottom),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 760),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        // slow hero – same tag as AppBar
                        Hero(
                          tag: 'go.live.pill',
                          flightShuttleBuilder: (c, a, d, from, to) => to.widget,
                          child: const _GoLivePill(),
                        ),
                        const SizedBox(height: 18),

                        // parallax glass card with specular sheen
                        Transform.translate(
                          offset: Offset(_parallaxX, _parallaxY),
                          child: _GlassCard(
                            child: Column(
                              children: [
                                const SizedBox(height: 14),
                                _LiveOrb(ring: _ring, pulse: _pulse),
                                const SizedBox(height: 16),

                                _PhaseTitle(phase: phase, ellipsis: _ellipsis),

                                if (room != null) ...[
                                  const SizedBox(height: 8),
                                  Opacity(
                                    opacity: .95,
                                    child: Text(
                                      (room!.title ?? 'Live on Talkieo'),
                                      textAlign: TextAlign.center,
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontWeight: FontWeight.w900,
                                        fontSize: 16,
                                      ),
                                    ),
                                  ),
                                  const SizedBox(height: 2),
                                  Opacity(
                                    opacity: .80,
                                    child: Text(
                                      'Room • ${room!.roomId}',
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontWeight: FontWeight.w700,
                                        fontSize: 12,
                                      ),
                                    ),
                                  ),
                                ],

                                const SizedBox(height: 18),
                                _ProgressRail(phase: phase, pulse: _pulse),
                                const SizedBox(height: 16),

                                // preflight toggles (visual, feels pro)
                                Row(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  children: [
                                    _GlassChip(
                                      icon: _micOn ? Icons.mic_rounded : Icons.mic_off_rounded,
                                      label: _micOn ? 'Mic on' : 'Mic off',
                                      onTap: () => setState(() => _micOn = !_micOn),
                                    ),
                                    const SizedBox(width: 10),
                                    _GlassChip(
                                      icon: _camOn ? Icons.videocam_rounded : Icons.videocam_off_rounded,
                                      label: _camOn ? 'Cam on' : 'Cam off',
                                      onTap: () => setState(() => _camOn = !_camOn),
                                    ),
                                  ],
                                ),

                                const SizedBox(height: 12),
                                if (phase == _Phase.error)
                                  _ErrorFooter(onRetry: _run)
                                else
                                  Opacity(
                                    opacity: .90,
                                    child: Padding(
                                      padding: const EdgeInsets.symmetric(horizontal: 8),
                                      child: Text(
                                        'We’re prepping the studio. Your blurred camera appears behind the glass. '
                                            'You’ll enter live as soon as the connection is ready.',
                                        textAlign: TextAlign.center,
                                        style: TextStyle(
                                          color: tokens.textSecondary,
                                          fontWeight: FontWeight.w600,
                                          fontSize: 12,
                                        ),
                                      ),
                                    ),
                                  ),
                                const SizedBox(height: 8),
                              ],
                            ),
                          ),
                        ),

                        const SizedBox(height: 14),
                        TextButton(
                          onPressed: () { Haptics.light(); Get.back(); },
                          style: TextButton.styleFrom(
                            foregroundColor: tokens.textPrimary.withOpacity(.95),
                          ),
                          child: const Text('Cancel'),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/* ============================= VISUAL PIECES ============================= */

class _GoLivePill extends StatelessWidget {
  const _GoLivePill();

  @override
  Widget build(BuildContext context) {
    final tokens = _startLiveTokens();
    return Material(
      color: Colors.transparent,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(14),
          gradient: LinearGradient(
            colors: tokens.primaryButtonGradient,
            begin: Alignment.topLeft, end: Alignment.bottomRight,
          ),
          boxShadow: [
            BoxShadow(
              color: tokens.glowColor,
              blurRadius: 16,
              spreadRadius: 1,
            ),
          ],
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: const [
            Icon(Icons.wifi_tethering_rounded, color: Colors.white),
            SizedBox(width: 8),
            _EllipsisText('Starting live'),
          ],
        ),
      ),
    );
  }
}

class _EllipsisText extends StatefulWidget {
  final String text;
  const _EllipsisText(this.text, {super.key});
  @override
  State<_EllipsisText> createState() => _EllipsisTextState();
}
class _EllipsisTextState extends State<_EllipsisText> with SingleTickerProviderStateMixin {
  late final AnimationController _c;
  @override
  void initState() { super.initState(); _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..repeat(); }
  @override
  void dispose() { _c.dispose(); super.dispose(); }
  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _c,
      builder: (_, __) {
        final dots = '.' * (1 + (_c.value * 3).floor() % 3);
        return Text('${widget.text}$dots', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900));
      },
    );
  }
}

class _GlassCard extends StatelessWidget {
  final Widget child;
  const _GlassCard({required this.child});

  @override
  Widget build(BuildContext context) {
    final tokens = _startLiveTokens();
    return ClipRRect(
      borderRadius: BorderRadius.circular(22),
      child: Stack(
        children: [
          // glass
          BackdropFilter(
            filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
            child: Container(
              width: double.infinity,
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                color: tokens.glassColor.withOpacity(.42),
                borderRadius: BorderRadius.circular(22),
                border: Border.all(color: tokens.borderColor.withOpacity(.82)),
                boxShadow: [
                  BoxShadow(color: Colors.black.withOpacity(.2), blurRadius: 24, offset: const Offset(0, 16)),
                ],
              ),
              child: child,
            ),
          ),
          // specular sheen line
          Positioned.fill(
            child: IgnorePointer(
              child: Opacity(
                opacity: .18,
                child: CustomPaint(painter: _SheenPainter()),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _SheenPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()
      ..shader = const LinearGradient(
        begin: Alignment.topLeft, end: Alignment.bottomRight,
        colors: [Colors.white24, Colors.transparent],
        stops: [0.0, 0.7],
      ).createShader(Offset.zero & size);
    final path = Path()
      ..moveTo(size.width * .05, size.height * .15)
      ..lineTo(size.width * .65, size.height * .02)
      ..lineTo(size.width * .70, size.height * .12)
      ..lineTo(size.width * .10, size.height * .26)
      ..close();
    canvas.drawPath(path, p);
  }
  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _LiveOrb extends StatelessWidget {
  final AnimationController ring;
  final AnimationController pulse;
  const _LiveOrb({required this.ring, required this.pulse});

  @override
  Widget build(BuildContext context) {
    final tokens = _startLiveTokens();
    return AnimatedBuilder(
      animation: Listenable.merge([ring, pulse]),
      builder: (_, __) {
        final scale = 0.96 + 0.08 * (pulse.value);
        final angle = ring.value * 2 * math.pi;
        return SizedBox(
          width: 120, height: 120,
          child: Stack(
            alignment: Alignment.center,
            children: [
              Transform.rotate(
                angle: angle,
                child: CustomPaint(
                  size: const Size(120, 120),
                  painter: _RingPainter(tokens.primaryButtonGradient.first),
                ),
              ),
              Transform.scale(
                scale: scale,
                child: Container(
                  width: 84, height: 84,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: RadialGradient(
                      colors: [
                        tokens.textPrimary.withOpacity(.9),
                        tokens.textPrimary.withOpacity(.55),
                      ],
                      radius: .95,
                    ),
                    boxShadow: [
                      BoxShadow(
                        color: tokens.glowColor.withOpacity(.35),
                        blurRadius: 22,
                      ),
                    ],
                  ),
                  child: Icon(
                    Icons.blur_linear_rounded,
                    color: tokens.primaryButtonGradient.first,
                    size: 30,
                  ),
                ),
              ),
              // pulsing live dot
              Positioned(
                right: 18, top: 18,
                child: _PulseDot(color: Colors.redAccent, controller: pulse),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _PulseDot extends StatelessWidget {
  final Color color;
  final AnimationController controller;
  const _PulseDot({required this.color, required this.controller});
  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: controller,
      builder: (_, __) {
        final s = 1 + controller.value * .35;
        final o = lerpDouble(.25, .75, controller.value)!;
        return Stack(
          alignment: Alignment.center,
          children: [
            Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
            Transform.scale(
              scale: s,
              child: Container(
                width: 14, height: 14,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: color.withOpacity(o),
                ),
              ),
            ),
          ],
        );
      },
    );
  }
}

class _RingPainter extends CustomPainter {
  final Color color;
  _RingPainter(this.color);
  @override
  void paint(Canvas canvas, Size size) {
    final r = size.width / 2;
    final rect = Rect.fromCircle(center: Offset(r, r), radius: r - 3);

    final sweep = SweepGradient(
      colors: [Colors.white.withOpacity(.08), color.withOpacity(.65), Colors.white.withOpacity(.08)],
      stops: const [0.0, 0.5, 1.0],
    ).createShader(rect);

    final bg = Paint()
      ..color = Colors.white.withOpacity(.07)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 11
      ..strokeCap = StrokeCap.round;

    final fg = Paint()
      ..shader = sweep
      ..style = PaintingStyle.stroke
      ..strokeWidth = 11
      ..strokeCap = StrokeCap.round;

    canvas.drawArc(rect, 0, 2 * math.pi, false, bg);
    canvas.drawArc(rect, 0, 2 * math.pi, false, fg);
  }
  @override
  bool shouldRepaint(covariant _RingPainter oldDelegate) => false;
}

class _PhaseTitle extends StatelessWidget {
  final _Phase phase;
  final AnimationController ellipsis;
  const _PhaseTitle({required this.phase, required this.ellipsis});

  @override
  Widget build(BuildContext context) {
    String label;
    switch (phase) {
      case _Phase.perm:    label = 'Checking camera & mic'; break;
      case _Phase.create:  label = 'Creating your room';     break;
      case _Phase.connect: label = 'Connecting to server';   break;
      case _Phase.ready:   label = 'Ready';                  break;
      case _Phase.error:   label = 'Something went wrong';   break;
    }

    final base = const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 18);
    if (phase == _Phase.ready || phase == _Phase.error) {
      return Text(label, style: base);
    }
    return _EllipsisText(label);
  }
}

class _ProgressRail extends StatelessWidget {
  final _Phase phase;
  final AnimationController pulse;
  const _ProgressRail({required this.phase, required this.pulse});

  @override
  Widget build(BuildContext context) {
    final steps = <(IconData, String, bool)>[
      (Icons.videocam_rounded,       'Permissions', phase.index >= _Phase.perm.index),
      (Icons.auto_awesome_rounded,   'Create room', phase.index >= _Phase.create.index),
      (Icons.wifi_tethering_rounded, 'Handshake',   phase.index >= _Phase.connect.index),
      (Icons.check_circle_rounded,   'Go live',     phase == _Phase.ready),
    ];

    return Column(
      children: [
        // animated gradient rail
        SizedBox(
          height: 6,
          child: LayoutBuilder(builder: (_, c) {
            final t = (pulse.value); // 0..1
            final w = c.maxWidth;
            final activeW = (.25 + .6 * t) * w;
            return Stack(
              children: [
                Container(
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(999),
                    color: Colors.white.withOpacity(.10),
                  ),
                ),
                AnimatedContainer(
                  duration: const Duration(milliseconds: 250),
                  width: activeW,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(999),
                    gradient: const LinearGradient(colors: [kTalkeePrimary, Color(0xFF3E2374)]),
                    boxShadow: [BoxShadow(color: kTalkeePrimary.withOpacity(.35), blurRadius: 10)],
                  ),
                ),
              ],
            );
          }),
        ),
        const SizedBox(height: 12),
        // steps row
        Column(
          children: List.generate(steps.length, (i) {
            final (icon, label, active) = steps[i];
            return Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(
                children: [
                  _StepDot(active: active, pulse: pulse),
                  const SizedBox(width: 12),
                  Icon(icon, color: Colors.white.withOpacity(active ? 1 : .6), size: 18),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      label,
                      style: TextStyle(
                        color: Colors.white.withOpacity(active ? 1 : .75),
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                  ),
                  AnimatedSwitcher(
                    duration: const Duration(milliseconds: 180),
                    child: active
                        ? const Icon(Icons.check_rounded, key: ValueKey(true), color: Colors.white)
                        : const SizedBox(key: ValueKey(false), width: 24),
                  ),
                ],
              ),
            );
          }),
        ),
      ],
    );
  }
}

class _StepDot extends StatelessWidget {
  final bool active;
  final AnimationController pulse;
  const _StepDot({required this.active, required this.pulse});
  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: pulse,
      builder: (_, __) {
        final scale = active ? (0.9 + 0.2 * pulse.value) : 1.0;
        return Transform.scale(
          scale: scale,
          child: Container(
            width: 8, height: 8,
            decoration: BoxDecoration(
              color: active ? kTalkeePrimary : Colors.white.withOpacity(.35),
              shape: BoxShape.circle,
              boxShadow: [if (active) BoxShadow(color: kTalkeePrimary, blurRadius: 10)],
            ),
          ),
        );
      },
    );
  }
}

class _GlassChip extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  const _GlassChip({required this.icon, required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      borderRadius: BorderRadius.circular(999),
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(999),
          color: Colors.white.withOpacity(.10),
          border: Border.all(color: Colors.white.withOpacity(.20)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, color: Colors.white, size: 16),
            const SizedBox(width: 8),
            Text(label, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
          ],
        ),
      ),
    );
  }
}

/* ------------------- Ambient Layers ------------------- */

class _GradientBackdrop extends StatelessWidget {
  const _GradientBackdrop();
  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment(-.8, -.8),
          end: Alignment(.8, .8),
          colors: [Color(0xFF0F0C29), Color(0xFF302B63), Color(0xFF24243E)],
        ),
      ),
    );
  }
}

class _AuroraLayer extends StatelessWidget {
  final AnimationController controller;
  const _AuroraLayer({required this.controller});

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: controller,
      builder: (_, __) => CustomPaint(painter: _AuroraPainter(controller.value)),
    );
  }
}

class _AuroraPainter extends CustomPainter {
  final double t;
  _AuroraPainter(this.t);

  @override
  void paint(Canvas canvas, Size size) {
    // two soft ribbons
    void ribbon(Color a, Color b, double yBase, double amp, double phase) {
      final path = Path();
      path.moveTo(0, yBase);
      for (double x = 0; x <= size.width; x += 12) {
        final y = yBase + math.sin((x / size.width * 2 * math.pi) + (t * 2 * math.pi) + phase) * amp;
        path.lineTo(x, y);
      }
      path.lineTo(size.width, yBase + 200);
      path.lineTo(0, yBase + 200);
      path.close();
      final paint = Paint()
        ..shader = LinearGradient(
          begin: Alignment.topCenter, end: Alignment.bottomCenter,
          colors: [a.withOpacity(.18), b.withOpacity(.04)],
        ).createShader(Rect.fromLTWH(0, yBase - amp.abs(), size.width, 200 + amp.abs()));
      canvas.drawPath(path, paint);
    }

    ribbon(kTalkeePrimary, Colors.white, size.height * .30, 36, 0);
    ribbon(const Color(0xFF3E2374), Colors.white, size.height * .55, 46, math.pi / 2);
  }

  @override
  bool shouldRepaint(covariant _AuroraPainter old) => old.t != t;
}

class _BokehLayer extends StatelessWidget {
  final AnimationController controller;
  const _BokehLayer({required this.controller});
  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: controller,
      builder: (_, __) {
        final t = controller.value;
        final bubbles = <_Bubble>[
          _Bubble(Offset(0.2 + 0.02 * math.sin(t * 6.0), 0.25 + 0.01 * math.cos(t * 4.0)), 50, .10),
          _Bubble(Offset(0.8 + 0.02 * math.cos(t * 5.0), 0.30 + 0.01 * math.sin(t * 3.5)), 70, .08),
          _Bubble(Offset(0.65 + 0.015 * math.sin(t * 3.2), 0.75 + 0.02 * math.cos(t * 2.7)), 60, .12),
          _Bubble(Offset(0.35 + 0.025 * math.cos(t * 4.8), 0.65 + 0.015 * math.sin(t * 4.2)), 40, .10),
        ];
        return CustomPaint(painter: _BokehPainter(bubbles));
      },
    );
  }
}

class _Bubble {
  final Offset normPos; // 0..1
  final double size;
  final double opacity;
  _Bubble(this.normPos, this.size, this.opacity);
}
class _BokehPainter extends CustomPainter {
  final List<_Bubble> bubbles;
  _BokehPainter(this.bubbles);

  @override
  void paint(Canvas canvas, Size size) {
    for (final b in bubbles) {
      final p = Paint()
        ..color = Colors.white.withOpacity(b.opacity)
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 20);
      final pos = Offset(b.normPos.dx * size.width, b.normPos.dy * size.height);
      canvas.drawCircle(pos, b.size, p);
    }
  }
  @override
  bool shouldRepaint(covariant _BokehPainter old) => true;
}
class _ErrorFooter extends StatelessWidget {
  final Future<void> Function() onRetry;
  const _ErrorFooter({required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        const SizedBox(height: 4),
        const Text(
          'We couldn’t start your live. Please try again.',
          textAlign: TextAlign.center,
          style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 10),
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: kTalkeePrimary),
          onPressed: () async {
            await onRetry(); // <-- wrap the Future in an async void
          },
          child: const Text('Try again'),
        ),
      ],
    );
  }
}
