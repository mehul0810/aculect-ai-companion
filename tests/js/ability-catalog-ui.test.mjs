import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(
	new URL( '../../src/index.js', import.meta.url ),
	'utf8'
);

const dashboard = source.slice(
	source.indexOf( 'function AbilityDashboard(' ),
	source.indexOf( 'function roleAbilitySearchText(' )
);
const normalizeSource = source.slice(
	source.indexOf( 'function normalizedAbilityRows(' ),
	source.indexOf( 'function AbilityDashboard(' )
);
const normalize = new Function(
	'normalizedAbilityGroup',
	'sortAbilities',
	`${ normalizeSource }; return normalizedAbilityRows;`
)(
	( group ) => group,
	( first, second ) => first.id.localeCompare( second.id )
);

test( 'third-party rows use provider grouping and preserve enabled choices', () => {
	const rows = normalize( {
		wpAbilities: [
			{ id: 'zeta/read', provider: 'Zeta', readOnly: true },
			{ id: 'alpha/write' },
		],
		enabledWpAbilities: [ 'alpha/write' ],
		abilityCatalog: [ { id: 'aculect/site' } ],
	} );
	assert.deepEqual(
		rows.map( ( row ) => row.id ),
		[ 'alpha/write', 'zeta/read' ]
	);
	assert.equal( rows[ 0 ].sourceLabel, 'alpha' );
	assert.equal( rows[ 0 ].enabled, true );
	assert.equal( rows[ 1 ].enabled, false );
} );

test( 'abilities form saves all external selections independently of visible rows', () => {
	assert.match( dashboard, /enabledWpAbilities\.map/ );
	assert.match( dashboard, /name="enabled_wp_abilities\[\]"/ );
	assert.match( dashboard, /name="confirmation_required_groups\[\]"/ );
	assert.match( dashboard, /name="confirmation_groups_present"/ );
	assert.match( dashboard, /label: 'Provider'/ );
	assert.doesNotMatch(
		dashboard,
		/name="enabled_abilities\[\]"|abilityCatalog|Surface type/
	);
} );
