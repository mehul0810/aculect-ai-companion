# MCP Skills in 0.9.0

Skills are reusable, declarative Markdown guidance served to authenticated MCP clients that negotiate the stable Skills extension on protocol revision `2026-07-28`. A Skill does not grant a WordPress ability, create an execution channel, or replace the connected user's permissions. Clients receive only Skills whose declared abilities are already exposed to that connection and whose required plugins are active on that site or network. Earlier protocol revisions retain their existing behavior.

## Site-local administration

Administrators can use **AI Companion > Skills** to create, edit, enable or disable, duplicate, export, import, and delete custom Skills. Each custom Skill has a `custom-` ID, a bounded `SKILL.md`, and up to 20 relative `references/*.md` files. Import and export use a versioned JSON package. Replacing an existing Skill is explicit; edits and replacement carry its version and digest so stale pages fail with a conflict instead of silently overwriting later work. The content stays in the site's WordPress options and is not uploaded to Aculect.

The validator accepts a deliberately constrained Markdown subset. It rejects active HTML, executable/code-fence content, external URLs, absolute or traversing paths, and unsupported reference files. A rejected import or edit does not change the stored Skill. This restriction limits what can be expressed; it is not a claim that assistant instructions are intrinsically trustworthy. Imports, including replacements of enabled Skills, are stored disabled regardless of the exported activation flag. Administrators must review imported guidance and enable it separately.

## Plugin-provided Skills

An active plugin may return declarative records through `aculect_ai_companion_mcp_skill_providers`. The filter value is a map keyed by the provider's exact WordPress plugin basename, for example `sample-plugin/sample-plugin.php`. Each value is a list of records with `id`, `skill_md`, `references`, `required_abilities`, `required_plugins`, and an optional boolean `enabled`. IDs must use the reserved `plugin-` prefix and match the `name` in `SKILL.md` frontmatter. All records pass the same bounded Markdown validation as custom Skills. Inactive providers, malformed records, missing dependencies, and conflicting IDs are omitted rather than partially served.

```php
add_filter(
	'aculect_ai_companion_mcp_skill_providers',
	static function ( array $providers ): array {
		$providers['sample-plugin/sample-plugin.php'] = array(
			array(
				'id'                 => 'plugin-sample-site-review',
				'skill_md'           => "---\nname: plugin-sample-site-review\ndescription: Review public site information\n---\n\n# Review\n\nUse the available site information ability and report uncertainty.\n",
				'references'         => array(),
				'required_abilities' => array( 'site.get_info' ),
				'required_plugins'   => array(),
				'enabled'            => true,
			),
		);
		return $providers;
	}
);
```

The example does not enable `site.get_info`; it only makes the Skill eligible when that tool is already exposed. Providers cannot supply executable files, arbitrary file paths, remote URLs, or resources outside their validated Markdown bundle.

## Protocol and discovery

Compatible clients use `skills/list` with pagination, `skills/get` for the full manifest, and `resources/read` for the `skill://{id}/SKILL.md` and declared reference URIs. Each manifest resource includes its SHA-256 digest and byte size. The server's Skills responses are private and non-cacheable. No public `.well-known` Skills index is shipped in 0.9.0; its current draft path and interoperability are being evaluated separately.

Real-host behavior, installed-package validation, and the full 0.9.0 release matrix remain separate gates in [the MCP Apps verification matrix](0.9.0-mcp-apps-verification-matrix.md).
