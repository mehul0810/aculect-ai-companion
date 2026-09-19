# Connect tab design QA

- Source visual: `/var/folders/2n/rgb1t2952r15p8nrpj3tbbzm0000gn/T/codex-clipboard-45fcd018-f82e-4cf0-903d-2681c316c15e.png`
- Implementation capture: `/private/tmp/aculect-connect-redesign-qa-exact/desktop-app.png`
- Constrained capture: `/private/tmp/aculect-connect-redesign-qa-exact/constrained.png`
- Desktop viewport: 1488 x 1058 CSS pixels at 1x density.
- Constrained viewport: 782 x 1000 CSS pixels.
- Runtime identity: packaged Aculect AI Companion v0.8.0 on WordPress 7.1.
- State: Connect tab, Ready endpoint, ChatGPT selected, Permissions expanded.

## Full-view comparison

The implementation preserves the approved composition: compact Connect introduction, endpoint and three-step setup row, five-provider picker, contextual action strip, and one expanded Permissions disclosure. Shell header and navigation remain unchanged. Spacing, borders, type hierarchy, status color, and action emphasis follow the source direction.

## Focused region comparison

The exact-package app-root capture keeps the endpoint, provider picker, Permissions labels, badges, safety row, and actions legible at full size. The constrained capture verifies the same content after responsive stacking.

## Findings

No remaining P0, P1, or P2 visual differences. The implementation intentionally uses truthful runtime policy labels instead of the mock's illustrative profile and access values. The local endpoint is fixture content, not a layout difference. The runtime version is the exact v0.8.0 candidate identity.

## Comparison history

1. Initial comparison found uncircled verification marks and Permissions badges pushed to the far edge. Both were corrected to match the approved visual hierarchy.
2. The initial implementation evidence used an older installed package identity and was rejected.
3. Final proof was regenerated from the exact v0.8.0 candidate package and confirmed the corrected status treatment, compact policy badges, aligned cards, and responsive stacking with no actionable discrepancy.

## Interaction and accessibility evidence

- Provider radio controls retain one selected tab stop and Arrow, Home, and End keyboard behavior.
- Permissions uses a native disclosure and supports collapse and expansion.
- Endpoint text wraps without horizontal overflow.
- No browser console errors were observed at desktop or constrained width.
- Document width matched viewport width at 1488px and 782px.
- ArrowRight moved selection from ChatGPT to Claude, then ArrowLeft restored ChatGPT before capture.

## Final result

final result: passed
