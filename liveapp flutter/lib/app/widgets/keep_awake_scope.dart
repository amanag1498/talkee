import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

import '../../services/screen_awake_service.dart';

class KeepAwakeScope extends StatefulWidget {
  const KeepAwakeScope({
    super.key,
    required this.child,
    this.enabled = true,
  });

  final Widget child;
  final bool enabled;

  @override
  State<KeepAwakeScope> createState() => _KeepAwakeScopeState();
}

class _KeepAwakeScopeState extends State<KeepAwakeScope>
    with WidgetsBindingObserver {
  bool _acquired = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _sync();
  }

  @override
  void didUpdateWidget(covariant KeepAwakeScope oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.enabled != widget.enabled) {
      _sync();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _release();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!widget.enabled) return;
    if (state == AppLifecycleState.resumed) {
      KeepAwakeController.reassert();
    }
  }

  void _sync() {
    if (widget.enabled && !_acquired) {
      _acquired = true;
      KeepAwakeController.acquire();
      return;
    }

    if (!widget.enabled && _acquired) {
      _release();
    }
  }

  void _release() {
    if (!_acquired) return;
    _acquired = false;
    KeepAwakeController.release();
  }

  @override
  Widget build(BuildContext context) => widget.child;
}

class KeepAwakeController {
  const KeepAwakeController._();

  static int _activeScopes = 0;

  static void acquire() {
    _activeScopes++;
    if (_activeScopes == 1) {
      _setEnabled(true);
    }
  }

  static void release() {
    if (_activeScopes == 0) return;
    _activeScopes--;
    if (_activeScopes == 0) {
      _setEnabled(false);
    }
  }

  static void reassert() {
    if (_activeScopes > 0) {
      _setEnabled(true);
    }
  }

  static void _setEnabled(bool enabled) {
    unawaited(() async {
      try {
        if (enabled) {
          await ScreenAwakeService.enable();
          await WakelockPlus.enable();
        } else {
          await WakelockPlus.disable();
          await ScreenAwakeService.disable();
        }
      } catch (_) {
        // Wakelock can fail during platform teardown; the next resume reasserts.
      }
    }());
  }
}
