import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/services.dart';

/// Simple, safe, throttled haptic feedback utility.
///
/// Works out of the box (no plugins). It:
/// - exposes common effects (selection/light/medium/heavy/success/warning/error/vibrate)
/// - throttles rapid calls so they don’t get ignored by the OS
/// - lets you globally enable/disable and tweak the minimum gap between pulses
///
/// Usage:
///   Haptics.selection();
///   Haptics.light();
///   Haptics.success();  // double-tap style tick
///   Haptics.error();    // beefier pattern
///   Haptics.enabled = false; // to globally disable (e.g., user setting)
class Haptics {
  Haptics._();

  /// Global on/off (wire this to your Settings).
  static bool enabled = true;

  /// Minimum gap between pulses to avoid OS rejection (spam guard).
  static Duration minGap = const Duration(milliseconds: 45);

  static DateTime? _last;

  static bool _shouldThrottle() {
    final now = DateTime.now();
    if (_last == null || now.difference(_last!) >= minGap) {
      _last = now;
      return false;
    }
    return true;
  }

  static Future<void> _safe(Future<void> Function() effect) async {
    if (!enabled) return;
    // On web and some emulators, haptics are no-ops; just swallow.
    if (kIsWeb) return;
    if (_shouldThrottle()) return;
    try {
      await effect();
    } catch (_) {
      // Ignore – not supported on this platform/device.
    }
  }

  /// Subtle one-tick for small interactions (e.g., toggles, chip taps).
  static Future<void> selection() => _safe(HapticFeedback.selectionClick);

  /// iOS: UIImpactFeedbackStyle.light | Android: light tick.
  static Future<void> light() => _safe(HapticFeedback.lightImpact);

  /// iOS: UIImpactFeedbackStyle.medium | Android: medium tick.
  static Future<void> medium() => _safe(HapticFeedback.mediumImpact);

  /// iOS: UIImpactFeedbackStyle.heavy | Android: strong tick.
  static Future<void> heavy() => _safe(HapticFeedback.heavyImpact);

  /// Generic “buzz”; can feel strong on some devices.
  static Future<void> vibrate() => _safe(HapticFeedback.vibrate);

  /// A pleasant double-tick for confirmations (save/success).
  static Future<void> success() async {
    await light();
    // Short, natural cadence
    await Future<void>.delayed(const Duration(milliseconds: 24));
    await light();
  }

  /// Noticeable tick for “be careful” moments.
  static Future<void> warning() async {
    await medium();
  }

  /// A stronger 2-step pattern for failures/destructive actions.
  static Future<void> error() async {
    await heavy();
    await Future<void>.delayed(const Duration(milliseconds: 30));
    await medium();
  }
}
