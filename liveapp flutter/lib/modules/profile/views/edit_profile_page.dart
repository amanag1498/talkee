import 'dart:io';

import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/utils/avatar_url.dart';
import '../../../app/widgets/haptics.dart';
import '../../../services/api_client.dart';
import '../controllers/profile_controller.dart';
import '../services/avatar_image_picker_service.dart';

class EditProfilePage extends StatefulWidget {
  const EditProfilePage({super.key});

  @override
  State<EditProfilePage> createState() => _EditProfilePageState();
}

class _EditProfilePageState extends State<EditProfilePage> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _nameCtl;
  late final TextEditingController _stageCtl;
  late final TextEditingController _phoneCtl;
  late final TextEditingController _countryCtl;
  late final TextEditingController _cityCtl;
  late final TextEditingController _bioCtl;
  final _avatarPicker = AvatarImagePickerService();
  ProfileController get controller => Get.find<ProfileController>();
  String? _localAvatarPath;
  bool _isPickingAvatar = false;

  @override
  void initState() {
    super.initState();
    final profile = controller.profile.value;
    _nameCtl = TextEditingController(text: profile?.name ?? controller.currentUser?.name ?? '');
    _stageCtl = TextEditingController(text: profile?.hostProfile?.stageName ?? '');
    _phoneCtl = TextEditingController(text: profile?.hostProfile?.contactPhone ?? '');
    _countryCtl = TextEditingController(text: profile?.hostProfile?.country ?? '');
    _cityCtl = TextEditingController(text: profile?.hostProfile?.city ?? '');
    _bioCtl = TextEditingController(text: profile?.hostProfile?.bio ?? '');
  }

  @override
  void dispose() {
    _nameCtl.dispose();
    _stageCtl.dispose();
    _phoneCtl.dispose();
    _countryCtl.dispose();
    _cityCtl.dispose();
    _bioCtl.dispose();
    super.dispose();
  }

  Future<void> _pickAvatar() async {
    if (_isPickingAvatar || controller.isUploadingAvatar.value) return;
    Haptics.light();
    setState(() => _isPickingAvatar = true);
    try {
      final path = await _avatarPicker.pickAndOptimizeFromGallery();
      if (!mounted || path == null || path.isEmpty) return;
      setState(() => _localAvatarPath = path);
      final ok = await controller.uploadAvatar(path);
      if (!mounted) return;
      if (ok) {
        Haptics.success();
        setState(() => _localAvatarPath = null);
        Get.snackbar(
          'Profile updated',
          'Avatar updated successfully.',
          snackPosition: SnackPosition.BOTTOM,
        );
      } else {
        Haptics.error();
        Get.snackbar(
          'Avatar upload failed',
          controller.error.value ?? 'Please try again.',
          snackPosition: SnackPosition.BOTTOM,
        );
      }
    } on AvatarImagePickerException catch (e) {
      if (!mounted) return;
      Haptics.error();
      Get.snackbar(
        'Image not supported',
        e.message,
        snackPosition: SnackPosition.BOTTOM,
      );
    } catch (e) {
      if (!mounted) return;
      Haptics.error();
      Get.snackbar(
        'Avatar upload failed',
        e.toString().replaceFirst('Exception: ', ''),
        snackPosition: SnackPosition.BOTTOM,
      );
    } finally {
      if (mounted) {
        setState(() => _isPickingAvatar = false);
      }
    }
  }

  Future<void> _submit() async {
    Haptics.medium();
    if (!_formKey.currentState!.validate()) return;
    final ok = await controller.saveProfile(
      name: _nameCtl.text.trim(),
      stageName: _stageCtl.text.trim().isEmpty ? null : _stageCtl.text.trim(),
      contactPhone: _phoneCtl.text.trim().isEmpty ? null : _phoneCtl.text.trim(),
      country: _countryCtl.text.trim().isEmpty ? null : _countryCtl.text.trim(),
      city: _cityCtl.text.trim().isEmpty ? null : _cityCtl.text.trim(),
      bio: _bioCtl.text.trim().isEmpty ? null : _bioCtl.text.trim(),
    );
    if (!mounted) return;
    if (ok) {
      Haptics.success();
      Get.back<void>();
      Get.snackbar('Profile updated', 'Your profile changes were saved.', snackPosition: SnackPosition.BOTTOM);
    } else {
      Haptics.error();
      Get.snackbar('Update failed', controller.error.value ?? 'Please try again.', snackPosition: SnackPosition.BOTTOM);
    }
  }

  @override
  Widget build(BuildContext context) {
    final api = Get.find<ApiClient>();
    return Scaffold(
      appBar: AppBar(title: const Text('Update Profile')),
      body: Obx(() {
        final profile = controller.profile.value;
        final isHost = profile?.isHost == true && profile?.hostProfile != null;
        final remoteAvatarUrl = resolveAvatarUrl(api, profile?.avatarUrl);
        final isAvatarBusy = _isPickingAvatar || controller.isUploadingAvatar.value;
        final ImageProvider<Object>? avatarImage =
            _localAvatarPath != null
                ? FileImage(File(_localAvatarPath!))
                : (remoteAvatarUrl != null ? NetworkImage(remoteAvatarUrl) : null);
        return Form(
          key: _formKey,
          child: ListView(
            padding: const EdgeInsets.all(18),
            children: [
              Center(
                child: Column(
                  children: [
                    GestureDetector(
                      onTap: isAvatarBusy ? null : _pickAvatar,
                      child: Stack(
                        alignment: Alignment.center,
                        children: [
                          CircleAvatar(
                            radius: 42,
                            backgroundImage: avatarImage,
                            child: (_localAvatarPath == null &&
                                    (profile?.avatarUrl == null ||
                                        (profile?.avatarUrl?.isEmpty ?? true)))
                                ? Text(
                                    ((profile?.name ?? 'U').isEmpty
                                            ? 'U'
                                            : (profile?.name ?? 'U').substring(0, 1))
                                        .toUpperCase(),
                                  )
                                : null,
                          ),
                          Positioned(
                            right: 0,
                            bottom: 0,
                            child: Container(
                              width: 30,
                              height: 30,
                              decoration: BoxDecoration(
                                color: Theme.of(context).colorScheme.primary,
                                shape: BoxShape.circle,
                                border: Border.all(color: Colors.white, width: 2),
                              ),
                              child: isAvatarBusy
                                  ? const Padding(
                                      padding: EdgeInsets.all(6),
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                        color: Colors.white,
                                      ),
                                    )
                                  : const Icon(
                                      Icons.photo_library_rounded,
                                      size: 16,
                                      color: Colors.white,
                                    ),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 10),
                    TextButton.icon(
                      onPressed: isAvatarBusy ? null : _pickAvatar,
                      icon: isAvatarBusy
                          ? const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.photo_library_rounded),
                      label: Text(
                        controller.isUploadingAvatar.value
                            ? 'Uploading avatar...'
                            : _isPickingAvatar
                            ? 'Preparing image...'
                            : 'Choose from gallery',
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 18),
              TextFormField(
                controller: _nameCtl,
                textInputAction: TextInputAction.next,
                decoration: const InputDecoration(labelText: 'Name'),
                validator: (value) => (value == null || value.trim().isEmpty) ? 'Name is required.' : null,
              ),
              if (isHost) ...[
                const SizedBox(height: 22),
                Text(
                  'Host Details',
                  style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _stageCtl,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(labelText: 'Stage Name'),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _phoneCtl,
                  keyboardType: TextInputType.phone,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(labelText: 'Phone'),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _countryCtl,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(labelText: 'Country'),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _cityCtl,
                  textInputAction: TextInputAction.next,
                  decoration: const InputDecoration(labelText: 'City'),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _bioCtl,
                  minLines: 4,
                  maxLines: 6,
                  decoration: const InputDecoration(labelText: 'Bio / About'),
                ),
              ],
              const SizedBox(height: 18),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: controller.isSaving.value ? null : _submit,
                  child: controller.isSaving.value
                      ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Text('Save Changes'),
                ),
              ),
              if (controller.error.value != null) ...[
                const SizedBox(height: 12),
                Text(
                  controller.error.value!,
                  style: const TextStyle(color: Colors.redAccent),
                ),
              ],
            ],
          ),
        );
      }),
    );
  }
}
