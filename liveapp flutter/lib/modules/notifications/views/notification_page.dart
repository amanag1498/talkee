import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:get/get.dart';
import 'package:intl/intl.dart';

import 'package:liveapp/app/widgets/animated_background.dart';
import 'package:liveapp/app/widgets/talkee_logo.dart';

import 'package:liveapp/modules/notifications/controllers/notification_controller.dart';
import 'package:liveapp/modules/notifications/models/notification_dto.dart';
import 'package:liveapp/modules/notifications/widgets/bell_badge_btn.dart';

import 'package:liveapp/modules/home/controllers/home_controller.dart';
import 'package:liveapp/modules/Live/views/live_preflight_sheet.dart';
import 'package:liveapp/services/app_settings_service.dart';
import 'package:liveapp/app/theme/brand.dart';

PremiumThemeTokens _notifTokens() =>
    getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );

class NotificationsPage extends StatefulWidget {
  const NotificationsPage({super.key});

  @override
  State<NotificationsPage> createState() => _NotificationsPageState();
}

class _NotificationsPageState extends State<NotificationsPage>
    with TickerProviderStateMixin {
  late final ScrollController _scroll;
  late final NotificationsController c;

  final _query = TextEditingController();
  String _activeType = 'all';
  final _selected = <String>{};

  // keep your background controller (no extra animations from me)
  final _bg = AnimatedBackgroundController();

  @override
  void initState() {
    super.initState();
    c = Get.find<NotificationsController>();
    _scroll = ScrollController()..addListener(_onScroll);
  }

  void _onScroll() {
    if (!_scroll.hasClients) return;
    final pos = _scroll.position;
    if (pos.pixels >= pos.maxScrollExtent - 200) {
      c.loadMore();
      _bg.pulse();
    }
  }

  @override
  void dispose() {
    _scroll.removeListener(_onScroll);
    _scroll.dispose();
    _query.dispose();
    _bg.dispose();
    super.dispose();
  }

  bool get _isSelecting => _selected.isNotEmpty;

  void _toggleSelect(String id) {
    setState(() {
      if (_selected.contains(id)) {
        _selected.remove(id);
      } else {
        _selected.add(id);
      }
    });
  }

  void _clearSelection() => setState(_selected.clear);

  Future<void> _markSelectedRead() async {
    if (_selected.isEmpty) return;
    final ids = _selected.toList();
    _clearSelection();
    await c.markManyRead(ids);
    _bg.pulse();
  }

  List<_Section> _group(List<NotificationDto> all) {
    // filter by query + type
    final q = _query.text.trim().toLowerCase();
    Iterable<NotificationDto> list = all;
    if (q.isNotEmpty) {
      list = list.where((n) =>
      n.title.toLowerCase().contains(q) ||
          n.body.toLowerCase().contains(q) ||
          n.type.toLowerCase().contains(q));
    }
    switch (_activeType) {
      case 'unread':
        list = list.where((n) => n.isUnread);
        break;
      case 'approvals':
        list =
            list.where((n) => n.type.contains('approved') || n.type.contains('rejected'));
        break;
      case 'system':
        list = list.where(
                (n) => !(n.type.contains('approved') || n.type.contains('rejected')));
        break;
      default:
        break;
    }

    // group by Today / Yesterday / Earlier
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final yesterday = today.subtract(const Duration(days: 1));

    final todayList = <NotificationDto>[];
    final ydayList = <NotificationDto>[];
    final earlierList = <NotificationDto>[];

    for (final n in list) {
      final d = n.createdAt;
      final dateOnly = DateTime(d.year, d.month, d.day);
      if (dateOnly == today) {
        todayList.add(n);
      } else if (dateOnly == yesterday) {
        ydayList.add(n);
      } else {
        earlierList.add(n);
      }
    }

    final sections = <_Section>[];
    if (todayList.isNotEmpty) sections.add(_Section('Today', todayList));
    if (ydayList.isNotEmpty) sections.add(_Section('Yesterday', ydayList));
    if (earlierList.isNotEmpty) sections.add(_Section('Earlier', earlierList));
    return sections;
  }

  Future<void> _goLiveIfAllowed() async {
    if (!Get.isRegistered<HomeController>()) return;
    final hc = Get.find<HomeController>();
    if (_canUserGoLive(hc)) {
      await showLivePreflightSheet(context);
    }
  }

  bool _canUserGoLive(HomeController hc) {
    try {
      final dynamic u = hc.user;
      final v = (u as dynamic).canGoLive;
      if (v is bool) return v;
    } catch (_) {}
    try {
      final roles = hc.user.roles;
      if (roles.contains('host')) return true;
    } catch (_) {}
    return false;
  }

  @override
  Widget build(BuildContext context) {
    final mq = MediaQuery.of(context);
    return Obx(() {
      return Scaffold(
      extendBodyBehindAppBar: true,
      backgroundColor: _notifTokens().backgroundGradient.first,
      appBar: _GlassAppBarNotifications(
        isSelecting: _isSelecting,
        unreadCountRx: c.unreadCount,
        onBack: () => Get.back(),
        onMarkAllRead: () async {
          await c.markAllRead();
          _bg.pulse();
        },
        onMarkSelectedRead: _markSelectedRead,
        selectedCount: _selected.length,
        onGoLive: _goLiveIfAllowed,
      ),
      body: Stack(
        children: [
          // your animated background (theme-friendly)
          AnimatedBackground(
            enabled: true,
            controller: _bg,
            richness: 20,
            reactToPointer: true,
          ),
          Padding(
            padding: EdgeInsets.only(top: kToolbarHeight + mq.padding.top),
            child: Column(
              children: [
                _GlassSearchField(
                  controller: _query,
                  onChanged: (_) => setState(() {}),
                  onClear: () {
                    _query.clear();
                    setState(() {});
                  },
                ),
                _GlassFilterChips(
                  active: _activeType,
                  onChanged: (v) => setState(() => _activeType = v),
                ),
                const SizedBox(height: 6),

                // Body
                Expanded(
                  child: Obx(() {
                    if (c.loading.value && c.items.isEmpty) {
                      return const _SkeletonList();
                    }
                    if (c.items.isEmpty) {
                      return RefreshIndicator(
                        onRefresh: c.refreshAll,
                        child: ListView(
                          physics: const AlwaysScrollableScrollPhysics(),
                          children: const [
                            SizedBox(height: 120),
                            _EmptyState(),
                          ],
                        ),
                      );
                    }

                    // Flatten sections into rows for a simple ListView (no slivers -> no crash)
                    final sections = _group(c.items);
                    final rows = <_Row>[];
                    for (final s in sections) {
                      rows.add(_Row.header(s.title));
                      for (final n in s.items) {
                        rows.add(_Row.item(n));
                      }
                    }

                    return RefreshIndicator(
                      onRefresh: c.refreshAll,
                      child: ListView.separated(
                        controller: _scroll,
                        padding: EdgeInsets.zero,
                        itemCount: rows.length + 2, // + "No more" + bottom gap
                        separatorBuilder: (_, __) => const SizedBox(height: 8),
                        itemBuilder: (ctx, i) {
                          // tail extras
                          if (i == rows.length) {
                            return Padding(
                              padding: const EdgeInsets.symmetric(vertical: 16),
                              child: Center(
                                child: c.hasMore
                                    ? const SizedBox(
                                    height: 28,
                                    width: 28,
                                    child: CircularProgressIndicator())
                                    : const Text('No more',
                                    style: TextStyle(fontWeight: FontWeight.w700)),
                              ),
                            );
                          }
                          if (i == rows.length + 1) {
                            return const SizedBox(height: 12);
                          }

                          final row = rows[i];
                          if (row.isHeader) {
                            return _StickyLookHeader(title: row.header!);
                          } else {
                            final n = row.item!;
                            final selected = _selected.contains(n.id);
                            return _NotificationTile(
                              key: ValueKey('notif-${n.id}'),
                              data: n,
                              selected: selected,
                              onLongPress: () => _toggleSelect(n.id),
                              onSelectTap: () => _toggleSelect(n.id),
                              onOpen: () => c.markRead(n.id),
                              onMarkRead: n.isUnread ? () => c.markRead(n.id) : null,
                            );
                          }
                        },
                      ),
                    );
                  }),
                ),
              ],
            ),
          ),
        ],
      ),
    );
    });
  }
}

/* ────────────────────────────────────────────────────────────────────────────
   App Bar (glass, theme-aware)
   ─────────────────────────────────────────────────────────────────────────── */
class _GlassAppBarNotifications extends StatelessWidget
    implements PreferredSizeWidget {
  final bool isSelecting;
  final RxInt unreadCountRx;
  final VoidCallback onBack;
  final Future<void> Function()? onMarkAllRead;
  final Future<void> Function()? onMarkSelectedRead;
  final int selectedCount;
  final Future<void> Function()? onGoLive;

  const _GlassAppBarNotifications({
    required this.isSelecting,
    required this.unreadCountRx,
    required this.onBack,
    this.onMarkAllRead,
    this.onMarkSelectedRead,
    required this.selectedCount,
    this.onGoLive,
  });

  @override
  Size get preferredSize => const Size.fromHeight(kToolbarHeight);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final tokens = _notifTokens();

    return AppBar(
      automaticallyImplyLeading: false,
      backgroundColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      surfaceTintColor: Colors.transparent,
      titleSpacing: 12,
      title: Row(
        children: [
          IconButton(
            tooltip: 'Back',
            onPressed: onBack,
            icon: const Icon(Icons.chevron_left_rounded),
          ),
          Hero(
            tag: 'brand.logo',
            child: TalkeeLogo(
              size: 24,
              showWordmark: true,
              wordmarkBelow: false,
              wordmarkStyle: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w800,
                color: tokens.textPrimary,
              ),
            ),
          ),
          const Spacer(),
          const BellBadgeButton(),
          const SizedBox(width: 6),
        ],
      ),
      actions: [
        if (!isSelecting)
          Obx(() => TextButton(
            onPressed: unreadCountRx.value > 0 ? onMarkAllRead : null,
            child: const Text('Mark all read'),
          )),
        if (isSelecting)
          TextButton.icon(
            onPressed: onMarkSelectedRead,
            icon: const Icon(Icons.mark_email_read_rounded, size: 18),
            label: Text('Mark $selectedCount read'),
          ),
        const SizedBox(width: 6),
      ],
      flexibleSpace: ClipRect(
        child: BackdropFilter(
          filter: ImageFilter.blur(sigmaX: 10, sigmaY: 10),
          child: Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  tokens.cardGradient.first.withValues(alpha: .84),
                  tokens.cardGradient.last.withValues(alpha: .72),
                ],
              ),
              border: Border(
                bottom: BorderSide(
                  color: tokens.borderColor.withValues(alpha: .72),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/* ────────────────────────────────────────────────────────────────────────────
   Search / Chips / Headers
   ─────────────────────────────────────────────────────────────────────────── */
class _GlassSearchField extends StatelessWidget {
  final TextEditingController controller;
  final ValueChanged<String> onChanged;
  final VoidCallback onClear;
  const _GlassSearchField({
    required this.controller,
    required this.onChanged,
    required this.onClear,
  });

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final tokens = _notifTokens();

    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
              color: tokens.borderColor),
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: tokens.cardGradient,
          ),
        ),
        child: TextField(
        controller: controller,
        onChanged: onChanged,
        style: TextStyle(
          color: tokens.textPrimary,
          fontWeight: FontWeight.w600,
        ),
        cursorColor: theme.colorScheme.primary,
        textInputAction: TextInputAction.search,
        decoration: InputDecoration(
          hintText: 'Search here.....',
          hintStyle:
          TextStyle(color: tokens.textSecondary),
          prefixIcon: Icon(Icons.search_rounded, color: tokens.textPrimary),
          suffixIcon: controller.text.isNotEmpty
              ? IconButton(
            icon: Icon(Icons.close_rounded, color: tokens.textPrimary),
            onPressed: onClear,
          )
              : null,
          border: InputBorder.none,
          contentPadding:
          const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        ),
      ),
    ),
    );
  }
}

class _GlassFilterChips extends StatelessWidget {
  final String active;
  final ValueChanged<String> onChanged;
  const _GlassFilterChips({required this.active, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    final tokens = _notifTokens();

    Widget chip(String key, String label, IconData icon) {
      final selected = active == key;

      return InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => onChanged(key),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(
              color: selected
                  ? tokens.primaryButtonGradient.first.withOpacity(.7)
                  : tokens.borderColor.withOpacity(.82),
            ),
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: selected
                  ? [
                      tokens.chipColor.withOpacity(.96),
                      tokens.glassColor.withOpacity(.78),
                    ]
                  : [
                      tokens.chipColor.withOpacity(.82),
                      tokens.glassColor.withOpacity(.66),
                    ],
            ),
          ),
          child: Row(
            children: [
              Icon(icon, size: 16, color: tokens.textPrimary),
              const SizedBox(width: 8),
              Text(
                label,
                style: TextStyle(
                  color: tokens.textPrimary,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ],
          ),
        ),
      );
    }

    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(horizontal: 12),
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          chip('all', 'All', Icons.inbox_rounded),
          const SizedBox(width: 8),
          chip('unread', 'Unread', Icons.mark_email_unread_rounded),
          const SizedBox(width: 8),
          chip('approvals', 'Approvals', Icons.verified_rounded),
          const SizedBox(width: 8),
          chip('system', 'System', Icons.settings_suggest_rounded),
        ],
      ),
    );
  }
}

class _StickyLookHeader extends StatelessWidget {
  final String title;
  const _StickyLookHeader({required this.title});

  @override
  Widget build(BuildContext context) {
    final tokens = _notifTokens();
    return ClipRect(
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 10, sigmaY: 10),
        child: Container(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
          decoration: BoxDecoration(
            color: tokens.glassColor.withOpacity(.82),
            border: Border(bottom: BorderSide(color: tokens.borderColor.withOpacity(.5))),
          ),
          child: Text(
            title,
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w800,
              color: tokens.textPrimary.withOpacity(.92),
              letterSpacing: .2,
            ),
          ),
        ),
      ),
    );
  }
}

/* ────────────────────────────────────────────────────────────────────────────
   Notification Tile (glass)
   ─────────────────────────────────────────────────────────────────────────── */
class _NotificationTile extends StatelessWidget {
  final NotificationDto data;
  final bool selected;
  final VoidCallback onOpen;
  final VoidCallback? onMarkRead;
  final VoidCallback onLongPress;
  final VoidCallback onSelectTap;

  const _NotificationTile({
    super.key,
    required this.data,
    required this.onOpen,
    required this.onLongPress,
    required this.onSelectTap,
    this.onMarkRead,
    this.selected = false,
  });

  @override
  Widget build(BuildContext context) {
    final isUnread = data.isUnread;
    final tokens = _notifTokens();

    return Dismissible(
      key: key ?? ValueKey('notif-${data.id}'),
      direction: isUnread ? DismissDirection.endToStart : DismissDirection.none,
      background: _SwipeBG(icon: Icons.mark_email_read_rounded, alignEnd: true),
      confirmDismiss: (_) async {
        if (onMarkRead != null) onMarkRead!();
        return false;
      },
      child: InkWell(
        onTap: selected ? onSelectTap : onOpen,
        onLongPress: onLongPress,
        borderRadius: BorderRadius.circular(14),
        child: _GlassTile(
          selected: selected,
          unread: isUnread,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _LeadingIcon(type: data.type, unread: isUnread),
              const SizedBox(width: 12),
              Expanded(
                child: _TitleBody(
                  title: data.title,
                  body: data.body,
                  type: data.type,
                  createdAt: data.createdAt,
                ),
              ),
              const SizedBox(width: 8),
              Column(
                children: [
                  if (isUnread)
                    Container(
                      height: 10,
                      width: 10,
                      decoration: BoxDecoration(
                        color: tokens.primaryButtonGradient.first,
                        shape: BoxShape.circle,
                      ),
                    ),
                  if (selected)
                    Icon(Icons.check_circle, size: 18, color: tokens.textPrimary),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _GlassTile extends StatelessWidget {
  final bool selected;
  final bool unread;
  final Widget child;
  const _GlassTile(
      {required this.selected, required this.unread, required this.child});

  @override
  Widget build(BuildContext context) {
    final tokens = _notifTokens();
    final shellColors =
        unread
            ? tokens.cardGradient
            : [
              tokens.cardGradient.first.withOpacity(.9),
              tokens.cardGradient.last.withOpacity(.82),
            ];

    return AnimatedContainer(
      duration: const Duration(milliseconds: 200),
      curve: Curves.easeOut,
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(28),
        child: BackdropFilter(
          filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
          child: Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(28),
              border: Border.all(
                color:
                    selected
                        ? tokens.primaryButtonGradient.first.withOpacity(.6)
                        : tokens.borderColor,
              ),
              boxShadow: [
                BoxShadow(
                  color: tokens.glowColor.withOpacity(selected ? .3 : .22),
                  blurRadius: selected ? 28 : 24,
                  offset: const Offset(0, 10),
                ),
              ],
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: shellColors,
              ),
            ),
            child: child,
          ),
        ),
      ),
    );
  }
}

class _LeadingIcon extends StatelessWidget {
  final String type;
  final bool unread;
  const _LeadingIcon({required this.type, required this.unread});

  @override
  Widget build(BuildContext context) {
    final tokens = _notifTokens();
    final icon = _iconFor(type);

    return Container(
      height: 42,
      width: 42,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        gradient: LinearGradient(
          colors: tokens.primaryButtonGradient,
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(color: tokens.borderColor),
        boxShadow: [
          BoxShadow(
              color: tokens.glowColor.withOpacity(.35),
              blurRadius: 14,
              offset: const Offset(0, 6))
        ],
      ),
      child: Icon(icon, color: tokens.textPrimary),
    );
  }

  IconData _iconFor(String type) {
    switch (type) {
      case 'host_approved':
        return Icons.verified_rounded;
      case 'host_rejected':
        return Icons.block_rounded;
      case 'host_blocked':
        return Icons.lock_rounded;
      case 'host_unblocked':
        return Icons.lock_open_rounded;
      case 'agency_approved':
        return Icons.apartment_rounded;
      case 'agency_rejected':
        return Icons.block_rounded;
      case 'agency_blocked':
        return Icons.block_rounded;
      case 'agency_unblocked':
        return Icons.lock_open_rounded;
      default:
        return Icons.notifications_rounded;
    }
  }
}

class _TitleBody extends StatelessWidget {
  final String title, body, type;
  final DateTime createdAt;
  const _TitleBody({
    required this.title,
    required this.body,
    required this.type,
    required this.createdAt,
  });

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final tokens = _notifTokens();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title,
            style: t.titleMedium?.copyWith(
              fontWeight: FontWeight.w700,
              color: tokens.textPrimary,
            )),
        const SizedBox(height: 4),
        Text(
          body,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          style: t.bodyMedium?.copyWith(
            color: tokens.textSecondary.withOpacity(.92),
          ),
        ),
        const SizedBox(height: 6),
        Row(
          children: [
            _TypeChip(type: type),
            const SizedBox(width: 8),
            Text(
              _ago(createdAt),
              style: t.bodySmall?.copyWith(
                color: tokens.textSecondary.withOpacity(.78),
              ),
            ),
          ],
        ),
      ],
    );
  }

  String _ago(DateTime dt) {
    final now = DateTime.now();
    final diff = now.difference(dt);
    if (diff.inMinutes < 1) return 'just now';
    if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
    if (diff.inHours < 24) return '${diff.inHours}h ago';
    if (diff.inDays < 7) return '${diff.inDays}d ago';
    return DateFormat('MMM d, yyyy').format(dt);
  }
}

class _TypeChip extends StatelessWidget {
  final String type;
  const _TypeChip({required this.type});

  @override
  Widget build(BuildContext context) {
    final tokens = _notifTokens();
    final label = type.isEmpty ? 'general' : type.replaceAll('_', ' ');
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tokens.borderColor),
        color: tokens.chipColor.withOpacity(.78),
      ),
      child: Text(
        label,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
          color: tokens.textPrimary,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

class _SwipeBG extends StatelessWidget {
  final IconData icon;
  final bool alignEnd;
  const _SwipeBG({required this.icon, this.alignEnd = false});

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Container(
      alignment: alignEnd ? Alignment.centerRight : Alignment.centerLeft,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      color: cs.primary.withOpacity(.15),
      child: Icon(icon, color: cs.primary),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState();

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final tokens = _notifTokens();

    return Column(
      children: [
        Icon(Icons.inbox_rounded, size: 52, color: tokens.textSecondary.withOpacity(.7)),
        const SizedBox(height: 12),
        Text('No notifications',
            style: t.titleMedium?.copyWith(
              fontWeight: FontWeight.w800,
              color: tokens.textPrimary,
            )),
        const SizedBox(height: 6),
        Text(
          'You’ll see updates and approvals here.',
          style:
          t.bodySmall?.copyWith(color: tokens.textSecondary.withOpacity(.78)),
        ),
        const SizedBox(height: 18),
      ],
    );
  }
}

class _SkeletonList extends StatelessWidget {
  const _SkeletonList();

  @override
  Widget build(BuildContext context) {
    final tokens = _notifTokens();

    Widget skel() => Container(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      height: 72,
      decoration: BoxDecoration(
        color: tokens.glassColor.withOpacity(.72),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: tokens.borderColor),
      ),
    );
    return ListView.builder(
      physics: const NeverScrollableScrollPhysics(),
      itemCount: 6,
      itemBuilder: (_, __) => skel(),
    );
  }
}

class _Section {
  final String title;
  final List<NotificationDto> items;
  _Section(this.title, this.items);
}

// flattened row model for simple ListView
class _Row {
  final String? header;
  final NotificationDto? item;
  const _Row._({this.header, this.item});
  factory _Row.header(String t) => _Row._(header: t);
  factory _Row.item(NotificationDto n) => _Row._(item: n);
  bool get isHeader => header != null;
}
