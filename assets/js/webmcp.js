( function () {
	'use strict';

	if (
		! document.modelContext ||
		typeof document.modelContext.registerTool !== 'function'
	) {
		return;
	}

	const MAX_OUTPUT_LENGTH = 1500;
	const MAX_LINKS = 4;
	const registration = new AbortController();

	const cleanText = ( value, limit ) =>
		String( value || '' )
			.replace( /\s+/g, ' ' )
			.trim()
			.slice( 0, limit );

	const metaContent = ( name ) => {
		const element = document.querySelector( `meta[name="${ name }"]` );
		return element
			? cleanText( element.getAttribute( 'content' ), 240 )
			: '';
	};

	const publicUrl = ( value, limit ) => {
		try {
			const url = new URL( value, document.location.href );
			url.username = '';
			url.password = '';
			url.search = '';
			url.hash = '';
			return cleanText( url.href, limit );
		} catch {
			return '';
		}
	};

	const sameOriginLinks = ( root, maximum ) => {
		const links = [];
		const seen = new Set();

		for ( const element of root.querySelectorAll( 'a[href]' ) ) {
			if ( links.length >= maximum ) {
				break;
			}

			const label = cleanText( element.innerText, 60 );
			if ( ! label ) {
				continue;
			}

			let url;
			try {
				url = new URL( element.href, document.location.href );
			} catch {
				continue;
			}

			const safeUrl = publicUrl( url.href, 120 );
			if (
				url.origin !== document.location.origin ||
				seen.has( safeUrl )
			) {
				continue;
			}

			seen.add( safeUrl );
			links.push( {
				label,
				url: safeUrl,
			} );
		}

		return links;
	};

	const fitOutputBudget = ( context ) => {
		while ( JSON.stringify( context ).length > MAX_OUTPUT_LENGTH ) {
			if ( context.links.length ) {
				context.links.pop();
				continue;
			}
			if ( context.content.length > 120 ) {
				context.content = context.content.slice(
					0,
					context.content.length - 80
				);
				continue;
			}
			context.description = context.description.slice(
				0,
				Math.max( 0, context.description.length - 40 )
			);
		}

		return context;
	};

	const collectPageContext = ( input = {} ) => {
		const root =
			document.querySelector( 'main, article, [role="main"]' ) ||
			document.body;
		const requestedLinks = Number.isInteger( input.maxLinks )
			? input.maxLinks
			: MAX_LINKS;
		const maximumLinks = Math.max(
			0,
			Math.min( MAX_LINKS, requestedLinks )
		);
		const headings = Array.from( root.querySelectorAll( 'h1, h2, h3' ) )
			.map( ( heading ) => cleanText( heading.innerText, 80 ) )
			.filter( Boolean )
			.slice( 0, 6 );

		return fitOutputBudget( {
			type: 'aculect_page_context',
			url: publicUrl( document.location.href, 180 ),
			title: cleanText( document.title, 120 ),
			language: cleanText( document.documentElement.lang, 20 ),
			description: metaContent( 'description' ),
			headings,
			content: cleanText( root.innerText, 600 ),
			links: input.includeLinks
				? sameOriginLinks( root, maximumLinks )
				: [],
		} );
	};

	const register = async () => {
		await document.modelContext.registerTool(
			{
				name: 'aculect_get_page_context',
				title: 'Understand this WordPress page',
				description:
					'Returns bounded, visible context from the current public WordPress page, with optional same-origin navigation links.',
				inputSchema: {
					type: 'object',
					properties: {
						includeLinks: {
							type: 'boolean',
							description:
								'Include up to four same-origin links visible in the main page content.',
						},
						maxLinks: {
							type: 'integer',
							minimum: 0,
							maximum: MAX_LINKS,
							description: 'Maximum same-origin links to return.',
						},
					},
					additionalProperties: false,
				},
				annotations: {
					readOnlyHint: true,
					untrustedContentHint: true,
					consequentialHint: false,
				},
				execute: async ( input ) => collectPageContext( input ),
			},
			{ signal: registration.signal }
		);

		return true;
	};

	window.addEventListener( 'pagehide', () => registration.abort(), {
		once: true,
	} );
	window.aculectWebMcp = { collectPageContext, register };
	register().catch( () => false );
} )();
