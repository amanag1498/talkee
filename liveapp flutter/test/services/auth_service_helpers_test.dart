import 'package:flutter_test/flutter_test.dart';
import 'package:liveapp/services/auth_service.dart';

void main() {
  test('recognizes Apple sign-in cancellation responses', () {
    expect(isAppleSignInCancellation('canceled', null), isTrue);
    expect(
      isAppleSignInCancellation(
        'unknown',
        'The operation was cancelled by the user',
      ),
      isTrue,
    );
    expect(isAppleSignInCancellation('invalid-credential', 'Expired'), isFalse);
  });
}
