import { execFileSync } from 'node:child_process';
import { appendFileSync, readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export const checks = [
	'quality',
	'claims',
	'oauth',
	'wordpress',
	'browser',
	'security',
	'codeql',
];

const full = () => new Set( checks );

const rules = [
	{
		test: /\.php$|^(?:phpstan|phpcs|phpunit)/i,
		checks: [ 'quality' ],
		// Shared PHP callers can change storage or authorization behavior
		// indirectly. Keep the conservative integration fan-out for production.
		production: [ 'claims', 'oauth', 'wordpress', 'browser' ],
	},
	{
		test: /\.(?:[cm]?js|jsx|tsx?|scss|css)$|^(?:package(?:-lock)?\.json|\.nvmrc|eslint\.|webpack\.)/i,
		checks: [ 'quality' ],
		production: [ 'browser' ],
	},
	{
		test: /^src\/Connectors\/(?:MCP|OAuth)\/|^tests\/Integration\/(?:ExecutionClaims|OAuth)\/|^tests\/Unit\/Connectors\//i,
		checks: [ 'claims', 'oauth', 'wordpress' ],
	},
	{
		test: /^tests\/integration\/oauth-/i,
		checks: [ 'oauth' ],
	},
	{
		test: /^tests\/Integration\/WordPressAbilities\//i,
		checks: [ 'wordpress' ],
	},
	{
		test: /^tests\/Integration\/(?:Browser|Memory|OAuth)\//i,
		checks: [ 'browser', 'quality' ],
	},
	{
		test: /^tests\/Unit\/Connectors\/OAuth\//i,
		checks: [ 'quality', 'browser' ],
	},
	{
		test: /^(?:\.distignore|assets\/|languages\/)/i,
		checks: [ 'quality', 'browser' ],
	},
];

function pathChecks( path ) {
	// WordPress.org metadata ships in the ZIP, unlike developer documentation.
	if ( path === 'readme.txt' ) {
		return full();
	}
	// Maintainer-agent metadata cannot change the shipped plugin.
	if ( /^\.codex\/(?:agents\/|config\.toml$)/i.test( path ) ) {
		return new Set();
	}

	// Documentation still receives the quality job's always-on metadata/secrets
	// scan, but never starts a separate Semgrep or integration matrix.
	const selected = new Set();
	if (
		/^(?:docs\/|README|CHANGELOG|CONTRIBUTING|AGENTS|DESIGN|TESTING|RELEASE|SECURITY|LICENSE)/i.test(
			path
		) &&
		/\.(?:md|txt)$/i.test( path )
	) {
		return selected;
	}

	// CI, bootstrap, dependency, and policy changes are intentionally fail
	// safe. They can affect the detector or every proof.
	if (
		/^(?:\.github\/|\.codex\/|bin\/|composer\.|package\.json$|package-lock\.json$|aculect-ai-companion\.php$|src\/Plugin\.php$|tests\/(?:bootstrap\.php$|fixtures\/|js\/(?:oauth|ci-|local-checks)))/.test(
			path
		)
	) {
		return full();
	}

	let known = false;
	for ( const rule of rules ) {
		if ( rule.test.test( path ) ) {
			known = true;
			rule.checks.forEach( ( name ) => selected.add( name ) );
			if ( ! /^tests\//i.test( path ) ) {
				rule.production?.forEach( ( name ) => selected.add( name ) );
			}
		}
	}
	return known ? selected : full();
}

/**
 * @param {string[]} paths    Changed repository-relative paths.
 * @param {boolean}  forceAll Request all checks when history is unavailable.
 * @return {Object} Boolean check selections.
 */
export function classifyChanges( paths, forceAll = false ) {
	const result = Object.fromEntries(
		checks.map( ( name ) => [ name, forceAll ] )
	);
	for ( const path of paths ) {
		for ( const name of pathChecks( path ) ) {
			result[ name ] = true;
		}
	}
	return result;
}

function git( args ) {
	return execFileSync( 'git', args, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
}

export function changedPaths( eventName, event, runGit = git ) {
	if ( eventName === 'workflow_dispatch' || eventName === 'workflow_call' ) {
		return null;
	}
	const base =
		eventName === 'pull_request'
			? event.pull_request?.base?.sha
			: event.before;
	const head =
		eventName === 'pull_request'
			? event.pull_request?.head?.sha
			: event.after;
	if (
		! [ base, head ].every(
			( sha ) =>
				/^[a-f0-9]{40}$/i.test( sha || '' ) && ! /^0+$/.test( sha )
		)
	) {
		return null;
	}
	try {
		const start =
			eventName === 'pull_request'
				? runGit( [ 'merge-base', base, head ] ).trim()
				: base;
		// No rename detection: both the deleted source and added destination stay in scope.
		return runGit( [
			'diff',
			'--no-renames',
			'--name-only',
			'-z',
			start,
			head,
			'--',
		] )
			.split( '\0' )
			.filter( Boolean );
	} catch {
		return null;
	}
}

export function selectChecks( eventName, event, paths, forceFull = false ) {
	const releasePullRequest =
		eventName === 'pull_request' &&
		event.pull_request?.base?.ref === 'main';
	return classifyChanges(
		paths || [],
		paths === null || forceFull || releasePullRequest
	);
}

if (
	process.argv[ 1 ] &&
	import.meta.url === pathToFileURL( process.argv[ 1 ] ).href
) {
	const eventName = process.env.GITHUB_EVENT_NAME || '';
	const event = JSON.parse(
		readFileSync( process.env.GITHUB_EVENT_PATH, 'utf8' )
	);
	const paths = changedPaths( eventName, event );
	const result = selectChecks(
		eventName,
		event,
		paths,
		process.env.CI_FULL === 'true'
	);
	const output =
		Object.entries( result )
			.map( ( [ name, value ] ) => name + '=' + value )
			.join( '\n' ) + '\n';
	if ( process.env.GITHUB_OUTPUT ) {
		appendFileSync( process.env.GITHUB_OUTPUT, output );
	}
	process.stdout.write( output );
}
