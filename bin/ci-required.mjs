import { pathToFileURL } from 'node:url';
import { checks } from './ci-changes.mjs';

export function failedChecks( needs ) {
	const flags = needs.changes?.outputs || {};
	if (
		checks.some(
			( name ) => ! [ 'true', 'false' ].includes( flags[ name ] )
		)
	) {
		return [ 'changes' ];
	}
	const expected = {
		changes: true,
		php: flags.php === 'true',
		assets: flags.assets === 'true',
		package: flags.package === 'true',
		database: [ 'workflows', 'claims', 'oauth' ].some(
			( name ) => flags[ name ] === 'true'
		),
		sqlite: flags.workflows === 'true',
		wordpress: flags.wordpress === 'true',
		browser: flags.browser === 'true',
		security: flags.security === 'true',
		codeql: flags.codeql === 'true',
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
