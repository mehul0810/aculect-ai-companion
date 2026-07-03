import test from 'node:test';
import assert from 'node:assert/strict';

import {
	DEFAULT_LANDING_PAGE,
	createPlaygroundUrl,
	createPreviewBlueprint,
	validateBlueprintFile,
} from '../../scripts/playground-preview.mjs';

test( 'creates a Playground blueprint for a PR package URL', () => {
	const blueprint = createPreviewBlueprint( {
		pluginUrl:
			'https://uploads.github.example/aculect-ai-companion-pr-258.zip',
	} );

	assert.equal( blueprint.landingPage, DEFAULT_LANDING_PAGE );
	assert.equal( blueprint.steps[ 0 ].step, 'installPlugin' );
	assert.equal( blueprint.steps[ 0 ].pluginData.resource, 'url' );
	assert.equal(
		blueprint.steps[ 0 ].pluginData.url,
		'https://uploads.github.example/aculect-ai-companion-pr-258.zip'
	);
	assert.equal( blueprint.steps[ 0 ].options.activate, true );
	assert.equal( blueprint.steps[ 1 ].step, 'login' );
} );

test( 'creates an encoded Playground URL containing the PR package install step', () => {
	const blueprint = createPreviewBlueprint( {
		pluginUrl:
			'https://uploads.github.example/aculect-ai-companion-pr-258.zip',
	} );
	const previewUrl = createPlaygroundUrl( blueprint );
	const url = new URL( previewUrl );
	const decodedBlueprint = JSON.parse( url.searchParams.get( 'blueprint' ) );

	assert.equal( url.origin, 'https://playground.wordpress.net' );
	assert.equal(
		decodedBlueprint.steps[ 0 ].pluginData.url,
		'https://uploads.github.example/aculect-ai-companion-pr-258.zip'
	);
} );

test( 'rejects non-zip package URLs', () => {
	assert.throws(
		() =>
			createPreviewBlueprint( {
				pluginUrl:
					'https://uploads.github.example/aculect-ai-companion-pr-258.tar.gz',
			} ),
		/\.zip/
	);
} );

test( 'validates the WordPress.org listing blueprint without mutating it', () => {
	const blueprint = validateBlueprintFile(
		'.wordpress-org/blueprints/blueprint.json'
	);

	assert.equal( blueprint.landingPage, DEFAULT_LANDING_PAGE );
	assert.equal(
		blueprint.steps[ 0 ].pluginData.resource,
		'wordpress.org/plugins'
	);
} );
