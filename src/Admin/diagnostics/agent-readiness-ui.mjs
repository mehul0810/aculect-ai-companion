const CHECKS = [
	[
		'oauth_discovery',
		'OAuth resource discovery',
		'Aculect',
		'Review the public OAuth resource metadata and retry from a network that can reach this site.',
	],
	[
		'oauth_authorization_metadata',
		'OAuth authorization metadata',
		'Aculect',
		'Review the public authorization metadata and retry after resolving any edge challenge.',
	],
	[
		'mcp_authentication_challenge',
		'MCP authentication challenge',
		'Aculect',
		'Review the unauthenticated Bearer challenge. A blocked probe does not prove an origin failure.',
	],
	[
		'mcp_server_card',
		'MCP Server Card',
		'Site owner',
		'This draft discovery endpoint is deferred until the owner approves a contract.',
	],
	[
		'webmcp',
		'WebMCP',
		'Aculect',
		'Check browser registration and public asset delivery separately; this server-side run cannot verify them.',
	],
	[
		'skills_index',
		'Public Skills Index',
		'Site owner',
		'Review the proposed public skills contract before enabling a discovery endpoint.',
	],
	[
		'robots_txt',
		'robots.txt',
		'Site owner',
		'Check the public text response and site or edge routing.',
	],
	[
		'ai_crawler_directives',
		'AI crawler directives',
		'Site owner',
		'Review the site crawler policy. Absence of named directives is informational.',
	],
	[
		'sitemap',
		'WordPress sitemap',
		'Site owner',
		'Check the conventional sitemap URL and the site’s chosen indexing policy.',
	],
	[
		'content_signals',
		'Content Signals',
		'Site owner',
		'Review any published Content-Signal policy; this check does not change it.',
	],
	[
		'link_headers',
		'Link headers',
		'Site owner',
		'Review site-owned Link headers and their targets outside this redacted report.',
	],
	[
		'llms_txt',
		'llms.txt',
		'Site owner',
		'Decide whether this optional file belongs on the site, then verify its public text response.',
	],
	[
		'markdown_negotiation',
		'Markdown negotiation',
		'Site owner',
		'Check whether the homepage intentionally supports Accept: text/markdown.',
	],
	[
		'a2a_agent_card',
		'A2A Agent Card',
		'Not applicable',
		'This protocol is outside Aculect AI Companion’s scope.',
	],
	[
		'web_bot_auth',
		'Web Bot Auth',
		'Not applicable',
		'This protocol is outside Aculect AI Companion’s scope.',
	],
	[
		'dns_aid',
		'DNS-AID',
		'Not applicable',
		'This protocol is outside Aculect AI Companion’s scope.',
	],
	[
		'commerce_protocols',
		'Commerce protocols',
		'Not applicable',
		'These protocols are outside Aculect AI Companion’s scope.',
	],
].map( ( [ id, label, owner, guidance ] ) => ( {
	id,
	label,
	owner,
	guidance,
} ) );

export const AGENT_READINESS_STATUSES = [
	{ id: 'pass', label: 'Pass' },
	{ id: 'warning', label: 'Needs review' },
	{ id: 'fail', label: 'Failed' },
	{ id: 'externally_blocked', label: 'Externally blocked' },
	{ id: 'owner_decision_required', label: 'Owner decision' },
	{ id: 'not_applicable', label: 'Not applicable' },
];

const SAFE_EVIDENCE = {
	oauth_discovery: [ 'httpStatus', 'jsonContentType', 'expectedShape' ],
	oauth_authorization_metadata: [
		'httpStatus',
		'jsonContentType',
		'expectedShape',
	],
	mcp_authentication_challenge: [ 'httpStatus', 'expectedChallenge' ],
	robots_txt: [ 'httpStatus' ],
	sitemap: [ 'httpStatus', 'advertisedByRobots' ],
	content_signals: [ 'headerPresent' ],
	link_headers: [ 'present' ],
	llms_txt: [ 'httpStatus' ],
	markdown_negotiation: [ 'httpStatus', 'markdownMediaType' ],
};

const EVIDENCE_LABELS = {
	httpStatus: 'HTTP status',
	jsonContentType: 'JSON content type',
	expectedShape: 'Expected JSON shape',
	expectedChallenge: 'Expected Bearer challenge',
	advertisedByRobots: 'Advertised by robots.txt',
	headerPresent: 'Header present',
	present: 'Link header present',
	markdownMediaType: 'Markdown media type',
};

export function agentReadinessEvidence( id, evidence ) {
	if (
		! evidence ||
		typeof evidence !== 'object' ||
		Array.isArray( evidence )
	) {
		return [];
	}

	return ( SAFE_EVIDENCE[ id ] || [] ).flatMap( ( key ) => {
		const value = evidence[ key ];
		if ( key === 'httpStatus' ) {
			return Number.isInteger( value ) && value >= 0 && value <= 599
				? [ `${ EVIDENCE_LABELS[ key ] }: ${ value }` ]
				: [];
		}
		return typeof value === 'boolean'
			? [ `${ EVIDENCE_LABELS[ key ] }: ${ value ? 'Yes' : 'No' }` ]
			: [];
	} );
}

export function agentReadinessStatus( status ) {
	return (
		AGENT_READINESS_STATUSES.find( ( item ) => item.id === status )?.id ||
		'warning'
	);
}

export function agentReadinessStatusLabel( status ) {
	return (
		AGENT_READINESS_STATUSES.find( ( item ) => item.id === status )
			?.label || 'Needs review'
	);
}

export function agentReadinessRows( readiness ) {
	const items = Array.isArray( readiness?.items ) ? readiness.items : [];
	const byId = new Map(
		items
			.filter( ( item ) => item && typeof item === 'object' )
			.map( ( item ) => [ item.id, item ] )
	);
	return CHECKS.filter( ( check ) => byId.has( check.id ) ).map(
		( check ) => ( {
			...check,
			status: agentReadinessStatus( byId.get( check.id ).status ),
			evidence: agentReadinessEvidence(
				check.id,
				byId.get( check.id ).evidence
			),
		} )
	);
}

export function agentReadinessExportText( readiness ) {
	const ranAt = /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(
		readiness?.ranAt || ''
	)
		? readiness.ranAt
		: 'Not run';
	const rows = agentReadinessRows( readiness );
	return [
		'Agent readiness (redacted)',
		`Last checked (UTC): ${ ranAt }`,
		'Note: These are saved observations, not proof of external reachability.',
		...rows.map(
			( row ) =>
				`${ row.label } [${ row.id }]: ${ agentReadinessStatusLabel(
					row.status
				) } | ${ row.owner }${
					row.evidence.length
						? ` | ${ row.evidence.join( '; ' ) }`
						: ''
				}`
		),
	].join( '\n' );
}
