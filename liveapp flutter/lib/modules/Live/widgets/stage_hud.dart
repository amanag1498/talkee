// lib/modules/live/widgets/stage_hud.dart
import 'package:flutter/material.dart';

class StageHUD extends StatelessWidget {
  final String title;
  final String timerText;
  final bool micOn;
  final bool camOn;
  final VoidCallback onToggleMic;
  final VoidCallback onToggleCam;
  final VoidCallback onEndHoldStart;
  final VoidCallback onEndHoldCancel;
  final Widget? rightExtra;

  const StageHUD({
    super.key,
    required this.title,
    required this.timerText,
    required this.micOn,
    required this.camOn,
    required this.onToggleMic,
    required this.onToggleCam,
    required this.onEndHoldStart,
    required this.onEndHoldCancel,
    this.rightExtra,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.all(12),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.black.withOpacity(.35),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withOpacity(.14)),
      ),
      child: Row(
        children: [
          const Icon(Icons.live_tv, color: Colors.white),
          const SizedBox(width: 8),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(title, maxLines: 1, overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: 14)),
              Opacity(opacity: .85, child: Text(timerText, style: const TextStyle(color: Colors.white70, fontWeight: FontWeight.w700, fontSize: 11))),
            ]),
          ),
          if (rightExtra != null) rightExtra!,
          const SizedBox(width: 8),
          _HudIcon(onTap: onToggleMic, active: micOn, activeIcon: Icons.mic, offIcon: Icons.mic_off),
          const SizedBox(width: 6),
          _HudIcon(onTap: onToggleCam, active: camOn, activeIcon: Icons.videocam, offIcon: Icons.videocam_off),
          const SizedBox(width: 10),
          _HoldToEnd(onHoldStart: onEndHoldStart, onHoldCancel: onEndHoldCancel),
        ],
      ),
    );
  }
}

class _HudIcon extends StatelessWidget {
  final VoidCallback onTap;
  final bool active;
  final IconData activeIcon, offIcon;
  const _HudIcon({required this.onTap, required this.active, required this.activeIcon, required this.offIcon});

  @override
  Widget build(BuildContext context) {
    return InkResponse(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.all(6),
        decoration: BoxDecoration(
          color: active ? Colors.white12 : Colors.redAccent,
          borderRadius: BorderRadius.circular(10),
        ),
        child: Icon(active ? activeIcon : offIcon, color: Colors.white, size: 18),
      ),
    );
  }
}

class _HoldToEnd extends StatefulWidget {
  final VoidCallback onHoldStart;
  final VoidCallback onHoldCancel;
  const _HoldToEnd({required this.onHoldStart, required this.onHoldCancel});

  @override
  State<_HoldToEnd> createState() => _HoldToEndState();
}

class _HoldToEndState extends State<_HoldToEnd> {
  bool _holding = false;
  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onLongPressStart: (_) { setState(() => _holding = true); widget.onHoldStart(); },
      onLongPressEnd: (_) { setState(() => _holding = false); widget.onHoldCancel(); },
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
        decoration: BoxDecoration(
          color: _holding ? Colors.red : Colors.redAccent,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(children: const [
          Icon(Icons.power_settings_new, color: Colors.white, size: 16),
          SizedBox(width: 6),
          Text('Hold to end', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 12)),
        ]),
      ),
    );
  }
}
