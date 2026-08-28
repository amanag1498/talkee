#!/bin/bash

set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
flutter_bin="${TALKIEO_FLUTTER_BIN:-/Users/amanagarwal/Desktop/flutter/bin/flutter}"
export_options="$project_dir/ios/ExportOptions-AppStore.plist"
apple_tool_path="/usr/bin:/bin:/usr/sbin:/sbin"
ipa_path="$project_dir/build/ios/ipa/Talkieo.ipa"

cd "$project_dir"

# Keep Homebrew rsync out of Xcode's packaging process. Apple's rsync client
# otherwise resolves the incompatible Homebrew helper and fails the IPA copy.
/usr/bin/env PATH="$apple_tool_path" "$flutter_bin" build ipa \
  --release \
  --export-options-plist="$export_options"

if [[ ! -f "$ipa_path" ]]; then
  echo "Missing exported IPA: $ipa_path" >&2
  exit 1
fi

shasum -a 256 "$ipa_path"
