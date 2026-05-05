# Changelog


## [0.0.8] - 2025-10-18

### 🛠 Fixes
- Fixed repeated music playback bug when reusing the same audio key.  
  (Thanks to [@wonderkidshihab](https://github.com/wonderkidshihab) for the PR and [@Sansuihe](https://github.com/Sansuihe) for reporting.)

### 🚀 Improvements
- Optimized audio handling and cache logic for better playback stability.
- Updated README and credits for contributors.
- Version bump for pub.dev publication.

---

## [0.0.7] - 2025-10-18

### Fixed
- 🎵 Fixed repeated music playback bug when same audio keys are used (#3)
- 🛡️ Improved race condition handling in audio playback logic
- ✅ Added early return check to prevent duplicate audio playback

### Maintenance
- Enhanced audio layer synchronization and error handling

---


## [0.0.6] - 2025-06-26

### Fixed
- 🎧 Prevent crash when AudioPlayer is accessed after dispose (#1)
- 🛠️ Updated to support protobuf 4+, other latest dependencies and use Flutter 3.32.5 (#2)

### Maintenance
- Improved safety checks in SVGAAudioLayer
- Compatible with Dart 3.0 and Flutter 3.32+

---

## 0.0.5 - Update (2025-03-14)

### 🔥 Upgrade to flutter version 3.29.2

---

## 0.0.4 - Update (2025-02-09)

### 🔥 New Features & Improvements
- ✅ **Added Audio Support**: Integrated audio playback within SVGA animations using the `audioplayers` package.

---

## 0.0.3 - Update (2025-01-30)

### 🔥 New Features & Improvements
- ✅ **Added Pause & Resume Playback Functions** for SVGA animations.
- ✅ **Enhanced Error Handling & Logging** for better debugging.
- ✅ **Improved Performance** when loading SVGA animations.

### 🛠 Fixes & Optimizations
- 🛠 Fixed potential crashes when loading SVGA assets.
- 🛠 Optimized memory usage for large SVGA files.
- 🛠 Improved logging messages for debugging.

---

## 0.0.2 - Update (2025-01-30)

### 🔥 Updates & Improvements
- ✅ Added **example GIFs** for better demonstration.
- ✅ **Supported all platforms** including **Web & Desktop**.

---

## 0.0.1 - Initial Release (2025-01-29)

### 🎉 New Features
- ✅ Added support for **SVGA parsing and rendering**.
- ✅ Load **SVGA animations** from **assets** and **network URLs**.
- ✅ Implemented **SVGAAnimationController** for playback control.
- ✅ Dynamic entity support (text & images).
- ✅ Optimized performance for **smooth animations**.
