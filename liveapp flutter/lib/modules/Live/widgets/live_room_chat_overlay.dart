import 'dart:async';
import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../../../app/theme/brand.dart';
import '../../../app/widgets/framed_avatar.dart';
import '../models/live_room_chat_message.dart';

class LiveRoomChatOverlay extends StatefulWidget {
  const LiveRoomChatOverlay({
    super.key,
    required this.messagesListenable,
    required this.viewerThemeKey,
    required this.roomId,
    required this.roomType,
    required this.onSend,
    required this.bottomOffset,
    this.topOffset = 0,
    this.maxWidth = 360,
    this.maxHeightFactor = 0.4,
    this.enabled = true,
    this.showEmptyPrompt = true,
    this.inputActions = const <Widget>[],
    this.footerActions = const <Widget>[],
    this.trailingActions = const <Widget>[],
    this.showSendButton = true,
    this.onMessageSenderTap,
    this.stickMessagesToBottom = false,
    this.compactBubbles = false,
  });

  final ValueListenable<List<LiveRoomChatMessage>> messagesListenable;
  final String viewerThemeKey;
  final String roomId;
  final String roomType;
  final Future<String?> Function(String message) onSend;
  final double bottomOffset;
  final double topOffset;
  final double maxWidth;
  final double maxHeightFactor;
  final bool enabled;
  final bool showEmptyPrompt;
  final List<Widget> inputActions;
  final List<Widget> footerActions;
  final List<Widget> trailingActions;
  final bool showSendButton;
  final ValueChanged<LiveRoomChatMessage>? onMessageSenderTap;
  final bool stickMessagesToBottom;
  final bool compactBubbles;

  @override
  State<LiveRoomChatOverlay> createState() => _LiveRoomChatOverlayState();
}

class _LiveRoomChatOverlayState extends State<LiveRoomChatOverlay> {
  final TextEditingController _input = TextEditingController();
  final ScrollController _scrollController = ScrollController();
  bool _sending = false;
  String? _inlineError;
  List<LiveRoomChatMessage> _lastMessages = const <LiveRoomChatMessage>[];

  @override
  void initState() {
    super.initState();
    _lastMessages = widget.messagesListenable.value;
    widget.messagesListenable.addListener(_handleMessagesChanged);
  }

  @override
  void didUpdateWidget(covariant LiveRoomChatOverlay oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.messagesListenable != widget.messagesListenable) {
      oldWidget.messagesListenable.removeListener(_handleMessagesChanged);
      _lastMessages = widget.messagesListenable.value;
      widget.messagesListenable.addListener(_handleMessagesChanged);
    }
  }

  @override
  void dispose() {
    widget.messagesListenable.removeListener(_handleMessagesChanged);
    _input.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _handleMessagesChanged() {
    if (!mounted) return;
    final next = widget.messagesListenable.value;
    if (next.length != _lastMessages.length ||
        (next.isNotEmpty &&
            _lastMessages.isNotEmpty &&
            next.last.id != _lastMessages.last.id)) {
      _lastMessages = next;
      scheduleMicrotask(_scrollToLatest);
    } else {
      _lastMessages = next;
    }
  }

  void _scrollToLatest() {
    if (!mounted || !_scrollController.hasClients) return;
    final position = _scrollController.position;
    final target = position.maxScrollExtent;
    _scrollController.animateTo(
      target,
      duration: const Duration(milliseconds: 220),
      curve: Curves.easeOutCubic,
    );
  }

  Future<void> _submit() async {
    if (_sending || !widget.enabled) return;
    final raw = _input.text.replaceAll('\n', ' ').trim();
    if (raw.isEmpty) {
      setState(() => _inlineError = 'Message cannot be empty.');
      return;
    }
    if (raw.length > 250) {
      setState(() => _inlineError = 'Message must be 250 characters or less.');
      return;
    }
    setState(() {
      _sending = true;
      _inlineError = null;
    });
    final error = await widget.onSend(raw);
    if (!mounted) return;
    if (error == null) {
      _input.clear();
    }
    setState(() {
      _sending = false;
      _inlineError = error;
    });
  }

  @override
  Widget build(BuildContext context) {
    final viewerTokens = getPremiumThemeTokens(widget.viewerThemeKey);
    final media = MediaQuery.of(context);
    final screenWidth = media.size.width;
    final isCompactDevice = screenWidth < 360 || media.size.height < 760;
    final maxHeight =
        media.size.height *
        (isCompactDevice
            ? math.min(widget.maxHeightFactor, 0.34)
            : widget.maxHeightFactor);
    final topOffset = math.max(0.0, widget.topOffset);
    final bottomInset = media.viewInsets.bottom;
    final maxAvailableHeight = math.max(
      0.0,
      media.size.height - topOffset - widget.bottomOffset - bottomInset,
    );
    final constrainedMaxHeight = math.min(maxHeight, maxAvailableHeight);
    final horizontalLeft = isCompactDevice ? 8.0 : screenWidth < 360 ? 10.0 : 12.0;
    final horizontalRight =
        isCompactDevice ? 2.0 : screenWidth < 360 ? 2.0 : screenWidth < 430 ? 4.0 : 8.0;
    final availableWidth = media.size.width - (horizontalLeft + horizontalRight);
    final overlayMaxWidth =
        widget.trailingActions.isNotEmpty
            ? availableWidth
            : math.min(widget.maxWidth, availableWidth);
    final messageRightGutter =
        widget.trailingActions.isNotEmpty
            ? math.min(availableWidth * (isCompactDevice ? 0.22 : 0.28), isCompactDevice ? 82.0 : 112.0)
            : 4.0;
    final trailingActionsMaxWidth =
        widget.trailingActions.isNotEmpty
            ? math.min(
              availableWidth * (isCompactDevice ? 0.34 : 0.42),
              isCompactDevice ? 114.0 : 156.0,
            )
            : 0.0;

    return IgnorePointer(
      ignoring: false,
      child: AnimatedPadding(
        duration: const Duration(milliseconds: 220),
        curve: Curves.easeOut,
        padding: EdgeInsets.only(top: topOffset, bottom: bottomInset),
        child: Align(
          alignment: Alignment.bottomLeft,
          child: Padding(
            padding: EdgeInsets.fromLTRB(
              horizontalLeft,
              0,
              horizontalRight,
              widget.bottomOffset,
            ),
            child: ConstrainedBox(
              constraints: BoxConstraints(
                maxWidth: overlayMaxWidth,
                maxHeight: constrainedMaxHeight,
              ),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(0, 0, 0, 0),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Flexible(
                      child: ValueListenableBuilder<List<LiveRoomChatMessage>>(
                        valueListenable: widget.messagesListenable,
                        builder: (context, messages, _) {
                          if (messages.isEmpty) {
                            if (!widget.showEmptyPrompt) {
                              return const SizedBox.shrink();
                            }
                            return Padding(
                              padding: EdgeInsets.fromLTRB(
                                4,
                                8,
                                messageRightGutter,
                                14,
                              ),
                              child: Text(
                                'Room chat is live. Say something...',
                                style: TextStyle(
                                  color: viewerTokens.textSecondary.withValues(alpha: .86),
                                  fontWeight: FontWeight.w700,
                                  fontSize: 12.2,
                                ),
                              ),
                            );
                          }
                          return LayoutBuilder(
                            builder: (context, listConstraints) {
                              return SingleChildScrollView(
                                controller: _scrollController,
                                padding: EdgeInsets.only(
                                  right: messageRightGutter,
                                  bottom: 10,
                                ),
                                child: ConstrainedBox(
                                  constraints: BoxConstraints(
                                    minHeight: listConstraints.maxHeight,
                                  ),
                                  child: Column(
                                    mainAxisAlignment: widget.stickMessagesToBottom
                                        ? MainAxisAlignment.end
                                        : MainAxisAlignment.start,
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      for (var index = 0; index < messages.length; index++)
                                        () {
                                          final message = messages[index];
                                          final distanceFromLatest = messages.length - 1 - index;
                                          final opacity = distanceFromLatest >= 6
                                              ? .48
                                              : distanceFromLatest >= 4
                                              ? .62
                                              : distanceFromLatest >= 2
                                              ? .78
                                              : 1.0;
                                          return TweenAnimationBuilder<double>(
                                            key: ValueKey(message.id),
                                            tween: Tween<double>(begin: 0, end: 1),
                                            duration: const Duration(milliseconds: 220),
                                            curve: Curves.easeOutCubic,
                                            builder: (context, progress, child) {
                                              return Opacity(
                                                opacity: opacity * progress,
                                                child: Transform.translate(
                                                  offset: Offset(0, (1 - progress) * 10),
                                                  child: child,
                                                ),
                                              );
                                            },
                                            child: _ChatBubble(
                                              message: message,
                                              compact: widget.compactBubbles,
                                              onSenderTap:
                                                  widget.onMessageSenderTap == null
                                                      ? null
                                                      : () => widget.onMessageSenderTap!(message),
                                            ),
                                          );
                                        }(),
                                    ],
                                  ),
                                ),
                              );
                            },
                          );
                        },
                      ),
                    ),
                    if (_inlineError != null) ...[
                      const SizedBox(height: 4),
                      Padding(
                        padding: const EdgeInsets.only(left: 4, bottom: 6),
                        child: Text(
                          _inlineError!,
                          style: TextStyle(
                            color: viewerTokens.dangerColor,
                            fontWeight: FontWeight.w700,
                            fontSize: 11.5,
                          ),
                        ),
                      ),
                    ],
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Expanded(
                          child: _InputBar(
                            controller: _input,
                            tokens: viewerTokens,
                            sending: _sending,
                            enabled: widget.enabled,
                            onSend: _submit,
                            actions: widget.inputActions,
                            showSendButton: widget.showSendButton,
                          ),
                        ),
                        if (widget.trailingActions.isNotEmpty) ...[
                          SizedBox(width: isCompactDevice ? 2 : 4),
                          ConstrainedBox(
                            constraints: BoxConstraints(
                              maxWidth: trailingActionsMaxWidth,
                            ),
                            child: FittedBox(
                              fit: BoxFit.scaleDown,
                              alignment: Alignment.centerRight,
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: widget.trailingActions,
                              ),
                            ),
                          ),
                        ],
                      ],
                    ),
                    if (widget.footerActions.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        child: Row(children: widget.footerActions),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _InputBar extends StatelessWidget {
  const _InputBar({
    required this.controller,
    required this.tokens,
    required this.sending,
    required this.enabled,
    required this.onSend,
    this.actions = const <Widget>[],
    this.showSendButton = true,
  });

  final TextEditingController controller;
  final PremiumThemeTokens tokens;
  final bool sending;
  final bool enabled;
  final VoidCallback onSend;
  final List<Widget> actions;
  final bool showSendButton;

  @override
  Widget build(BuildContext context) {
    final screenWidth = MediaQuery.of(context).size.width;
    final screenHeight = MediaQuery.of(context).size.height;
    final compact = screenWidth < 360 || screenHeight < 760;
    final controlHeight = compact ? 42.0 : 46.0;
    final leadingInset = compact ? 8.0 : screenWidth < 360 ? 10.0 : 12.0;
    final iconGap = compact ? 5.0 : screenWidth < 360 ? 6.0 : 8.0;
    final actionGap = compact ? 4.0 : screenWidth < 360 ? 6.0 : 8.0;
    final trailingInset =
        compact ? 3.0 : screenWidth < 360 ? 4.0 : screenWidth < 430 ? 6.0 : 8.0;
    return Container(
      height: controlHeight,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(999),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            tokens.cardGradient.first.withValues(alpha: .88),
            tokens.chipColor.withValues(alpha: .84),
          ],
        ),
        border: Border.all(color: tokens.borderColor.withValues(alpha: .18)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: .08),
            blurRadius: 10,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      child: Row(
        children: [
          SizedBox(width: leadingInset),
          Icon(
            Icons.chat_bubble_outline_rounded,
            size: 15,
            color: tokens.textSecondary.withValues(alpha: .76),
          ),
          SizedBox(width: iconGap),
          Expanded(
            child: Material(
              color: Colors.transparent,
              child: TextField(
                controller: controller,
                minLines: 1,
                maxLines: 1,
                textInputAction: TextInputAction.send,
                onSubmitted: (_) => onSend(),
                enabled: enabled && !sending,
                cursorColor: tokens.textPrimary,
                style: TextStyle(
                  color: tokens.textPrimary,
                  fontWeight: FontWeight.w600,
                  fontSize: compact ? 12.2 : 13,
                ),
                decoration: InputDecoration(
                  isDense: true,
                  isCollapsed: true,
                  filled: false,
                  fillColor: Colors.transparent,
                  hoverColor: Colors.transparent,
                  focusColor: Colors.transparent,
                  border: InputBorder.none,
                  enabledBorder: InputBorder.none,
                  focusedBorder: InputBorder.none,
                  disabledBorder: InputBorder.none,
                  hintText: enabled ? 'Say something...' : 'Chat unavailable',
                  hintStyle: TextStyle(
                    color: tokens.textSecondary.withValues(alpha: .74),
                    fontWeight: FontWeight.w600,
                    fontSize: compact ? 12.0 : 12.8,
                  ),
                ),
              ),
            ),
          ),
          if (actions.isNotEmpty) ...[
            SizedBox(width: actionGap),
            ...actions,
          ],
          if (showSendButton) ...[
            SizedBox(width: actionGap),
            GestureDetector(
              onTap: enabled && !sending ? onSend : null,
              child: AnimatedOpacity(
                duration: const Duration(milliseconds: 180),
                opacity: enabled ? 1 : .45,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: LinearGradient(colors: tokens.primaryButtonGradient),
                    boxShadow: [
                      BoxShadow(
                        color: tokens.glowColor.withValues(alpha: .22),
                        blurRadius: 16,
                      ),
                    ],
                  ),
                  child: SizedBox(
                    width: controlHeight,
                    height: controlHeight,
                      child: Center(
                      child: sending
                          ? SizedBox(
                              width: compact ? 16 : 18,
                              height: compact ? 16 : 18,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                valueColor: AlwaysStoppedAnimation<Color>(
                                  tokens.textPrimary,
                                ),
                              ),
                            )
                          : Icon(
                              Icons.arrow_upward_rounded,
                              color: tokens.textPrimary,
                              size: compact ? 18 : 20,
                            ),
                    ),
                  ),
                ),
              ),
            ),
          ],
          SizedBox(width: trailingInset),
        ],
      ),
    );
  }
}

class _ChatBubble extends StatelessWidget {
  const _ChatBubble({
    required this.message,
    required this.compact,
    this.onSenderTap,
  });

  final LiveRoomChatMessage message;
  final bool compact;
  final VoidCallback? onSenderTap;

  @override
  Widget build(BuildContext context) {
    final screenWidth = MediaQuery.of(context).size.width;
    final compactScreen = compact || screenWidth < 360;
    if (message.isSystem) {
      final tokens = getPremiumThemeTokens('midnight');
      return Align(
        alignment: Alignment.center,
        child: Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: tokens.chipColor.withValues(alpha: .86),
              borderRadius: BorderRadius.circular(999),
              border: Border.all(color: tokens.borderColor.withValues(alpha: .24)),
            ),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              child: Text(
                message.message,
                style: TextStyle(
                  color: tokens.textSecondary,
                  fontSize: 11.6,
                  fontWeight: FontWeight.w800,
                ),
                textAlign: TextAlign.center,
              ),
            ),
          ),
        ),
      );
    }

    final tokens = getPremiumThemeTokens(message.senderActiveThemeKey);
    final isHighLevel = (message.senderLevel ?? 0) >= 5;
    final bubbleGradient =
        message.senderIsVip
            ? <Color>[
              tokens.primaryButtonGradient.first.withValues(alpha: .28),
              tokens.cardGradient.last.withValues(alpha: .72),
            ]
            : message.senderIsHost
            ? <Color>[
              tokens.primaryButtonGradient.last.withValues(alpha: .24),
              tokens.cardGradient.first.withValues(alpha: .70),
            ]
            : <Color>[
              tokens.cardGradient.first.withValues(alpha: .66),
              tokens.cardGradient.last.withValues(alpha: .48),
            ];

    return Padding(
      padding: EdgeInsets.only(bottom: compact ? 4 : 8),
      child: Align(
        alignment: Alignment.centerLeft,
        child: ConstrainedBox(
          constraints: BoxConstraints(
            maxWidth: compactScreen
                ? math.min(screenWidth - 96, 224)
                : compact
                ? 248
                : 280,
          ),
          child: DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(compact ? 14 : 20),
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: bubbleGradient,
              ),
              border: Border.all(
                color: (message.senderIsHost || message.senderIsVip)
                    ? tokens.borderColor.withValues(alpha: .78)
                    : tokens.borderColor.withValues(alpha: .28),
                width: message.senderIsHost ? 1.1 : 1,
              ),
              boxShadow: [
                if (!compact && (message.senderIsVip || message.senderIsHost))
                  BoxShadow(
                    color: tokens.glowColor.withValues(alpha: message.senderIsVip ? .22 : .14),
                    blurRadius: 18,
                  ),
              ],
            ),
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                compact ? 8 : 10,
                compact ? 7 : 10,
                compact ? 8 : 10,
                compact ? 7 : 10,
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _SenderAvatar(
                    name: message.senderName,
                    avatarUrl: message.senderAvatar,
                    frameUrl: message.senderProfileFrame,
                    tokens: tokens,
                    compact: compact,
                    onTap: onSenderTap,
                  ),
                  SizedBox(width: compact ? 7 : 10),
                  Flexible(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Wrap(
                          spacing: compact ? 4 : 6,
                          runSpacing: compact ? 3 : 6,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            InkWell(
                              onTap: onSenderTap,
                              borderRadius: BorderRadius.circular(999),
                              child: Padding(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 1,
                                  vertical: 1,
                                ),
                                child: Text(
                                  message.senderName,
                                  style: TextStyle(
                                    color: tokens.primaryButtonGradient.first,
                                    fontWeight: FontWeight.w900,
                                    fontSize: compact ? 11.7 : 12.8,
                                  ),
                                ),
                              ),
                            ),
                            if (message.senderIsHost)
                              _Badge(
                                label: 'HOST',
                                textColor: tokens.textPrimary,
                                background: tokens.primaryButtonGradient.last,
                                border: tokens.borderColor,
                              ),
                            if (message.senderIsVip)
                              _Badge(
                                label: 'VIP',
                                textColor: tokens.textPrimary,
                                background: tokens.primaryButtonGradient.first,
                                border: tokens.borderColor,
                              ),
                            if (message.senderLevel != null)
                              _Badge(
                                label: 'LV ${message.senderLevel}',
                                textColor: isHighLevel ? tokens.textPrimary : tokens.textSecondary,
                                background: isHighLevel
                                    ? tokens.chipColor.withValues(alpha: .96)
                                    : tokens.chipColor.withValues(alpha: .76),
                                border: tokens.borderColor.withValues(alpha: isHighLevel ? .68 : .24),
                              ),
                          ],
                        ),
                        SizedBox(height: compact ? 2 : 4),
                        Text(
                          message.message,
                          style: TextStyle(
                            color: tokens.textPrimary,
                            fontSize: compact ? 12.1 : 13.4,
                            height: compact ? 1.2 : 1.28,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _SenderAvatar extends StatelessWidget {
  const _SenderAvatar({
    required this.name,
    required this.avatarUrl,
    required this.frameUrl,
    required this.tokens,
    required this.compact,
    this.onTap,
  });

  final String name;
  final String? avatarUrl;
  final String? frameUrl;
  final PremiumThemeTokens tokens;
  final bool compact;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final trimmed = avatarUrl?.trim();
    final initial = name.trim().isNotEmpty ? name.trim()[0].toUpperCase() : '?';
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(999),
      child: SizedBox(
        width: compact ? 30 : 34,
        height: compact ? 30 : 34,
        child: FramedAvatar(
          avatarUrl: trimmed,
          frameUrl: frameUrl,
          label: initial,
          size: compact ? 30 : 34,
          textColor: tokens.textPrimary,
          backgroundColor: tokens.chipColor,
        ),
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({
    required this.label,
    required this.textColor,
    required this.background,
    required this.border,
  });

  final String label;
  final Color textColor;
  final Color background;
  final Color border;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: border.withValues(alpha: .72)),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
        child: Text(
          label,
          style: TextStyle(
            color: textColor,
            fontSize: 10.2,
            fontWeight: FontWeight.w900,
            letterSpacing: .35,
          ),
        ),
      ),
    );
  }
}
