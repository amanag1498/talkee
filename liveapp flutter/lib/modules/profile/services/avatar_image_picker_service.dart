import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

class AvatarImagePickerException implements Exception {
  final String message;

  const AvatarImagePickerException(this.message);

  @override
  String toString() => message;
}

class AvatarImagePickerService {
  AvatarImagePickerService({ImagePicker? picker})
    : _picker = picker ?? ImagePicker();

  final ImagePicker _picker;

  Future<String?> pickAndOptimizeFromGallery() async {
    final picked = await _picker.pickImage(
      source: ImageSource.gallery,
      maxWidth: 1024,
      maxHeight: 1024,
    );
    if (picked == null) return null;

    final rawLength = await picked.length();
    if (rawLength <= 0) {
      throw const AvatarImagePickerException(
        'The selected image appears to be invalid.',
      );
    }

    final optimized = await _compressToAvatarJpg(picked.path);
    if (optimized == null || optimized.isEmpty) {
      throw const AvatarImagePickerException(
        'Unable to optimize the selected image.',
      );
    }

    final tempDir = await getTemporaryDirectory();
    final fileName =
        'avatar_${DateTime.now().millisecondsSinceEpoch}_${p.basenameWithoutExtension(picked.path)}.jpg';
    final targetPath = p.join(tempDir.path, fileName);
    final file = File(targetPath);
    await file.writeAsBytes(optimized, flush: true);
    return file.path;
  }

  Future<Uint8List?> _compressToAvatarJpg(String sourcePath) async {
    const qualities = <int>[88, 78, 68];

    for (final quality in qualities) {
      final bytes = await FlutterImageCompress.compressWithFile(
        sourcePath,
        minWidth: 1024,
        minHeight: 1024,
        quality: quality,
        format: CompressFormat.jpeg,
        keepExif: false,
      );

      if (bytes == null || bytes.isEmpty) {
        continue;
      }

      if (bytes.lengthInBytes <= 4 * 1024 * 1024) {
        return bytes;
      }
    }

    return FlutterImageCompress.compressWithFile(
      sourcePath,
      minWidth: 1024,
      minHeight: 1024,
      quality: 60,
      format: CompressFormat.jpeg,
      keepExif: false,
    );
  }
}
