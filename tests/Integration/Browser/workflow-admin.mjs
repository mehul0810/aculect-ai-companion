import axe from 'axe-core';
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
	// WordPress may cancel a view transition while an admin form redirect is
	// already navigating. Treat that browser-level cancellation as expected;
	// every other page error remains a hard browser-proof failure.
	if ( 'Transition was skipped' === error.message ) {
		return;
	}
	pageErrors.push( error.message );
} );

const workflowUrl = `${ baseUrl }/wp-admin/options-general.php?page=aculect-ai-companion-workflows`;

const activeElementId = async () =>
	page
		.locator( '.aculect-workflow-admin' )
		.evaluate(
			( element ) => element.ownerDocument.activeElement?.id || ''
		);

const assertA11y = async () => {
	const labelProblems = await page
		.locator( '.aculect-workflow-admin label[for]' )
		.evaluateAll( ( labels ) =>
			labels
				.filter(
					( label ) => ! document.getElementById( label.htmlFor )
				)
				.map( ( label ) => label.htmlFor )
		);
	if ( labelProblems.length > 0 ) {
		throw new Error(
			`Labels reference missing controls: ${ labelProblems.join( ', ' ) }`
		);
	}

	await page.addScriptTag( { content: axe.source } );
	const violations = await page.evaluate( async () => {
		const result = await window.axe.run(
			document.querySelector( '.aculect-workflow-admin' ),
			{ resultTypes: [ 'violations' ] }
		);
		return result.violations.map( ( violation ) => ( {
			id: violation.id,
			help: violation.help,
			nodes: violation.nodes.slice( 0, 5 ).map( ( node ) => ( {
				target: node.target,
				html: node.html,
				failureSummary: node.failureSummary,
			} ) ),
		} ) );
	} );
	if ( violations.length > 0 ) {
		throw new Error(
			`Accessibility violations: ${ JSON.stringify( violations ) }`
		);
	}
};

const assertNoMobileOverflow = async () => {
	const dimensions = await page.locator( '.aculect-workflow-admin' ).evaluate( ( element ) => {
		const rootRect = element.getBoundingClientRect();
		const offenders = [ element, ...element.querySelectorAll( '*' ) ]
			.filter( ( candidate ) => candidate.scrollWidth > candidate.clientWidth + 1 )
			.slice( 0, 8 )
			.map( ( candidate ) => ( {
				tag: candidate.tagName.toLowerCase(),
				id: candidate.id || '',
				className: candidate.className || '',
				clientWidth: candidate.clientWidth,
				scrollWidth: candidate.scrollWidth,
			} ) );
		const outOfBounds = [ ...element.querySelectorAll( '*' ) ]
			.filter( ( candidate ) => candidate.getBoundingClientRect().right > rootRect.right + 1 )
			.slice( 0, 8 )
			.map( ( candidate ) => {
				const rect = candidate.getBoundingClientRect();
				return {
					tag: candidate.tagName.toLowerCase(),
					id: candidate.id || '',
					className: candidate.className || '',
					right: Math.round( rect.right ),
					rootRight: Math.round( rootRect.right ),
					text: ( candidate.textContent || '' ).trim().slice( 0, 120 ),
				};
			} );

		return {
			clientWidth: element.clientWidth,
			scrollWidth: element.scrollWidth,
			offenders,
			outOfBounds,
		};
	} );
	if ( dimensions.scrollWidth > dimensions.clientWidth + 1 ) {
		throw new Error(
			`Workflow admin overflows at mobile width (${ dimensions.scrollWidth } > ${ dimensions.clientWidth }): ${ JSON.stringify( { offenders: dimensions.offenders, outOfBounds: dimensions.outOfBounds } ) }`
		);
	}
};

try {
	await page.goto( `${ baseUrl }/wp-login.php`, {
		waitUntil: 'networkidle',
	} );
	await page.fill( '#user_login', username );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	await page.waitForLoadState( 'networkidle' );

	await page.goto( workflowUrl, { waitUntil: 'networkidle' } );
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
	if (
		( await page.locator( '#aculect-workflow-live-preview' ).count() ) !== 1
	) {
		throw new Error(
			'Live target preview is missing from the packaged screen.'
		);
	}
	if (
		( await page.locator( 'button, input, select, textarea' ).count() ) < 8
	) {
		throw new Error(
			'Workflow editor controls are missing from the packaged screen.'
		);
	}
	await page.locator( '#aculect-name' ).focus();
	await page.keyboard.press( 'Tab' );
	if ( ! ( await activeElementId() ) ) {
		throw new Error(
			'Workflow editor controls are not keyboard focusable.'
		);
	}
	await assertA11y();

	// A blank submission must return a focused, actionable validation summary.
	await page.locator( 'button[name="save_status"][value="draft"]' ).click();
	await page.waitForLoadState( 'networkidle' );
	if ( ( await page.locator( '#aculect-workflow-errors' ).count() ) !== 1 ) {
		throw new Error(
			'Validation errors were not rendered for an invalid workflow.'
		);
	}
	const focusedErrorId = await activeElementId();
	if ( focusedErrorId !== 'aculect-workflow-errors' ) {
		throw new Error(
			`Validation summary did not receive focus: ${ focusedErrorId }`
		);
	}

	// The custom-post-type starter intentionally hydrates an empty JSON object;
	// exercise the real selector and save path so an empty array cannot reach the
	// service as an invalid list contract.
	await page.selectOption( '#aculect-workflow-template', 'custom_post_type_creation' );
	if ( ( await page.inputValue( '#aculect-step_arguments' ) ) !== '{}' ) {
		throw new Error(
			'Custom post type starter did not hydrate an empty object for step arguments.'
		);
	}
	if (
		!( await page.locator( '#aculect-live-steps' ).textContent() ).includes(
			'content/create-item'
		)
	) {
		throw new Error(
			'Custom post type starter did not expose its create step.'
		);
	}

	// Create a disposable draft so the browser proof exercises the real edit,
	// migration-approval, and publish paths rather than only static markup.
	const workflowId = `browser_workflow_${ Date.now() }`;
	await page.fill( '#aculect-workflow_id', workflowId );
	await page.fill( '#aculect-name', 'Browser workflow proof' );
	await page.fill(
		'#aculect-description',
		'Disposable browser proof workflow.'
	);
	await page.locator( 'button[name="save_status"][value="draft"]' ).click();
	await page.waitForLoadState( 'networkidle' );
	if ( ! page.url().includes( `workflow_id=${ workflowId }` ) ) {
		throw new Error( 'The browser proof draft was not persisted.' );
	}
	// Re-open the canonical editor URL after the redirect so the remainder of
	// the proof always exercises the page that owns the saved draft. This also
	// makes the check independent of an intermediate admin-post response.
	await page.goto(
		`${ workflowUrl }&workflow_id=${ encodeURIComponent( workflowId ) }`,
		{ waitUntil: 'networkidle' }
	);
	const editorHeading = page.locator( 'h1' ).first();
	if ( ( await editorHeading.count() ) !== 1 ) {
		const diagnosticBody = ( await page.locator( 'body' ).textContent() ) || '';
		const visibleBody = ( await page.locator( 'body' ).innerText() ) || '';
		throw new Error(
			`The saved workflow editor could not be reopened (URL: ${ page.url() }; title: ${ await page.title() }; visible: ${ visibleBody.trim().slice( 0, 600 ) }; body-tail: ${ diagnosticBody.trim().slice( -3000 ) }).`
		);
	}
	if ( ( await editorHeading.textContent() )?.trim() !== 'Content Workflows' ) {
		throw new Error( `The saved workflow editor returned an unexpected heading at ${ page.url() }.` );
	}
	if ( ( await page.locator( '#aculect-workflow-template' ).count() ) !== 1 ) {
		throw new Error( 'The saved workflow editor is missing its starter template control.' );
	}

	// Selecting a write-capable starter must expose a migration preview and an
	// explicit plan approval before the immutable version can be changed.
	await page.selectOption( '#aculect-workflow-template', 'blog_post_draft' );
	if (
		! (
			await page.locator( '#aculect-live-steps' ).textContent()
		).includes( 'content/create-item' )
	) {
		throw new Error(
			'Template editing did not update the live step preview.'
		);
	}
	await page.locator( 'button[name="save_intent"][value="preview"]' ).click();
	await page.waitForLoadState( 'networkidle' );
	const migrationText =
		(
			await page
				.locator( '#aculect-workflow-migration-preview' )
				.textContent()
		)?.toLowerCase() || '';
	if (
		! migrationText.includes( 'blocked' ) &&
		! migrationText.includes( 'review required' )
	) {
		throw new Error(
			'Migration preview did not expose a review decision.'
		);
	}
	if (
		( await page.locator( '#aculect-migration-confirmed' ).count() ) !== 1
	) {
		throw new Error(
			'Migration approval control is missing for a behavior change.'
		);
	}
	await page.check( '#aculect-migration-confirmed' );
	await page.locator( 'button[name="save_status"][value="draft"]' ).click();
	await page.waitForLoadState( 'networkidle' );
	if ( ! page.url().includes( `workflow_id=${ workflowId }` ) ) {
		throw new Error( 'The approved migration draft was not persisted.' );
	}

	await page
		.locator( 'button[name="save_status"][value="published"]' )
		.click();
	await page.waitForLoadState( 'networkidle' );
	const publishedBody = ( await page.locator( 'body' ).textContent() ) || '';
	if ( ! publishedBody.includes( 'Published to connected assistants' ) ) {
		throw new Error( 'The browser proof workflow was not published.' );
	}

	await page.setViewportSize( { width: 390, height: 844 } );
	await page.reload( { waitUntil: 'networkidle' } );
	await assertNoMobileOverflow();
	await assertA11y();

	if ( pageErrors.length > 0 ) {
		throw new Error( `Browser page errors: ${ pageErrors.join( '; ' ) }` );
	}

	// eslint-disable-next-line no-console -- CI proof reports its result to the job log.
	console.log( 'PASS packaged WordPress workflow admin browser proof' );
} finally {
	await browser.close();
}
