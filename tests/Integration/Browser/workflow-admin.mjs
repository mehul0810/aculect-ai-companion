import assert from 'node:assert/strict';
import { chromium } from '@playwright/test';

const baseUrl = process.env.ACULECT_WP_BASE_URL || 'http://localhost:8880';
const username = process.env.ACULECT_WP_ADMIN_USER || 'workflow-admin';
const password =
	process.env.ACULECT_WP_ADMIN_PASSWORD || 'workflow-admin-password';
const browser = await chromium.launch();
const page = await browser.newPage( {
	viewport: { width: 1440, height: 1000 },
} );
const pageErrors = [];
page.on( 'pageerror', ( error ) => {
	if ( error.message !== 'Transition was skipped' ) {
		pageErrors.push( error.message );
	}
} );

try {
	await page.goto( `${ baseUrl }/wp-login.php` );
	await page.fill( '#user_login', username );
	await page.fill( '#user_pass', password );
	await Promise.all( [
		page.waitForURL( '**/wp-admin/**' ),
		page.click( '#wp-submit' ),
	] );
	await page.goto(
		`${ baseUrl }/wp-admin/admin.php?page=aculect-ai-companion`,
		{
			waitUntil: 'networkidle',
		}
	);
	await page
		.locator( '#aculect-ai-companion-settings-app-root > *' )
		.first()
		.waitFor();
	assert.ok(
		await page
			.locator( '#adminmenu a[href*="page=aculect-ai-companion"]' )
			.count(),
		'Aculect settings navigation must remain available.'
	);
	assert.equal(
		await page
			.locator( '#adminmenu a[href*="aculect-ai-companion-workflows"]' )
			.count(),
		0,
		'The custom workflow submenu must not be registered.'
	);
	assert.ok(
		! ( await page.locator( '#adminmenu' ).innerText() ).includes(
			'Content Workflows'
		)
	);
	assert.equal( await page.locator( '.aculect-workflow-admin' ).count(), 0 );
	assert.deepEqual(
		pageErrors,
		[],
		'Settings must render without JavaScript errors.'
	);

	const removedPage = await page.goto(
		`${ baseUrl }/wp-admin/options-general.php?page=aculect-ai-companion-workflows`
	);
	assert.equal(
		removedPage.status(),
		403,
		'The old admin page must be inaccessible.'
	);
	assert.equal( await page.locator( '.aculect-workflow-admin' ).count(), 0 );

	// WordPress rejects an unregistered authenticated admin-post action with 400.
	// No nonce or workflow payload is supplied, so this probe cannot save data.
	for ( const action of [
		'aculect_ai_companion_save_workflow',
		'aculect_ai_companion_disable_workflow',
	] ) {
		const response = await page.request.post(
			`${ baseUrl }/wp-admin/admin-post.php`,
			{
				form: { action },
				maxRedirects: 0,
			}
		);
		assert.equal(
			response.status(),
			400,
			`${ action } must not have a handler.`
		);
	}
	process.stdout.write(
		'PASS packaged settings and custom workflow absence proof\n'
	);
} finally {
	await browser.close();
}
