import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../app/widgets/framed_avatar.dart';
import '../controllers/user_block_controller.dart';

class PersonalBlockedUsersPage extends StatefulWidget {
  const PersonalBlockedUsersPage({super.key});

  @override
  State<PersonalBlockedUsersPage> createState() =>
      _PersonalBlockedUsersPageState();
}

class _PersonalBlockedUsersPageState extends State<PersonalBlockedUsersPage> {
  final UserBlockController blocks = Get.find<UserBlockController>();
  int? _busyUserId;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      blocks.refreshForCurrentAuth();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('People you blocked')),
      body: Obx(() {
        if (blocks.loading.value && blocks.blockedUsers.isEmpty) {
          return const Center(child: CircularProgressIndicator());
        }
        if (blocks.error.value != null && blocks.blockedUsers.isEmpty) {
          return _MessageState(
            icon: Icons.cloud_off_rounded,
            message: blocks.error.value!,
            action: 'Retry',
            onPressed: blocks.refreshForCurrentAuth,
          );
        }
        if (blocks.blockedUsers.isEmpty) {
          return const _MessageState(
            icon: Icons.shield_outlined,
            message: 'You have not blocked anyone.',
          );
        }
        return RefreshIndicator(
          onRefresh: blocks.refreshForCurrentAuth,
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: blocks.blockedUsers.length,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final row = blocks.blockedUsers[index];
              final userId = _asInt(row['user_id']);
              final name = (row['name'] ?? 'User').toString();
              return Card(
                child: ListTile(
                  leading: FramedAvatar(
                    avatarUrl: row['avatar_url']?.toString(),
                    label: name,
                    size: 48,
                    backgroundColor: Theme.of(context).colorScheme.primary,
                  ),
                  title: Text(name),
                  subtitle: Text(
                    row['is_host'] == true
                        ? 'Host rooms and direct interactions are hidden'
                        : 'Direct interactions are blocked',
                  ),
                  trailing: TextButton(
                    onPressed: userId == null || _busyUserId != null
                        ? null
                        : () => _confirmUnblock(context, blocks, userId, name),
                    child: _busyUserId == userId
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('Unblock'),
                  ),
                ),
              );
            },
          ),
        );
      }),
    );
  }

  Future<void> _confirmUnblock(
    BuildContext context,
    UserBlockController blocks,
    int userId,
    String name,
  ) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text('Unblock $name?'),
        content: const Text(
          'Their messages and direct interactions will be visible again. '
          'If they are a host, their live rooms will also reappear.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Unblock'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    if (!mounted) return;
    setState(() => _busyUserId = userId);
    try {
      await blocks.unblock(userId);
      Get.snackbar(
        'Privacy',
        '$name was unblocked.',
        snackPosition: SnackPosition.BOTTOM,
      );
    } catch (exception) {
      Get.snackbar(
        'Could not unblock',
        exception.toString().replaceFirst('Exception: ', ''),
        snackPosition: SnackPosition.BOTTOM,
      );
    } finally {
      if (mounted) setState(() => _busyUserId = null);
    }
  }

  static int? _asInt(dynamic value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse(value?.toString() ?? '');
  }
}

class _MessageState extends StatelessWidget {
  const _MessageState({
    required this.icon,
    required this.message,
    this.action,
    this.onPressed,
  });

  final IconData icon;
  final String message;
  final String? action;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48, color: Theme.of(context).colorScheme.primary),
            const SizedBox(height: 14),
            Text(message, textAlign: TextAlign.center),
            if (action != null) ...[
              const SizedBox(height: 12),
              TextButton(onPressed: onPressed, child: Text(action!)),
            ],
          ],
        ),
      ),
    );
  }
}
