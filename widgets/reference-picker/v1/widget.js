/* One read-result page, local filters, and an acknowledged selection only. */
( ( scope ) => {
	const doc = scope.document;
	const kind = scope.AculectReferencePickerKind;
	const media = kind === 'media';
	const bridge = scope.AculectWidgetBridge.createBridge( {
		windowRef: scope,
		appInfo: {
			name: `Aculect ${ media ? 'Media' : 'Content' } Picker`,
			version: '1.0.0',
		},
		readOnlyTools: [],
		onEvent: receive,
	} );
	const names = [
		'notice',
		'picker',
		'search',
		'kind',
		'count',
		'cards',
		'empty',
		'previous',
		'next',
		'page-label',
	];
	const el = Object.fromEntries(
		names.map( ( name ) => [ name, doc.getElementById( name ) ] )
	);
	const PAGE_SIZE = media ? 9 : 12;
	let data = { state: 'loading', items: [] };
	let page = 1;
	let context = {};
	let selected = '';
	let pending = '';
	let revision = 0;
	let selectionError = '';
	function node( tag, className, value ) {
		const result = doc.createElement( tag );
		if ( className ) {
			result.className = className;
		}
		if ( value !== undefined ) {
			result.textContent = value;
		}
		return result;
	}
	function key( item ) {
		return `${ item.type }:${ item.id }`;
	}
	function label( item ) {
		return `${ item.title } (${ item.type } #${ item.id })`;
	}
	function card( item ) {
		const article = node( 'article', 'card' );
		article.setAttribute( 'role', 'listitem' );
		if ( media ) {
			const preview = node( 'div', 'preview' );
			const origin = scope.ACULECT_MEDIA_ORIGIN;
			if (
				item.thumbnail &&
				origin &&
				new URL( item.thumbnail.url ).origin === origin
			) {
				const image = node( 'img' );
				image.src = item.thumbnail.url;
				image.alt = item.alt || '';
				image.width = item.thumbnail.width;
				image.height = item.thumbnail.height;
				image.loading = 'lazy';
				preview.append( image );
			} else {
				preview.append(
					node( 'span', 'placeholder', 'Preview unavailable' )
				);
			}
			article.append( preview );
		}
		const details = node( 'div', 'details' );
		details.append( node( 'h2', '', item.title ) );
		const meta = media
			? [
					item.mime || 'Media',
					item.thumbnail
						? `${ item.thumbnail.width } × ${ item.thumbnail.height } preview`
						: 'Preview dimensions unavailable',
			  ]
			: [
					item.type,
					item.status || 'Status unavailable',
					...( item.stale
						? [ 'Stale index result; refresh before selection' ]
						: [] ),
			  ];
		details.append( node( 'p', 'meta', meta.join( ' · ' ) ) );
		if ( media ) {
			details.append(
				node( 'p', 'meta', `Alt text: ${ item.alt || 'Not provided' }` )
			);
		} else if ( item.excerpt ) {
			details.append( node( 'p', 'excerpt', item.excerpt ) );
		}
		if ( item.link ) {
			details.append( node( 'p', 'meta url', item.link ) );
		}
		const buttonLabel =
			selected === key( item ) ? 'Selected' : `Choose ${ kind }`;
		const button = node( 'button', '', buttonLabel );
		button.type = 'button';
		button.dataset.key = key( item );
		button.setAttribute(
			'aria-label',
			`${ selected === key( item ) ? 'Selected' : 'Choose' } ${ label(
				item
			) }`
		);
		button.setAttribute(
			'aria-pressed',
			String( selected === key( item ) )
		);
		button.setAttribute(
			'aria-disabled',
			String( Boolean( pending ) || item.stale )
		);
		button.disabled = item.stale || ! bridge.isConnected();
		details.append( button );
		article.append( details );
		return article;
	}
	function filtered() {
		const query = el.search.value.trim().toLocaleLowerCase();
		return data.items.filter(
			( item ) =>
				( ! el.kind.value ||
					( media ? item.family : item.type ) === el.kind.value ) &&
				( ! query ||
					[
						item.title,
						item.type,
						item.status,
						item.excerpt,
						item.alt,
						item.mime,
						item.link,
					]
						.join( ' ' )
						.toLocaleLowerCase()
						.includes( query ) )
		);
	}
	function updateTypes() {
		const old = el.kind.value;
		el.kind.replaceChildren(
			Object.assign( node( 'option', '', 'All types' ), { value: '' } )
		);
		for ( const value of [
			...new Set(
				data.items.map( ( item ) =>
					media ? item.family : item.type
				)
			),
		].sort() ) {
			el.kind.append(
				Object.assign( node( 'option', '', value ), { value } )
			);
		}
		el.kind.value = [ ...el.kind.options ].some(
			( option ) => option.value === old
		)
			? old
			: '';
	}
	function render() {
		doc.documentElement.dataset.picker = kind;
		doc.documentElement.dataset.theme = [ 'light', 'dark' ].includes(
			context.theme
		)
			? context.theme
			: 'auto';
		const state =
			data.state === 'ready' && ! data.items.length
				? 'empty'
				: data.state;
		const messages = {
			loading: `Waiting for ${ kind } results.`,
			ready: `Choose an item to share its reference with the assistant. Nothing is changed.`,
			empty: `No ${ kind } items were returned. Ask the assistant to try another page or query.`,
			stale: 'This result is stale. Ask the assistant to refresh it.',
			unavailable:
				'The result is unavailable or access has changed. Ask the assistant to retry.',
			error: 'The result could not be displayed. Ask the assistant to retry the read.',
			cancelled:
				'The read was cancelled. Ask the assistant to try again.',
			disabled:
				'This interactive picker is unavailable. Use the text result in your host.',
		};
		el.notice.dataset.state = state;
		el.notice.textContent = messages[ state ];
		if ( selected ) {
			el.notice.textContent = `Selected ${ selected }. No changes were made.`;
		}
		if ( pending ) {
			el.notice.textContent = 'Sharing the reference with the assistant…';
		}
		if ( selectionError ) {
			el.notice.textContent = selectionError;
		}
		if ( selected && ! selectionError ) {
			el.notice.dataset.state = 'selected';
		}
		if ( selectionError ) {
			el.notice.dataset.state = 'error';
		}
		el.picker.hidden = data.state !== 'ready' || ! data.items.length;
		if ( el.picker.hidden ) {
			return;
		}
		const items = filtered();
		const pages = Math.max( 1, Math.ceil( items.length / PAGE_SIZE ) );
		page = Math.min( page, pages );
		const more =
			data.hasMore ||
			( data.total !== null &&
				data.total > data.page * Math.max( data.perPage, 1 ) );
		let suffix = '';
		if ( more ) {
			suffix = ` Ask the assistant for server page ${
				data.page + 1
			} to see more.`;
		} else if ( data.truncated ) {
			suffix =
				' Only the first 100 returned items are shown; ask the assistant to refine the query.';
		}
		el.count.textContent = `${ items.length } matching ${ kind } item${
			items.length === 1 ? '' : 's'
		} in this response. Search filters this loaded response only.${ suffix }`;
		const focus = doc.activeElement?.dataset?.key;
		el.cards.replaceChildren(
			...items
				.slice( ( page - 1 ) * PAGE_SIZE, page * PAGE_SIZE )
				.map( card )
		);
		if ( focus ) {
			[ ...el.cards.querySelectorAll( 'button[data-key]' ) ]
				.find(
					( button ) =>
						button.dataset.key === focus && ! button.disabled
				)
				?.focus();
		}
		el.empty.hidden = items.length !== 0;
		el.previous.disabled = page <= 1;
		el.next.disabled = page >= pages;
		el[ 'page-label' ].textContent = `Local page ${ page } of ${ pages }`;
	}
	function receive( event ) {
		switch ( event.type ) {
			case 'ready':
			case 'host-context':
				context = { ...context, ...event.context };
				break;
			case 'tool-input-partial':
			case 'tool-input':
				revision++;
				pending = '';
				selected = '';
				data = { state: 'loading', items: [] };
				break;
			case 'tool-result':
				revision++;
				pending = '';
				selected = '';
				data = scope.AculectReferencePickerContract.parse(
					event.params,
					kind
				);
				page = 1;
				updateTypes();
				break;
			case 'tool-cancelled':
				revision++;
				pending = '';
				selected = '';
				data = { state: 'cancelled', items: [] };
				break;
			case 'closed':
				revision++;
				pending = '';
				selected = '';
				data = { state: 'disabled', items: [] };
				break;
		}
		selectionError = '';
		render();
	}
	async function choose( value ) {
		const item = data.items.find(
			( candidate ) => key( candidate ) === value
		);
		if ( ! item || item.stale || pending || ! bridge.isConnected() ) {
			return;
		}
		pending = value;
		selectionError = '';
		const at = revision;
		render();
		try {
			await bridge.updateModelContext( {
				structuredContent: {
					schema: 'aculect.reference-selection.v1',
					id: item.id,
					type: item.type,
				},
			} );
			if ( at === revision ) {
				selected = value;
			}
		} catch {
			if ( at === revision ) {
				selectionError =
					'The host did not accept this reference. Nothing was changed. Try again or use the text result.';
			}
		} finally {
			if ( at === revision ) {
				pending = '';
				render();
			}
		}
	}
	el.search.addEventListener( 'input', () => {
		page = 1;
		render();
	} );
	el.kind.addEventListener( 'change', () => {
		page = 1;
		render();
	} );
	el.previous.addEventListener( 'click', () => {
		page--;
		render();
		( el.previous.disabled ? el.next : el.previous ).focus();
	} );
	el.next.addEventListener( 'click', () => {
		page++;
		render();
		( el.next.disabled ? el.previous : el.next ).focus();
	} );
	el.cards.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( 'button[data-key]' );
		if ( button ) {
			choose( button.dataset.key );
		}
	} );
	scope.AculectReferencePickerFixture = Object.freeze( {
		showResult( result ) {
			revision++;
			pending = '';
			selected = '';
			selectionError = '';
			data = scope.AculectReferencePickerContract.parse( result, kind );
			page = 1;
			updateTypes();
			render();
		},
	} );
	render();
	bridge.start().catch( () => {
		data = { state: 'disabled', items: [] };
		render();
	} );
} )( globalThis );
