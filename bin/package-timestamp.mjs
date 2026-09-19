// POSIX touch -d is unavailable on macOS. Use identical UTC mtimes on both hosts.
import { lutimesSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const [ directory, seconds ] = process.argv.slice( 2 );
if ( ! directory || ! /^\d+$/.test( seconds || '' ) ) {
	throw new Error( 'Expected staging directory and commit Unix timestamp.' );
}
const timestamp = Number( seconds );
function stamp( target ) {
	for ( const entry of readdirSync( target, { withFileTypes: true } ) ) {
		const path = join( target, entry.name );
		if ( entry.isDirectory() ) {
			stamp( path );
		} else {
			lutimesSync( path, timestamp, timestamp );
		}
	}
	lutimesSync( target, timestamp, timestamp );
}
stamp( directory );
