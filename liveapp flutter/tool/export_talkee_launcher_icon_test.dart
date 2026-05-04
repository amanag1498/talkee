import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter_test/flutter_test.dart';
import 'package:liveapp/app/widgets/talkee_logo.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('exports Talkee launcher icon PNG', () async {
    const canvasSize = 1024;
    const outputDir = 'branding';
    const outputFile = 'talkee_launcher_icon_1024.png';
    final recorder = ui.PictureRecorder();
    final canvas = ui.Canvas(recorder);

    const painter = TalkeeLauncherIconPainter();
    painter.paint(canvas, ui.Size.square(canvasSize.toDouble()));

    final picture = recorder.endRecording();
    final image = await picture.toImage(canvasSize, canvasSize);
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);

    if (bytes == null) {
      fail('Failed to encode Talkee launcher icon image.');
    }

    final dir = Directory(outputDir);
    await dir.create(recursive: true);
    final file = File('${dir.path}/$outputFile');
    await file.writeAsBytes(bytes.buffer.asUint8List(), flush: true);

    print('Talkee launcher icon exported to ${file.path}');
    expect(file.existsSync(), isTrue);
  });
}
