import { chromium } from '@playwright/test';

const baseUrl = process.env.ACULECT_WP_BASE_URL || 'http://localhost:8880';
const username = process.env.ACULECT_WP_ADMIN_USER || 'workflow-admin';
const password =
	process.env.ACULECT_WP_ADMIN_PASSWORD || 'workflow-admin-password';
const browser = await chromium.launch();
const page = await browser.newPage();
const pageErrors = [];

page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

try {
	await page.goto( `${ baseUrl }/wp-login.php`, {
		waitUntil: 'networkidle',
	} );
	await page.fill( '#user_login', username );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'networkidle' );

	await page.goto(
		`${ baseUrl }/wp-admin/options-general.php?page=aculect-ai-companion-workflows`,
		{ waitUntil: 'networkidle' }
	);
	const heading =
		( await page.locator( 'h1' ).first().textContent() )?.trim() || '';
	if ( heading !== 'Content Workflows' ) {
		throw new Error( `Unexpected workflow admin heading: ${ heading }` );
	}
	const body = ( await page.locator( 'body' ).textContent() ) || '';
	if ( ! body.includes( 'Create bounded, versioned workflows' ) ) {
		throw new Error(
			'Workflow admin guidance is missing from the packaged screen.'
		);
	}
	if ( pageErrors.length > 0 ) {
		throw new Error( `Browser page errors: ${ pageErrors.join( '; ' ) }` );
	}

	// eslint-disable-next-line no-console -- CI proof reports its result to the job log.
	console.log( 'PASS packaged WordPress workflow admin browser proof' );
} finally {
	await browser.close();
}
