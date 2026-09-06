import assert from 'node:assert/strict';
import test from 'node:test';
import {
	changedPaths,
	checks,
	classifyChanges,
} from '../../bin/ci-changes.mjs';
import { failedChecks } from '../../bin/ci-required.mjs';
import {
	chmodSync,
	copyFileSync,
	existsSync,
	mkdirSync,
	mkdtempSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

test( 'documentation keeps secrets scanning without rebuilding the plugin', () => {
	const result = classifyChanges( [ 'docs/setup.md' ] );
	assert.equal( result.security, true );
	assert.equal( result.package, false );
	assert.equal( result.php, false );
} );

test( 'unknown files and unavailable history fail safe to every check', () => {
	for ( const result of [
		classifyChanges( [ 'new-bootstrap.conf' ] ),
		classifyChanges( [], true ),
	] ) {
		assert.ok( checks.every( ( key ) => result[ key ] ) );
	}
} );

test( 'shared bootstrap and CI changes require every proof', () => {
	for ( const path of [
		'src/Plugin.php',
		'composer.lock',
		'.github/workflows/ci.yml',
		'bin/ci-changes.mjs',
	] ) {
		assert.ok(
			checks.every( ( key ) => classifyChanges( [ path ] )[ key ] )
		);
	}
} );

test( 'MCP callers cover execution claims, OAuth and native abilities', () => {
	const result = classifyChanges( [
		'src/Connectors/MCP/AbilityExecutionGateway.php',
	] );
	for ( const key of [
		'claims',
		'oauth',
		'wordpress',
		'workflows',
		'php',
		'assets',
		'package',
	] ) {
		assert.equal( result[ key ], true );
	}
} );

test( 'frontend requires one build and packaged browser proof', () => {
	const result = classifyChanges( [ 'src/admin/connect.js' ] );
	assert.equal( result.assets, true );
	assert.equal( result.package, true );
	assert.equal( result.browser, true );
	assert.equal( result.oauth, false );
} );

test( 'memory integration-only edits run the existing packaged WordPress proof', () => {
	const result = classifyChanges( [
		'tests/Integration/Memory/wp-memory-proof.php',
	] );
	for ( const name of [ 'php', 'assets', 'package', 'browser' ] ) {
		assert.equal( result[ name ], true );
	}
} );

const base = 'a'.repeat( 40 );
const head = 'b'.repeat( 40 );

test( 'PR ranges use merge-base and include both sides of a rename', () => {
	const calls = [];
	const paths = changedPaths(
		'pull_request',
		{ pull_request: { base: { sha: base }, head: { sha: head } } },
		( args ) => {
			calls.push( args );
			return args[ 0 ] === 'merge-base'
				? base + '\n'
				: 'src/Old.php\0src/New.php\0';
		}
	);
	assert.deepEqual( paths, [ 'src/Old.php', 'src/New.php' ] );
	assert.deepEqual( calls[ 0 ], [ 'merge-base', base, head ] );
	assert.ok( calls[ 1 ].includes( '--no-renames' ) );
} );

test( 'direct release push compares event before and after without skipping proof', () => {
	const calls = [];
	const paths = changedPaths(
		'push',
		{ before: base, after: head },
		( args ) => {
			calls.push( args );
			return 'src/Plugin.php\0';
		}
	);
	assert.equal( calls.length, 1 );
	assert.ok( calls[ 0 ].includes( base ) && calls[ 0 ].includes( head ) );
	assert.ok( checks.every( ( key ) => classifyChanges( paths )[ key ] ) );
} );

test( 'new branches, missing objects and manual validation select full coverage', () => {
	assert.equal(
		changedPaths( 'push', { before: '0'.repeat( 40 ), after: head } ),
		null
	);
	assert.equal(
		changedPaths( 'push', { before: base, after: head }, () => {
			throw new Error( 'missing' );
		} ),
		null
	);
	assert.equal( changedPaths( 'workflow_dispatch', {} ), null );
} );

test( 'aggregate rejects unexpectedly skipped, cancelled, failed or missing required jobs', () => {
	const flags = classifyChanges( [], true );
	const names = [
		'php',
		'assets',
		'package',
		'database',
		'sqlite',
		'wordpress',
		'browser',
		'security',
		'codeql',
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
	for ( const result of [ 'skipped', 'failure', 'cancelled', undefined ] ) {
		assert.deepEqual( failedChecks( { ...needs, php: { result } } ), [
			'php',
		] );
	}
	assert.deepEqual(
		failedChecks( {
			...needs,
			changes: { ...needs.changes, result: 'failure' },
		} ),
		[ 'changes' ]
	);
} );

test( 'aggregate allows only detector-authorized skips', () => {
	const names = [
		'php',
		'assets',
		'package',
		'database',
		'sqlite',
		'wordpress',
		'browser',
		'security',
		'codeql',
	];
	const needs = {
		changes: {
			result: 'success',
			outputs: Object.fromEntries(
				checks.map( ( name ) => [
					name,
					String( name === 'security' ),
				] )
			),
		},
		...Object.fromEntries(
			names.map( ( name ) => [
				name,
				{ result: name === 'security' ? 'success' : 'skipped' },
			] )
		),
	};
	assert.deepEqual( failedChecks( needs ), [] );
	assert.deepEqual(
		failedChecks( {
			...needs,
			changes: { result: 'success', outputs: {} },
		} ),
		[ 'changes' ]
	);
	assert.deepEqual(
		failedChecks( { ...needs, assets: { result: 'failure' } } ),
		[ 'assets' ]
	);
} );

test( 'consolidated workflows retain every pre-existing integration proof', () => {
	const read = ( name ) =>
		readFileSync(
			new URL( '../../.github/workflows/' + name, import.meta.url ),
			'utf8'
		);
	const database = read( 'database-proof.yml' );
	for ( const proof of [
		'real-database-installer.php',
		'real-database-runner.php',
		'real-database-concurrency.php',
		'oauth-issuer-engine.php',
	] ) {
		assert.ok( database.includes( proof ), proof );
	}
	for ( const name of [
		'aculect_installer_proof',
		'aculect_runner_proof',
		'aculect_claims_proof',
		'aculect_oauth_proof',
	] ) {
		assert.ok( database.includes( name ) );
	}
	const sqlite = read( 'workflow-runner-proof.yml' );
	assert.ok(
		sqlite.includes( 'wp-sqlite-installer.php' ) &&
			sqlite.includes( 'wp-sqlite-runner.php' )
	);
	const packaged = read( 'workflow-proof.yml' );
	assert.ok(
		packaged.includes( 'wp-options-retry-cache.php' ) &&
			packaged.includes( 'workflow-admin.mjs' )
	);
	assert.ok( packaged.includes( 'wp plugin check' ) );
	assert.ok(
		packaged.includes(
			'wp-memory-proof.php aculect-disposable-memory-proof'
		)
	);
	assert.ok( ! packaged.includes( 'npm run build' ) );
	const ci = read( 'ci.yml' );
	assert.match( ci, /pull_request:\n  push:/ );
	assert.match( ci, /name: Required CI\n    if: always\(\)/ );
} );

test( 'release uploader is idempotent and refuses mismatched existing artifacts', ( t ) => {
	const directory = mkdtempSync(
		join( tmpdir(), 'aculect-release-upload-test-' )
	);
	t.after( () => rmSync( directory, { recursive: true, force: true } ) );
	for ( const name of [ 'release', 'remote', 'bin' ] ) {
		mkdirSync( join( directory, name ) );
	}
	const zip = 'verified-fixture-package';
	const digest = createHash( 'sha256' ).update( zip ).digest( 'hex' );
	writeFileSync( join( directory, 'release/aculect-ai-companion.zip' ), zip );
	writeFileSync(
		join( directory, 'release/aculect-ai-companion.zip.sha256' ),
		digest + '  aculect-ai-companion.zip\n'
	);
	for ( const name of [
		'aculect-ai-companion.zip',
		'aculect-ai-companion.zip.sha256',
	] ) {
		copyFileSync(
			join( directory, 'release', name ),
			join( directory, 'remote', name )
		);
	}
	const mock =
		'#!/bin/sh\ncase "$2" in\nview) printf "%s\\n" "$MOCK_EXISTING";;\ndownload) cp "$MOCK_REMOTE/$5" "$7/$5";;\nupload) printf "%s\\n" "$4" >> "$MOCK_UPLOAD_LOG";;\n*) exit 70;;\nesac\n';
	writeFileSync( join( directory, 'bin/gh' ), mock );
	chmodSync( join( directory, 'bin/gh' ), 0o755 );
	const env = {
		...process.env,
		PATH: join( directory, 'bin' ) + ':' + process.env.PATH + ':/sbin',
		RELEASE_TAG: 'test-only',
		EXPECTED_SHA256: digest,
		MOCK_REMOTE: join( directory, 'remote' ),
		MOCK_UPLOAD_LOG: join( directory, 'uploads' ),
		RUNNER_TEMP: directory,
	};
	const script = fileURLToPath(
		new URL( '../../bin/ci-upload-release.sh', import.meta.url )
	);
	const run = ( existing, args = [] ) =>
		spawnSync( 'bash', [ script, ...args ], {
			cwd: directory,
			env: { ...env, MOCK_EXISTING: existing },
			encoding: 'utf8',
			timeout: 5000,
		} );
	assert.equal( run( '', [ '--check-only' ] ).status, 0 );
	assert.equal( existsSync( env.MOCK_UPLOAD_LOG ), false );
	const existing =
		'aculect-ai-companion.zip\naculect-ai-companion.zip.sha256';
	assert.equal( run( existing ).status, 0 );
	assert.equal( existsSync( env.MOCK_UPLOAD_LOG ), false );
	writeFileSync(
		join( directory, 'remote/aculect-ai-companion.zip' ),
		'different-package'
	);
	const conflict = run( existing );
	assert.notEqual( conflict.status, 0 );
	assert.match( conflict.stdout, /Refusing to overwrite/ );
	assert.equal( existsSync( env.MOCK_UPLOAD_LOG ), false );
	assert.equal( run( '' ).status, 0 );
	assert.deepEqual(
		readFileSync( env.MOCK_UPLOAD_LOG, 'utf8' ).trim().split( '\n' ),
		[ 'aculect-ai-companion.zip', 'aculect-ai-companion.zip.sha256' ]
	);
} );
