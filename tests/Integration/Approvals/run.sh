#!/usr/bin/env bash
# Run only after the caller installs/verifies the intended package in an isolated
# WordPress fixture. This script never starts a site or runs any OAuth endpoint.
# Usage: ACULECT_APPROVAL_PROOF_DISPOSABLE=1 bash run.sh EVIDENCE ZIP_OR_source-checkout \
#   CONTAINER_CONTRACT_PATH -- WP_COMMAND [ARGS...]
# Example WP_COMMAND: bash /workspace/wp-codex-cloud/bin/dev.sh ai wp --user=cloud-dev
set -euo pipefail
[[ "${ACULECT_APPROVAL_PROOF_DISPOSABLE:-}" == 1 ]] || { echo 'Disposable fixture opt-in required.' >&2; exit 2; }
[[ "$#" -ge 5 && "$4" == -- ]] || { echo 'Expected EVIDENCE ZIP_OR_source-checkout CONTRACT -- WP_COMMAND [ARGS].' >&2; exit 2; }
proof_evidence="$1"
proof_artifact="$2"
proof_contract="$3"
shift 4
mkdir -p "$proof_evidence"
proof_run="$(mktemp -d "$proof_evidence/run-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")"
proof_digest=source-checkout
if [[ "$proof_artifact" != source-checkout ]]; then
  test -f "$proof_artifact"
  proof_digest="$(sha256sum "$proof_artifact" | cut -d ' ' -f 1)"
fi
proof_repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd -P)"
git -C "$proof_repo" rev-parse HEAD > "$proof_run/revision.txt"
git -C "$proof_repo" status --porcelain > "$proof_run/working-tree-status.txt"
sha256sum "$(dirname "${BASH_SOURCE[0]}")/contract.php" > "$proof_run/contract-sha256.txt"
printf '%s\n' "$proof_artifact" "$proof_digest" > "$proof_run/artifact.txt"
printf '%q ' "$@" eval-file "$proof_contract" disposable-loopback "$proof_digest" > "$proof_run/command.txt"
printf '\n' >> "$proof_run/command.txt"
proof_status=0
"$@" eval-file "$proof_contract" disposable-loopback "$proof_digest" > "$proof_run/result.json" 2> "$proof_run/stderr.log" || proof_status=$?
printf '%s\n' "$proof_status" > "$proof_run/exit-code.txt"
cat "$proof_run/result.json"
printf 'Evidence: %s\n' "$proof_run"
exit "$proof_status"
