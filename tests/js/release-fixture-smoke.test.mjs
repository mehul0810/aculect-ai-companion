import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync } from 'node:fs';
import { rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath( new URL( '../..', import.meta.url ) );
const SCRIPT = fileURLToPath(
	new URL( '../../scripts/smoke/release-fixture.mjs', import.meta.url )
);

function runFixtureSmoke( scenario ) {
	const artifactDir = mkdtempSync(
		path.join( tmpdir(), 'aculect-fixture-smoke-' )
	);
	const result = spawnSync(
		process.execPath,
		[ SCRIPT, '--artifact-dir', artifactDir, '--scenario', scenario ],
		{
			cwd: ROOT,
			encoding: 'utf8',
			env: {
				PATH: process.env.PATH,
			},
		}
	);

	return {
		artifactDir,
		result,
	};
}

test( 'release fixture smoke writes a public-safe no-secret summary', async () => {
	const { artifactDir, result } = runFixtureSmoke( 'valid' );

	try {
		assert.equal( result.status, 0, result.stderr );
		assert.match( result.stdout, /Release fixture smoke passed/ );

		const summary = JSON.parse(
			readFileSync(
				path.join( artifactDir, 'latest', 'summary.json' ),
				'utf8'
			)
		);

		assert.equal( summary.status, 'passed' );
		assert.equal( summary.mode, 'fixture-no-secret' );
		assert.equal( summary.mcpFixture.toolsList.toolCount, 3 );
		assert.equal( summary.mcpFixture.toolsList.invalidToolNames, 0 );
		assert.equal( summary.mcpFixture.toolsList.duplicateToolNames, 0 );
		assert.deepEqual( summary.releaseUiContract.requiredEnvironment, [
			'ACULECT_SMOKE_BASE_URL',
			'ACULECT_SMOKE_USERNAME',
			'ACULECT_SMOKE_PASSWORD',
		] );
		assert.equal(
			summary.releaseUiContract.manualProof.connectApproval.status,
			'deferred'
		);
		assert.equal( summary.releaseUiContract.tabs.length, 9 );
		assert.deepEqual( summary.releaseUiContract.learningSurfaces.required, [
			'Learning Suggestions',
			'Memory Records',
			'Incident Reports',
		] );
		assert.doesNotMatch(
			JSON.stringify( summary ),
			/redacted-test-token|raw live MCP payload/i
		);
	} finally {
		await rm( artifactDir, { force: true, recursive: true } );
	}
} );

test( 'release fixture smoke fails invalid fixture tool names', async () => {
	const { artifactDir, result } = runFixtureSmoke( 'invalid-tool-name' );

	try {
		assert.notEqual( result.status, 0 );
		assert.match( result.stderr, /invalid tool names/ );
	} finally {
		await rm( artifactDir, { force: true, recursive: true } );
	}
} );

test( 'release fixture smoke fails duplicate fixture tool names', async () => {
	const { artifactDir, result } = runFixtureSmoke( 'duplicate-tool-name' );

	try {
		assert.notEqual( result.status, 0 );
		assert.match( result.stderr, /duplicate tool names/ );
	} finally {
		await rm( artifactDir, { force: true, recursive: true } );
	}
} );
