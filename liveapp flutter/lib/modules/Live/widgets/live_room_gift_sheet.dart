import 'package:flutter/material.dart';
import 'package:get/get.dart';

import '../../../services/app_settings_service.dart';
import '../../../app/theme/brand.dart';
import '../models/live_gift_item.dart';

class LiveRoomGiftSelection {
  final LiveGiftItem gift;
  final int quantity;

  const LiveRoomGiftSelection({required this.gift, required this.quantity});
}

class LiveRoomGiftSheet extends StatefulWidget {
  const LiveRoomGiftSheet({super.key, required this.gifts});

  final List<LiveGiftItem> gifts;

  static Future<LiveRoomGiftSelection?> show(
    BuildContext context, {
    required List<LiveGiftItem> gifts,
  }) {
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );
    return showModalBottomSheet<LiveRoomGiftSelection>(
      context: context,
      isScrollControlled: true,
      backgroundColor: tokens.backgroundGradient.first,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (_) => LiveRoomGiftSheet(gifts: gifts),
    );
  }

  @override
  State<LiveRoomGiftSheet> createState() => _LiveRoomGiftSheetState();
}

class _LiveRoomGiftSheetState extends State<LiveRoomGiftSheet> {
  LiveGiftItem? _selected;
  int _quantity = 1;

  @override
  void initState() {
    super.initState();
    if (widget.gifts.isNotEmpty) {
      _selected = widget.gifts.first;
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.of(context).viewInsets.bottom;
    final selected = _selected;
    final total = selected == null ? 0 : selected.coins * _quantity;
    final tokens = getPremiumThemeTokens(
      Get.find<AppSettingsService>().activePremiumThemeVariant,
    );

    return SafeArea(
      top: false,
      child: Padding(
        padding: EdgeInsets.fromLTRB(12, 0, 12, 12 + bottom),
        child: Container(
          decoration: BoxDecoration(
            color: tokens.cardGradient.first.withValues(alpha: .98),
            borderRadius: BorderRadius.circular(28),
            border: Border.all(color: tokens.borderColor.withOpacity(.34)),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const SizedBox(height: 10),
              Container(
                width: 42,
                height: 4,
                decoration: BoxDecoration(
                  color: tokens.borderColor.withOpacity(.42),
                  borderRadius: BorderRadius.circular(99),
                ),
              ),
              const SizedBox(height: 14),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 18),
                child: Row(
                  children: [
                    const Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Send Gift',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 20,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                          SizedBox(height: 4),
                          Text(
                            'Choose a gift and send it instantly.',
                            style: TextStyle(
                              color: Colors.white70,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                    Icon(Icons.redeem_rounded, color: tokens.dangerColor),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              Flexible(
                child: GridView.builder(
                  shrinkWrap: true,
                  padding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 8,
                  ),
                  itemCount: widget.gifts.length,
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 4,
                    crossAxisSpacing: 10,
                    mainAxisSpacing: 10,
                    childAspectRatio: 0.82,
                  ),
                  itemBuilder: (_, index) {
                    final gift = widget.gifts[index];
                    final selectedCard = selected?.id == gift.id;
                    return InkWell(
                      onTap: () => setState(() => _selected = gift),
                      borderRadius: BorderRadius.circular(18),
                      child: Container(
                        decoration: BoxDecoration(
                          color:
                              selectedCard
                                  ? tokens.dangerColor.withOpacity(.14)
                                  : tokens.glassColor.withOpacity(.08),
                          borderRadius: BorderRadius.circular(18),
                          border: Border.all(
                            color:
                                selectedCard
                                    ? tokens.dangerColor.withOpacity(.48)
                                    : tokens.borderColor.withOpacity(.22),
                            width: selectedCard ? 1.4 : 1,
                          ),
                        ),
                        padding: const EdgeInsets.all(10),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Expanded(
                              child:
                                  gift.giftUrl != null &&
                                          gift.giftUrl!.isNotEmpty
                                      ? ClipRRect(
                                        borderRadius: BorderRadius.circular(12),
                                        child: Image.network(
                                          gift.giftUrl!,
                                          fit: BoxFit.cover,
                                          errorBuilder: (_, __, ___) {
                                            return const Icon(
                                              Icons.card_giftcard_rounded,
                                              color: Colors.white70,
                                              size: 30,
                                            );
                                          },
                                        ),
                                      )
                                      : const Icon(
                                        Icons.card_giftcard_rounded,
                                        color: Colors.white70,
                                        size: 30,
                                      ),
                            ),
                            const SizedBox(height: 6),
                            Text(
                              gift.name,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              '${gift.coins} coins',
                              style: const TextStyle(
                                color: Colors.white60,
                                fontSize: 11,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
                child: Row(
                  children: [
                    Container(
                      decoration: BoxDecoration(
                        color: tokens.chipColor.withOpacity(.82),
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(
                          color: tokens.borderColor.withOpacity(.28),
                        ),
                      ),
                      child: Row(
                        children: [
                          IconButton(
                            onPressed:
                                _quantity > 1
                                    ? () => setState(() => _quantity -= 1)
                                    : null,
                            icon: const Icon(
                              Icons.remove_rounded,
                              color: Colors.white,
                            ),
                          ),
                          Text(
                            'x$_quantity',
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          IconButton(
                            onPressed:
                                _quantity < 99
                                    ? () => setState(() => _quantity += 1)
                                    : null,
                            icon: const Icon(
                              Icons.add_rounded,
                              color: Colors.white,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: FilledButton.icon(
                        onPressed:
                            selected == null
                                ? null
                                : () => Navigator.of(context).pop(
                                  LiveRoomGiftSelection(
                                    gift: selected,
                                    quantity: _quantity,
                                  ),
                                ),
                        style: FilledButton.styleFrom(
                          backgroundColor: tokens.dangerColor,
                          foregroundColor: tokens.textPrimary,
                          padding: const EdgeInsets.symmetric(vertical: 15),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(16),
                          ),
                        ),
                        icon: const Icon(Icons.redeem_rounded),
                        label: Text('Send • $total'),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
