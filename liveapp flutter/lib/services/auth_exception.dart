class AuthBlockedException implements Exception {
  final String message;
  AuthBlockedException([this.message = 'blocked']);
  @override
  String toString() => message;
}
