class BannerItem {
  final int id;
  final String title;
  final String imageUrl;
  final String actionType;
  final String? actionValue;
  final String? buttonText;
  final int sortOrder;

  const BannerItem({
    required this.id,
    required this.title,
    required this.imageUrl,
    required this.actionType,
    this.actionValue,
    this.buttonText,
    this.sortOrder = 0,
  });

  factory BannerItem.fromJson(Map<String, dynamic> j) => BannerItem(
        id: (j['id'] as num?)?.toInt() ?? 0,
        title: (j['title'] ?? '').toString(),
        imageUrl: (j['image_url'] ?? '').toString(),
        actionType: (j['action_type'] ?? 'none').toString(),
        actionValue: j['action_value']?.toString(),
        buttonText: j['button_text']?.toString(),
        sortOrder: (j['sort_order'] as num?)?.toInt() ?? 0,
      );

  bool get hasImage => imageUrl.trim().isNotEmpty;
}
