import assert from 'node:assert/strict';
import test from 'node:test';
import { tabUrl } from '../../src/admin-tab-url.mjs';

test( 'approval navigation stays on the configured shared admin page', () => {
	assert.equal(
		tabUrl(
			'approvals',
			'https://example.test/subsite/wp-admin/options-general.php?page=aculect-ai-companion&tab=activity'
		),
		'https://example.test/subsite/wp-admin/options-general.php?page=aculect-ai-companion&tab=approvals'
	);
} );

test( 'overview removes the selected tab and retains the configured site path', () => {
	assert.equal(
		tabUrl(
			'overview',
			'https://example.test/subsite/wp-admin/options-general.php?page=aculect-ai-companion&tab=approvals'
		),
		'https://example.test/subsite/wp-admin/options-general.php?page=aculect-ai-companion'
	);
} );

test( 'invalid base URLs retain the shared-page fallback', () => {
	assert.equal(
		tabUrl( 'approvals', 'not a URL' ),
		'options-general.php?page=aculect-ai-companion&tab=approvals'
	);
	assert.equal(
		tabUrl( 'overview', 'not a URL' ),
		'options-general.php?page=aculect-ai-companion'
	);
} );
