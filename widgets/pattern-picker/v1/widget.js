/* Selection only: no write tool, pattern markup, or unreviewed network fetch. */
( ( scope ) => {
	const doc = scope.document;
	const contract = scope.AculectPatternPickerContract;
	const bridge = scope.AculectWidgetBridge.createBridge( {
		windowRef: scope,
		appInfo: { name: 'Aculect Pattern Picker', version: '1.0.0' },
		readOnlyTools: [],
		onEvent: receive,
	} );
	const el = Object.fromEntries(
		[
			'notice',
			'picker',
			'search',
			'source',
			'compatibility',
			'count',
			'cards',
			'empty',
			'previous',
			'next',
			'page-label',
		].map( ( id ) => [ id, doc.getElementById( id ) ] )
	);
	const PAGE_SIZE = 12;
	const messages = {
		loading: 'Waiting for available patterns.',
		ready: 'Choose a compatible pattern to share its identifier with the assistant.',
		empty: 'No patterns were returned. Ask the assistant to refresh the site inventory.',
		stale: 'This pattern list is out of date. Ask the assistant to refresh it before choosing.',
		unavailable:
			'Patterns are unavailable or your access has changed. Check WordPress and try again.',
		error: 'The pattern list could not be displayed. Ask the assistant to retry discovery.',
		cancelled:
			'Pattern discovery was cancelled. Ask the assistant to try again.',
		disabled:
			'This interactive picker is unavailable. Use the text list in your host.',
	};
	let data = { state: 'loading', items: [] };
	let context = {};
	let page = 1;
	let selected = '';
	let pending = '';
	let selectionError = '';

	function element( tag, className, value ) {
		const node = doc.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( value !== undefined ) {
			node.textContent = value;
		}
		return node;
	}

	function card( item ) {
		const article = element( 'article', 'card' );
		article.setAttribute( 'role', 'listitem' );
		article.append(
			element(
				'span',
				'badge',
				item.compatibility === 'unknown'
					? 'Not verified'
					: item.compatibility
			)
		);
		article.firstChild.dataset.state = item.compatibility;
		article.append( element( 'h2', '', item.title ) );
		article.append(
			element(
				'p',
				'meta',
				`${ item.category } · ${ item.source }${
					item.contentType ? ` · ${ item.contentType }` : ''
				}`
			)
		);
		const preview = element( 'div', 'preview' );
		preview.setAttribute(
			'aria-label',
			'Semantic preview, not a screenshot'
		);
		preview.append(
			element( 'span', 'preview-label', 'Block information' )
		);
		const row = element( 'div', 'preview-row' );
		for ( const block of item.blocks.length
			? item.blocks
			: [ 'Block details unavailable' ] ) {
			row.append( element( 'span', 'preview-chip', block ) );
		}
		preview.append( row );
		article.append( preview );
		if ( item.message ) {
			article.append( element( 'p', 'meta', item.message ) );
		}
		const button = element(
			'button',
			'',
			selected === item.id ? 'Selected' : 'Choose pattern'
		);
		button.type = 'button';
		button.dataset.id = item.id;
		button.setAttribute(
			'aria-label',
			`${ selected === item.id ? 'Selected' : 'Choose' } ${ item.title }`
		);
		button.setAttribute( 'aria-pressed', String( selected === item.id ) );
		button.disabled =
			item.compatibility !== 'compatible' ||
			Boolean( pending ) ||
			! bridge.isConnected();
		article.append( button );
		return article;
	}

	function filtered() {
		const query = el.search.value.trim().toLocaleLowerCase();
		return data.items.filter( ( item ) => {
			const searchable = [
				item.title,
				item.id,
				item.category,
				item.source,
				item.contentType,
				...item.blocks,
			]
				.join( ' ' )
				.toLocaleLowerCase();
			return (
				( ! query || searchable.includes( query ) ) &&
				( ! el.source.value || item.source === el.source.value ) &&
				( ! el.compatibility.value ||
					item.compatibility === el.compatibility.value )
			);
		} );
	}

	function sources() {
		const previous = el.source.value;
		const all = element( 'option', '', 'All sources' );
		all.value = '';
		el.source.replaceChildren( all );
		for ( const source of [
			...new Set( data.items.map( ( item ) => item.source ) ),
		].sort() ) {
			const option = element(
				'option',
				'',
				source[ 0 ].toUpperCase() + source.slice( 1 )
			);
			option.value = source;
			el.source.append( option );
		}
		el.source.value = [ ...el.source.options ].some(
			( option ) => option.value === previous
		)
			? previous
			: '';
	}

	function render() {
		doc.documentElement.dataset.theme = [ 'light', 'dark' ].includes(
			context.theme
		)
			? context.theme
			: 'auto';
		const state =
			data.state === 'ready' && ! data.items.length
				? 'empty'
				: data.state;
		el.notice.dataset.state = state;
		el.notice.textContent = messages[ state ] || messages.error;
		if ( selected ) {
			el.notice.dataset.state = 'selected';
			el.notice.textContent = `Selected ${ selected }. Nothing has been inserted.`;
		}
		if ( pending ) {
			el.notice.textContent =
				'Sharing the selected identifier with the assistant…';
		}
		if ( selectionError ) {
			el.notice.dataset.state = 'error';
			el.notice.textContent = selectionError;
		}
		el.picker.hidden = data.state !== 'ready' || ! data.items.length;
		if ( el.picker.hidden ) {
			return;
		}
		const items = filtered();
		const pages = Math.max( 1, Math.ceil( items.length / PAGE_SIZE ) );
		page = Math.min( page, pages );
		const moreOnServer = data.total > data.items.length;
		let countSuffix = '.';
		if ( moreOnServer ) {
			countSuffix = `, from ${ data.total } server matches. Ask the assistant for the next discovery page to see more.`;
		} else if ( data.truncated ) {
			countSuffix =
				'. Only the first 100 returned can be shown; refine discovery to see more.';
		}
		el.count.textContent = `${ items.length } matching pattern${
			items.length === 1 ? '' : 's'
		} in this response${ countSuffix }`;
		el.cards.replaceChildren(
			...items
				.slice( ( page - 1 ) * PAGE_SIZE, page * PAGE_SIZE )
				.map( card )
		);
		el.empty.hidden = items.length !== 0;
		el.previous.disabled = page <= 1;
		el.next.disabled = page >= pages;
		el[ 'page-label' ].textContent = `Page ${ page } of ${ pages }`;
	}

	function receive( event ) {
		switch ( event.type ) {
			case 'ready':
			case 'host-context':
				context = { ...context, ...event.context };
				break;
			case 'tool-input':
				data = { state: 'loading', items: [] };
				selected = '';
				break;
			case 'tool-result':
				data = contract.parse( event.params );
				selected = '';
				page = 1;
				sources();
				break;
			case 'tool-cancelled':
				data = { state: 'cancelled', items: [] };
				break;
			case 'closed':
				data = { state: 'disabled', items: [] };
				break;
		}
		selectionError = '';
		render();
	}

	async function choose( id ) {
		const item = data.items.find( ( candidate ) => candidate.id === id );
		if (
			! item ||
			item.compatibility !== 'compatible' ||
			pending ||
			! bridge.isConnected()
		) {
			return;
		}
		pending = id;
		selectionError = '';
		render();
		try {
			await bridge.updateModelContext( {
				structuredContent: {
					schema: 'aculect.pattern-selection.v1',
					pattern_id: id,
				},
			} );
			selected = id;
		} catch {
			selectionError =
				'The host could not accept this selection. Nothing was inserted. Try again or use the text list.';
		} finally {
			pending = '';
			render();
			const active = [
				...el.cards.querySelectorAll( 'button[data-id]' ),
			].find( ( button ) => button.dataset.id === id );
			active?.focus();
		}
	}

	el.search.addEventListener( 'input', () => {
		page = 1;
		render();
	} );
	for ( const control of [ el.source, el.compatibility ] ) {
		control.addEventListener( 'change', () => {
			page = 1;
			render();
		} );
	}
	el.previous.addEventListener( 'click', () => {
		page--;
		render();
		el.previous.focus();
	} );
	el.next.addEventListener( 'click', () => {
		page++;
		render();
		el.next.focus();
	} );
	el.cards.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( 'button[data-id]' );
		if ( button ) {
			choose( button.dataset.id );
		}
	} );
	scope.AculectPatternPickerFixture = Object.freeze( {
		showResult( result ) {
			data = contract.parse( result );
			selected = '';
			page = 1;
			sources();
			render();
		},
	} );
	render();
	bridge.start().catch( () => {
		data = { state: 'disabled', items: [] };
		render();
	} );
} )( globalThis );
