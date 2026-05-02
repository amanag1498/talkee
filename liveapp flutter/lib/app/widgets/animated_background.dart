// lib/app/widgets/animated_background.dart
// Talkee — Premium Mesh Background
// Branded gradient mesh + moving aurora blobs + subtle texture + drifting icons.

import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../services/app_settings_service.dart';
import '../theme/brand.dart';

double _now() => DateTime.now().millisecondsSinceEpoch / 1000.0;

/* ───────────────── Controller (API unchanged) ───────────────── */

class AnimatedBackgroundController extends ChangeNotifier {
  double _energy = 0.18; // calmer default
  double get energy => _energy;

  double _lastPulse = -1e9;

  void setEnergy(double v) {
    final nv = v.clamp(0.0, 1.0);
    if (nv == _energy) return;
    _energy = nv;
    notifyListeners();
  }

  void pulse() {
    _lastPulse = _now();
    notifyListeners();
  }

  double takePulseAge([double life = .7]) {
    final age = _now() - _lastPulse;
    return age > life ? 1e9 : age;
  }
}

/* ───────────────── Public widget ───────────────── */

class AnimatedBackground extends StatefulWidget {
  final bool enabled;
  final AnimatedBackgroundController? controller;
  final double visualScale;
  final int richness; // icons count target
  final bool reactToPointer;

  /// Live elements to draw (icons only).
  final List<IconData> iconSet;

  const AnimatedBackground({
    super.key,
    this.enabled = true,
    this.controller,
    this.visualScale = 1.0,
    this.richness = 8,             // sparser by default
    this.reactToPointer = false,   // non-reactive default
    this.iconSet = const [
      Icons.mic_rounded,
      Icons.videocam_rounded,
      Icons.favorite_rounded,
      Icons.chat_bubble_rounded,
      Icons.card_giftcard_rounded,
      Icons.wifi_tethering_rounded,
      Icons.music_note_rounded,
      Icons.send_rounded,
      Icons.people_alt_rounded,
      Icons.emoji_events_rounded,
    ],
  });

  @override
  State<AnimatedBackground> createState() => _AnimatedBackgroundState();
}

class _AnimatedBackgroundState extends State<AnimatedBackground>
    with SingleTickerProviderStateMixin {
  late final AnimationController _clock;
  _Field? _field;
  Size _lastSize = Size.zero;
  double _lastVs = -1;
  Offset? _pointerN;

  @override
  void initState() {
    super.initState();
    _clock = AnimationController(vsync: this, duration: const Duration(seconds: 10))..repeat();
    widget.controller?.addListener(_bump);
  }

  void _bump() => setState(() {});
  @override
  void didUpdateWidget(covariant AnimatedBackground oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.controller != widget.controller) {
      oldWidget.controller?.removeListener(_bump);
      widget.controller?.addListener(_bump);
    }
  }

  @override
  void dispose() {
    widget.controller?.removeListener(_bump);
    _clock.dispose();
    super.dispose();
  }

  void _ensureField(Size size, double vs, _Palette pal) {
    if (_field == null || _lastSize != size || (_lastVs - vs).abs() > 1e-6) {
      _lastSize = size;
      _lastVs = vs;
      _field = _Field(
        rect: Offset.zero & size,
        vs: vs,
        approxCount: widget.richness,
        pal: pal,
        icons: widget.iconSet,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.enabled) return const SizedBox.shrink();

    final size = MediaQuery.of(context).size;
    final baseVs = (size.shortestSide / 390.0).clamp(0.9, 1.35);
    final vs = baseVs * widget.visualScale;

    final pal = _Palette.fromTheme(context);
    _ensureField(size, vs, pal);

    final layer = RepaintBoundary(
      child: AnimatedBuilder(
        animation: _clock,
        builder: (_, __) {
          final energy = widget.controller?.energy ?? 0.0;
          final pulseAge = widget.controller?.takePulseAge() ?? 1e9;
          final tick = _clock.value;
          _field!.step(
            dt: 1 / 60.0,
            energy: energy,
            pulseAge: pulseAge,
            pointerN: _pointerN,
          );

          return Stack(
            fit: StackFit.expand,
            children: [
              _Backdrop(pal: pal),
              _AuroraOrbs(pal: pal, t: tick, energy: energy),
              _Vignette(pal: pal),
              const _TextureOverlay(),
              CustomPaint(
                painter: _Painter(field: _field!, energy: energy, pal: pal, repaint: _clock),
              ),
            ],
          );
        },
      ),
    );

    if (!widget.reactToPointer) return layer;

    return Listener(
      onPointerHover: (e) => _updatePointer(e.localPosition, size),
      onPointerDown: (e) => _updatePointer(e.localPosition, size),
      onPointerMove: (e) => _updatePointer(e.localPosition, size),
      onPointerUp: (_) => _pointerN = null,
      onPointerCancel: (_) => _pointerN = null,
      child: layer,
    );
  }

  void _updatePointer(Offset pos, Size size) {
    setState(() {
      _pointerN = Offset(
        (pos.dx / size.width).clamp(0.0, 1.0),
        (pos.dy / size.height).clamp(0.0, 1.0),
      );
    });
  }
}

/* ───────────────── Palette ───────────────── */

class _Palette {
  final Color deep;     // 0xFF0E0821
  final Color brand1;   // 0xFF7B50C5
  final Color brand2;   // 0xFF3E2374
  final Color brand3;   // 0xFF9A7EF0
  final Color gold;     // 0xFFFFC107

  const _Palette(this.deep, this.brand1, this.brand2, this.brand3, this.gold);

  factory _Palette.fromTheme(BuildContext context) {
    final tokens =
        Get.isRegistered<AppSettingsService>()
            ? getPremiumThemeTokens(
              Get.find<AppSettingsService>().activePremiumThemeVariant,
            )
            : getPremiumThemeTokens('midnight');
    return _Palette(
      tokens.backgroundGradient.first,
      tokens.primaryButtonGradient.first,
      tokens.cardGradient.last,
      tokens.glowColor,
      tokens.dangerColor,
    );
  }
}

/* ───────────────── Backdrop layers ───────────────── */

class _Backdrop extends StatelessWidget {
  final _Palette pal;
  const _Backdrop({required this.pal});

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: const Alignment(-1.0, -1.0),
          end: const Alignment(1.0, 1.0),
          colors: [
            pal.deep,
            Color.lerp(pal.deep, pal.brand2, .52)!,
            Color.lerp(pal.deep, pal.brand1, .36)!,
            Color.lerp(pal.brand2, pal.brand1, .40)!,
          ],
          stops: const [0.0, 0.38, 0.78, 1.0],
        ),
      ),
    );
  }
}

class _AuroraOrbs extends StatelessWidget {
  final _Palette pal;
  final double t;
  final double energy;
  const _AuroraOrbs({required this.pal, required this.t, required this.energy});

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: CustomPaint(
        painter: _AuroraPainter(pal: pal, t: t, energy: energy),
        size: Size.infinite,
      ),
    );
  }
}

class _AuroraPainter extends CustomPainter {
  final _Palette pal;
  final double t;
  final double energy;
  const _AuroraPainter({required this.pal, required this.t, required this.energy});

  @override
  void paint(Canvas canvas, Size size) {
    final e = (0.18 + energy * 0.32).clamp(0.0, 1.0);
    final cx1 = size.width * (0.18 + 0.12 * math.sin(t * math.pi * 2));
    final cy1 = size.height * (0.20 + 0.07 * math.cos(t * math.pi * 2));
    final cx2 = size.width * (0.82 + 0.10 * math.cos(t * math.pi * 2 + 1.2));
    final cy2 = size.height * (0.72 + 0.06 * math.sin(t * math.pi * 2 + 0.8));
    final cx3 = size.width * (0.52 + 0.08 * math.sin(t * math.pi * 2 + 2.0));
    final cy3 = size.height * (0.40 + 0.05 * math.cos(t * math.pi * 2 + 1.7));

    void orb(Offset c, double r, Color color) {
      final rect = Rect.fromCircle(center: c, radius: r);
      final p = Paint()
        ..shader = RadialGradient(
          colors: [
            color.withOpacity(0.20 + 0.22 * e),
            color.withOpacity(0.05 + 0.06 * e),
            Colors.transparent,
          ],
          stops: const [0.0, 0.62, 1.0],
        ).createShader(rect)
        ..blendMode = BlendMode.plus;
      canvas.drawCircle(c, r, p);
    }

    orb(Offset(cx1, cy1), size.shortestSide * 0.52, pal.brand1);
    orb(Offset(cx2, cy2), size.shortestSide * 0.48, pal.brand3);
    orb(Offset(cx3, cy3), size.shortestSide * 0.42, pal.brand2);
  }

  @override
  bool shouldRepaint(covariant _AuroraPainter oldDelegate) {
    return oldDelegate.t != t ||
        oldDelegate.energy != energy ||
        oldDelegate.pal != pal;
  }
}

class _Vignette extends StatelessWidget {
  final _Palette pal;
  const _Vignette({required this.pal});

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: RadialGradient(
            center: const Alignment(0.0, -0.12),
            radius: 1.15,
            colors: [
              Colors.transparent,
              pal.deep.withOpacity(0.30),
            ],
            stops: const [0.54, 1.0],
          ),
        ),
      ),
    );
  }
}

class _TextureOverlay extends StatelessWidget {
  const _TextureOverlay();

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: CustomPaint(
        painter: _TexturePainter(),
        size: Size.infinite,
      ),
    );
  }
}

class _TexturePainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final grid = Paint()
      ..color = Colors.white.withOpacity(0.022)
      ..strokeWidth = 1;
    const step = 44.0;
    for (double x = 0; x <= size.width; x += step) {
      canvas.drawLine(Offset(x, 0), Offset(x, size.height), grid);
    }
    for (double y = 0; y <= size.height; y += step) {
      canvas.drawLine(Offset(0, y), Offset(size.width, y), grid);
    }

    final noise = Paint()
      ..color = Colors.white.withOpacity(0.012)
      ..style = PaintingStyle.fill;
    for (double x = 8; x < size.width; x += 32) {
      final y = 12 + (x * 0.37) % size.height;
      canvas.drawCircle(Offset(x, y), 0.7, noise);
    }
  }

  @override
  bool shouldRepaint(covariant _TexturePainter oldDelegate) => false;
}

/* ───────────────── Model / Field (gentle drift + parallax) ───────────────── */

class _Wanderer {
  Offset p; // position
  Offset v; // velocity
  final double size;
  final double layer; // 0..1 (parallax depth; 0 = near, 1 = far)
  final double seed;  // per-item phase
  final IconData icon;

  _Wanderer({
    required this.p,
    required this.v,
    required this.size,
    required this.layer,
    required this.seed,
    required this.icon,
  });
}

class _Field {
  final Rect rect;
  final double vs;
  final int approxCount;
  final _Palette pal;
  final List<IconData> icons;
  final math.Random rnd = math.Random(23);

  late final List<_Wanderer> items;

  _Field({
    required this.rect,
    required this.vs,
    required this.approxCount,
    required this.pal,
    required this.icons,
  }) {
    final n = approxCount.clamp(4, 14);
    items = List.generate(n, (i) {
      final p = Offset(
        rect.left + rnd.nextDouble() * rect.width,
        rect.top + rnd.nextDouble() * rect.height,
      );
      final base = 13.0 + rnd.nextDouble() * 16.0;
      final size = base * (0.9 + rnd.nextDouble() * 0.7) * vs;
      final layer = rnd.nextDouble(); // depth
      final speed = (6.0 + rnd.nextDouble() * 8.0) * (0.4 + 0.8 * (1.0 - layer)) * vs;
      final v = Offset.fromDirection(rnd.nextDouble() * math.pi * 2, speed);
      return _Wanderer(
        p: p,
        v: v,
        size: size,
        layer: layer,
        seed: rnd.nextDouble() * 1000.0,
        icon: icons[i % icons.length],
      );
    });
  }

  Offset _wrap(Offset p) {
    // wrap softly to avoid pops
    double x = p.dx, y = p.dy;
    const m = 48.0;
    if (x < rect.left - m) x = rect.right + m;
    if (x > rect.right + m) x = rect.left - m;
    if (y < rect.top - m) y = rect.bottom + m;
    if (y > rect.bottom + m) y = rect.top - m;
    return Offset(x, y);
  }

  // smooth sinusoidal drift with subtle curl
  Offset _flow(Offset p, double t, double seed, double layer) {
    final scale = (0.0016 + 0.0007 * layer) * vs;
    final f1 = math.sin((p.dx * scale) + t * (0.15 + 0.06 * layer) + seed);
    final f2 = math.cos((p.dy * scale * 0.9) - t * (0.14 + 0.05 * layer) + seed * 1.3);
    final u = f1 + math.cos((p.dy * scale * 1.15) + t * 0.06);
    final v = f2 - math.sin((p.dx * scale * 0.85) - t * 0.05);
    var dir = Offset(u, v);
    final d = dir.distance;
    if (d > 0) dir = dir / d;
    return dir;
  }

  void step({
    required double dt,
    required double energy,
    required double pulseAge,
    required Offset? pointerN,
  }) {
    final t = _now();

    // low caps for subtle motion
    final baseAcc = _lerpDouble(16.0, 30.0, energy);       // px/s^2
    final maxSpeed = _lerpDouble(20.0, 40.0, energy) * vs; // px/s
    double pulseBoost = 0.0;
    if (pulseAge < 1e9) {
      final fade = (1.0 - (pulseAge / 0.7)).clamp(0.0, 1.0).toDouble();
      if (fade > 0.001) pulseBoost = fade * 0.45;
    }

    // optional pointer bias (kept minimal)
    final target = pointerN == null
        ? null
        : Offset(rect.left + rect.width * pointerN.dx, rect.top + rect.height * pointerN.dy);

    for (final it in items) {
      // flow + parallax (far layers move less)
      final dir = _flow(it.p, t, it.seed, it.layer);
      Offset acc = dir * (baseAcc * (0.55 + 0.45 * (1.0 - it.layer)) * (1.0 + pulseBoost));

      if (target != null) {
        final toT = target - it.p;
        final d = toT.distance.clamp(1.0, 9999.0);
        final pull = (0.0 + 14.0 * (1.0 - it.layer)) * vs * (0.15 + 0.6 * energy);
        acc += (toT / d) * pull;
      }

      // integrate with light damping
      it.v = (it.v + acc * dt) * (0.978 - 0.012 * energy);
      final sp = it.v.distance;
      if (sp > maxSpeed) it.v = it.v / sp * maxSpeed;

      it.p = _wrap(it.p + it.v * dt);
    }
  }
}

/* ───────────────── Painter (subtle glyph field) ───────────────── */

class _Painter extends CustomPainter {
  final _Field field;
  final double energy;
  final _Palette pal;

  _Painter({required this.field, required this.energy, required this.pal, Listenable? repaint})
      : super(repaint: repaint);

  final _iconCache = <String, TextPainter>{};

  @override
  void paint(Canvas canvas, Size size) {
    _icons(canvas);
  }

  TextPainter _tp(_Wanderer w, Color color) {
    final key = '${w.icon.codePoint}_${w.size.toStringAsFixed(1)}_${color.value}';
    return _iconCache.putIfAbsent(key, () {
      return TextPainter(
        text: TextSpan(
          text: String.fromCharCode(w.icon.codePoint),
          style: TextStyle(
            fontFamily: w.icon.fontFamily,
            package: w.icon.fontPackage,
            fontSize: w.size,
            color: color,
            letterSpacing: 0.0,
          ),
        ),
        textDirection: TextDirection.ltr,
      )..layout();
    });
  }

  void _icons(Canvas canvas) {
    final base = pal.brand1; // #7B50C5
    final hi = Color.lerp(pal.brand3, pal.gold, 0.06 + 0.18 * energy)!;

    for (final w in field.items) {
      // tiny ambient shadow (much smaller + fainter)
      final shadow = Paint()
        ..color = Color.lerp(base, hi, 0.18)!.withOpacity(0.06 + 0.04 * (1.0 - w.layer))
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 3);
      canvas.drawCircle(w.p.translate(w.size * 0.04, w.size * 0.03), w.size * 0.28, shadow);

      // faint glyph with depth-based opacity
      final alpha = _lerpDouble(0.28, 0.14, w.layer); // far = fainter
      final color = Color.lerp(base, hi, 0.18 + 0.28 * energy)!.withOpacity(alpha);
      final tp = _tp(w, color);
      tp.paint(canvas, Offset(w.p.dx - tp.width / 2, w.p.dy - tp.height / 2));
    }
  }

  @override
  bool shouldRepaint(covariant _Painter old) => false;
}

/* ───────────────── Small util ───────────────── */

double _lerpDouble(num a, num b, double t) => a + (b - a) * t;
