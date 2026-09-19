import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import {
	copyFileSync,
	mkdirSync,
	mkdtempSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

function fixture( context ) {
	const root = mkdtempSync( join( tmpdir(), 'aculect-modularity-' ) );
	context.after( () => rmSync( root, { recursive: true, force: true } ) );
	const git = ( ...args ) =>
		execFileSync( 'git', args, { cwd: root, encoding: 'utf8' } ).trim();
	git( 'init', '--quiet' );
	git( 'config', 'user.name', 'Fixture' );
	git( 'config', 'user.email', 'fixture@example.test' );
	for ( const path of [ 'bin', 'src', '.codex' ] ) {
		mkdirSync( join( root, path ) );
	}
	copyFileSync(
		new URL( '../../bin/check-modularity.php', import.meta.url ),
		join( root, 'bin/check-modularity.php' )
	);
	const source = ( lines ) =>
		writeFileSync(
			join( root, 'src/example.js' ),
			'// example\n'.repeat( lines )
		);
	const config = () =>
		writeFileSync(
			join( root, '.codex/modularity-rules.php' ),
			`<?php
return [
 'production_roots' => ['src'], 'test_roots' => [],
 'budgets' => ['production' => ['line_hard' => 8, 'line_review' => 8]],
 'exceptions' => [['path' => 'src/example.js', 'max_lines' => 10, 'owner' => 'Fixture', 'reason' => 'Test', 'issue' => '#1', 'target' => 5]],
 'dependency_rules' => [['root' => 'src', 'forbidden' => ['ForbiddenDependency']]],
];
`
		);
	const commit = () => {
		git( 'add', '.' );
		git( 'commit', '--quiet', '-m', 'Fixture state' );
		return git( 'rev-parse', 'HEAD' );
	};
	const run = ( base ) =>
		spawnSync(
			'php',
			[ 'bin/check-modularity.php', '--changed-from=' + base ],
			{ cwd: root, encoding: 'utf8' }
		);
	source( 3 );
	return { root, git, source, config, commit, run };
}

test( 'initial adoption enforces current ceilings and dependency rules', ( context ) => {
	const f = fixture( context );
	const base = f.commit();
	f.config();
	f.source( 7 );
	assert.equal( f.run( base ).status, 0 );
	assert.match( f.run( base ).stdout, /Initial modularity adoption/ );
	f.source( 11 );
	assert.equal( f.run( base ).status, 1 );
	assert.match( f.run( base ).stdout, /exception ceiling/ );
	f.source( 7 );
	writeFileSync( join( f.root, 'src/new.js' ), '// new\n'.repeat( 9 ) );
	assert.equal( f.run( base ).status, 1 );
	rmSync( join( f.root, 'src/new.js' ) );
	writeFileSync(
		join( f.root, 'src/boundary.php' ),
		'<?php // ForbiddenDependency\n'
	);
	assert.equal( f.run( base ).status, 1 );
	assert.match( f.run( base ).stdout, /forbidden dependency/ );
} );

test( 'governed bases reject growth even below the absolute ceiling', ( context ) => {
	const f = fixture( context );
	f.config();
	const base = f.commit();
	f.source( 4 );
	assert.equal( f.run( base ).status, 1 );
	assert.match( f.run( base ).stdout, /grew on/ );
	f.source( 3 );
	assert.equal( f.run( base ).status, 0 );
	f.source( 2 );
	assert.equal( f.run( base ).status, 0 );
} );

test( 'unknown revision and removed configuration cannot bypass the ratchet', ( context ) => {
	const f = fixture( context );
	f.config();
	f.commit();
	assert.equal( f.run( 'missing-ref' ).status, 2 );
	rmSync( join( f.root, '.codex/modularity-rules.php' ) );
	const removed = f.commit();
	f.config();
	assert.equal( f.run( removed ).status, 2 );
	assert.match( f.run( removed ).stderr, /configuration was removed/ );
} );

test( 'shallow history cannot establish initial adoption', ( context ) => {
	const f = fixture( context );
	f.commit();
	const clone = join( f.root, 'shallow-clone' );
	f.git( 'clone', '--quiet', '--depth=1', 'file://' + f.root, clone );
	f.config();
	mkdirSync( join( clone, '.codex' ) );
	copyFileSync(
		join( f.root, '.codex/modularity-rules.php' ),
		join( clone, '.codex/modularity-rules.php' )
	);
	const result = spawnSync(
		'php',
		[ 'bin/check-modularity.php', '--changed-from=HEAD' ],
		{ cwd: clone, encoding: 'utf8' }
	);
	assert.equal( result.status, 2 );
	assert.match( result.stderr, /history is incomplete/ );
} );
