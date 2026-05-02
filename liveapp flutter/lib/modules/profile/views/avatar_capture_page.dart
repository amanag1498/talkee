import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/theme/brand.dart';
import '../../../services/app_settings_service.dart';

PremiumThemeTokens _avatarCaptureTokens() => getPremiumThemeTokens(
  Get.find<AppSettingsService>().activePremiumThemeVariant,
);

class AvatarCapturePage extends StatefulWidget {
  const AvatarCapturePage({super.key});

  @override
  State<AvatarCapturePage> createState() => _AvatarCapturePageState();
}

class _AvatarCapturePageState extends State<AvatarCapturePage> {
  CameraController? _controller;
  bool _loading = true;
  bool _capturing = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _setup();
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  Future<void> _setup() async {
    try {
      final cameras = await availableCameras();
      final selected = cameras.firstWhere(
        (camera) => camera.lensDirection == CameraLensDirection.front,
        orElse: () => cameras.first,
      );
      final controller = CameraController(
        selected,
        ResolutionPreset.medium,
        enableAudio: false,
      );
      await controller.initialize();
      if (!mounted) {
        await controller.dispose();
        return;
      }
      setState(() {
        _controller = controller;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = 'Camera unavailable. ${e.toString().replaceFirst('Exception: ', '')}';
      });
    }
  }

  Future<void> _capture() async {
    final controller = _controller;
    if (controller == null || _capturing) return;
    setState(() => _capturing = true);
    try {
      final file = await controller.takePicture();
      if (!mounted) return;
      Get.back(result: file.path);
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _capturing = false;
        _error = 'Capture failed. ${e.toString().replaceFirst('Exception: ', '')}';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final tokens = _avatarCaptureTokens();
    return Scaffold(
      backgroundColor: tokens.backgroundGradient.first,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        foregroundColor: tokens.textPrimary,
        title: Text(
          'Capture Avatar',
          style: TextStyle(
            color: tokens.textPrimary,
            fontWeight: FontWeight.w800,
          ),
        ),
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
      body: _loading
          ? Center(
              child: CircularProgressIndicator(
                color: tokens.primaryButtonGradient.first,
              ),
            )
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(20),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          _error!,
                          style: TextStyle(color: tokens.textPrimary),
                          textAlign: TextAlign.center,
                        ),
                        const SizedBox(height: 12),
                        FilledButton(
                          onPressed: _setup,
                          style: FilledButton.styleFrom(
                            backgroundColor: tokens.primaryButtonGradient.first,
                            foregroundColor: tokens.textPrimary,
                          ),
                          child: const Text('Retry'),
                        ),
                      ],
                    ),
                  ),
                )
              : Stack(
                  children: [
                    Positioned.fill(
                      child: CameraPreview(_controller!),
                    ),
                    Positioned(
                      left: 20,
                      right: 20,
                      bottom: 28,
                      child: FilledButton.icon(
                        onPressed: _capturing ? null : _capture,
                        icon: _capturing
                            ? SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(
                                  strokeWidth: 2,
                                  color: tokens.textPrimary,
                                ),
                              )
                            : const Icon(Icons.camera_alt_rounded),
                        style: FilledButton.styleFrom(
                          backgroundColor: tokens.primaryButtonGradient.first,
                          foregroundColor: tokens.textPrimary,
                        ),
                        label: Text(_capturing ? 'Capturing...' : 'Use This Photo'),
                      ),
                    ),
                  ],
                ),
    );
  }
}
