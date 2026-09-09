import { execFileSync } from 'node:child_process';
import { appendFileSync, readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export const checks = [
	'php',
	'assets',
	'package',
	'claims',
	'oauth',
	'wordpress',
	'browser',
	'security',
	'codeql',
];

const rules = [
	{
		test: /\.php$|^(?:phpstan|phpcs|phpunit)/,
		checks: [ 'php' ],
		// Shared PHP callers can change storage or authorization behavior indirectly.
		production: [ 'package', 'wordpress', 'browser', 'claims', 'oauth' ],
	},
	{
		test: /\.(?:[cm]?js|jsx|tsx?|scss|css)$|^(?:package(?:-lock)?\.json|\.nvmrc|eslint\.|webpack\.)/,
		checks: [ 'assets' ],
		production: [ 'package', 'browser' ],
	},
	{
		test: /\.(?:[cm]?js|jsx|tsx?)$|^(?:package(?:-lock)?\.json|webpack\.)/,
		checks: [ 'codeql' ],
	},
	{
		test: /^(?:src\/Connectors\/(?:MCP|OAuth)\/|tests\/(?:Integration\/ExecutionClaims|Unit\/Connectors)\/)/,
		checks: [ 'claims', 'oauth', 'wordpress' ],
	},
	{ test: /^tests\/integration\/oauth-/, checks: [ 'oauth' ] },
	{
		test: /^tests\/Integration\/WordPressAbilities\//,
		checks: [ 'wordpress' ],
	},
	{
		test: /^tests\/Integration\/(?:Browser|Memory)\//,
		checks: [ 'browser', 'package' ],
	},
	{
		test: /^(?:\.distignore|assets\/|languages\/)/,
		checks: [ 'package', 'browser' ],
	},
];

function pathChecks( path ) {
	// Documentation and configuration may also contain secrets.
	const selected = new Set( [ 'security' ] );
	if (
		/^(?:docs\/|README|CHANGELOG|CONTRIBUTING|AGENTS|DESIGN|TESTING|RELEASE|SECURITY|LICENSE)/i.test(
			path
		) &&
		/\.(?:md|txt)$/.test( path )
	) {
		return selected;
	}
	if (
		/^(?:\.github\/|\.codex\/|bin\/|composer\.|aculect-ai-companion\.php$|src\/Plugin\.php$|tests\/(?:bootstrap\.php$|fixtures\/))/.test(
			path
		)
	) {
		return new Set( checks );
	}
	let known = false;
	for ( const rule of rules ) {
		if ( rule.test.test( path ) ) {
			known = true;
			rule.checks.forEach( ( name ) => selected.add( name ) );
			if ( ! /^tests\//.test( path ) ) {
				rule.production?.forEach( ( name ) => selected.add( name ) );
			}
		}
	}
	return known ? selected : new Set( checks );
}

/**
 * Unknown paths and unavailable history deliberately request every proof.
 *
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
	if ( result.package ) {
		result.assets = true; // The package consumes this run's canonical build.
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
	if ( eventName === 'workflow_dispatch' ) {
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

if (
	process.argv[ 1 ] &&
	import.meta.url === pathToFileURL( process.argv[ 1 ] ).href
) {
	const event = JSON.parse(
		readFileSync( process.env.GITHUB_EVENT_PATH, 'utf8' )
	);
	const paths = changedPaths( process.env.GITHUB_EVENT_NAME, event );
	const result = classifyChanges( paths || [], paths === null );
	const output =
		Object.entries( result )
			.map( ( [ name, value ] ) => name + '=' + value )
			.join( '\n' ) + '\n';
	if ( process.env.GITHUB_OUTPUT ) {
		appendFileSync( process.env.GITHUB_OUTPUT, output );
	}
	process.stdout.write( output );
}
