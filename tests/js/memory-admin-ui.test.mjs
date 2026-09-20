import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
	safeExternalUrl,
	isPlainObject,
	connectionDateValue,
} from '../../src/Admin/shared/value-utils.mjs';

const card = readFileSync(
	new URL( '../../src/Admin/memory/MemoryRecordCard.js', import.meta.url ),
	'utf8'
);

test( 'both memory forms submit namespace and observed version with an immutable key', () => {
	assert.match( card, /name="memory_item\[namespace\]"/ );
	assert.match( card, /name="memory_item\[expected_version\]"/ );
	assert.equal(
		card.match( /<MemoryIdentityInputs record=\{ record \} \/>/g ).length,
		2
	);
	assert.match(
		card,
		/name="memory_item\[key\]"\s+value=\{ formValues.key \}\s+readOnly/s
	);
} );

test( 'shared presentation utilities retain safe links and date fallbacks after extraction', () => {
	assert.equal( safeExternalUrl( 'javascript:alert(1)' ), '' );
	assert.equal(
		safeExternalUrl( 'https://example.com/path' ),
		'https://example.com/path'
	);
	assert.equal( Boolean( isPlainObject( [] ) ), false );
	assert.equal( Boolean( isPlainObject( {} ) ), true );
	assert.equal( connectionDateValue( '', 'Updated' ), 'Updated' );
	assert.equal( connectionDateValue( 'not-a-date' ), 'not-a-date' );
} );
