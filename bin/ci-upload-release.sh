#!/usr/bin/env bash
# Only release workflows call this publisher after the immutable package passes proof.
set -euo pipefail
: "${RELEASE_TAG:?Release tag is required}"
: "${EXPECTED_SHA256:?Expected package digest is required}"
mode="${1:-upload}"
test "$mode" = upload || test "$mode" = --check-only
cd release
sha256sum --check aculect-ai-companion.zip.sha256
test "$(sha256sum aculect-ai-companion.zip | cut -d ' ' -f 1)" = "$EXPECTED_SHA256"
existing="$(gh release view "$RELEASE_TAG" --json assets --jq '.assets[].name')"
for name in aculect-ai-companion.zip aculect-ai-companion.zip.sha256; do
  if printf '%s\n' "$existing" | grep -Fxq "$name"; then
    comparison="$(mktemp -d "${RUNNER_TEMP:-/tmp}/aculect-release-compare.XXXXXX")"
    gh release download "$RELEASE_TAG" --pattern "$name" --dir "$comparison"
    if ! cmp -s "$name" "$comparison/$name"; then
      echo "::error::Release asset $name differs from the verified package. Refusing to overwrite it."
      exit 1
    fi
    echo "Identical $name already attached; leaving it unchanged."
  elif [ "$mode" = upload ]; then
    gh release upload "$RELEASE_TAG" "$name"
  fi
done
