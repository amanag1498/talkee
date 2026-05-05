import AppKit
import Foundation
import UniformTypeIdentifiers

struct ColorKey: Hashable {
    let r: Int
    let g: Int
    let b: Int

    init(r: Int, g: Int, b: Int, quantum: Int = 8) {
        self.r = ((r + (quantum / 2)) / quantum) * quantum
        self.g = ((g + (quantum / 2)) / quantum) * quantum
        self.b = ((b + (quantum / 2)) / quantum) * quantum
    }
}

struct Pixel {
    var r: UInt8
    var g: UInt8
    var b: UInt8
    var a: UInt8
}

func sampleBackgroundPalette(
    pixels: [UInt8],
    width: Int,
    height: Int
) -> [ColorKey] {
    var counts: [ColorKey: Int] = [:]
    let patch = min(16, max(4, min(width, height) / 10))
    let origins = [
        (0, 0),
        (max(0, width - patch), 0),
        (0, max(0, height - patch)),
        (max(0, width - patch), max(0, height - patch)),
    ]

    for (ox, oy) in origins {
        for y in oy..<(oy + patch) {
            for x in ox..<(ox + patch) {
                let idx = ((y * width) + x) * 4
                let key = ColorKey(
                    r: Int(pixels[idx]),
                    g: Int(pixels[idx + 1]),
                    b: Int(pixels[idx + 2])
                )
                counts[key, default: 0] += 1
            }
        }
    }

    return counts
        .sorted { lhs, rhs in
            if lhs.value == rhs.value { return lhs.key.r < rhs.key.r }
            return lhs.value > rhs.value
        }
        .prefix(6)
        .map(\.key)
}

func closeToBackground(_ pixel: Pixel, palette: [ColorKey], tolerance: Int = 14) -> Bool {
    guard pixel.a > 0 else { return false }
    for key in palette {
        if abs(Int(pixel.r) - key.r) <= tolerance &&
            abs(Int(pixel.g) - key.g) <= tolerance &&
            abs(Int(pixel.b) - key.b) <= tolerance {
            return true
        }
    }
    return false
}

func processImage(at url: URL) throws {
    guard
        let source = CGImageSourceCreateWithURL(url as CFURL, nil),
        let image = CGImageSourceCreateImageAtIndex(source, 0, nil)
    else {
        throw NSError(domain: "clean_profile_frames", code: 1, userInfo: [
            NSLocalizedDescriptionKey: "Unable to load image at \(url.path)",
        ])
    }

    let width = image.width
    let height = image.height
    let bytesPerRow = width * 4
    let colorSpace = CGColorSpaceCreateDeviceRGB()
    var pixels = [UInt8](repeating: 0, count: Int(height * bytesPerRow))

    guard
        let context = CGContext(
            data: &pixels,
            width: width,
            height: height,
            bitsPerComponent: 8,
            bytesPerRow: bytesPerRow,
            space: colorSpace,
            bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue
        )
    else {
        throw NSError(domain: "clean_profile_frames", code: 2, userInfo: [
            NSLocalizedDescriptionKey: "Unable to create bitmap context for \(url.lastPathComponent)",
        ])
    }

    context.draw(image, in: CGRect(x: 0, y: 0, width: width, height: height))

    let palette = sampleBackgroundPalette(pixels: pixels, width: width, height: height)

    for y in 0..<height {
        for x in 0..<width {
            let idx = ((y * width) + x) * 4
            let pixel = Pixel(
                r: pixels[idx],
                g: pixels[idx + 1],
                b: pixels[idx + 2],
                a: pixels[idx + 3]
            )
            if closeToBackground(pixel, palette: palette) {
                pixels[idx + 3] = 0
            }
        }
    }

    if ["host-sovereign-crest.png", "lion-king-crest.png"].contains(url.lastPathComponent) {
        let cx = Double(width) * 0.5
        let cy = Double(height) * 0.61
        let rx = Double(width) * 0.25
        let ry = Double(height) * 0.27

        for y in 0..<height {
            for x in 0..<width {
                let dx = (Double(x) - cx) / rx
                let dy = (Double(y) - cy) / ry
                if (dx * dx) + (dy * dy) <= 1.0 {
                    let idx = ((y * width) + x) * 4
                    pixels[idx + 3] = 0
                }
            }
        }
    }

    var minX = width
    var minY = height
    var maxX = 0
    var maxY = 0

    for y in 0..<height {
        for x in 0..<width {
            let idx = ((y * width) + x) * 4
            if pixels[idx + 3] > 0 {
                minX = min(minX, x)
                minY = min(minY, y)
                maxX = max(maxX, x)
                maxY = max(maxY, y)
            }
        }
    }

    if minX > maxX || minY > maxY {
        throw NSError(domain: "clean_profile_frames", code: 3, userInfo: [
            NSLocalizedDescriptionKey: "No visible pixels left in \(url.lastPathComponent)",
        ])
    }

    let cropWidth = maxX - minX + 1
    let cropHeight = maxY - minY + 1
    let margin = max(8, Int(Double(max(cropWidth, cropHeight)) * 0.05))
    let canvas = max(cropWidth, cropHeight) + (margin * 2)
    let canvasBytesPerRow = canvas * 4
    var outPixels = [UInt8](repeating: 0, count: canvas * canvasBytesPerRow)

    let offsetX = (canvas - cropWidth) / 2
    let offsetY = (canvas - cropHeight) / 2

    for y in 0..<cropHeight {
        for x in 0..<cropWidth {
            let srcIdx = (((minY + y) * width) + (minX + x)) * 4
            let dstIdx = (((offsetY + y) * canvas) + (offsetX + x)) * 4
            outPixels[dstIdx] = pixels[srcIdx]
            outPixels[dstIdx + 1] = pixels[srcIdx + 1]
            outPixels[dstIdx + 2] = pixels[srcIdx + 2]
            outPixels[dstIdx + 3] = pixels[srcIdx + 3]
        }
    }

    guard
        let provider = CGDataProvider(
            data: NSData(bytes: &outPixels, length: outPixels.count)
        ),
        let outImage = CGImage(
            width: canvas,
            height: canvas,
            bitsPerComponent: 8,
            bitsPerPixel: 32,
            bytesPerRow: canvasBytesPerRow,
            space: colorSpace,
            bitmapInfo: CGBitmapInfo(rawValue: CGImageAlphaInfo.premultipliedLast.rawValue),
            provider: provider,
            decode: nil,
            shouldInterpolate: true,
            intent: .defaultIntent
        )
    else {
        throw NSError(domain: "clean_profile_frames", code: 4, userInfo: [
            NSLocalizedDescriptionKey: "Unable to create output image for \(url.lastPathComponent)",
        ])
    }

    guard let destination = CGImageDestinationCreateWithURL(url as CFURL, UTType.png.identifier as CFString, 1, nil) else {
        throw NSError(domain: "clean_profile_frames", code: 5, userInfo: [
            NSLocalizedDescriptionKey: "Unable to create destination for \(url.lastPathComponent)",
        ])
    }
    CGImageDestinationAddImage(destination, outImage, nil)
    CGImageDestinationFinalize(destination)
}

let seedDir = URL(fileURLWithPath: "/Users/amanagarwal/Desktop/New Live App/liveapp laravel/public/profile-frames/seed")
let fm = FileManager.default
let files = try fm.contentsOfDirectory(at: seedDir, includingPropertiesForKeys: nil)
    .filter { $0.pathExtension.lowercased() == "png" }

for file in files {
    try processImage(at: file)
    print("cleaned \(file.lastPathComponent)")
}
