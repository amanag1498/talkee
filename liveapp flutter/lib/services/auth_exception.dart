class AuthBlockedException implements Exception {
  final String message;
  AuthBlockedException([this.message = 'blocked']);
  @override
  String toString() => message;
}

class AppUpgradeRequiredException implements Exception {
  final String message;
  AppUpgradeRequiredException([
    this.message = 'Please update Talkieo to continue using the app.',
  ]);

  @override
  String toString() => message;
}
