import assert from 'node:assert/strict';
import { chromium } from '@playwright/test';
import axe from 'axe-core';

const baseUrl = process.env.ACULECT_SMOKE_BASE_URL;
const username = process.env.ACULECT_SMOKE_USERNAME;
const password = process.env.ACULECT_SMOKE_PASSWORD;

if ( ! baseUrl || ! username || ! password ) {
	throw new Error(
		'Set ACULECT_SMOKE_BASE_URL, ACULECT_SMOKE_USERNAME, and ACULECT_SMOKE_PASSWORD for packaged admin proof.'
	);
}

const browser = await chromium.launch();
const context = await browser.newContext();

async function fixturePage( readiness, width ) {
	const page = await context.newPage();
	await page.setViewportSize( { width, height: 900 } );
	await page.addInitScript( ( fixture ) => {
		let payload;
		Object.defineProperty( window, 'aculectAICompanionSettingsData', {
			configurable: true,
			get: () => payload,
			set: ( value ) => {
				payload = { ...value, agentReadiness: fixture };
			},
		} );
	}, readiness );
	await page.goto(
		`${ baseUrl }/wp-admin/options-general.php?page=aculect-ai-companion&tab=diagnostics`,
		{ waitUntil: 'networkidle' }
	);
	await page.getByRole( 'heading', { name: 'Agent readiness' } ).waitFor();
	return page;
}

try {
	const login = await context.newPage();
	await login.goto( `${ baseUrl }/wp-login.php` );
	await login.fill( '#user_login', username );
	await login.fill( '#user_pass', password );
	await Promise.all( [
		login.waitForURL( '**/wp-admin/**' ),
		login.click( '#wp-submit' ),
	] );
	await login.close();

	const empty = await fixturePage(
		{ ranAt: '', summary: 'not_run', items: [] },
		1440
	);
	const emptySection = empty.locator(
		'.aculect-ai-companion-agent-readiness'
	);
	assert.match(
		await emptySection.innerText(),
		/No saved agent-readiness run yet/
	);
	assert.equal(
		await emptySection
			.getByRole( 'button', { name: 'Copy redacted report' } )
			.isDisabled(),
		true
	);
	await empty.close();

	const statuses = [
		'pass',
		'warning',
		'fail',
		'externally_blocked',
		'owner_decision_required',
		'not_applicable',
	];
	const ids = [
		'oauth_discovery',
		'robots_txt',
		'oauth_authorization_metadata',
		'mcp_authentication_challenge',
		'content_signals',
		'a2a_agent_card',
	];
	const result = await fixturePage(
		{
			ranAt: '2026-09-25 08:00:00',
			summary: 'fail',
			items: statuses.map( ( status, index ) => ( {
				id: ids[ index ],
				status,
				message: `secret-marker ${ 'long message '.repeat( 400 ) }`,
				evidence: {
					httpStatus: 200,
					client_secret: 'secret-marker',
					url: 'https://private.example',
				},
			} ) ),
		},
		375
	);
	const section = result.locator( '.aculect-ai-companion-agent-readiness' );
	assert.equal(
		await section
			.locator( '.aculect-ai-companion-agent-readiness__row' )
			.count(),
		6
	);
	assert.doesNotMatch(
		await section.innerText(),
		/secret-marker|private\.example/
	);
	assert.equal(
		await section
			.locator( '.aculect-ai-companion-agent-readiness__status' )
			.count(),
		12
	);
	assert.equal(
		await section.evaluate(
			( element ) => element.scrollWidth <= element.clientWidth + 2
		),
		true
	);
	await result.addScriptTag( { content: axe.source } );
	const accessibility = await result.evaluate( async () =>
		window.axe.run( '.aculect-ai-companion-agent-readiness', {
			runOnly: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ],
		} )
	);
	assert.deepEqual(
		accessibility.violations.map( ( issue ) => issue.id ),
		[]
	);

	await result.evaluate( () => {
		const button = [ ...document.querySelectorAll( 'button' ) ].find(
			( item ) => item.textContent.includes( 'Run all checks' )
		);
		const form = button.closest( 'form' );
		form.addEventListener( 'submit', ( event ) => event.preventDefault(), {
			capture: true,
		} );
		form.requestSubmit();
	} );
	await result
		.locator( '.aculect-ai-companion-agent-readiness[aria-busy="true"]' )
		.waitFor();
	assert.match(
		await section.innerText(),
		/Checks are running; saved results remain visible/
	);
	await result.close();
	process.stdout.write(
		'PASS agent readiness empty, six statuses, mobile, redaction, accessibility, and running UI\n'
	);
} finally {
	await browser.close();
}
