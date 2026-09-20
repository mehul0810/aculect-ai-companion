#!/usr/bin/env node
// Shared local/hosted validation. Never publishes or changes OAuth behavior.
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = fileURLToPath( new URL( '..', import.meta.url ) );

export function checkPlan( mode ) {
	if ( ! [ 'ci', 'local', 'release' ].includes( mode ) ) {
		throw new Error( 'Expected ci, local or release.' );
	}
	const plan = [
		[ 'composer', 'validate', '--strict' ],
		[ 'composer', 'lint:php' ],
		[ 'composer', 'check:modularity' ],
		[ 'composer', 'analyse' ],
		[ 'composer', 'test:unit' ],
		[ 'composer', 'lint:wpcs' ],
		[ 'npm', 'run', 'test:js' ],
		[ 'npm', 'run', 'build' ],
		[ 'composer', 'audit', '--locked', '--no-dev' ],
		[ 'npm', 'audit', '--omit=dev' ],
	];
	if ( mode !== 'ci' ) {
		plan.push(
			[ 'npm', 'run', 'lint:js', '--', '--quiet' ],
			[ 'npm', 'run', 'lint:css' ],
			[ 'git', 'diff', '--check', 'HEAD' ]
		);
	}
	if ( mode === 'release' ) {
		plan.push(
			[ 'npm', 'run', 'smoke:mcp-local' ],
			[ 'npm', 'run', 'smoke:release-fixture' ]
		);
	}
	return plan;
}

function capture( args ) {
	const result = spawnSync( 'git', args, { cwd: root, encoding: 'utf8' } );
	if ( result.status !== 0 ) {
		throw new Error(
			'Unable to establish Git revision and working-tree state.'
		);
	}
	return result.stdout.trim();
}

function run( command, cwd = root ) {
	process.stdout.write( '\n> ' + command.join( ' ' ) + '\n' );
	const result = spawnSync( command[ 0 ], command.slice( 1 ), {
		cwd,
		stdio: 'inherit',
		env: process.env,
	} );
	if ( result.error || result.status !== 0 ) {
		throw new Error( 'Validation failed: ' + command.join( ' ' ) );
	}
}

function state() {
	return {
		commit: capture( [ 'rev-parse', 'HEAD' ] ),
		dirty:
			capture( [ 'status', '--porcelain', '--untracked-files=all' ] ) !==
			'',
		diffSha256: createHash( 'sha256' )
			.update( capture( [ 'diff', 'HEAD', '--binary' ] ) )
			.digest( 'hex' ),
	};
}

function packageCandidate( commit, receipt ) {
	// Keep the developer's vendor/build trees intact; retain the proof checkout.
	const directory = mkdtempSync( join( tmpdir(), 'aculect-local-release-' ) );
	const checkout = join( directory, 'checkout' );
	receipt.packageCheckout = checkout;
	run( [
		'git',
		'clone',
		'--no-hardlinks',
		'--no-checkout',
		root,
		checkout,
	] );
	run( [ 'git', 'checkout', '--detach', commit ], checkout );
	run(
		[
			'composer',
			'install',
			'--no-dev',
			'--prefer-dist',
			'--optimize-autoloader',
			'--no-interaction',
			'--no-progress',
		],
		checkout
	);
	run( [ 'npm', 'ci' ], checkout );
	run( [ 'npm', 'run', 'build' ], checkout );
	run( [ 'bash', 'bin/ci-package.sh' ], checkout );
	const archive = join( checkout, 'release/aculect-ai-companion.zip' );
	const digest = createHash( 'sha256' )
		.update( readFileSync( archive ) )
		.digest( 'hex' );
	const recorded = readFileSync( archive + '.sha256', 'utf8' ).split(
		/\s/
	)[ 0 ];
	if ( digest !== recorded ) {
		throw new Error( 'Canonical package checksum mismatch.' );
	}
	receipt.package = { path: archive, sha256: digest };
}

export function main( args = process.argv.slice( 2 ) ) {
	const [ mode, option ] = args;
	const plan = checkPlan( mode );
	if ( args.length > 2 || ( option && option !== '--list' ) ) {
		throw new Error(
			'Only --list is supported; checks cannot be skipped.'
		);
	}
	if ( option === '--list' ) {
		process.stdout.write(
			JSON.stringify(
				{ mode, commands: plan, package: mode === 'release' },
				null,
				2
			) + '\n'
		);
		return;
	}
	const receipt = {
		mode,
		startedAt: new Date().toISOString(),
		...state(),
		status: 'running',
		completed: [],
	};
	const output = join(
		root,
		'artifacts/smoke/checks',
		`${ Date.now() }-${ process.pid }.json`
	);
	mkdirSync( join( root, 'artifacts/smoke/checks' ), { recursive: true } );
	try {
		if ( mode === 'release' && receipt.dirty ) {
			throw new Error(
				'Release preflight requires a clean committed checkout. Use check:local while editing.'
			);
		}
		for ( const command of plan ) {
			receipt.stage = command.join( ' ' );
			run( command );
			receipt.completed.push( receipt.stage );
		}
		if ( mode === 'release' ) {
			receipt.stage = 'canonical production package';
			packageCandidate( receipt.commit, receipt );
		}
		const final = state();
		if (
			receipt.commit !== final.commit ||
			receipt.diffSha256 !== final.diffSha256 ||
			( mode === 'release' && final.dirty )
		) {
			throw new Error(
				'Checkout changed during validation; rerun against the intended revision.'
			);
		}
		receipt.status = 'passed';
	} catch ( error ) {
		receipt.status = 'failed';
		throw error;
	} finally {
		receipt.finishedAt = new Date().toISOString();
		receipt.scope =
			mode === 'release'
				? 'Local release preflight only; hosted packaged OAuth, security and compatibility gates remain required. Run smoke:release-ui locally when browser proof applies.'
				: 'Development validation; not release authorization.';
		writeFileSync( output, JSON.stringify( receipt, null, 2 ) + '\n' );
		process.stdout.write( `Validation receipt: ${ output }\n` );
	}
}

if (
	process.argv[ 1 ] &&
	import.meta.url === pathToFileURL( process.argv[ 1 ] ).href
) {
	try {
		main();
	} catch ( error ) {
		process.stderr.write( error.message + '\n' );
		process.exitCode = 1;
	}
}
