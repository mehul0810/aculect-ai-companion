import { pathToFileURL } from 'node:url';
import { checks } from './ci-changes.mjs';

export function failedChecks( needs ) {
	const quality = needs.quality;
	const flags = quality?.outputs || {};
	if (
		checks.some(
			( name ) => ! [ 'true', 'false' ].includes( flags[ name ] )
		)
	) {
		return [ 'quality' ];
	}

	const expected = {
		quality: true,
		database: [ 'claims', 'oauth' ].some(
			( name ) => flags[ name ] === 'true'
		),
		wordpress: flags.wordpress === 'true',
		browser: flags.browser === 'true',
		security: flags.security === 'true',
		codeql: flags.codeql === 'true',
		'oauth-contract': flags.quality === 'true',
	};

	return Object.entries( expected )
		.filter( ( [ name, required ] ) => {
			const result = needs[ name ]?.result;
			return required
				? result !== 'success'
				: ! [ 'success', 'skipped' ].includes( result );
		} )
		.map( ( [ name ] ) => name );
}

if (
	process.argv[ 1 ] &&
	import.meta.url === pathToFileURL( process.argv[ 1 ] ).href
) {
	const failed = failedChecks( JSON.parse( process.env.CI_NEEDS || '{}' ) );
	if ( failed.length ) {
		process.stderr.write(
			'Required checks failed, cancelled, missing or unexpectedly skipped: ' +
				failed.join( ', ' ) +
				'\n'
		);
		process.exitCode = 1;
	} else {
		process.stdout.write( 'All applicable checks passed.\n' );
	}
}
