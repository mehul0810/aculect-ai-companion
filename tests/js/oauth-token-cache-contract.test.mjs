import assert from 'node:assert/strict';
import test from 'node:test';
import { tokenCacheDisposition } from '../Integration/Browser/oauth-token-cache-contract.mjs';

test( 'owner-approved token error cache exception is limited to 0.8.0', () => {
	assert.equal(
		tokenCacheDisposition( '0.8.0', 400, {}, { error: 'invalid_grant' } ),
		'deferred-531'
	);
	for ( const version of [ '0.8.1', '0.9.0', '', undefined ] ) {
		assert.throws( () =>
			tokenCacheDisposition(
				version,
				400,
				{},
				{ error: 'invalid_grant' }
			)
		);
	}
} );

test( 'exception never permits unprotected successful or credential-bearing responses', () => {
	for ( const [ status, data ] of [
		[ 200, { access_token: 'synthetic' } ],
		[ 200, { error: 'invalid_grant' } ],
		[ 302, { error: 'invalid_grant' } ],
		[ 400, { error: 'invalid_grant', access_token: 'synthetic' } ],
		[ 400, { error: 'invalid_grant', refresh_token: 'synthetic' } ],
		[ 400, {} ],
		[ 500, null ],
		[ 400, { error: '' } ],
	] ) {
		assert.throws( () =>
			tokenCacheDisposition( '0.8.0', status, {}, data )
		);
	}
} );

test( 'normal token responses require both cache-prevention headers', () => {
	const headers = { 'cache-control': 'no-store', pragma: 'no-cache' };
	assert.equal(
		tokenCacheDisposition( '0.8.1', 200, headers, {} ),
		'protected'
	);
	assert.equal(
		tokenCacheDisposition( '0.8.1', 400, headers, {
			error: 'invalid_grant',
		} ),
		'protected'
	);
	assert.throws( () =>
		tokenCacheDisposition(
			'0.8.0',
			200,
			{ 'cache-control': 'no-store' },
			{}
		)
	);
} );
