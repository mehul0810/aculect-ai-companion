/**
 * Owner-approved 0.8.0-only deferral, tracked in GitHub issue #531.
 * @param {string}      version Candidate version.
 * @param {number}      status  HTTP status.
 * @param {Object}      headers Response headers.
 * @param {Object|null} data    Parsed response body.
 */
export function tokenCacheDisposition( version, status, headers, data ) {
	const protectedResponse =
		/no-store/i.test( headers[ 'cache-control' ] || '' ) &&
		/no-cache/i.test( headers.pragma || '' );
	if ( protectedResponse ) {
		return 'protected';
	}
	if (
		version === '0.8.0' &&
		status >= 400 &&
		status <= 599 &&
		typeof data?.error === 'string' &&
		data.error !== '' &&
		! Object.prototype.hasOwnProperty.call( data, 'access_token' ) &&
		! Object.prototype.hasOwnProperty.call( data, 'refresh_token' )
	) {
		return 'deferred-531';
	}
	throw new Error( 'contract_token-cache' );
}
