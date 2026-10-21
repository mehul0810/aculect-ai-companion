( function () {
	'use strict';

	// The asset can be executed again by a theme or a navigation framework.
	if ( window.aculectWebMcp?.__publicBridge === true ) {
		return;
	}

	const MAX_OUTPUT_LENGTH = 1500;
	const MAX_LINKS = 4;
	const MAX_NODES = 400;
	let registration = null;
	let registrationResult = null;
	let pageHidden = false;
	let state = 'unavailable';
	let attempts = 0;
	let failures = 0;
	const readinessTimers = new Set();
	const isActive = () =>
		! pageHidden && document.visibilityState !== 'hidden';
	const hasApi = () => {
		try {
			return typeof document.modelContext?.registerTool === 'function';
		} catch {
			return false;
		}
	};
	const stopReadiness = () => {
		for ( const timer of readinessTimers ) {
			window.clearTimeout( timer );
		}
		readinessTimers.clear();
	};
	const stopRegistration = () => {
		registration?.abort();
		registration = null;
		registrationResult = null;
		state = 'hidden';
	};

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
			node.isContentEditable === true ||
			( node.hasAttribute?.( 'contenteditable' ) &&
				node.getAttribute( 'contenteditable' ) !== 'false' ) ||
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
				! [ 'http:', 'https:' ].includes( url.protocol ) ||
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
		if ( ! isActive() ) {
			throw new Error( 'Page context is unavailable while hidden.' );
		}
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
			links:
				input.includeLinks === true
					? sameOriginLinks( nodes, maximumLinks )
					: [],
		} );
	};

	const toolDefinition = ( controller ) => ( {
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
		execute: async ( input, options ) => {
			const unavailable = () =>
				controller.signal.aborted ||
				registration !== controller ||
				options?.signal?.aborted === true ||
				! isActive();
			if ( unavailable() ) {
				throw new Error( 'Page context is unavailable.' );
			}
			const context = collectPageContext( input );
			if ( unavailable() ) {
				throw new Error( 'Page context is unavailable.' );
			}
			return context;
		},
	} );

	const register = () => {
		if ( ! isActive() ) {
			state = 'hidden';
			return Promise.resolve( false );
		}
		if ( ! hasApi() ) {
			state = 'unavailable';
			return Promise.resolve( false );
		}
		if ( registrationResult ) {
			return registrationResult;
		}
		const controller = new AbortController();
		registration = controller;
		state = 'registering';
		// Defer invocation so synchronous browser errors use the same recovery path.
		registrationResult = Promise.resolve()
			.then( async () => {
				if ( controller.signal.aborted || ! isActive() ) {
					return false;
				}
				attempts++;
				await document.modelContext.registerTool(
					toolDefinition( controller ),
					{
						signal: controller.signal,
					}
				);

				if ( controller.signal.aborted || ! isActive() ) {
					return false;
				}
				state = 'registered';
				stopReadiness();
				return true;
			} )
			.catch( () => {
				failures++;
				controller.abort();
				if ( registration === controller ) {
					registration = null;
					registrationResult = null;
					state = isActive() ? 'failed' : 'hidden';
				}
				return false;
			} );
		return registrationResult;
	};

	// Two finite retries per lifecycle checkpoint accommodate a late browser API.
	const scheduleReadiness = () => {
		stopReadiness();
		if ( ! isActive() ) {
			return;
		}
		for ( const delay of [ 100, 500 ] ) {
			const timer = window.setTimeout( () => {
				readinessTimers.delete( timer );
				if ( isActive() && ! registrationResult ) {
					register();
				}
			}, delay );
			readinessTimers.add( timer );
		}
	};

	window.addEventListener( 'pagehide', () => {
		pageHidden = true;
		stopReadiness();
		stopRegistration();
	} );
	window.addEventListener( 'pageshow', () => {
		pageHidden = false;
		register();
		scheduleReadiness();
	} );
	window.addEventListener( 'visibilitychange', () => {
		if ( ! isActive() ) {
			stopReadiness();
			stopRegistration();
			return;
		}
		register();
		scheduleReadiness();
	} );
	document.addEventListener( 'DOMContentLoaded', () => {
		register();
		scheduleReadiness();
	} );
	window.aculectWebMcp = {
		__publicBridge: true,
		collectPageContext,
		register,
		getStatus: () => ( { state, attempts, failures } ),
	};
	register();
	scheduleReadiness();
} )();
