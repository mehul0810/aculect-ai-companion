import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import path from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { chromium } from '@playwright/test';
import axe from 'axe-core';

const require = createRequire( import.meta.url );
// Provided transitively by the repository's @wordpress/scripts build tool.
// eslint-disable-next-line import/no-extraneous-dependencies
const webpack = require( 'webpack' );
const repoRoot = path.resolve(
	path.dirname( fileURLToPath( import.meta.url ) ),
	'../../..'
);
const tempDir = await mkdtemp( path.join( tmpdir(), 'aculect-readiness-ui-' ) );

function bundleFixture() {
	return new Promise( ( resolve, reject ) => {
		webpack(
			{
				mode: 'development',
				entry: path.join(
					repoRoot,
					'tests/fixtures/agent-readiness-app.jsx'
				),
				output: { path: tempDir, filename: 'fixture.js' },
				externals: {
					'@wordpress/components': 'var wp.components',
					'@wordpress/icons': 'var wp.icons',
				},
				module: {
					rules: [
						{
							test: /\.jsx?$/,
							include: [
								path.join( repoRoot, 'src/Admin/diagnostics' ),
								path.join( repoRoot, 'tests/fixtures' ),
							],
							use: {
								loader: require.resolve( 'babel-loader' ),
								options: {
									presets: [
										[
											require.resolve(
												'@babel/preset-react'
											),
											{ runtime: 'automatic' },
										],
									],
								},
							},
						},
					],
				},
			},
			( error, stats ) => {
				if ( error || stats.hasErrors() ) {
					reject(
						error ||
							new Error(
								stats.toString( { all: false, errors: true } )
							)
					);
					return;
				}
				resolve();
			}
		);
	} );
}

let browser;
try {
	await bundleFixture();
	const bundle = await readFile( path.join( tempDir, 'fixture.js' ), 'utf8' );
	const styles = await readFile(
		path.join( repoRoot, 'build/style-index.css' ),
		'utf8'
	);
	browser = await chromium.launch();
	const page = await browser.newPage( {
		viewport: { width: 1440, height: 900 },
	} );
	await page.setContent(
		'<!doctype html><html lang="en"><head><title>Agent readiness fixture</title></head><body><main id="fixture-root"></main></body></html>'
	);
	await page.addStyleTag( {
		content:
			`:root{--aculect-ai-companion-border:#e5e7eb;--aculect-ai-companion-surface:#fff;--aculect-ai-companion-muted:#64748b}body{margin:0;padding:20px;font:14px Arial,sans-serif}` +
			styles,
	} );
	await page.evaluate( () => {
		window.wp = {
			components: {
				Button: ( { children, variant, ...props } ) =>
					window.React.createElement(
						'button',
						{
							...props,
							className: `components-button is-${ variant }`,
						},
						children
					),
				Icon: () => null,
			},
			icons: { copy: {} },
		};
	} );
	await page.addScriptTag( { content: bundle } );
	await page.evaluate( () =>
		window.renderAgentReadiness( { ranAt: '', items: [] } )
	);
	const section = page.locator( '.aculect-ai-companion-agent-readiness' );
	assert.match(
		await section.innerText(),
		/No saved agent-readiness run yet/
	);
	assert.equal(
		await section
			.getByRole( 'button', { name: 'Copy redacted report' } )
			.isDisabled(),
		true
	);

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
	const readiness = {
		ranAt: '2026-09-25 08:00:00',
		items: statuses.map( ( status, index ) => ( {
			id: ids[ index ],
			status,
			message: `secret-marker ${ 'long message '.repeat( 400 ) }`,
			evidence: {
				httpStatus: [ 200, 404, 500, 0, 200, 200 ][ index ],
				client_secret: 'secret-marker',
				url: 'https://private.example',
			},
		} ) ),
	};
	await page.setViewportSize( { width: 375, height: 900 } );
	await page.evaluate(
		( payload ) => window.renderAgentReadiness( payload, true ),
		readiness
	);
	assert.equal(
		await section
			.locator( '.aculect-ai-companion-agent-readiness__row' )
			.count(),
		6
	);
	assert.equal( await section.getAttribute( 'aria-busy' ), 'true' );
	assert.match(
		await section.innerText(),
		/Checks are running; saved results remain visible/
	);
	assert.doesNotMatch(
		await section.innerText(),
		/secret-marker|private\.example/
	);
	assert.equal(
		await section.evaluate(
			( element ) => element.scrollWidth <= element.clientWidth + 2
		),
		true
	);
	await section
		.getByRole( 'button', { name: 'Copy redacted report' } )
		.click();
	assert.doesNotMatch(
		await page.evaluate( () => window.agentReadinessCopied ),
		/secret-marker|private\.example/
	);
	await page.addScriptTag( { content: axe.source } );
	const accessibility = await page.evaluate( async () =>
		window.axe.run( '.aculect-ai-companion-agent-readiness', {
			runOnly: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ],
		} )
	);
	assert.deepEqual(
		accessibility.violations.map( ( issue ) => issue.id ),
		[]
	);
	await page.screenshot( {
		path: '/private/tmp/aculect-agent-readiness-mobile.png',
		fullPage: true,
	} );
	await page.setViewportSize( { width: 1440, height: 900 } );
	await page.evaluate(
		( payload ) => window.renderAgentReadiness( payload, false ),
		readiness
	);
	await page.screenshot( {
		path: '/private/tmp/aculect-agent-readiness-desktop.png',
		fullPage: true,
	} );
	process.stdout.write(
		'PASS isolated agent readiness empty, six-status, running, mobile, redaction, and axe proof\n'
	);
} finally {
	await browser?.close();
	await rm( tempDir, { recursive: true, force: true } );
}
