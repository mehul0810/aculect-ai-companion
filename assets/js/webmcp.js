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
	const MAX_NODES = 400;
	const registration = new AbortController();

	const cleanText = ( value, limit ) =>
		String( value || '' )
			.slice( 0, limit * 4 )
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

	const isHidden = ( node ) => {
		if ( node.nodeType !== 1 ) {
			return false;
		}
		const style =
			typeof window.getComputedStyle === 'function'
				? window.getComputedStyle( node )
				: {};
		return (
			/^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE|FORM|INPUT|TEXTAREA|SELECT)$/.test(
				node.tagName
			) ||
			node.hidden ||
			node.getAttribute( 'aria-hidden' ) === 'true' ||
			( node.tagName === 'DETAILS' && ! node.open ) ||
			style.display === 'none' ||
			[ 'hidden', 'collapse' ].includes( style.visibility ) ||
			style.opacity === '0'
		);
	};

	// Bounded traversal avoids materializing the entire page or forcing innerText layout.
	const visibleNodes = ( root ) => {
		const nodes = [];
		let node = root;
		let visited = 0;
		let ancestor = root?.parentNode;
		while ( ancestor && visited++ < MAX_NODES ) {
			if ( isHidden( ancestor ) ) {
				return nodes;
			}
			ancestor = ancestor.parentNode;
		}
		if ( ancestor ) {
			return nodes;
		}
		while ( node && visited++ < MAX_NODES ) {
			if ( ! isHidden( node ) ) {
				nodes.push( node );
				if ( node.firstChild ) {
					node = node.firstChild;
					continue;
				}
			}
			while ( node !== root && ! node.nextSibling ) {
				node = node.parentNode;
			}
			node = node === root ? null : node.nextSibling;
		}
		return nodes;
	};

	const visibleText = ( root, maximum ) => {
		let text = '';
		for ( const node of visibleNodes( root ) ) {
			if ( node.nodeType === 3 ) {
				text += ` ${ String( node.nodeValue || '' ).slice(
					0,
					maximum
				) }`;
				if ( text.length >= maximum ) {
					break;
				}
			}
		}
		return cleanText( text, maximum );
	};

	const sameOriginLinks = ( nodes, maximum ) => {
		const links = [];
		const seen = new Set();

		for ( const element of nodes ) {
			if ( links.length >= maximum ) {
				break;
			}

			if ( element.tagName !== 'A' || ! element.href ) {
				continue;
			}
			const label = visibleText( element, 60 );
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
		// Every iteration consumes data; even JSON-escaped headings cannot stall this loop.
		for ( const field of [
			'links',
			'content',
			'description',
			'headings',
			'title',
			'url',
			'language',
		] ) {
			while (
				context[ field ].length &&
				JSON.stringify( context ).length > MAX_OUTPUT_LENGTH
			) {
				context[ field ] = context[ field ].slice(
					0,
					Math.max(
						0,
						context[ field ].length -
							( Array.isArray( context[ field ] ) ? 1 : 40 )
					)
				);
			}
		}

		return context;
	};

	const collectPageContext = ( input = {} ) => {
		input = input && typeof input === 'object' ? input : {};
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
		const nodes = visibleNodes( root );
		const headings = nodes
			.filter( ( node ) => /^(H1|H2|H3)$/.test( node.tagName ) )
			.slice( 0, 6 )
			.map( ( heading ) => visibleText( heading, 80 ) )
			.filter( Boolean )
			.slice( 0, 6 );

		return fitOutputBudget( {
			type: 'aculect_page_context',
			url: publicUrl( document.location.href, 180 ),
			title: cleanText( document.title, 120 ),
			language: cleanText( document.documentElement.lang, 20 ),
			description: metaContent( 'description' ),
			headings,
			content: visibleText( root, 600 ),
			links: input.includeLinks
				? sameOriginLinks( nodes, maximumLinks )
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
