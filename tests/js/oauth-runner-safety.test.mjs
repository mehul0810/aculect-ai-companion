import assert from 'node:assert/strict';
import test from 'node:test';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const runner = fileURLToPath(
	new URL( '../Integration/Browser/oauth-contract.mjs', import.meta.url )
);

test( 'OAuth runner rejects unsafe configuration before browser or network work', () => {
	for ( const config of [
		{ optIn: '0', url: 'http://127.0.0.1:1' },
		{ optIn: '1', url: 'https://example.invalid' },
		{ optIn: '1', url: 'http://example.invalid' },
		{ optIn: '1', url: 'http://secret-user:secret-value@127.0.0.1' },
		{ optIn: '1', url: 'http://127.0.0.1/?token=secret-value' },
	] ) {
		const result = spawnSync( process.execPath, [ runner ], {
			env: {
				...process.env,
				ACULECT_OAUTH_FIXTURE_OPT_IN: config.optIn,
				ACULECT_WP_BASE_URL: config.url,
			},
			encoding: 'utf8',
			timeout: 10000,
		} );
		assert.equal( result.status, 1 );
		assert.equal( result.stdout, '' );
		assert.match( result.stderr, /^FAIL configuration\b/ );
		assert.doesNotMatch(
			result.stderr,
			/secret-value|secret-user|example\.invalid/
		);
	}
} );
