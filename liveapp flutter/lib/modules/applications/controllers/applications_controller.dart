import 'package:get/get.dart';

import '../../../app/widgets/haptics.dart';
import '../../../services/auth_service.dart';
import '../models/application_dto.dart';
import '../services/applications_api.dart';

class ApplicationsController extends GetxController {
  final ApplicationsApi api;
  final AuthService auth;

  ApplicationsController({
    required this.api,
    required this.auth,
  });

  final isLoading = false.obs;
  final isSubmitting = false.obs;
  final error = RxnString();
  final summary = Rxn<ApplicationSummaryDto>();

  bool get isHost => auth.currentUser?.roles.contains('host') == true;
  bool get isAgency => auth.currentUser?.roles.contains('agency') == true;
  bool get isAdmin => auth.currentUser?.roles.contains('admin') == true;
  bool get isNormalUser => !isHost && !isAgency && !isAdmin;

  @override
  void onInit() {
    super.onInit();
    load();
  }

  Future<void> load() async {
    isLoading.value = true;
    error.value = null;
    try {
      final data = await api.fetchSummary();
      if (isClosed) return;
      summary.value = data;
    } catch (e) {
      if (!isClosed) error.value = api.extractError(e);
    } finally {
      if (!isClosed) isLoading.value = false;
    }
  }

  List<ApplicationItemDto> get applications => summary.value?.applications ?? const <ApplicationItemDto>[];
  List<AgencyOptionDto> get availableAgencies => summary.value?.availableAgencies ?? const <AgencyOptionDto>[];

  ApplicationItemDto? latestByType(String type) {
    final matches = applications.where((e) => e.type == type).toList()
      ..sort((a, b) => (b.submittedAt ?? DateTime.fromMillisecondsSinceEpoch(0))
          .compareTo(a.submittedAt ?? DateTime.fromMillisecondsSinceEpoch(0)));
    return matches.isEmpty ? null : matches.first;
  }

  Future<bool> submitAgency({
    required String agencyName,
    String? legalName,
    String? contactPhone,
    String? website,
    String? about,
  }) async {
    return _runSubmit(() => api.applyAgency(
          agencyName: agencyName,
          legalName: legalName,
          contactPhone: contactPhone,
          website: website,
          about: about,
        ));
  }

  Future<bool> submitHost({
    String? stageName,
    String? contactPhone,
    String? country,
    String? city,
    String? about,
  }) async {
    return _runSubmit(() => api.applyHost(
          stageName: stageName,
          contactPhone: contactPhone,
          country: country,
          city: city,
          about: about,
        ));
  }

  Future<bool> submitEnroll({
    required int agencyId,
    String? message,
  }) async {
    return _runSubmit(() => api.enrollHost(agencyId: agencyId, message: message));
  }

  Future<bool> _runSubmit(Future<String> Function() fn) async {
    if (isSubmitting.value) return false;
    isSubmitting.value = true;
    error.value = null;
    try {
      final message = await fn();
      await load();
      if (!isClosed) {
        Haptics.success();
        Get.snackbar('Success', message, snackPosition: SnackPosition.BOTTOM);
      }
      return true;
    } catch (e) {
      if (!isClosed) {
        Haptics.error();
        error.value = api.extractError(e);
      }
      return false;
    } finally {
      if (!isClosed) isSubmitting.value = false;
    }
  }
}
