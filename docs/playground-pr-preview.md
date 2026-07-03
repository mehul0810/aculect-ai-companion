# WordPress Playground Pull Request Preview

Use this workflow when a pull request needs a temporary WordPress site with the pull-request build of Aculect AI Companion installed and active.

The WordPress.org listing blueprint remains at `.wordpress-org/blueprints/blueprint.json`. That blueprint intentionally installs the latest published WordPress.org release for the public plugin listing. Pull request review must use a separate generated blueprint URL that installs a PR package ZIP.

## Reviewer Path

1. Build or download a PR plugin ZIP whose top-level folder is `aculect-ai-companion`.
2. Upload the ZIP somewhere publicly reachable over HTTPS, such as a GitHub Actions artifact proxy, release-candidate storage bucket, or other temporary review host.
3. Generate a Playground URL:

   ```sh
   npm run preview:playground -- --package-url https://example.com/aculect-ai-companion-pr-258.zip
   ```

4. Open the generated `https://playground.wordpress.net/?blueprint=...` URL.
5. WordPress Playground logs in as `admin` and lands on `/wp-admin/options-general.php?page=aculect-ai-companion` with the PR package installed and activated.

To write the generated PR blueprint JSON for inspection or PR comments:

```sh
npm run preview:playground -- --package-url https://example.com/aculect-ai-companion-pr-258.zip --out build/playground-pr-preview.json
```

## Validation

Validate the existing WordPress.org listing blueprint:

```sh
npm run preview:playground -- --validate-blueprint .wordpress-org/blueprints/blueprint.json
```

Validate the PR preview URL shape:

```sh
npm run preview:playground -- --package-url https://example.com/aculect-ai-companion-pr-258.zip
```

The command rejects non-HTTP URLs, non-ZIP package URLs, missing blueprint schema data, missing landing pages, and blueprints with no steps.

## Limitations

- Playground is appropriate for quick admin UI and workflow checks, including settings, diagnostics, activity views, and role/ability screens.
- External MCP and OAuth clients may require an HTTPS-reachable WordPress site with stable callback and resource URLs. Those flows may not fully work in Playground.
- Do not use production credentials, production OAuth clients, or secrets in Playground previews.
- Browser storage and Playground state are temporary. Repeat checks from a fresh generated URL when validating a new PR package.
- Full automation that builds a PR package, uploads it, and comments with a ready-to-open Playground URL is a follow-up slice; this first implementation defines the manual reviewer path and validates the generated URL/blueprint shape.
