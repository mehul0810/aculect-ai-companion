#!/usr/bin/env bash
# Build the canonical package from an already-built checkout. No network or publishing.
set -euo pipefail
repository="$(git rev-parse --show-toplevel)"
cd "$repository"
test -s build/index.js
test -s vendor/autoload.php
staging="$(mktemp -d "${RUNNER_TEMP:-/tmp}/aculect-package.XXXXXX")"
mkdir -p "$staging/aculect-ai-companion" release
# A fresh staging directory avoids stale files without destructive cleanup.
rsync -rc --exclude-from="$repository/.distignore" "$repository/" "$staging/aculect-ai-companion/"
php bin/verify-production-package.php "$staging/aculect-ai-companion"
timestamp="$(git log -1 --format=%ct)"
find "$staging/aculect-ai-companion" -exec touch -h -d "@$timestamp" {} +
test ! -e "$repository/release/aculect-ai-companion.zip"
cd "$staging"
find aculect-ai-companion -type f -print | LC_ALL=C sort | zip -q -X "$repository/release/aculect-ai-companion.zip" -@
cd "$repository/release"
sha256sum aculect-ai-companion.zip > aculect-ai-companion.zip.sha256
