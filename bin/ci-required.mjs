import { pathToFileURL } from 'node:url';
import { checks } from './ci-changes.mjs';

export function failedSelectedJobs( needs, expected ) {
	const isRecord = ( value ) =>
		value !== null && typeof value === 'object' && ! Array.isArray( value );
	if (
		! isRecord( needs ) ||
		! isRecord( expected ) ||
		Object.keys( expected ).length === 0 ||
		Object.keys( needs ).length !== Object.keys( expected ).length ||
		Object.entries( expected ).some(
			( [ name, selected ] ) =>
				! Object.hasOwn( needs, name ) || typeof selected !== 'boolean'
		)
	) {
		return [ 'selection' ];
	}
	return Object.entries( expected )
		.filter( ( [ name, selected ] ) => {
			const result = needs[ name ]?.result;
			return selected
				? result !== 'success'
				: ! [ 'success', 'skipped' ].includes( result );
		} )
		.map( ( [ name ] ) => name );
}

export function failedChecks( needs ) {
	const quality = needs?.quality;
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
		compatibility: [ 'quality', 'wordpress' ].some(
			( name ) => flags[ name ] === 'true'
		),
		security: [ 'security', 'codeql' ].some(
			( name ) => flags[ name ] === 'true'
		),
		'package-proof': flags.quality === 'true',
	};

	return failedSelectedJobs( needs, expected );
}

if (
	process.argv[ 1 ] &&
	import.meta.url === pathToFileURL( process.argv[ 1 ] ).href
) {
	const needs = JSON.parse( process.env.CI_NEEDS || '{}' );
	const failed =
		process.env.CI_EXPECTED === undefined
			? failedChecks( needs )
			: failedSelectedJobs(
					needs,
					JSON.parse( process.env.CI_EXPECTED )
			  );
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
