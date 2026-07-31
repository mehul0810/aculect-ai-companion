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

test( 'Connect renders advanced tool filtering as a collapsed native disclosure', () => {
	assert.match(
		source,
		/function ConnectToolFilteringGuidance\( \{ provider, onCopy \} \)/
	);
	assert.match( source, /<details>/ );
	assert.match( source, /\{ guidance\.title \}/ );
	assert.match( source, /\{ guidance\.advancedLabel \}/ );
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
	assert.match( source, /provider=\{ selectedConnectProvider \}/ );
	assert.doesNotMatch( source, /providerGuidance\.map/ );
	assert.doesNotMatch( source, /providers\.filter/ );
} );

test( 'Connect keeps copied text focusable, escaped by React, and reflow-safe', () => {
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
	assert.match( source, /guidance\.warning/ );
	assert.match( source, /copyToolFilteringField/ );
	assert.match( source, /tabIndex=\{ 0 \}/ );
	assert.match( source, /\{ guidance\.providerNote \}/ );

	const style = fs.readFileSync(
		path.resolve( testDirectory, '../../src/style.scss' ),
		'utf8'
	);
	assert.match(
		style,
		/\.aculect-ai-companion-tool-filtering__provider \{\s+min-width: 0;/
	);
	assert.match(
		style,
		/\.aculect-ai-companion-tool-filtering__set code \{[\s\S]*overflow-wrap: anywhere;/
	);
	assert.match(
		style,
		/@media[\s\S]*\.aculect-ai-companion-tool-filtering__sets \{\s+grid-template-columns: 1fr;/
	);
} );
