import 'package:flutter/widgets.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

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
    if (widget.enabled) {
      WakelockPlus.disable();
    }
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (!widget.enabled) return;
    if (state == AppLifecycleState.resumed) {
      WakelockPlus.enable();
    }
  }

  Future<void> _sync() async {
    if (widget.enabled) {
      await WakelockPlus.enable();
    } else {
      await WakelockPlus.disable();
    }
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
