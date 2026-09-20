import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
	connectToolFilteringViewModel,
	copyToolFilteringField,
} from '../../src/connect-tool-filtering.mjs';

const testDirectory = path.dirname( fileURLToPath( import.meta.url ) );
const source = fs.readFileSync(
	path.resolve( testDirectory, '../../src/index.js' ),
	'utf8'
);

test( 'Connect does not render advanced tool filtering guidance', () => {
	assert.doesNotMatch( source, /ConnectToolFilteringGuidance/ );
	assert.doesNotMatch( source, /connectToolFilteringViewModel/ );
	assert.doesNotMatch( source, /copyToolFilteringField/ );
	assert.doesNotMatch( source, /Advanced tool filtering/ );
} );

test( 'Connect renders filtering only for the selected provider', () => {
	const chatgpt = {
		id: 'chatgpt',
		label: 'ChatGPT',
		toolFiltering: {
			toolSets: [ { id: 'read' } ],
			copyFields: [ { label: 'OpenAI' } ],
		},
	};
	const claude = {
		id: 'claude',
		label: 'Claude',
		toolFiltering: {
			toolSets: [ { id: 'audit' } ],
			copyFields: [ { label: 'Claude' } ],
		},
	};

	const viewModel = connectToolFilteringViewModel( claude );

	assert.equal( viewModel.provider, claude );
	assert.equal( viewModel.guidance, claude.toolFiltering );
	assert.deepEqual( viewModel.copyFields, [ { label: 'Claude' } ] );
	assert.notEqual( viewModel.provider, chatgpt );
	assert.equal( connectToolFilteringViewModel( { id: 'mcp' } ), null );
	assert.doesNotMatch( source, /providerGuidance\.map/ );
	assert.doesNotMatch( source, /providers\.filter/ );
} );

test( 'tool filtering copy helper remains deterministic for non-UI consumers', () => {
	const copyCalls = [];
	copyToolFilteringField(
		{
			value: '<script>not markup</script>',
			copiedMessage: 'Claude copied.',
		},
		( ...args ) => copyCalls.push( args )
	);

	assert.deepEqual( copyCalls, [
		[ '<script>not markup</script>', 'Claude copied.' ],
	] );
} );
