import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { checks } from '../../bin/ci-changes.mjs';
import { failedChecks, failedSelectedJobs } from '../../bin/ci-required.mjs';

const directory = new URL( '../../.github/workflows/', import.meta.url );
const workflow = ( name ) => readFileSync( new URL( name, directory ), 'utf8' );
const results = ( expected ) =>
	Object.fromEntries(
		Object.entries( expected ).map( ( [ name, selected ] ) => [
			name,
			{ result: selected ? 'success' : 'skipped' },
		] )
	);

test( 'seven workflow owners retain independent validation and publishing', () => {
	assert.deepEqual( readdirSync( directory ).sort(), [
		'ci.yml',
		'compatibility.yml',
		'database-proof.yml',
		'package-proof.yml',
		'prerelease.yml',
		'release.yml',
		'security.yml',
	] );
	for ( const [ file, names ] of [
		[ 'compatibility.yml', [ 'php', 'wordpress' ] ],
		[ 'security.yml', [ 'php', 'codeql' ] ],
		[ 'package-proof.yml', [ 'oauth', 'release' ] ],
	] ) {
		const content = workflow( file );
		assert.match(
			content,
			/\n  required:\n    name: [^\n]+\n    if: always\(\)/
		);
		assert.ok( content.includes( `needs: [${ names.join( ', ' ) }]` ) );
		assert.match( content, /CI_NEEDS: \$\{\{ toJSON\(needs\) \}\}/ );
		assert.match( content, /CI_EXPECTED:/ );
		assert.match( content, /run: node bin\/ci-required\.mjs/ );
		assert.doesNotMatch(
			content,
			/continue-on-error: true|SVN_PASSWORD|ci-upload-release\.sh/
		);
	}
} );

test( 'selected suites cannot disappear inside a successful workflow family', () => {
	for ( const names of [
		[ 'php', 'wordpress' ],
		[ 'php', 'codeql' ],
		[ 'oauth', 'release' ],
	] ) {
		for ( const first of [ false, true ] ) {
			for ( const second of [ false, true ] ) {
				const expected = {
					[ names[ 0 ] ]: first,
					[ names[ 1 ] ]: second,
				};
				const needs = results( expected );
				assert.deepEqual( failedSelectedJobs( needs, expected ), [] );
				for ( const name of names ) {
					for ( const result of [
						'failure',
						'cancelled',
						undefined,
						...( expected[ name ] ? [ 'skipped' ] : [] ),
					] ) {
						assert.deepEqual(
							failedSelectedJobs(
								{ ...needs, [ name ]: { result } },
								expected
							),
							[ name ]
						);
					}
				}
			}
		}
	}
} );

test( 'malformed or incomplete family selections fail closed', () => {
	for ( const [ needs, expected ] of [
		[ {}, {} ],
		[ null, { php: true } ],
		[ {}, { php: true } ],
		[ { php: { result: 'success' } }, { php: 'true' } ],
		[
			{ php: { result: 'success' }, wordpress: { result: 'skipped' } },
			{ php: true },
		],
		[ [], [] ],
	] ) {
		assert.deepEqual( failedSelectedJobs( needs, expected ), [
			'selection',
		] );
	}
} );

test( 'outer CI requires every selected family and permits only authorized skips', () => {
	for ( let bits = 0; bits < 16; bits++ ) {
		const flags = Object.fromEntries(
			checks.map( ( name ) => [ name, 'false' ] )
		);
		for ( const [ index, name ] of [
			'quality',
			'wordpress',
			'security',
			'codeql',
		].entries() ) {
			flags[ name ] = String( Math.floor( bits / 2 ** index ) % 2 === 1 );
		}
		const expected = {
			quality: true,
			database: false,
			compatibility:
				flags.quality === 'true' || flags.wordpress === 'true',
			security: flags.security === 'true' || flags.codeql === 'true',
			'package-proof': flags.quality === 'true',
		};
		const needs = results( expected );
		needs.quality.outputs = flags;
		assert.deepEqual( failedChecks( needs ), [] );
		for ( const [ name, selected ] of Object.entries( expected ) ) {
			if ( selected ) {
				assert.deepEqual(
					failedChecks( {
						...needs,
						[ name ]: { ...needs[ name ], result: 'skipped' },
					} ),
					[ name ]
				);
			}
		}
	}
} );

test( 'the family CLI rejects a skipped release proof when publication requests it', () => {
	for ( const [ releaseResult, expectedStatus ] of [
		[ 'success', 0 ],
		[ 'skipped', 1 ],
		[ 'failure', 1 ],
		[ 'cancelled', 1 ],
	] ) {
		const outcome = spawnSync(
			process.execPath,
			[
				new URL( '../../bin/ci-required.mjs', import.meta.url )
					.pathname,
			],
			{
				encoding: 'utf8',
				env: {
					...process.env,
					CI_EXPECTED: JSON.stringify( {
						oauth: true,
						release: true,
					} ),
					CI_NEEDS: JSON.stringify( {
						oauth: { result: 'success' },
						release: { result: releaseResult },
					} ),
				},
			}
		);
		assert.equal( outcome.status, expectedStatus );
	}
} );

test( 'scanner schedules and their required-result selectors remain aligned', () => {
	const content = workflow( 'security.yml' );
	assert.deepEqual(
		Array.from(
			content.matchAll( /cron: "([^"]+)"/g ),
			( match ) => match[ 1 ]
		),
		[ '42 3 * * 3', '24 3 * * 1' ]
	);
	for ( const [ name, cron ] of [
		[ 'php', '42 3 * * 3' ],
		[ 'codeql', '24 3 * * 1' ],
	] ) {
		const section = content
			.split( `\n  ${ name }:\n` )[ 1 ]
			.split( /\n  [a-z][\w-]*:\n/ )[ 0 ];
		const condition = section
			.match( /    if: >-\n([\s\S]*?)\n    runs-on:/ )[ 1 ]
			.trim()
			.replace( /\s+/g, ' ' );
		assert.ok(
			condition.includes( `github.event.schedule == '${ cron }'` )
		);
		assert.ok(
			condition.includes(
				`github.event_name != 'schedule' && inputs.${ name }`
			)
		);
		assert.ok(
			content.includes( `"${ name }":\u0024{{ ${ condition } }}` )
		);
	}
} );

test( 'manual release validation is read-only and forces the complete CI selection', () => {
	const ci = workflow( 'ci.yml' );
	assert.match( ci, /workflow_dispatch:\n    inputs:\n      release-proof:/ );
	assert.match(
		ci,
		/CI_FULL: \$\{\{ inputs.full \|\| inputs.release-proof \}\}/
	);
	assert.match(
		ci,
		/release-proof: \$\{\{ inputs.release-proof \|\| false \}\}/
	);
	assert.doesNotMatch(
		ci,
		/contents: write|SVN_PASSWORD|ci-upload-release\.sh|action-wordpress-plugin-deploy/
	);
	assert.match(
		workflow( 'package-proof.yml' ),
		/\n  release:\n    if: inputs.release-proof/
	);
	assert.match( workflow( 'package-proof.yml' ), /port: 8884/ );
	assert.match( workflow( 'package-proof.yml' ), /"port": 8882/ );
} );
