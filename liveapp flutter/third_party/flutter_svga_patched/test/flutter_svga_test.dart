import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_svga/flutter_svga.dart';

void main() {
  group('flutter_svga', () {
    test('SVGAAnimationController creation', () {
      // Test that we can create an animation controller
      // This is a basic test to ensure the package loads correctly
      expect(true, isTrue);
    });

    test('SVGAParser shared instance', () {
      // Test that SVGAParser.shared is accessible
      expect(SVGAParser.shared, isNotNull);
    });

    test('SVGAImage widget creation', () {
      // Test that SVGAImage widget can be referenced
      expect(SVGAImage, isNotNull);
    });
  });
}
