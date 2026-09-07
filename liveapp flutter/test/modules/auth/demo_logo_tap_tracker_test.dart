import 'package:flutter_test/flutter_test.dart';
import 'package:liveapp/modules/auth/controllers/auth_controller.dart';

void main() {
  test('demo logo tracker opens only after three quick taps', () {
    final tracker = DemoLogoTapTracker();
    final start = DateTime(2026, 9, 7, 10);

    expect(tracker.register(start), isFalse);
    expect(
      tracker.register(start.add(const Duration(milliseconds: 300))),
      isFalse,
    );
    expect(
      tracker.register(start.add(const Duration(milliseconds: 600))),
      isTrue,
    );
    expect(
      tracker.register(start.add(const Duration(milliseconds: 700))),
      isFalse,
    );
  });

  test('demo logo tracker resets taps outside the time window', () {
    final tracker = DemoLogoTapTracker();
    final start = DateTime(2026, 9, 7, 10);

    expect(tracker.register(start), isFalse);
    expect(
      tracker.register(start.add(const Duration(milliseconds: 1300))),
      isFalse,
    );
    expect(
      tracker.register(start.add(const Duration(milliseconds: 1500))),
      isFalse,
    );
  });
}
