class ApplicationSummaryDto {
  final List<ApplicationItemDto> applications;
  final List<AgencyOptionDto> availableAgencies;

  const ApplicationSummaryDto({
    required this.applications,
    required this.availableAgencies,
  });

  factory ApplicationSummaryDto.fromJson(Map<String, dynamic> json) {
    final apps = (json['applications'] as List?)
            ?.map((e) => ApplicationItemDto.fromJson(Map<String, dynamic>.from(e as Map)))
            .toList() ??
        const <ApplicationItemDto>[];
    final agencies = (json['available_agencies'] as List?)
            ?.map((e) => AgencyOptionDto.fromJson(Map<String, dynamic>.from(e as Map)))
            .toList() ??
        const <AgencyOptionDto>[];
    return ApplicationSummaryDto(applications: apps, availableAgencies: agencies);
  }
}

class ApplicationItemDto {
  final int id;
  final String type;
  final String title;
  final String status;
  final DateTime? submittedAt;
  final DateTime? reviewedAt;
  final String? reviewNotes;
  final Map<String, dynamic> details;

  const ApplicationItemDto({
    required this.id,
    required this.type,
    required this.title,
    required this.status,
    required this.details,
    this.submittedAt,
    this.reviewedAt,
    this.reviewNotes,
  });

  bool get isPending => status == 'pending';
  bool get isApproved => status == 'approved';
  bool get isRejected => status == 'rejected';

  factory ApplicationItemDto.fromJson(Map<String, dynamic> json) {
    return ApplicationItemDto(
      id: (json['id'] as num?)?.toInt() ?? 0,
      type: (json['type'] ?? '').toString(),
      title: (json['title'] ?? '').toString(),
      status: (json['status'] ?? '').toString(),
      submittedAt: DateTime.tryParse((json['submitted_at'] ?? '').toString()),
      reviewedAt: DateTime.tryParse((json['reviewed_at'] ?? '').toString()),
      reviewNotes: json['review_notes']?.toString(),
      details: json['details'] is Map<String, dynamic>
          ? json['details'] as Map<String, dynamic>
          : Map<String, dynamic>.from(json['details'] as Map? ?? const <String, dynamic>{}),
    );
  }
}

class AgencyOptionDto {
  final int id;
  final String name;

  const AgencyOptionDto({
    required this.id,
    required this.name,
  });

  factory AgencyOptionDto.fromJson(Map<String, dynamic> json) {
    return AgencyOptionDto(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: (json['name'] ?? '').toString(),
    );
  }
}
