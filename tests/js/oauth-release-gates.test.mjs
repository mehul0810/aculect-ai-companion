import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { classifyChanges } from '../../bin/ci-changes.mjs';
import { failedChecks } from '../../bin/ci-required.mjs';

const workflow = ( name ) =>
	readFileSync(
		new URL( '../../.github/workflows/' + name, import.meta.url ),
		'utf8'
	);

test( 'OAuth fixture and unit-contract edits cannot skip packaged proof', () => {
	for ( const path of [
		'tests/Integration/OAuth/setup.php',
		'tests/Unit/Connectors/OAuth/AuthorizationControllerTest.php',
		'tests/Integration/Browser/oauth-contract.mjs',
		'tests/js/oauth-release-gates.test.mjs',
		'tests/js/ci-changes.test.mjs',
	] ) {
		const flags = classifyChanges( [ path ] );
		assert.equal( flags.package, true );
		assert.equal( flags.assets, true );
	}
} );

test( 'every canonical package requires OAuth proof in the aggregate', () => {
	const flags = classifyChanges( [ 'src/Connectors/Helpers.php' ] );
	const names = [
		'php',
		'assets',
		'package',
		'database',
		'wordpress',
		'browser',
		'security',
		'codeql',
		'oauth-contract',
	];
	const needs = {
		changes: {
			result: 'success',
			outputs: Object.fromEntries(
				Object.entries( flags ).map( ( [ key, value ] ) => [
					key,
					String( value ),
				] )
			),
		},
		...Object.fromEntries(
			names.map( ( name ) => [ name, { result: 'success' } ] )
		),
	};
	assert.deepEqual( failedChecks( needs ), [] );
	for ( const result of [ 'failure', 'cancelled', 'skipped', undefined ] ) {
		assert.deepEqual(
			failedChecks( { ...needs, 'oauth-contract': { result } } ),
			[ 'oauth-contract' ]
		);
	}
} );

test( 'both release publishers depend on exact-package OAuth proof', () => {
	for ( const name of [ 'prerelease.yml', 'release.yml' ] ) {
		const content = workflow( name );
		assert.match(
			content,
			/oauth-contract:\n    needs: package\n    uses: \.\/\.github\/workflows\/oauth-contract\.yml/
		);
		const publishing = content.slice( content.indexOf( '\n  publish:' ) );
		assert.match( publishing, /needs: \[[^\]\n]*oauth-contract[^\]\n]*\]/ );
	}
	const content = workflow( 'oauth-contract.yml' );
	assert.match(
		content,
		/sha256sum --check aculect-ai-companion\.zip\.sha256/
	);
	assert.match(
		content,
		/node tests\/Integration\/Browser\/oauth-contract\.mjs/
	);
	assert.match( content, /explicit owner approval is required/ );
	assert.doesNotMatch(
		content,
		/continue-on-error: true|retry@|upload-artifact/
	);
} );
