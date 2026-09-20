( function ( wp, settings ) {
	const PluginDocumentSettingPanel =
		wp && wp.editor && wp.editor.PluginDocumentSettingPanel
			? wp.editor.PluginDocumentSettingPanel
			: wp && wp.editPost && wp.editPost.PluginDocumentSettingPanel;

	if (
		! wp ||
		! settings ||
		! wp.apiFetch ||
		! wp.components ||
		! wp.data ||
		! wp.element ||
		! wp.plugins ||
		! PluginDocumentSettingPanel
	) {
		return;
	}

	const { apiFetch } = wp;
	const { Button, Notice, Spinner } = wp.components;
	const { createElement: el, useEffect, useState } = wp.element;
	const { useSelect } = wp.data;
	const { __ } = wp.i18n || { __: ( value ) => value };
	const { registerPlugin } = wp.plugins;
	const APPLY_DISABLED_REASON = __(
		'Safe insertion is waiting on the reviewed apply workflow. Copy or open suggestions for now.',
		'aculect-ai-companion'
	);

	apiFetch.use( apiFetch.createNonceMiddleware( settings.nonce ) );

	function confidenceLabel( value ) {
		const label = String( value || 'medium' ).replace( /_/g, ' ' );
		return label.charAt( 0 ).toUpperCase() + label.slice( 1 );
	}

	function copyText( value, setCopied ) {
		if ( ! value || ! window.navigator || ! window.navigator.clipboard ) {
			return;
		}

		window.navigator.clipboard.writeText( value ).then( () => {
			setCopied( value );
			window.setTimeout( () => setCopied( '' ), 1800 );
		} );
	}

	function ActionLinks( { item, copied, setCopied } ) {
		return el(
			'div',
			{ className: 'aculect-ai-companion-editor-links__actions' },
			el(
				Button,
				{
					type: 'button',
					variant: 'secondary',
					size: 'small',
					onClick: () => copyText( item.anchor, setCopied ),
					disabled: ! item.anchor,
				},
				copied === item.anchor
					? __( 'Copied', 'aculect-ai-companion' )
					: __( 'Copy anchor', 'aculect-ai-companion' )
			),
			item.url
				? el(
						Button,
						{
							href: item.url,
							target: '_blank',
							rel: 'noreferrer',
							variant: 'tertiary',
							size: 'small',
						},
						__( 'Open', 'aculect-ai-companion' )
				  )
				: null,
			item.editUrl
				? el(
						Button,
						{
							href: item.editUrl,
							target: '_blank',
							rel: 'noreferrer',
							variant: 'tertiary',
							size: 'small',
						},
						__( 'Edit', 'aculect-ai-companion' )
				  )
				: null,
			el(
				Button,
				{
					type: 'button',
					variant: 'secondary',
					size: 'small',
					disabled: true,
					title: APPLY_DISABLED_REASON,
				},
				__( 'Apply unavailable', 'aculect-ai-companion' )
			)
		);
	}

	function SuggestionItem( { item, copied, setCopied } ) {
		const warnings = Array.isArray( item.warnings ) ? item.warnings : [];
		const warningNodes = [];

		if ( item.stale ) {
			warningNodes.push(
				el(
					'span',
					{ key: 'stale' },
					__( 'Stale index row', 'aculect-ai-companion' )
				)
			);
		}

		warnings.forEach( ( warning ) => {
			warningNodes.push(
				el(
					'span',
					{ key: warning },
					String( warning ).replace( /_/g, ' ' )
				)
			);
		} );

		return el(
			'li',
			{ className: 'aculect-ai-companion-editor-links__item' },
			el(
				'div',
				{ className: 'aculect-ai-companion-editor-links__item-header' },
				el(
					'strong',
					null,
					item.title ||
						__( 'Untitled target', 'aculect-ai-companion' )
				),
				el(
					'span',
					{
						className: item.alreadyLinked
							? 'aculect-ai-companion-editor-links__pill is-linked'
							: 'aculect-ai-companion-editor-links__pill',
					},
					item.alreadyLinked
						? __( 'Already linked', 'aculect-ai-companion' )
						: confidenceLabel( item.confidence )
				)
			),
			item.anchor
				? el(
						'p',
						{
							className:
								'aculect-ai-companion-editor-links__anchor',
						},
						item.anchor
				  )
				: null,
			item.reason
				? el(
						'p',
						{
							className:
								'aculect-ai-companion-editor-links__reason',
						},
						item.reason
				  )
				: null,
			warningNodes.length
				? el(
						'div',
						{
							className:
								'aculect-ai-companion-editor-links__warnings',
						},
						warningNodes
				  )
				: null,
			el( ActionLinks, { item, copied, setCopied } )
		);
	}

	function InternalLinkSuggestionsPanel() {
		const [ payload, setPayload ] = useState( null );
		const [ error, setError ] = useState( '' );
		const [ copied, setCopied ] = useState( '' );
		const postId = useSelect(
			( select ) => select( 'core/editor' )?.getCurrentPostId?.(),
			[]
		);

		useEffect( () => {
			if ( ! postId ) {
				return undefined;
			}

			let cancelled = false;
			setError( '' );
			setPayload( null );
			apiFetch( {
				url: `${ settings.restUrl }?post_id=${ encodeURIComponent(
					postId
				) }`,
			} )
				.then( ( response ) => {
					if ( ! cancelled ) {
						setPayload( response );
					}
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setError(
							__(
								'Internal-link suggestions are unavailable right now.',
								'aculect-ai-companion'
							)
						);
					}
				} );

			return () => {
				cancelled = true;
			};
		}, [ postId ] );

		const items =
			payload && Array.isArray( payload.items ) ? payload.items : [];
		const linkedItems =
			payload && Array.isArray( payload.alreadyLinkedItems )
				? payload.alreadyLinkedItems
				: [];
		const itemNodes = items
			.map( ( item ) =>
				el( SuggestionItem, {
					key: `suggestion-${ item.postId }`,
					item,
					copied,
					setCopied,
				} )
			)
			.concat(
				linkedItems.map( ( item ) =>
					el( SuggestionItem, {
						key: `linked-${ item.postId }`,
						item,
						copied,
						setCopied,
					} )
				)
			);

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'aculect-ai-companion-internal-link-suggestions',
				title: __( 'Aculect internal links', 'aculect-ai-companion' ),
				className: 'aculect-ai-companion-editor-links',
			},
			error
				? el(
						Notice,
						{ status: 'warning', isDismissible: false },
						error
				  )
				: null,
			! error && ! payload
				? el(
						'div',
						{
							className:
								'aculect-ai-companion-editor-links__loading',
						},
						el( Spinner )
				  )
				: null,
			payload
				? el(
						'div',
						null,
						payload.message
							? el(
									'p',
									{
										className:
											'aculect-ai-companion-editor-links__message',
									},
									payload.message
							  )
							: null,
						payload.source && payload.source.stale
							? el(
									Notice,
									{ status: 'warning', isDismissible: false },
									__(
										'Refresh the content index before making final link decisions.',
										'aculect-ai-companion'
									)
							  )
							: null,
						itemNodes.length
							? el(
									'ul',
									{
										className:
											'aculect-ai-companion-editor-links__list',
									},
									itemNodes
							  )
							: el(
									'p',
									{
										className:
											'aculect-ai-companion-editor-links__empty',
									},
									__(
										'No suggestions to show.',
										'aculect-ai-companion'
									)
							  )
				  )
				: null
		);
	}

	registerPlugin( 'aculect-ai-companion-internal-link-suggestions', {
		render: InternalLinkSuggestionsPanel,
	} );
} )( window.wp, window.aculectAICompanionEditorLinks );
