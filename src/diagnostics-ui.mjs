const STATUS_ORDER = {
	fail: 0,
	warn: 1,
	pass: 2,
};

const SENSITIVE_EVIDENCE_KEYS = new Set( [ 'code', 'state' ] );
const SENSITIVE_EVIDENCE_FRAGMENTS = [
	'authorization',
	'auth_code',
	'body',
	'client_secret',
	'code_challenge',
	'code_verifier',
	'cookie',
	'auth_header',
	'nonce',
	'oauth_code',
	'password',
	'private_payload',
	'raw',
	'refresh_token',
	'salt',
	'secret',
	'set_cookie',
	'token',
];
const MAX_EVIDENCE_DEPTH = 4;
const MAX_EVIDENCE_ITEMS = 25;
const MAX_EVIDENCE_STRING_LENGTH = 500;
const MAX_COPIED_RESULT_LENGTH = 5000;
const STALE_RESULT_AGE_MS = 24 * 60 * 60 * 1000;

export function diagnosticItems( health ) {
	return Array.isArray( health?.items ) ? health.items : [];
}

export function diagnosticItemById( health, id ) {
	return (
		diagnosticItems( health ).find( ( item ) => item?.id === id ) || null
	);
}

export function normalizeDiagnosticStatus( status ) {
	return [ 'pass', 'warn', 'fail' ].includes( status ) ? status : 'warn';
}

export function diagnosticCounts( items ) {
	return items.reduce(
		( counts, item ) => {
			counts[ normalizeDiagnosticStatus( item?.status ) ] += 1;
			counts.total += 1;
			return counts;
		},
		{ total: 0, pass: 0, warn: 0, fail: 0 }
	);
}

export function diagnosticFilterCount( counts, filterName ) {
	return filterName === 'all' ? counts.total : counts[ filterName ] || 0;
}

export function diagnosticStatusLabel( status ) {
	const labels = {
		pass: 'Pass',
		warn: 'Needs review',
		fail: 'Error',
	};

	return labels[ status ] || 'Not run';
}

export function formatDiagnosticCheckLabel( id ) {
	return String( id || 'connection_check' )
		.replace( /[_-]+/g, ' ' )
		.replace( /\b\w/g, ( character ) => character.toUpperCase() );
}

export function filteredDiagnosticItems(
	items,
	filterName = 'all',
	searchQuery = ''
) {
	const query = String( searchQuery || '' )
		.trim()
		.toLowerCase();

	return items
		.map( ( item, index ) => ( { item, index } ) )
		.filter( ( { item } ) => {
			const status = normalizeDiagnosticStatus( item?.status );
			if ( filterName !== 'all' && status !== filterName ) {
				return false;
			}

			if ( query === '' ) {
				return true;
			}

			const searchable = [
				item?.id,
				formatDiagnosticCheckLabel( item?.id ),
				item?.message,
				item?.remediation,
				diagnosticStatusLabel( status ),
			]
				.map( ( value ) => String( value || '' ).toLowerCase() )
				.join( ' ' );

			return searchable.includes( query );
		} )
		.sort( ( first, second ) => {
			const firstStatus =
				STATUS_ORDER[ normalizeDiagnosticStatus( first.item?.status ) ];
			const secondStatus =
				STATUS_ORDER[
					normalizeDiagnosticStatus( second.item?.status )
				];

			return firstStatus - secondStatus || first.index - second.index;
		} )
		.map( ( entry ) => entry.item );
}

export function diagnosticOverallStatus( counts ) {
	if ( counts.total === 0 ) {
		return 'unavailable';
	}

	if ( counts.fail > 0 ) {
		return 'fail';
	}

	if ( counts.warn > 0 ) {
		return 'warn';
	}

	return 'pass';
}

export function diagnosticResultsStatusText(
	totalCount,
	visibleCount,
	filterName = 'all',
	searchQuery = ''
) {
	if ( totalCount === 0 ) {
		return 'No diagnostic checks are available. Run all checks to create a saved result.';
	}

	if ( visibleCount === 0 ) {
		return 'No diagnostic checks match the current filter and search.';
	}

	const context =
		filterName === 'all' && String( searchQuery || '' ).trim() === ''
			? ''
			: ' for the current filter and search';

	return `${ visibleCount } of ${ totalCount } diagnostic checks shown${ context }.`;
}

export function diagnosticFreshness(
	ranAt,
	now = Date.now(),
	staleAfterMs = STALE_RESULT_AGE_MS
) {
	if ( ! ranAt ) {
		return 'never';
	}

	const normalized = String( ranAt ).includes( 'T' )
		? String( ranAt )
		: `${ String( ranAt ).replace( ' ', 'T' ) }Z`;
	const timestamp = Date.parse( normalized );

	if ( ! Number.isFinite( timestamp ) ) {
		return 'stale';
	}

	return now - timestamp > staleAfterMs ? 'stale' : 'fresh';
}

export function safeDiagnosticEvidence( value, depth = 0 ) {
	if ( depth >= MAX_EVIDENCE_DEPTH ) {
		return '[Additional details omitted]';
	}

	if ( typeof value === 'string' ) {
		return value.slice( 0, MAX_EVIDENCE_STRING_LENGTH );
	}

	if (
		value === null ||
		typeof value === 'number' ||
		typeof value === 'boolean'
	) {
		return value;
	}

	if ( Array.isArray( value ) ) {
		return value
			.slice( 0, MAX_EVIDENCE_ITEMS )
			.map( ( item ) => safeDiagnosticEvidence( item, depth + 1 ) );
	}

	if ( ! value || typeof value !== 'object' ) {
		return String( value || '' ).slice( 0, MAX_EVIDENCE_STRING_LENGTH );
	}

	return Object.entries( value )
		.slice( 0, MAX_EVIDENCE_ITEMS )
		.reduce( ( result, [ key, item ] ) => {
			const normalizedKey = String( key )
				.toLowerCase()
				.replace( /[- ]+/g, '_' );
			const isSensitive =
				SENSITIVE_EVIDENCE_KEYS.has( normalizedKey ) ||
				SENSITIVE_EVIDENCE_FRAGMENTS.some( ( fragment ) =>
					normalizedKey.includes( fragment )
				);

			if ( ! isSensitive ) {
				result[ key ] = safeDiagnosticEvidence( item, depth + 1 );
			}
			return result;
		}, {} );
}

export function diagnosticResultText( item ) {
	if ( ! item ) {
		return '';
	}

	const lines = [
		`Check: ${ formatDiagnosticCheckLabel( item.id ) }`,
		`Identifier: ${ item.id || 'connection_check' }`,
		`Status: ${ diagnosticStatusLabel(
			normalizeDiagnosticStatus( item.status )
		) }`,
		`Result: ${ item.message || 'No result message' }`,
	];

	if ( item.remediation ) {
		lines.push( `Next action: ${ item.remediation }` );
	}

	const safeEvidence = safeDiagnosticEvidence( item.details || {} );
	if (
		safeEvidence &&
		typeof safeEvidence === 'object' &&
		Object.keys( safeEvidence ).length > 0
	) {
		lines.push( `Evidence: ${ JSON.stringify( safeEvidence, null, 2 ) }` );
	}

	return lines.join( '\n' ).slice( 0, MAX_COPIED_RESULT_LENGTH );
}

export function diagnosticGuidanceSteps( item ) {
	const label = formatDiagnosticCheckLabel( item?.id );

	return [
		{
			title: 'Resolve the reported issue',
			description:
				item?.remediation ||
				'Review the bounded technical evidence and correct the reported configuration.',
		},
		{
			title: 'Run diagnostics again',
			description:
				'Use Run all checks after the change so the saved result reflects the current site state.',
		},
		{
			title: 'Confirm recovery',
			description: `Confirm ${ label } now reports Passed and no related warning or error remains.`,
		},
	];
}

export function diagnosticWhyItMatters( item ) {
	const id = String( item?.id || '' );

	if ( /secret|storage|transient/.test( id ) ) {
		return 'Reliable, encrypted storage protects connection credentials and the short-lived state used by authorization and confirmation flows.';
	}

	if ( /https|route|metadata|auth|approval|cloudflare/.test( id ) ) {
		return 'Hosted assistants must reach the correct public endpoint and authorization flow without exposing credentials or bypassing WordPress permissions.';
	}

	if ( /ability|tool|manifest/.test( id ) ) {
		return 'Assistants rely on a stable, policy-filtered tool contract so they can discover only the actions this site safely supports.';
	}

	if ( /index|intelligence/.test( id ) ) {
		return 'A healthy content index keeps retrieval and intelligence results current without blocking normal WordPress editing.';
	}

	return 'This check contributes to the connection health shown to administrators and helps isolate setup problems before they affect assistants.';
}
