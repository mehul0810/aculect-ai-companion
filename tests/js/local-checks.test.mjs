import assert from 'node:assert/strict';
import test from 'node:test';
import { spawnSync } from 'node:child_process';
import {
	chmodSync,
	copyFileSync,
	mkdirSync,
	mkdtempSync,
	readFileSync,
	readdirSync,
	rmSync,
	statSync,
	writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkPlan } from '../../bin/check-project.mjs';

test( 'local checks include every CI command plus asset style and diff checks', () => {
	const ci = checkPlan( 'ci' );
	assert.deepEqual( checkPlan( 'local' ).slice( 0, ci.length ), ci );
	assert.ok( ci.some( ( cmd ) => cmd.includes( 'lint:wpcs' ) ) );
	assert.ok(
		checkPlan( 'local' ).some( ( cmd ) => cmd.includes( 'lint:css' ) )
	);
	assert.ok( ci.some( ( cmd ) => cmd.includes( 'analyse' ) ) );
	assert.ok( ci.some( ( cmd ) => cmd.includes( 'test:unit' ) ) );
	assert.ok( ci.some( ( cmd ) => cmd.includes( 'audit' ) ) );
	assert.throws( () => checkPlan( 'skip-oauth' ) );
} );

test( 'local browser proof remains an explicit credentialed smoke command', () => {
	const packageJson = JSON.parse(
		readFileSync( new URL( '../../package.json', import.meta.url ), 'utf8' )
	);
	assert.equal(
		packageJson.scripts[ 'smoke:release-ui' ],
		'node scripts/smoke/release-ui.mjs'
	);
} );

test( 'release preflight includes local checks and no-secret smoke, never publication', () => {
	const local = checkPlan( 'local' );
	const release = checkPlan( 'release' );
	assert.deepEqual( release.slice( 0, local.length ), local );
	assert.ok( release.some( ( cmd ) => cmd.includes( 'smoke:mcp-local' ) ) );
	assert.ok(
		release.some( ( cmd ) => cmd.includes( 'smoke:release-fixture' ) )
	);
	assert.ok(
		! release
			.flat()
			.some( ( part ) =>
				[ 'push', 'publish', 'deploy' ].includes( part )
			)
	);
} );

function fixture( t ) {
	const directory = mkdtempSync( join( tmpdir(), 'aculect-check-runner-' ) );
	t.after( () => rmSync( directory, { recursive: true, force: true } ) );
	mkdirSync( join( directory, 'bin' ) );
	copyFileSync(
		fileURLToPath(
			new URL( '../../bin/check-project.mjs', import.meta.url )
		),
		join( directory, 'bin/check-project.mjs' )
	);
	for ( const args of [
		[ 'init', '-q' ],
		[ 'add', '.' ],
		[
			'-c',
			'user.name=Fixture',
			'-c',
			'user.email=fixture@example.test',
			'commit',
			'-qm',
			'fixture',
		],
	] ) {
		assert.equal( spawnSync( 'git', args, { cwd: directory } ).status, 0 );
	}
	return directory;
}

test( 'dirty release preflight fails before any commands and records failed evidence', ( t ) => {
	const directory = fixture( t );
	writeFileSync( join( directory, 'uncommitted.txt' ), 'not a candidate' );
	const result = spawnSync(
		process.execPath,
		[ 'bin/check-project.mjs', 'release' ],
		{ cwd: directory, encoding: 'utf8' }
	);
	assert.equal( result.status, 1 );
	assert.match( result.stderr, /clean committed checkout/ );
	const receipts = join( directory, 'artifacts/smoke/checks' );
	const receipt = JSON.parse(
		readFileSync( join( receipts, readdirSync( receipts )[ 0 ] ), 'utf8' )
	);
	assert.equal( receipt.status, 'failed' );
	assert.deepEqual( receipt.completed, [] );
	assert.equal( receipt.dirty, true );
	assert.match( receipt.scope, /hosted packaged OAuth/ );
} );

test( 'first failing command stops the runner without a retry', ( t ) => {
	const directory = fixture( t );
	const executable = join( directory, 'bin/composer' );
	writeFileSync( executable, '#!/bin/sh\nexit 17\n' );
	chmodSync( executable, 0o755 );
	const result = spawnSync(
		process.execPath,
		[ 'bin/check-project.mjs', 'local' ],
		{
			cwd: directory,
			encoding: 'utf8',
			env: {
				...process.env,
				PATH: join( directory, 'bin' ) + ':' + process.env.PATH,
			},
		}
	);
	assert.equal( result.status, 1 );
	assert.match( result.stderr, /Validation failed: composer validate/ );
	assert.equal( ( result.stdout.match( /> composer/g ) || [] ).length, 1 );
} );

test( 'portable package timestamps normalize nested files and directories', ( t ) => {
	const directory = mkdtempSync( join( tmpdir(), 'aculect-package-time-' ) );
	t.after( () => rmSync( directory, { recursive: true, force: true } ) );
	mkdirSync( join( directory, 'nested' ) );
	writeFileSync( join( directory, 'nested/file' ), 'package' );
	const script = fileURLToPath(
		new URL( '../../bin/package-timestamp.mjs', import.meta.url )
	);
	assert.equal(
		spawnSync( process.execPath, [ script, directory, '1700000000' ] )
			.status,
		0
	);
	for ( const path of [
		directory,
		join( directory, 'nested' ),
		join( directory, 'nested/file' ),
	] ) {
		assert.equal( statSync( path ).mtimeMs, 1700000000000 );
	}
	assert.notEqual(
		spawnSync( process.execPath, [ script, directory, 'invalid' ] ).status,
		0
	);
} );
