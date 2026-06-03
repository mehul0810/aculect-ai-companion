import {
	render,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import './style.scss';
import {
	mergeSettingsPayload,
	normalizeTabName,
	tabNameIsHydrated,
} from './admin-tab-hydration.mjs';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Modal,
	Notice,
	SearchControl,
	SelectControl,
	TextareaControl,
	TextControl,
	Tooltip,
	ToggleControl,
} from '@wordpress/components';
import {
	Icon,
	arrowRight,
	category,
	chartBar,
	check,
	chevronDown,
	chevronUp,
	cog,
	comment,
	copy,
	external,
	globe,
	help,
	home,
	info,
	link,
	lock,
	media,
	moreVertical,
	page,
	people,
	plugins,
	postContent,
	settings,
	shield,
} from '@wordpress/icons';

const TAB_QUERY_PARAM = 'tab';
const SETTINGS_TABS = [
	{ name: 'overview', title: 'Overview', icon: home },
	{ name: 'connect', title: 'Connect', icon: link },
	{ name: 'connections', title: 'Connections', icon: people },
	{ name: 'abilities', title: 'Abilities', icon: plugins },
	{ name: 'activity', title: 'Activity', icon: chartBar },
	{ name: 'diagnostics', title: 'Diagnostics', icon: shield },
	{ name: 'advanced', title: 'Advanced', icon: cog },
	{ name: 'changelog', title: 'Changelog', icon: page },
	{ name: 'brand', title: 'Brand', hidden: true },
	{ name: 'logs', title: 'Logs', hidden: true },
];
const EMPTY_ARRAY = [];
const DATA_VIEW_TABLE_LAYOUTS = { table: true };
const DIAGNOSTIC_FILTERS = [
	{ name: 'all', label: 'All checks' },
	{ name: 'pass', label: 'Passed' },
	{ name: 'warn', label: 'Warnings' },
	{ name: 'fail', label: 'Errors' },
];
const DIAGNOSTIC_STATUS_LABELS = {
	pass: 'Pass',
	warn: 'Warning',
	fail: 'Error',
};
const CHANGELOG_METADATA_KEYS = new Set( [
	'date',
	'releaseDate',
	'releasedAt',
	'type',
] );
const CONNECTOR_LOGO_PATHS = {
	chatgpt:
		'M22.282 9.821a6 6 0 0 0-.516-4.91a6.05 6.05 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a6 6 0 0 0-3.998 2.9a6.05 6.05 0 0 0 .743 7.097a5.98 5.98 0 0 0 .51 4.911a6.05 6.05 0 0 0 6.515 2.9A6 6 0 0 0 13.26 24a6.06 6.06 0 0 0 5.772-4.206a6 6 0 0 0 3.997-2.9a6.06 6.06 0 0 0-.747-7.073M13.26 22.43a4.48 4.48 0 0 1-2.876-1.04l.141-.081l4.779-2.758a.8.8 0 0 0 .392-.681v-6.737l2.02 1.168a.07.07 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494M3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085l4.783 2.759a.77.77 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646M2.34 7.896a4.5 4.5 0 0 1 2.366-1.973V11.6a.77.77 0 0 0 .388.677l5.815 3.354l-2.02 1.168a.08.08 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.872zm16.597 3.855l-5.833-3.387L15.119 7.2a.08.08 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.407-.667m2.01-3.023l-.141-.085l-4.774-2.782a.78.78 0 0 0-.785 0L9.409 9.23V6.897a.07.07 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08L8.704 5.46a.8.8 0 0 0-.393.681zm1.097-2.365l2.602-1.5l2.607 1.5v2.999l-2.597 1.5l-2.607-1.5Z',
	claude: 'm4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z',
	codex: 'M22.282 9.821a6 6 0 0 0-.516-4.91a6.05 6.05 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a6 6 0 0 0-3.998 2.9a6.05 6.05 0 0 0 .743 7.097a5.98 5.98 0 0 0 .51 4.911a6.05 6.05 0 0 0 6.515 2.9A6 6 0 0 0 13.26 24a6.06 6.06 0 0 0 5.772-4.206a6 6 0 0 0 3.997-2.9a6.06 6.06 0 0 0-.747-7.073M13.26 22.43a4.48 4.48 0 0 1-2.876-1.04l.141-.081l4.779-2.758a.8.8 0 0 0 .392-.681v-6.737l2.02 1.168a.07.07 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494M3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085l4.783 2.759a.77.77 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646M2.34 7.896a4.5 4.5 0 0 1 2.366-1.973V11.6a.77.77 0 0 0 .388.677l5.815 3.354l-2.02 1.168a.08.08 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.872zm16.597 3.855l-5.833-3.387L15.119 7.2a.08.08 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.407-.667m2.01-3.023l-.141-.085l-4.774-2.782a.78.78 0 0 0-.785 0L9.409 9.23V6.897a.07.07 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08L8.704 5.46a.8.8 0 0 0-.393.681zm1.097-2.365l2.602-1.5l2.607 1.5v2.999l-2.597 1.5l-2.607-1.5Z',
};
const ADMIN_NOTICE_SELECTOR = [
	'#wpbody-content > .notice',
	'#wpbody-content > .updated',
	'#wpbody-content > .error',
	'.aculect-ai-companion-settings-wrap > .notice',
	'.aculect-ai-companion-settings-wrap > .updated',
	'.aculect-ai-companion-settings-wrap > .error',
	'.aculect-ai-companion-app-header .notice',
	'.aculect-ai-companion-app-header .updated',
	'.aculect-ai-companion-app-header .error',
].join( ',' );

function hasTab( tabs, tabName ) {
	return tabs.some( ( tab ) => tab.name === tabName );
}

function initialTabName( tabs ) {
	const defaultTab = tabs[ 0 ]?.name || 'overview';

	try {
		const url = new URL( window.location.href );
		const requestedTab = normalizeTabName(
			url.searchParams.get( TAB_QUERY_PARAM )
		);

		return requestedTab && hasTab( tabs, requestedTab )
			? requestedTab
			: defaultTab;
	} catch {
		return defaultTab;
	}
}

function tabUrl( tabName, adminPageUrl = '' ) {
	const normalizedTabName = normalizeTabName( tabName );

	try {
		const url = new URL( adminPageUrl || window.location.href );
		url.searchParams.set( 'page', 'aculect-ai-companion' );
		if ( normalizedTabName === 'overview' ) {
			url.searchParams.delete( TAB_QUERY_PARAM );
		} else {
			url.searchParams.set( TAB_QUERY_PARAM, normalizedTabName );
		}

		return url.toString();
	} catch {
		return normalizedTabName === 'overview'
			? 'options-general.php?page=aculect-ai-companion'
			: `options-general.php?page=aculect-ai-companion&tab=${ normalizedTabName }`;
	}
}

function persistTabName( tabName, replace = true ) {
	const normalizedTabName = normalizeTabName( tabName );

	try {
		const url = new URL( window.location.href );
		if ( normalizedTabName === 'overview' ) {
			url.searchParams.delete( TAB_QUERY_PARAM );
		} else {
			url.searchParams.set( TAB_QUERY_PARAM, normalizedTabName );
		}
		const stateMethod = replace ? 'replaceState' : 'pushState';
		window.history[ stateMethod ]( {}, '', url.toString() );
	} catch {
		// URL state is progressive enhancement; tab navigation still works.
	}
}

function formatVersion( version ) {
	const normalizedVersion = String( version || '' ).trim();

	if ( ! normalizedVersion ) {
		return '';
	}

	return normalizedVersion.startsWith( 'v' )
		? normalizedVersion
		: `v${ normalizedVersion }`;
}

function adminTabTitle( title ) {
	return `Aculect AI Companion: ${ title }`;
}

function shouldIgnoreTabClick( event ) {
	return (
		event.defaultPrevented ||
		event.metaKey ||
		event.altKey ||
		event.ctrlKey ||
		event.shiftKey ||
		event.button !== 0
	);
}

function maybeSelectTab( event, tabName ) {
	if ( shouldIgnoreTabClick( event ) ) {
		return;
	}

	event.preventDefault();
	persistTabName( tabName, false );
	window.dispatchEvent(
		new CustomEvent( 'aculect-ai-companion-tab-selected', {
			detail: { tabName },
		} )
	);
}

function hasHydratedTab(
	tabName,
	data = window.aculectAICompanionSettingsData || {}
) {
	const fallbackTabs = SETTINGS_TABS.map( ( tab ) => tab.name );

	return tabNameIsHydrated( tabName, data, fallbackTabs );
}

async function fetchSettingsPayload( data, tabName ) {
	if ( ! data.settingsPayloadUrl ) {
		throw new Error( 'Settings payload URL is unavailable.' );
	}

	const url = new URL( data.settingsPayloadUrl, window.location.origin );
	url.searchParams.set( TAB_QUERY_PARAM, normalizeTabName( tabName ) );

	const headers = {
		Accept: 'application/json',
	};

	if ( data.settingsRestNonce ) {
		headers[ 'X-WP-Nonce' ] = data.settingsRestNonce;
	}

	const response = await window.fetch( url.toString(), {
		credentials: 'same-origin',
		headers,
	} );

	if ( ! response.ok ) {
		throw new Error( 'Settings payload request failed.' );
	}

	const payload = await response.json();
	if ( ! isPlainObject( payload ) ) {
		throw new Error( 'Settings payload response is invalid.' );
	}

	return payload;
}

function updateAdminSubmenuSelection( tabName ) {
	const submenu = document.querySelector( '#menu-settings .wp-submenu' );
	if ( ! submenu ) {
		return;
	}

	const normalizedTabName = normalizeTabName( tabName ) || 'overview';
	submenu.querySelectorAll( 'li' ).forEach( ( item ) => {
		const linkNode = item.querySelector( 'a[href]' );
		const href = linkNode?.getAttribute( 'href' ) || '';
		let isActive = href.includes( 'page=aculect-ai-companion' );

		try {
			const url = new URL( href, window.location.href );
			isActive =
				url.searchParams.get( 'page' ) === 'aculect-ai-companion' &&
				( normalizedTabName === 'overview' ||
					! url.searchParams.has( TAB_QUERY_PARAM ) );
		} catch {
			// Fall back to the string checks above for unusual admin URLs.
		}

		item.classList.toggle( 'current', isActive );
		if ( linkNode ) {
			linkNode.classList.toggle( 'current', isActive );
			if ( isActive ) {
				linkNode.setAttribute( 'aria-current', 'page' );
			} else {
				linkNode.removeAttribute( 'aria-current' );
			}
		}
	} );
}

function useActiveTabName( tabs ) {
	const [ activeTabName, setActiveTabName ] = useState( () =>
		initialTabName( tabs )
	);

	useEffect( () => {
		updateAdminSubmenuSelection( activeTabName );
	}, [ activeTabName ] );

	useEffect( () => {
		const handleSelect = ( event ) => {
			const requestedTab = normalizeTabName(
				event.detail?.tabName || ''
			);
			if ( hasTab( tabs, requestedTab ) ) {
				setActiveTabName( requestedTab );
			}
		};
		const handlePopState = () => {
			setActiveTabName( initialTabName( tabs ) );
		};

		window.addEventListener(
			'aculect-ai-companion-tab-selected',
			handleSelect
		);
		window.addEventListener( 'popstate', handlePopState );

		return () => {
			window.removeEventListener(
				'aculect-ai-companion-tab-selected',
				handleSelect
			);
			window.removeEventListener( 'popstate', handlePopState );
		};
	}, [ tabs ] );

	return activeTabName;
}

function relocateAdminNotices( target ) {
	if ( ! target ) {
		return;
	}

	document.querySelectorAll( ADMIN_NOTICE_SELECTOR ).forEach( ( notice ) => {
		if ( notice.closest( '.aculect-ai-companion-admin-notices' ) ) {
			return;
		}

		notice.classList.add( 'aculect-ai-companion-admin-notice' );
		target.appendChild( notice );
	} );
}

function CopyField( { label, value, secret = false, onCopy } ) {
	const inputId = useRef(
		`aculect-ai-companion-copy-field-${ String( label )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' ) }-${ Math.random()
			.toString( 36 )
			.slice( 2 ) }`
	);
	const labelId = `${ inputId.current }-label`;
	const copyValue = String( value || '' );

	return (
		<div className="aculect-ai-companion-copy-field">
			{ secret ? (
				<label
					className="aculect-ai-companion-copy-field__label"
					htmlFor={ inputId.current }
					id={ labelId }
				>
					{ label }
				</label>
			) : (
				<span
					className="aculect-ai-companion-copy-field__label"
					id={ labelId }
				>
					{ label }
				</span>
			) }
			<div className="aculect-ai-companion-copy-field__control">
				{ secret ? (
					<input
						id={ inputId.current }
						className="aculect-ai-companion-copy-field__input"
						type="password"
						value={ copyValue }
						readOnly
						aria-label={ label }
						spellCheck={ false }
					/>
				) : (
					<code
						id={ inputId.current }
						className="aculect-ai-companion-copy-field__display"
						tabIndex={ 0 }
						aria-labelledby={ labelId }
						title={ copyValue }
					>
						{ copyValue }
					</code>
				) }
				<Button
					type="button"
					variant="secondary"
					className="aculect-ai-companion-copy-field__button"
					onClick={ () => onCopy( value ) }
					aria-label={ `Copy ${ label }` }
				>
					<span
						className="aculect-ai-companion-copy-field__icon"
						aria-hidden="true"
					>
						<Icon icon={ copy } size={ 18 } />
					</span>
				</Button>
			</div>
		</div>
	);
}

function ConnectStepHeading( { number, title, children } ) {
	return (
		<div className="aculect-ai-companion-connect-step-heading">
			<span className="aculect-ai-companion-connect-step-number">
				{ number }
			</span>
			<div>
				<h2>{ title }</h2>
				{ children && <p>{ children }</p> }
			</div>
		</div>
	);
}

function preferredOpenProviderId( providers ) {
	return (
		providers.find( ( provider ) => provider.id === 'chatgpt' )?.id ||
		providers[ 0 ]?.id ||
		''
	);
}

function providerBadgeLabel( provider ) {
	const labels = {
		chatgpt: 'C',
		claude: 'A',
		codex: 'Cx',
	};

	return labels[ provider.id ] || provider.label?.charAt( 0 ) || 'AI';
}

function providerOverviewText( provider ) {
	return `Connect ${ provider.label } to manage your WordPress site.`;
}

function ConnectProviderBadge( { provider } ) {
	const logoPath = CONNECTOR_LOGO_PATHS[ provider.id ] || '';

	return (
		<span
			className={ `aculect-ai-companion-provider-badge is-${ provider.id }` }
			aria-hidden="true"
		>
			{ logoPath ? (
				<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
					<path d={ logoPath } />
				</svg>
			) : (
				providerBadgeLabel( provider )
			) }
		</span>
	);
}

function ConnectCapabilityCard( { icon, title, children, tone = 'blue' } ) {
	return (
		<div className="aculect-ai-companion-connect-capability-card">
			<span
				className={ `aculect-ai-companion-connect-capability-card__icon is-${ tone }` }
				aria-hidden="true"
			>
				<Icon icon={ icon } size={ 20 } />
			</span>
			<div>
				<h3>{ title }</h3>
				<p>{ children }</p>
			</div>
		</div>
	);
}

function EmptyState( { title, children } ) {
	return (
		<div className="aculect-ai-companion-empty-state">
			<strong>{ title }</strong>
			{ children && <p>{ children }</p> }
		</div>
	);
}

function TabLoadingState( { tab, failed = false, isLoading = false } ) {
	if ( failed ) {
		return (
			<div className="aculect-ai-companion-tab-loading is-error">
				<Notice status="error" isDismissible={ false }>
					{ tab.title } could not be loaded. Use the direct tab link
					if the problem persists.
				</Notice>
			</div>
		);
	}

	const label = isLoading ? 'Loading' : 'Preparing';

	return (
		<section
			className="aculect-ai-companion-tab-loading"
			role="status"
			aria-live="polite"
			aria-busy="true"
		>
			<div className="aculect-ai-companion-tab-loading__header">
				<span
					className="aculect-ai-companion-tab-loading__icon"
					aria-hidden="true"
				>
					<Icon icon={ tab.icon || settings } size={ 22 } />
				</span>
				<div>
					<span className="aculect-ai-companion-eyebrow">
						{ label } workspace
					</span>
					<h2>{ tab.title }</h2>
					<p>WordPress is preparing the latest data for this view.</p>
				</div>
				<span
					className="aculect-ai-companion-tab-loading__spinner"
					aria-hidden="true"
				/>
			</div>
			<div className="aculect-ai-companion-tab-loading__grid">
				{ [ 1, 2, 3 ].map( ( item ) => (
					<span
						key={ item }
						className="aculect-ai-companion-tab-loading__skeleton"
						aria-hidden="true"
					/>
				) ) }
			</div>
		</section>
	);
}

function useDataViewsModule() {
	const [ dataViewsModule, setDataViewsModule ] = useState( null );

	useEffect( () => {
		let isMounted = true;

		import( '@wordpress/dataviews/wp' ).then( ( nextModule ) => {
			if ( isMounted ) {
				setDataViewsModule( nextModule );
			}
		} );

		return () => {
			isMounted = false;
		};
	}, [] );

	return dataViewsModule;
}

function DataViewLoadingState( { label } ) {
	return (
		<div className="aculect-ai-companion-data-view__loading">
			<span
				className="aculect-ai-companion-tab-loading__spinner"
				aria-hidden="true"
			/>
			<strong>{ label }</strong>
		</div>
	);
}

function ActionForm( {
	data,
	action,
	nonce,
	label,
	children,
	destructive = false,
	onSubmit,
	isBusy = false,
	busyLabel = '',
	disabled = false,
	variant = '',
	enctype = '',
	confirmMessage = '',
	confirmTitle = 'Confirm action',
	confirmButtonLabel = '',
} ) {
	const [ isConfirmOpen, setIsConfirmOpen ] = useState( false );
	const formRef = useRef( null );
	const confirmedSubmitRef = useRef( false );
	const submitLabel = isBusy && busyLabel ? busyLabel : label;
	const isDisabled = disabled || isBusy;
	const handleSubmit = ( event ) => {
		if ( confirmMessage && ! confirmedSubmitRef.current ) {
			event.preventDefault();
			setIsConfirmOpen( true );
			return false;
		}

		confirmedSubmitRef.current = false;

		if ( onSubmit ) {
			return onSubmit( event );
		}

		return undefined;
	};
	const submitConfirmedAction = () => {
		confirmedSubmitRef.current = true;
		setIsConfirmOpen( false );

		if ( formRef.current?.requestSubmit ) {
			formRef.current.requestSubmit();
			return;
		}

		formRef.current?.submit();
	};

	return (
		<>
			<form
				ref={ formRef }
				method="post"
				action={ data.actions?.adminPostUrl }
				className="aculect-ai-companion-action-form"
				onSubmit={ handleSubmit }
				{ ...( enctype ? { encType: enctype } : {} ) }
			>
				<input type="hidden" name="action" value={ action } />
				<input type="hidden" name="_wpnonce" value={ nonce } />
				{ children }
				<Button
					type="submit"
					variant={
						variant || ( destructive ? 'secondary' : 'primary' )
					}
					isDestructive={ destructive }
					isBusy={ isBusy }
					disabled={ isDisabled }
					accessibleWhenDisabled
				>
					{ submitLabel }
				</Button>
			</form>
			{ isConfirmOpen && (
				<Modal
					title={ confirmTitle }
					onRequestClose={ () => setIsConfirmOpen( false ) }
				>
					<p>{ confirmMessage }</p>
					<div className="aculect-ai-companion-confirm-dialog__actions">
						<Button
							type="button"
							variant="secondary"
							onClick={ () => setIsConfirmOpen( false ) }
						>
							Cancel
						</Button>
						<Button
							type="button"
							variant="primary"
							isDestructive={ destructive }
							onClick={ submitConfirmedAction }
						>
							{ confirmButtonLabel || label }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}

function brandFieldValue( values, key ) {
	const value = values?.[ key ];

	return typeof value === 'string' ? value : '';
}

function BrandDefaultValue( { value, color = false } ) {
	if ( ! value ) {
		return null;
	}

	return (
		<p className="aculect-ai-companion-brand-default">
			<span>Detected default</span>
			{ color && (
				<i
					className="aculect-ai-companion-brand-swatch"
					style={ { backgroundColor: value } }
					aria-hidden="true"
				/>
			) }
			<code>{ value }</code>
		</p>
	);
}

function BrandTextField( {
	fields,
	defaults,
	name,
	label,
	type = 'text',
	color = false,
} ) {
	const value = brandFieldValue( fields, name );
	const defaultValue = brandFieldValue( defaults, name );
	const [ fieldValue, setFieldValue ] = useState( value );

	useEffect( () => {
		setFieldValue( value );
	}, [ value ] );

	return (
		<div className="aculect-ai-companion-brand-field">
			<div className="aculect-ai-companion-brand-field__control">
				{ color && value && (
					<i
						className="aculect-ai-companion-brand-swatch"
						style={ { backgroundColor: value } }
						aria-hidden="true"
					/>
				) }
				<TextControl
					label={ label }
					type={ type }
					name={ `brand_profile[${ name }]` }
					value={ fieldValue }
					onChange={ setFieldValue }
				/>
			</div>
			<BrandDefaultValue value={ defaultValue } color={ color } />
		</div>
	);
}

function BrandTextareaField( { fields, defaults, name, label } ) {
	const value = brandFieldValue( fields, name );
	const [ fieldValue, setFieldValue ] = useState( value );

	useEffect( () => {
		setFieldValue( value );
	}, [ value ] );

	return (
		<div className="aculect-ai-companion-brand-field">
			<TextareaControl
				label={ label }
				name={ `brand_profile[${ name }]` }
				value={ fieldValue }
				onChange={ setFieldValue }
				rows={ 4 }
			/>
			<BrandDefaultValue value={ brandFieldValue( defaults, name ) } />
		</div>
	);
}

function LogContext( { context } ) {
	const hasContext =
		context &&
		typeof context === 'object' &&
		Object.keys( context ).length > 0;

	if ( ! hasContext ) {
		return <span className="aculect-ai-companion-muted">None</span>;
	}

	return (
		<details className="aculect-ai-companion-log-context">
			<summary>View</summary>
			<pre>{ JSON.stringify( context, null, 2 ) }</pre>
		</details>
	);
}

function LogsTable( { logs } ) {
	const items = Array.isArray( logs?.items ) ? logs.items : [];

	if ( items.length === 0 ) {
		return (
			<EmptyState title="No diagnostic logs">
				No diagnostic logs have been recorded yet.
			</EmptyState>
		);
	}

	return (
		<div className="aculect-ai-companion-log-table-wrap">
			<table className="widefat striped aculect-ai-companion-log-table">
				<thead>
					<tr>
						<th>Time</th>
						<th>Level</th>
						<th>Event</th>
						<th>Provider</th>
						<th>Status</th>
						<th>Error</th>
						<th>Message</th>
						<th>Context</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( item ) => (
						<tr key={ item.id }>
							<td>{ item.created_at }</td>
							<td>
								<span
									className={ `aculect-ai-companion-log-level is-${ item.level }` }
								>
									{ item.level || 'info' }
								</span>
							</td>
							<td>
								<code>{ item.event }</code>
							</td>
							<td>{ item.provider || '-' }</td>
							<td>{ item.http_status || '-' }</td>
							<td>{ item.error_code || '-' }</td>
							<td>{ item.message || '-' }</td>
							<td>
								<LogContext context={ item.context } />
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function activityAssistantName( item ) {
	return item.client_name || item.provider || item.client_id || 'Assistant';
}

function activityUserName( item ) {
	if ( item.user ) {
		return item.user;
	}

	return item.user_id ? `User #${ item.user_id }` : 'Unknown user';
}

function activityTargetLabel( item ) {
	const targetType = item.target_type || 'site';

	return item.target_id ? `${ targetType } #${ item.target_id }` : targetType;
}

function activityBreakdown( items, getLabel ) {
	const counts = items.reduce( ( totals, item ) => {
		const label = getLabel( item );

		return {
			...totals,
			[ label ]: ( totals[ label ] || 0 ) + 1,
		};
	}, {} );

	return Object.entries( counts )
		.map( ( [ label, count ] ) => ( { label, count } ) )
		.sort(
			( a, b ) => b.count - a.count || a.label.localeCompare( b.label )
		)
		.slice( 0, 5 );
}

function ActivityStatusPill( { status } ) {
	const normalizedStatus = status === 'error' ? 'error' : 'success';

	return (
		<span
			className={ `aculect-ai-companion-activity-status is-${ normalizedStatus }` }
		>
			{ normalizedStatus === 'error' ? 'Failed' : 'Success' }
		</span>
	);
}

function ActivityTable( { activity } ) {
	const items = Array.isArray( activity?.items ) ? activity.items : [];

	if ( items.length === 0 ) {
		return (
			<EmptyState title="No connected AI activity">
				No connected AI activity has been recorded yet.
			</EmptyState>
		);
	}

	return (
		<div className="aculect-ai-companion-activity-table-wrap">
			<table className="widefat striped aculect-ai-companion-activity-table">
				<thead>
					<tr>
						<th>Time</th>
						<th>User</th>
						<th>Assistant</th>
						<th>Action</th>
						<th>Target</th>
						<th>Result</th>
						<th>Details</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( item ) => (
						<tr key={ item.id }>
							<td>
								<strong className="aculect-ai-companion-activity-table__time">
									{ item.created_at }
								</strong>
							</td>
							<td>{ activityUserName( item ) }</td>
							<td>{ activityAssistantName( item ) }</td>
							<td>
								<code>{ item.action }</code>
							</td>
							<td>{ activityTargetLabel( item ) }</td>
							<td>
								<ActivityStatusPill status={ item.status } />
								{ item.error_code && (
									<span className="aculect-ai-companion-activity-error-code">
										{ item.error_code }
									</span>
								) }
							</td>
							<td>
								{ item.message || '-' }
								<LogContext context={ item.context } />
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function ActivityInsightList( { title, items, emptyText } ) {
	return (
		<div className="aculect-ai-companion-activity-insight-list">
			<h3>{ title }</h3>
			{ items.length === 0 ? (
				<p>{ emptyText }</p>
			) : (
				<ul>
					{ items.map( ( item ) => (
						<li key={ item.label }>
							<span>{ item.label }</span>
							<strong>{ item.count }</strong>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

function ActivityInsights( { activity } ) {
	const items = Array.isArray( activity?.items ) ? activity.items : [];
	const actionBreakdown = activityBreakdown(
		items,
		( item ) => item.action || 'Unknown action'
	);
	const userBreakdown = activityBreakdown( items, activityUserName );

	return (
		<aside className="aculect-ai-companion-activity-sidebar">
			<div className="aculect-ai-companion-side-panel">
				<div className="aculect-ai-companion-side-panel__heading">
					<span className="aculect-ai-companion-side-panel__icon">
						<Icon icon={ chartBar } size={ 20 } />
					</span>
					<h3>Activity trend</h3>
				</div>
				<p>
					{ activity.total || 0 } sanitized records match the current
					filters across { activity.totalPages || 1 } page
					{ activity.totalPages === 1 ? '' : 's' }.
				</p>
			</div>
			<ActivityInsightList
				title="Action breakdown"
				items={ actionBreakdown }
				emptyText="No actions match the current filters."
			/>
			<ActivityInsightList
				title="Top active users"
				items={ userBreakdown }
				emptyText="No connected users appear in this result set."
			/>
			<div className="aculect-ai-companion-side-panel">
				<div className="aculect-ai-companion-side-panel__heading">
					<span className="aculect-ai-companion-side-panel__icon">
						<Icon icon={ shield } size={ 20 } />
					</span>
					<h3>Privacy posture</h3>
				</div>
				<p>
					IP addresses are omitted from this dashboard. Details are
					limited to sanitized audit context and never include raw
					request bodies, tokens, OAuth codes, or secrets.
				</p>
			</div>
		</aside>
	);
}

function diagnosticItems( health ) {
	return Array.isArray( health?.items ) ? health.items : [];
}

function diagnosticCounts( items ) {
	return items.reduce(
		( counts, item ) => {
			if ( item.status === 'pass' ) {
				counts.pass += 1;
			} else if ( item.status === 'fail' ) {
				counts.fail += 1;
			} else {
				counts.warn += 1;
			}

			counts.total += 1;
			return counts;
		},
		{ total: 0, pass: 0, warn: 0, fail: 0 }
	);
}

function diagnosticFilterCount( counts, filterName ) {
	return filterName === 'all' ? counts.total : counts[ filterName ] || 0;
}

function filteredDiagnosticItems( items, filterName ) {
	return filterName === 'all'
		? items
		: items.filter( ( item ) => item.status === filterName );
}

function diagnosticStatusLabel( status ) {
	return DIAGNOSTIC_STATUS_LABELS[ status ] || 'Not run';
}

function formatDiagnosticCheckLabel( id ) {
	return String( id || 'connection_check' )
		.replace( /[_-]+/g, ' ' )
		.replace( /\b\w/g, ( character ) => character.toUpperCase() );
}

function diagnosticObject( value ) {
	return value && typeof value === 'object' && ! Array.isArray( value )
		? value
		: {};
}

function hasDiagnosticValue( value ) {
	return (
		value !== undefined && value !== null && String( value ).trim() !== ''
	);
}

function diagnosticSystemRows( health ) {
	const system = diagnosticObject( health?.system );
	const details = diagnosticObject( health?.details );
	const rows = [
		{ label: 'Site URL', value: system.site_url },
		{ label: 'REST URL', value: system.rest_url },
		{
			label: 'Connection URL',
			value:
				system.connection_url ||
				details.connection_url ||
				details.connectionurl,
		},
		{ label: 'WordPress', value: system.wordpress_version },
		{ label: 'PHP', value: system.php_version },
		{ label: 'Environment', value: system.environment_type },
		{ label: 'Debug mode', value: system.debug_mode },
	];

	return rows.filter( ( row ) => hasDiagnosticValue( row.value ) );
}

function diagnosticSupportInfo( health ) {
	const rows = [
		...diagnosticSystemRows( health ),
		{
			label: 'Last diagnostics run',
			value: health?.ranAt || 'Never',
		},
		{
			label: 'Overall status',
			value: diagnosticStatusLabel( health?.summary ),
		},
	];
	const checks = diagnosticItems( health );
	const lines = rows.map( ( row ) => `${ row.label }: ${ row.value }` );

	if ( checks.length > 0 ) {
		lines.push( '', 'Checks:' );
		checks.forEach( ( item ) => {
			lines.push(
				`${ formatDiagnosticCheckLabel(
					item.id
				) }: ${ diagnosticStatusLabel( item.status ) } - ${
					item.message || 'No result message'
				}`
			);
			if ( item.remediation ) {
				lines.push( `Next action: ${ item.remediation }` );
			}
		} );
	}

	return lines.join( '\n' );
}

function StatusBadge( { status } ) {
	const normalizedStatus = [ 'pass', 'warn', 'fail' ].includes( status )
		? status
		: 'warn';

	return (
		<span
			className={ `aculect-ai-companion-health-status is-${ normalizedStatus }` }
		>
			{ diagnosticStatusLabel( normalizedStatus ) }
		</span>
	);
}

function DiagnosticsFilterTabs( { counts, activeFilter, onChange } ) {
	return (
		<div
			className="aculect-ai-companion-diagnostic-filters"
			aria-label="Diagnostic check filters"
		>
			{ DIAGNOSTIC_FILTERS.map( ( filter ) => {
				const isActive = activeFilter === filter.name;

				return (
					<button
						key={ filter.name }
						type="button"
						aria-pressed={ isActive }
						className={ isActive ? 'is-active' : '' }
						onClick={ () => onChange( filter.name ) }
					>
						<span>{ filter.label }</span>
						<strong className="aculect-ai-companion-diagnostic-filter__count">
							{ diagnosticFilterCount( counts, filter.name ) }
						</strong>
					</button>
				);
			} ) }
		</div>
	);
}

function ConnectionHealthChecks( { health, filter } ) {
	const items = diagnosticItems( health );
	const visibleItems = filteredDiagnosticItems( items, filter );

	if ( items.length === 0 ) {
		return (
			<EmptyState title="No diagnostics run">
				Run all checks to verify the connection URL, metadata endpoints,
				authorization challenge, and approval screen.
			</EmptyState>
		);
	}

	if ( visibleItems.length === 0 ) {
		return (
			<EmptyState title="No checks in this view">
				Choose another diagnostic status to review the saved results.
			</EmptyState>
		);
	}

	return (
		<div className="aculect-ai-companion-health-table-wrap">
			<table className="widefat striped aculect-ai-companion-health-table">
				<thead>
					<tr>
						<th>Check</th>
						<th>Status</th>
						<th>Result</th>
						<th>Next Action</th>
						<th>Details</th>
					</tr>
				</thead>
				<tbody>
					{ visibleItems.map( ( item ) => (
						<tr key={ item.id }>
							<td data-label="Check">
								<strong>
									{ formatDiagnosticCheckLabel( item.id ) }
								</strong>
								<code>{ item.id }</code>
							</td>
							<td data-label="Status">
								<StatusBadge status={ item.status } />
							</td>
							<td data-label="Result">{ item.message || '-' }</td>
							<td data-label="Next Action">
								{ item.remediation || '-' }
							</td>
							<td data-label="Details">
								<LogContext context={ item.details } />
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function SetupSection( { provider, section, sectionIndex, onCopy } ) {
	const steps = Array.isArray( section.steps ) ? section.steps : [];
	const copyFields = Array.isArray( section.copyFields )
		? section.copyFields
		: [];

	return (
		<div
			className={ `aculect-ai-companion-setup-method ${
				copyFields.length > 0 ? 'has-copy-fields' : ''
			}` }
		>
			<div className="aculect-ai-companion-setup-method__content">
				<h4 className="aculect-ai-companion-setup-method__title">
					{ section.title || 'Setup' }
				</h4>
				{ section.description && (
					<p className="aculect-ai-companion-setup-method__description">
						{ section.description }
					</p>
				) }
				{ steps.length > 0 && (
					<ol className="aculect-ai-companion-steps">
						{ steps.map( ( step, index ) => (
							<li
								key={ `${ provider.id }-${ sectionIndex }-${ index }` }
							>
								{ step }
							</li>
						) ) }
					</ol>
				) }
				{ section.actionUrl && (
					<div className="aculect-ai-companion-provider-actions">
						<Button
							href={ section.actionUrl }
							target="_blank"
							rel="noreferrer noopener"
							variant="secondary"
						>
							{ section.actionLabel || 'Open Docs' }
						</Button>
					</div>
				) }
			</div>
			{ copyFields.length > 0 && (
				<div className="aculect-ai-companion-setup-method__fields">
					<h5 className="aculect-ai-companion-section-heading">
						Copy
					</h5>
					{ copyFields.map( ( field ) => (
						<CopyField
							key={ `${ provider.id }-${ sectionIndex }-${ field.label }` }
							label={ field.label }
							value={ field.value }
							secret={ Boolean( field.secret ) }
							onCopy={ ( value ) =>
								onCopy( value, `${ field.label } copied.` )
							}
						/>
					) ) }
				</div>
			) }
		</div>
	);
}

function connectStatusDetails( {
	isAccessPaused,
	hasActiveSessions,
	sessionCount,
} ) {
	if ( isAccessPaused ) {
		return {
			tone: 'is-paused',
			title: 'Access paused',
			description:
				'Existing connections are paused and cannot run MCP actions until access is resumed.',
			meta:
				sessionCount > 0
					? `${ sessionCount } active session${
							sessionCount === 1 ? '' : 's'
					  } paused`
					: 'No active sessions',
		};
	}

	if ( hasActiveSessions ) {
		return {
			tone: 'is-connected',
			title: 'Connected',
			description:
				'At least one AI assistant has an active WordPress-approved session.',
			meta: `${ sessionCount } active session${
				sessionCount === 1 ? '' : 's'
			}`,
		};
	}

	return {
		tone: 'is-ready',
		title: 'Ready to connect',
		description:
			'No assistants are connected yet. Follow the steps to get started.',
		meta: 'No active sessions',
	};
}

function uniqueHelpLinks( providers ) {
	const links = [];
	const seenUrls = new Set();

	providers.forEach( ( provider ) => {
		if (
			provider.primaryActionUrl &&
			! seenUrls.has( provider.primaryActionUrl )
		) {
			seenUrls.add( provider.primaryActionUrl );
			links.push( {
				url: provider.primaryActionUrl,
				label: provider.primaryActionLabel || provider.label,
			} );
		}

		const setupSections = Array.isArray( provider.setupSections )
			? provider.setupSections
			: [];
		setupSections.forEach( ( section ) => {
			if ( ! section.actionUrl || seenUrls.has( section.actionUrl ) ) {
				return;
			}

			seenUrls.add( section.actionUrl );
			links.push( {
				url: section.actionUrl,
				label: section.actionLabel || section.title || provider.label,
			} );
		} );
	} );

	return links;
}

function ConnectStatusPanel( { status, connectionsUrl, onNavigate } ) {
	const statusIcon = status.tone === 'is-paused' ? lock : check;

	return (
		<div
			className={ `aculect-ai-companion-connect-card aculect-ai-companion-connection-status ${ status.tone }` }
		>
			<span className="aculect-ai-companion-connection-status__icon">
				<Icon icon={ statusIcon } size={ 18 } />
			</span>
			<div>
				<h3>{ status.title }</h3>
				<p>{ status.description }</p>
				<strong>{ status.meta }</strong>
				<a
					className="aculect-ai-companion-connection-status__link"
					href={ connectionsUrl }
					onClick={ onNavigate }
				>
					Learn more about connections
				</a>
			</div>
		</div>
	);
}

function normalizeConnectionSession( session, status ) {
	return {
		...session,
		status: session.status || status,
		writePermissionEnabled: isEnabledFlag(
			session.write_permission_enabled ??
				session.writePermissionEnabled ??
				false
		),
	};
}

function isEnabledFlag( value ) {
	return value === true || value === 1 || value === '1';
}

function connectionScopeLabel( scope ) {
	const labels = {
		'content:read': 'Read content',
		'content:draft': 'Create and update content',
	};

	return labels[ scope ] || scope;
}

function connectionDateValue( value, fallback = 'Never' ) {
	const rawValue = String( value || '' ).trim();

	if ( ! rawValue ) {
		return fallback;
	}

	const parsedDate = new Date( rawValue.replace( ' ', 'T' ) );
	if ( Number.isNaN( parsedDate.getTime() ) ) {
		return rawValue;
	}

	return new Intl.DateTimeFormat( undefined, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} ).format( parsedDate );
}

function connectionSessionStatus( session, isAccessPaused ) {
	if ( session.status === 'revoked' ) {
		return {
			label: 'Revoked',
			tone: 'is-revoked',
		};
	}

	if ( isAccessPaused ) {
		return {
			label: 'Paused',
			tone: 'is-paused',
		};
	}

	return {
		label: 'Active',
		tone: 'is-active',
	};
}

function connectionAbilityLabels( session, abilities, enabledAbilityIds ) {
	const scopes = Array.isArray( session.scopes ) ? session.scopes : [];
	const enabledIds = new Set( enabledAbilityIds );
	const labels = abilities
		.filter(
			( ability ) =>
				enabledIds.has( ability.id ) && scopes.includes( ability.scope )
		)
		.map( ( ability ) => ability.title || ability.id );

	if ( labels.length > 0 ) {
		return Array.from( new Set( labels ) );
	}

	return scopes.map( connectionScopeLabel );
}

function isCurrentUserSession( session, currentUserId ) {
	return (
		Number( currentUserId || 0 ) > 0 &&
		Number( session.user_id || 0 ) === Number( currentUserId || 0 )
	);
}

function connectionStatusKey( session, isAccessPaused ) {
	const status = session.status || 'active';

	if ( status === 'revoked' ) {
		return 'revoked';
	}

	if ( status === 'active' && isAccessPaused ) {
		return 'paused';
	}

	if ( status === 'pending' ) {
		return 'pending';
	}

	return 'active';
}

function connectionOwnershipKey( session, currentUserId ) {
	if ( isCurrentUserSession( session, currentUserId ) ) {
		return 'my';
	}

	if (
		Number( currentUserId || 0 ) > 0 &&
		Number( session.user_id || 0 ) > 0
	) {
		return 'team';
	}

	return 'unknown';
}

function connectionTimestamp( value ) {
	const timestamp = Date.parse( value || '' );

	return Number.isFinite( timestamp ) ? timestamp : 0;
}

function connectionOptionElements( values ) {
	return Array.from(
		new Set(
			values
				.map( ( value ) => String( value || '' ).trim() )
				.filter( Boolean )
		)
	)
		.sort()
		.map( ( value ) => ( {
			value,
			label: value,
		} ) );
}

function ConnectionStatusChip( { session, isAccessPaused } ) {
	const status = connectionSessionStatus( session, isAccessPaused );

	return (
		<span
			className={ `aculect-ai-companion-connection-status-chip ${ status.tone }` }
		>
			{ status.label }
		</span>
	);
}

function ConnectionAbilityChips( { session, abilities, enabledAbilityIds } ) {
	const labels = connectionAbilityLabels(
		session,
		abilities,
		enabledAbilityIds
	);
	const visibleLabels = labels.slice( 0, 3 );
	const remainingCount = labels.length - visibleLabels.length;

	if ( labels.length === 0 ) {
		return <span className="aculect-ai-companion-muted">No scopes</span>;
	}

	return (
		<div className="aculect-ai-companion-connection-ability-list">
			{ visibleLabels.map( ( label ) => (
				<span key={ label }>{ label }</span>
			) ) }
			{ remainingCount > 0 && <span>{ remainingCount } more</span> }
		</div>
	);
}

function ConnectionWritePermissionControl( { session, data } ) {
	const enabled = Boolean( session.writePermissionEnabled );
	const canManage =
		session.status !== 'revoked' &&
		data.actions?.setSessionWritePermissionAction &&
		data.actions?.setSessionWritePermissionNonce;

	if ( session.status === 'revoked' ) {
		return (
			<span className="aculect-ai-companion-connection-action-note">
				Unavailable
			</span>
		);
	}

	return (
		<div className="aculect-ai-companion-write-permission">
			<span
				className={ `aculect-ai-companion-write-permission__state ${
					enabled ? 'is-enabled' : ''
				}` }
			>
				{ enabled ? 'Direct writes' : 'Confirm writes' }
			</span>
			{ canManage ? (
				<ActionForm
					data={ data }
					action={ data.actions?.setSessionWritePermissionAction }
					nonce={ data.actions?.setSessionWritePermissionNonce }
					label={ enabled ? 'Require confirmation' : 'Enable writes' }
					variant="secondary"
					confirmTitle="Enable direct writes"
					confirmMessage={
						enabled
							? ''
							: 'Enable direct writes for this connection? This assistant can write without per-action confirmation while the connection remains active.'
					}
				>
					<input
						type="hidden"
						name="session_id"
						value={ session.id }
					/>
					<input
						type="hidden"
						name="write_permission_enabled"
						value={ enabled ? '0' : '1' }
					/>
				</ActionForm>
			) : (
				<span className="aculect-ai-companion-connection-action-note">
					Unavailable
				</span>
			) }
		</div>
	);
}

function ConnectionActionsMenu( { session, data } ) {
	const canRevoke =
		session.status !== 'revoked' &&
		data.actions?.revokeSessionAction &&
		data.actions?.revokeSessionNonce;

	if ( session.status === 'revoked' ) {
		return (
			<span className="aculect-ai-companion-connection-action-note">
				Reconnect required
			</span>
		);
	}

	if ( ! canRevoke ) {
		return (
			<span className="aculect-ai-companion-connection-action-note">
				Unavailable
			</span>
		);
	}

	return (
		<details className="aculect-ai-companion-connection-action-menu">
			<summary
				className="aculect-ai-companion-connection-action-menu__trigger"
				aria-label="Open connection actions"
			>
				<Icon icon={ moreVertical } size={ 18 } />
			</summary>
			<div className="aculect-ai-companion-connection-action-menu__content">
				<ActionForm
					data={ data }
					action={ data.actions?.revokeSessionAction }
					nonce={ data.actions?.revokeSessionNonce }
					label="Disconnect"
					destructive
					confirmTitle="Disconnect assistant"
					confirmMessage="Disconnect this assistant? Its active token and refresh token will be revoked."
				>
					<input
						type="hidden"
						name="session_id"
						value={ session.id }
					/>
				</ActionForm>
			</div>
		</details>
	);
}

function ConnectionsDataViews( {
	sessions: connectionSessions,
	data,
	isAccessPaused,
	currentUserId,
	abilities,
	enabledAbilityIds,
} ) {
	const dataViewsModule = useDataViewsModule();
	const DataViewsComponent = dataViewsModule?.DataViews;
	const filterSortAndPaginateRows = dataViewsModule?.filterSortAndPaginate;
	const defaultView = {
		type: 'table',
		search: '',
		page: 1,
		perPage: 10,
		sort: {
			field: 'last_activity',
			direction: 'desc',
		},
		fields: [
			'assistant',
			'user',
			'roles',
			'abilities',
			'last_activity',
			'write_access',
			'status',
			'actions',
		],
		layout: {
			density: 'balanced',
		},
	};
	const [ view, setView ] = useState( defaultView );
	const roleOptions = useMemo(
		() =>
			connectionOptionElements(
				connectionSessions.flatMap( ( session ) =>
					Array.isArray( session.user_roles )
						? session.user_roles
						: []
				)
			),
		[ connectionSessions ]
	);
	const providerOptions = useMemo(
		() =>
			connectionOptionElements(
				connectionSessions.map(
					( session ) => session.provider || 'mcp'
				)
			),
		[ connectionSessions ]
	);
	const fields = useMemo(
		() => [
			{
				id: 'assistant',
				label: 'Assistant',
				enableGlobalSearch: true,
				enableHiding: false,
				getValue: ( { item } ) =>
					[ item.client_name, item.client_id, item.provider ].join(
						' '
					),
				render: ( { item: session } ) => (
					<div className="aculect-ai-companion-data-view__stack">
						<strong className="aculect-ai-companion-connections-table__primary">
							{ session.client_name || 'AI Assistant' }
						</strong>
						<span className="aculect-ai-companion-connections-table__secondary">
							{ session.provider || 'mcp' }
						</span>
					</div>
				),
			},
			{
				id: 'user',
				label: 'WordPress User',
				enableGlobalSearch: true,
				getValue: ( { item } ) =>
					[ item.user, item.resource, item.user_id ].join( ' ' ),
				render: ( { item: session } ) => (
					<div className="aculect-ai-companion-data-view__stack">
						<strong className="aculect-ai-companion-connections-table__primary">
							{ session.user || 'Unknown user' }
						</strong>
						<span className="aculect-ai-companion-connections-table__secondary">
							{ session.resource || 'Default resource' }
						</span>
					</div>
				),
			},
			{
				id: 'roles',
				label: 'Role',
				type: 'array',
				elements: roleOptions,
				filterBy:
					roleOptions.length > 0 ? { operators: [ 'isAny' ] } : false,
				enableGlobalSearch: true,
				getValue: ( { item } ) =>
					Array.isArray( item.user_roles ) ? item.user_roles : [],
				render: ( { item: session } ) =>
					Array.isArray( session.user_roles ) &&
					session.user_roles.length > 0
						? session.user_roles.join( ', ' )
						: 'Unknown',
			},
			{
				id: 'abilities',
				label: 'Granted Abilities',
				type: 'array',
				enableSorting: false,
				enableGlobalSearch: true,
				getValue: ( { item } ) =>
					connectionAbilityLabels(
						item,
						abilities,
						enabledAbilityIds
					),
				render: ( { item: session } ) => (
					<ConnectionAbilityChips
						session={ session }
						abilities={ abilities }
						enabledAbilityIds={ enabledAbilityIds }
					/>
				),
			},
			{
				id: 'last_activity',
				label: 'Last Activity',
				type: 'datetime',
				getValue: ( { item } ) => item.last_used_at || '',
				sort: ( first, second, direction ) => {
					const delta =
						connectionTimestamp( first.last_used_at ) -
						connectionTimestamp( second.last_used_at );

					return direction === 'asc' ? delta : -delta;
				},
				render: ( { item: session } ) => (
					<div className="aculect-ai-companion-data-view__stack">
						<strong className="aculect-ai-companion-connections-table__primary">
							{ connectionDateValue( session.last_used_at ) }
						</strong>
						<span className="aculect-ai-companion-connections-table__secondary">
							Created{ ' ' }
							{ connectionDateValue(
								session.created_at,
								'Unknown'
							) }
						</span>
					</div>
				),
			},
			{
				id: 'write_access',
				label: 'Write Access',
				elements: [
					{ value: 'direct', label: 'Direct writes' },
					{ value: 'confirm', label: 'Confirm writes' },
				],
				filterBy: { operators: [ 'isAny' ], isPrimary: true },
				enableSorting: false,
				getValue: ( { item } ) =>
					item.writePermissionEnabled ? 'direct' : 'confirm',
				render: ( { item: session } ) => (
					<ConnectionWritePermissionControl
						session={ session }
						data={ data }
					/>
				),
			},
			{
				id: 'status',
				label: 'Status',
				elements: [
					{ value: 'active', label: 'Active' },
					{ value: 'paused', label: 'Paused' },
					{ value: 'pending', label: 'Pending' },
					{ value: 'revoked', label: 'Revoked' },
				],
				filterBy: { operators: [ 'isAny' ], isPrimary: true },
				getValue: ( { item } ) =>
					connectionStatusKey( item, isAccessPaused ),
				render: ( { item: session } ) => (
					<div className="aculect-ai-companion-data-view__stack">
						<ConnectionStatusChip
							session={ session }
							isAccessPaused={ isAccessPaused }
						/>
						<span className="aculect-ai-companion-connections-table__secondary">
							Connection expires{ ' ' }
							{ connectionDateValue(
								session.expires_at,
								'Unknown'
							) }
						</span>
					</div>
				),
			},
			{
				id: 'ownership',
				label: 'Owner',
				elements: [
					{ value: 'my', label: 'My connections' },
					{ value: 'team', label: 'Team connections' },
					{ value: 'unknown', label: 'Unknown owner' },
				],
				filterBy: { operators: [ 'isAny' ] },
				getValue: ( { item } ) =>
					connectionOwnershipKey( item, currentUserId ),
			},
			{
				id: 'provider',
				label: 'Provider',
				elements: providerOptions,
				filterBy:
					providerOptions.length > 0
						? { operators: [ 'isAny' ] }
						: false,
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.provider || 'mcp',
			},
			{
				id: 'actions',
				label: 'Actions',
				enableSorting: false,
				enableHiding: false,
				render: ( { item: session } ) => (
					<ConnectionActionsMenu session={ session } data={ data } />
				),
			},
		],
		[
			abilities,
			currentUserId,
			data,
			enabledAbilityIds,
			isAccessPaused,
			providerOptions,
			roleOptions,
		]
	);
	const { data: visibleSessions, paginationInfo } = useMemo(
		() =>
			filterSortAndPaginateRows
				? filterSortAndPaginateRows( connectionSessions, view, fields )
				: {
						data: [],
						paginationInfo: {
							totalItems: connectionSessions.length,
							totalPages: 1,
						},
				  },
		[ connectionSessions, fields, filterSortAndPaginateRows, view ]
	);
	const emptyStateTitle =
		connectionSessions.length > 0
			? 'No matching connections'
			: 'No connections';

	if ( ! DataViewsComponent ) {
		return (
			<div className="aculect-ai-companion-data-view aculect-ai-companion-data-view--connections">
				<DataViewLoadingState label="Loading connections table" />
			</div>
		);
	}

	return (
		<div className="aculect-ai-companion-data-view aculect-ai-companion-data-view--connections">
			<DataViewsComponent
				data={ visibleSessions }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				search
				searchLabel="Search connections"
				defaultLayouts={ DATA_VIEW_TABLE_LAYOUTS }
				paginationInfo={ paginationInfo }
				getItemId={ ( session ) =>
					`${ session.status || 'active' }-${ session.id }`
				}
				config={ { perPageSizes: [ 10, 20, 50 ] } }
				onReset={ () => setView( defaultView ) }
				empty={
					<EmptyState title={ emptyStateTitle }>
						{ connectionSessions.length > 0
							? 'Try a different assistant, WordPress user, role, ability, provider, or status filter.'
							: 'Add the connection URL to an AI assistant, then approve the connection in WordPress.' }
					</EmptyState>
				}
			/>
		</div>
	);
}

function ConnectionsSidePanels( {
	activityUrl,
	abilitiesUrl,
	onActivity,
	onAbilities,
} ) {
	return (
		<aside className="aculect-ai-companion-connections-sidebar">
			<div className="aculect-ai-companion-side-panel">
				<div className="aculect-ai-companion-side-panel__heading">
					<span className="aculect-ai-companion-side-panel__icon">
						<Icon icon={ shield } size={ 20 } />
					</span>
					<h3>Connection security</h3>
				</div>
				<p>
					OAuth tokens are stored hashed and never shown in this
					dashboard. Disconnecting a session revokes the active access
					token and its refresh token.
				</p>
				<a
					className="aculect-ai-companion-side-panel__link"
					href={ abilitiesUrl }
					onClick={ onAbilities }
				>
					Review abilities
				</a>
			</div>
			<div className="aculect-ai-companion-side-panel">
				<div className="aculect-ai-companion-side-panel__heading">
					<span className="aculect-ai-companion-side-panel__icon">
						<Icon icon={ lock } size={ 20 } />
					</span>
					<h3>Pending approval</h3>
				</div>
				<p>
					Assistant approvals are handled during the WordPress OAuth
					consent flow. This site does not keep a separate pending
					approval queue.
				</p>
			</div>
			<div className="aculect-ai-companion-side-panel">
				<div className="aculect-ai-companion-side-panel__heading">
					<span className="aculect-ai-companion-side-panel__icon">
						<Icon icon={ chartBar } size={ 20 } />
					</span>
					<h3>Activity audit</h3>
				</div>
				<p>
					Review successful and blocked assistant actions, including
					the assistant name, connected user, target, and error state.
				</p>
				<a
					className="aculect-ai-companion-side-panel__link"
					href={ activityUrl }
					onClick={ onActivity }
				>
					View activity
				</a>
			</div>
		</aside>
	);
}

function AdvancedSection( { icon, title, description, children } ) {
	return (
		<section className="aculect-ai-companion-advanced-section">
			<div className="aculect-ai-companion-advanced-section__header">
				<span
					className="aculect-ai-companion-advanced-section__icon"
					aria-hidden="true"
				>
					<Icon icon={ icon } size={ 20 } />
				</span>
				<div>
					<h3 className="aculect-ai-companion-advanced-section__title">
						{ title }
					</h3>
					<p className="aculect-ai-companion-advanced-section__description">
						{ description }
					</p>
				</div>
			</div>
			<div className="aculect-ai-companion-advanced-section__body">
				{ children }
			</div>
		</section>
	);
}

function AdvancedSettingRow( {
	title,
	description,
	status,
	statusTone = '',
	children,
} ) {
	return (
		<div className="aculect-ai-companion-advanced-setting">
			<div className="aculect-ai-companion-advanced-setting__content">
				<div className="aculect-ai-companion-advanced-setting__title">
					<h4 className="aculect-ai-companion-advanced-setting__heading">
						{ title }
					</h4>
					{ status && (
						<span
							className={ `aculect-ai-companion-advanced-setting__status ${ statusTone }` }
						>
							{ status }
						</span>
					) }
				</div>
				<p className="aculect-ai-companion-advanced-setting__description">
					{ description }
				</p>
			</div>
			{ children && (
				<div className="aculect-ai-companion-advanced-setting__control">
					{ children }
				</div>
			) }
		</div>
	);
}

function AdvancedRoleControls( {
	roleConnections,
	roleConnectionsEnabled,
	roleConnectionRoles,
	onToggleRole,
} ) {
	const roleOptions = Array.isArray( roleConnections.roleOptions )
		? roleConnections.roleOptions
		: [];

	if ( ! roleConnectionsEnabled ) {
		return (
			<p className="aculect-ai-companion-help-text">
				Role selection is available when role-based entry points are
				enabled.
			</p>
		);
	}

	return (
		<div className="aculect-ai-companion-advanced-role-controls">
			{ roleOptions.map( ( role ) => (
				<CheckboxControl
					key={ role.id }
					label={ role.label }
					checked={ roleConnectionRoles.includes( role.id ) }
					onChange={ ( checked ) =>
						onToggleRole( role.id, Boolean( checked ) )
					}
				/>
			) ) }
		</div>
	);
}

function AdvancedDashboard( {
	data,
	diagnostics,
	roleConnections,
	loggingEnabled,
	onLoggingChange,
	roleConnectionsEnabled,
	onRoleConnectionsChange,
	roleConnectionRoles,
	onToggleRole,
	enabledAbilities,
	enabledWpAbilities,
} ) {
	const retentionDays = diagnostics.retentionDays || 30;
	const enabledAbilityCount =
		enabledAbilities.length + enabledWpAbilities.length;
	const abilitiesUrl = tabUrl( 'abilities', data.adminPageUrl );
	const diagnosticsUrl = tabUrl( 'diagnostics', data.adminPageUrl );
	const advancedSettingsFormId =
		'aculect-ai-companion-advanced-settings-form';

	return (
		<div className="aculect-ai-companion-form aculect-ai-companion-form--advanced-dashboard">
			<form
				id={ advancedSettingsFormId }
				method="post"
				action={ data.actions?.adminPostUrl }
				className="aculect-ai-companion-advanced-save-form"
			>
				<input
					type="hidden"
					name="action"
					value={ data.actions?.saveAdvancedAction }
				/>
				<input
					type="hidden"
					name="_wpnonce"
					value={ data.actions?.saveAdvancedNonce }
				/>
				<input
					type="hidden"
					name="diagnostic_logging_enabled"
					value={ loggingEnabled ? '1' : '0' }
				/>
				<input
					type="hidden"
					name="role_connections_enabled"
					value={ roleConnectionsEnabled ? '1' : '0' }
				/>
				{ roleConnectionRoles.map( ( role ) => (
					<input
						key={ role }
						type="hidden"
						name="role_connection_roles[]"
						value={ role }
					/>
				) ) }
			</form>

			<div className="aculect-ai-companion-tab-actions">
				<Button
					type="submit"
					form={ advancedSettingsFormId }
					variant="primary"
				>
					Save Advanced Settings
				</Button>
			</div>

			<div className="aculect-ai-companion-advanced-layout">
				<div className="aculect-ai-companion-advanced-main">
					<AdvancedSection
						icon={ shield }
						title="Security and access"
						description="Connection approval, permission policy, and user-facing entry points."
					>
						<AdvancedSettingRow
							title="Approval requirement"
							description="Every assistant connection uses the WordPress OAuth consent flow before it can run actions."
							status="Always on"
							statusTone="is-active"
						/>
						<AdvancedSettingRow
							title="Permission model"
							description="MCP actions still run through WordPress capabilities and the enabled abilities policy."
							status="Server enforced"
						>
							<Button
								href={ abilitiesUrl }
								variant="secondary"
								onClick={ ( event ) =>
									maybeSelectTab( event, 'abilities' )
								}
							>
								Review Abilities
							</Button>
						</AdvancedSettingRow>
						<AdvancedSettingRow
							title="Role-based connection entry points"
							description="Allow selected logged-in roles to copy the MCP connection URL from a shortcode, block, or PHP template function."
							status={
								roleConnectionsEnabled ? 'Enabled' : 'Disabled'
							}
							statusTone={
								roleConnectionsEnabled ? 'is-active' : ''
							}
						>
							<ToggleControl
								label="Enable role entry points"
								checked={ roleConnectionsEnabled }
								onChange={ ( checked ) =>
									onRoleConnectionsChange(
										Boolean( checked )
									)
								}
							/>
							<AdvancedRoleControls
								roleConnections={ roleConnections }
								roleConnectionsEnabled={
									roleConnectionsEnabled
								}
								roleConnectionRoles={ roleConnectionRoles }
								onToggleRole={ onToggleRole }
							/>
						</AdvancedSettingRow>
					</AdvancedSection>

					<AdvancedSection
						icon={ plugins }
						title="Capabilities and permissions"
						description="Ability boundaries for connected assistants."
					>
						<AdvancedSettingRow
							title="Default capability set"
							description="The active ability policy controls which content, comment, media, taxonomy, SEO, and site tools assistants can use."
							status={ `${ enabledAbilityCount } enabled` }
							statusTone="is-active"
						>
							<Button
								href={ abilitiesUrl }
								variant="secondary"
								onClick={ ( event ) =>
									maybeSelectTab( event, 'abilities' )
								}
							>
								Edit Abilities
							</Button>
						</AdvancedSettingRow>
					</AdvancedSection>
				</div>

				<aside className="aculect-ai-companion-advanced-sidebar">
					<AdvancedSection
						icon={ cog }
						title="Developer"
						description="Diagnostics and troubleshooting controls."
					>
						<AdvancedSettingRow
							title="Diagnostic logging"
							description={ `Stores sanitized OAuth and MCP lifecycle events for ${ retentionDays } days.` }
							status={ loggingEnabled ? 'Enabled' : 'Disabled' }
							statusTone={ loggingEnabled ? 'is-active' : '' }
						>
							<ToggleControl
								label="Enable diagnostic logging"
								checked={ loggingEnabled }
								onChange={ ( checked ) =>
									onLoggingChange( Boolean( checked ) )
								}
							/>
							<Button
								href={ diagnosticsUrl }
								variant="secondary"
								onClick={ ( event ) =>
									maybeSelectTab( event, 'diagnostics' )
								}
							>
								Open Diagnostics
							</Button>
						</AdvancedSettingRow>
					</AdvancedSection>

					<AdvancedSection
						icon={ settings }
						title="Settings JSON"
						description="Export, import, or reset plugin configuration."
					>
						<AdvancedSettingRow
							title="Export settings JSON"
							description="Download a sanitized settings file that excludes OAuth records, tokens, keys, logs, and activity history."
						>
							<ActionForm
								data={ data }
								action={ data.actions?.exportSettingsAction }
								nonce={ data.actions?.exportSettingsNonce }
								label="Export Settings"
								variant="secondary"
							/>
						</AdvancedSettingRow>
						<AdvancedSettingRow
							title="Import settings JSON"
							description="Upload a settings file exported from Aculect AI Companion to replace supported configuration values."
						>
							<ActionForm
								data={ data }
								action={ data.actions?.importSettingsAction }
								nonce={ data.actions?.importSettingsNonce }
								label="Import Settings"
								variant="secondary"
								enctype="multipart/form-data"
								confirmTitle="Import settings"
								confirmMessage="Import settings from this JSON file? Current supported settings will be overwritten."
							>
								<label
									className="aculect-ai-companion-file-field"
									htmlFor="aculect-ai-companion-settings-import-file"
								>
									<span>Settings JSON</span>
									<input
										id="aculect-ai-companion-settings-import-file"
										type="file"
										name="settings_file"
										accept="application/json,.json"
										required
									/>
								</label>
							</ActionForm>
						</AdvancedSettingRow>
						<AdvancedSettingRow
							title="Reset settings to default"
							description="Restore plugin configuration defaults. Connections, OAuth material, logs, and activity history are not deleted."
							status="Destructive"
							statusTone="is-danger"
						>
							<ActionForm
								data={ data }
								action={ data.actions?.resetSettingsAction }
								nonce={ data.actions?.resetSettingsNonce }
								label="Reset Settings"
								destructive
								confirmTitle="Reset settings"
								confirmMessage="Reset Aculect AI Companion settings to defaults? Connections, OAuth material, logs, and activity history will not be deleted."
							/>
						</AdvancedSettingRow>
					</AdvancedSection>
				</aside>
			</div>
		</div>
	);
}

function DiagnosticMetaList( { rows } ) {
	return (
		<dl className="aculect-ai-companion-diagnostic-meta-list">
			{ rows.map( ( row ) => (
				<div key={ row.label }>
					<dt>{ row.label }</dt>
					<dd>{ row.value }</dd>
				</div>
			) ) }
		</dl>
	);
}

function DiagnosticsSystemPanel( { health, onCopy } ) {
	const rows = diagnosticSystemRows( health );

	return (
		<div className="aculect-ai-companion-side-panel aculect-ai-companion-diagnostic-panel">
			<div className="aculect-ai-companion-side-panel__heading">
				<span className="aculect-ai-companion-side-panel__icon">
					<Icon icon={ info } size={ 20 } />
				</span>
				<h3>System summary</h3>
			</div>
			{ rows.length > 0 ? (
				<DiagnosticMetaList rows={ rows } />
			) : (
				<p>
					System details will appear after the settings screen loads.
				</p>
			) }
			<Button
				type="button"
				variant="secondary"
				onClick={ () =>
					onCopy(
						diagnosticSupportInfo( health ),
						'System info copied.'
					)
				}
			>
				<Icon icon={ copy } size={ 16 } />
				<span>Copy system info</span>
			</Button>
		</div>
	);
}

function DiagnosticsEnvironmentPanel( { health } ) {
	const items = diagnosticItems( health );
	const counts = diagnosticCounts( items );
	const httpsCheck = items.find( ( item ) => item.id === 'https_url' );

	return (
		<div className="aculect-ai-companion-side-panel aculect-ai-companion-diagnostic-panel">
			<div className="aculect-ai-companion-side-panel__heading">
				<span className="aculect-ai-companion-side-panel__icon">
					<Icon icon={ globe } size={ 20 } />
				</span>
				<h3>Environment</h3>
			</div>
			{ httpsCheck ? (
				<div className="aculect-ai-companion-diagnostic-environment">
					<StatusBadge status={ httpsCheck.status } />
					<p>{ httpsCheck.message }</p>
					{ httpsCheck.remediation && (
						<strong className="aculect-ai-companion-diagnostic-environment__remediation">
							{ httpsCheck.remediation }
						</strong>
					) }
				</div>
			) : (
				<p>
					Run checks to confirm whether this site is ready for hosted
					AI tools.
				</p>
			) }
			<DiagnosticMetaList
				rows={ [
					{ label: 'Total checks', value: counts.total },
					{ label: 'Warnings', value: counts.warn },
					{ label: 'Errors', value: counts.fail },
				] }
			/>
		</div>
	);
}

function DiagnosticsHelpPanel( { links } ) {
	return (
		<div className="aculect-ai-companion-side-panel aculect-ai-companion-diagnostic-panel">
			<div className="aculect-ai-companion-side-panel__heading">
				<span className="aculect-ai-companion-side-panel__icon">
					<Icon icon={ help } size={ 20 } />
				</span>
				<h3>Help links</h3>
			</div>
			{ links.length > 0 ? (
				<ul className="aculect-ai-companion-help-link-list">
					{ links.map( ( item ) => (
						<li key={ item.url }>
							<a
								href={ item.url }
								target="_blank"
								rel="noreferrer noopener"
							>
								<span>{ item.label }</span>
								<Icon icon={ external } size={ 16 } />
							</a>
						</li>
					) ) }
				</ul>
			) : (
				<p>
					Provider-specific setup links appear here when they are
					configured.
				</p>
			) }
		</div>
	);
}

function DiagnosticsDashboard( {
	data,
	health,
	activeFilter,
	onFilterChange,
	isRunning,
	onRun,
	onCopy,
	helpLinks: links,
} ) {
	const items = diagnosticItems( health );
	const counts = diagnosticCounts( items );

	return (
		<div className="aculect-ai-companion-diagnostics">
			<div className="aculect-ai-companion-tab-actions">
				<ActionForm
					data={ data }
					action={ data.actions?.runDiagnosticsAction }
					nonce={ data.actions?.runDiagnosticsNonce }
					label="Run all checks"
					busyLabel="Running checks"
					isBusy={ isRunning }
					onSubmit={ onRun }
				/>
			</div>

			<div className="aculect-ai-companion-diagnostics-layout">
				<div className="aculect-ai-companion-diagnostics-main">
					<div className="aculect-ai-companion-diagnostics-checks">
						<div className="aculect-ai-companion-section-title-row">
							<div>
								<h2 className="aculect-ai-companion-section-title">
									Check results
								</h2>
								<p className="aculect-ai-companion-help-text">
									{ health?.ranAt
										? `Last checked ${ health.ranAt }`
										: 'No saved diagnostics run yet.' }
								</p>
							</div>
						</div>
						<DiagnosticsFilterTabs
							counts={ counts }
							activeFilter={ activeFilter }
							onChange={ onFilterChange }
						/>
						<ConnectionHealthChecks
							health={ health }
							filter={ activeFilter }
						/>
					</div>
				</div>

				<aside className="aculect-ai-companion-diagnostics-sidebar">
					<DiagnosticsSystemPanel
						health={ health }
						onCopy={ onCopy }
					/>
					<DiagnosticsEnvironmentPanel health={ health } />
					<DiagnosticsHelpPanel links={ links } />
				</aside>
			</div>
		</div>
	);
}

function ConnectProviderCard( { provider, isOpen, onToggle, onCopy } ) {
	const setupSections = Array.isArray( provider.setupSections )
		? provider.setupSections
		: [];
	const panelId = `aculect-ai-companion-provider-panel-${ provider.id }`;

	return (
		<div
			key={ provider.id }
			className={ `aculect-ai-companion-provider-card ${
				isOpen ? 'is-open' : ''
			}` }
		>
			<div className="aculect-ai-companion-provider-card__header">
				<ConnectProviderBadge provider={ provider } />
				<div className="aculect-ai-companion-provider-card__title-wrap">
					<h3 className="aculect-ai-companion-provider-card__title">
						{ provider.label }
					</h3>
					<p className="aculect-ai-companion-provider-card__description">
						{ providerOverviewText( provider ) }
					</p>
				</div>
				<Button
					variant="secondary"
					className="aculect-ai-companion-provider-toggle"
					onClick={ onToggle }
					aria-expanded={ isOpen }
					aria-controls={ panelId }
				>
					<span>{ isOpen ? 'Hide steps' : 'Show steps' }</span>
					<Icon
						icon={ isOpen ? chevronUp : chevronDown }
						size={ 16 }
					/>
				</Button>
			</div>

			{ isOpen && (
				<div
					id={ panelId }
					className="aculect-ai-companion-provider-panel"
				>
					{ setupSections.length > 0 ? (
						<div className="aculect-ai-companion-setup-method-list">
							{ setupSections.map( ( section, index ) => (
								<SetupSection
									key={ `${ provider.id }-${ index }` }
									provider={ provider }
									section={ section }
									sectionIndex={ index }
									onCopy={ onCopy }
								/>
							) ) }
						</div>
					) : (
						<p className="aculect-ai-companion-provider-card__description">
							Setup steps are not available for this provider yet.
						</p>
					) }
					<div className="aculect-ai-companion-connect-info-message">
						<span aria-hidden="true">
							<Icon icon={ info } size={ 18 } />
						</span>
						<p>
							You will be asked to approve the connection in
							WordPress. You can review or remove access at any
							time.
						</p>
					</div>
				</div>
			) }
		</div>
	);
}

function OverviewFeatureCard( { icon, title, children } ) {
	return (
		<div className="aculect-ai-companion-feature-card">
			<span
				className="aculect-ai-companion-feature-card__icon"
				aria-hidden="true"
			>
				<Icon icon={ icon } size={ 20 } />
			</span>
			<div className="aculect-ai-companion-feature-card__body">
				<h3 className="aculect-ai-companion-feature-card__title">
					{ title }
				</h3>
				<p className="aculect-ai-companion-feature-card__copy">
					{ children }
				</p>
			</div>
		</div>
	);
}

function OverviewCircuit( { brandIconUrl } ) {
	return (
		<div
			className="aculect-ai-companion-overview-circuit"
			aria-hidden="true"
		>
			<span className="aculect-ai-companion-overview-circuit__line is-horizontal" />
			<span className="aculect-ai-companion-overview-circuit__line is-vertical" />
			<span className="aculect-ai-companion-overview-circuit__node is-top" />
			<span className="aculect-ai-companion-overview-circuit__node is-left" />
			<span className="aculect-ai-companion-overview-circuit__node is-right" />
			<span className="aculect-ai-companion-overview-circuit__node is-bottom" />
			<div className="aculect-ai-companion-overview-circuit__logo">
				{ brandIconUrl && (
					<img src={ brandIconUrl } alt="" aria-hidden="true" />
				) }
			</div>
		</div>
	);
}

function normalizedAbilityGroup( value ) {
	return String( value || 'Other' ).trim() || 'Other';
}

function abilitySearchText( ability ) {
	return [
		ability.title,
		ability.id,
		ability.description,
		ability.scope,
		ability.group,
		ability.category,
		ability.toolName,
		ability.sourceLabel,
	]
		.join( ' ' )
		.toLowerCase();
}

function sortAbilities( firstAbility, secondAbility ) {
	return (
		String( firstAbility.group ).localeCompare(
			String( secondAbility.group )
		) ||
		String( firstAbility.title ).localeCompare(
			String( secondAbility.title )
		)
	);
}

function sameStringSet( firstValue, secondValue ) {
	const first = Array.isArray( firstValue ) ? firstValue : [];
	const second = Array.isArray( secondValue ) ? secondValue : [];

	if ( first.length !== second.length ) {
		return false;
	}

	const secondSet = new Set( second );
	return first.every( ( item ) => secondSet.has( item ) );
}

function activeConnectionLabel( count ) {
	return `${ count } active connection${ count === 1 ? '' : 's' }`;
}

function normalizedAbilityRows( {
	abilities,
	enabledAbilities,
	wpAbilities,
	enabledWpAbilities,
	activeConnectionCount,
} ) {
	const enabledAbilityIds = new Set( enabledAbilities );
	const enabledWpAbilityIds = new Set( enabledWpAbilities );
	const firstParty = abilities.map( ( ability ) => ( {
		id: String( ability.id || '' ),
		title: String( ability.title || ability.id || 'Untitled ability' ),
		description: String( ability.description || '' ),
		group: normalizedAbilityGroup( ability.group ),
		scope: String( ability.scope || 'content:read' ),
		source: 'system',
		sourceLabel: 'System',
		readOnly: Boolean( ability.readOnly ),
		enabled: enabledAbilityIds.has( ability.id ),
		toolName: String( ability.toolName || ability.id || '' ),
		assignedTo: enabledAbilityIds.has( ability.id )
			? activeConnectionLabel( activeConnectionCount )
			: 'Not exposed',
		updated: 'Bundled registry',
	} ) );
	const wordpress = wpAbilities.map( ( ability ) => ( {
		id: String( ability.id || '' ),
		title: String( ability.title || ability.id || 'WordPress ability' ),
		description: String( ability.description || '' ),
		group: normalizedAbilityGroup( ability.category || 'WordPress API' ),
		scope: ability.destructive ? 'write' : 'content:read',
		source: 'wordpress',
		sourceLabel: 'WordPress API',
		readOnly: Boolean( ability.readOnly ),
		destructive: Boolean( ability.destructive ),
		enabled: enabledWpAbilityIds.has( ability.id ),
		toolName: String( ability.id || '' ),
		assignedTo: enabledWpAbilityIds.has( ability.id )
			? activeConnectionLabel( activeConnectionCount )
			: 'Not exposed',
		updated: 'Runtime policy',
	} ) );

	return [ ...firstParty, ...wordpress ]
		.filter( ( ability ) => ability.id )
		.sort( sortAbilities );
}

function AbilityDashboard( {
	data,
	abilities,
	enabledAbilities,
	wpAbilities,
	enabledWpAbilities,
	confirmationGroups,
	confirmationGroupOptions,
	activeConnectionCount,
	hasChanges,
	onToggleAbility,
	onToggleWpAbility,
	onToggleConfirmationGroup,
	onEnableAll,
	onDisableAll,
	onManageRoleAbilities,
	onResetChanges,
	onCopy,
} ) {
	const dataViewsModule = useDataViewsModule();
	const DataViewsComponent = dataViewsModule?.DataViews;
	const filterSortAndPaginateRows = dataViewsModule?.filterSortAndPaginate;
	const defaultView = {
		type: 'table',
		search: '',
		page: 1,
		perPage: 12,
		sort: {
			field: 'ability',
			direction: 'asc',
		},
		fields: [
			'enabled_toggle',
			'ability',
			'scope',
			'active_connections',
			'updated',
			'actions',
		],
		layout: {
			density: 'balanced',
			styles: {
				enabled_toggle: {
					width: 64,
					maxWidth: 64,
					align: 'center',
				},
				ability: {
					width: '38%',
				},
				scope: {
					width: '18%',
				},
				active_connections: {
					width: '14%',
				},
				updated: {
					width: '12%',
				},
				actions: {
					width: 64,
					maxWidth: 64,
					align: 'center',
				},
			},
		},
	};
	const [ view, setView ] = useState( defaultView );
	const rows = useMemo(
		() =>
			normalizedAbilityRows( {
				abilities,
				enabledAbilities,
				wpAbilities,
				enabledWpAbilities,
				activeConnectionCount,
			} ),
		[
			abilities,
			activeConnectionCount,
			enabledAbilities,
			enabledWpAbilities,
			wpAbilities,
		]
	);
	const categoryOptions = useMemo(
		() =>
			Array.from( new Set( rows.map( ( ability ) => ability.group ) ) )
				.sort()
				.map( ( group ) => ( { value: group, label: group } ) ),
		[ rows ]
	);
	const sourceOptions = useMemo(
		() => [
			{ value: 'system', label: 'System' },
			...( wpAbilities.length > 0
				? [ { value: 'wordpress', label: 'WordPress API' } ]
				: [] ),
		],
		[ wpAbilities.length ]
	);
	const fields = useMemo(
		() => [
			{
				id: 'enabled_toggle',
				label: 'Toggle',
				enableSorting: false,
				enableHiding: false,
				getValue: ( { item } ) => Boolean( item.enabled ),
				render: ( { item: ability } ) => (
					<div className="aculect-ai-companion-ability-toggle-cell">
						<ToggleControl
							label={ `${ ability.title } active state` }
							checked={ ability.enabled }
							onChange={ ( checked ) => {
								if ( ability.source === 'wordpress' ) {
									onToggleWpAbility(
										ability.id,
										Boolean( checked )
									);
									return;
								}

								onToggleAbility(
									ability.id,
									Boolean( checked )
								);
							} }
						/>
					</div>
				),
			},
			{
				id: 'ability',
				label: 'Ability Title',
				enableGlobalSearch: true,
				enableHiding: false,
				getValue: ( { item } ) => abilitySearchText( item ),
				render: ( { item: ability } ) => (
					<div className="aculect-ai-companion-ability-name-cell">
						<div className="aculect-ai-companion-ability-title-row">
							<strong>{ ability.title }</strong>
							<Tooltip
								text={ `${ ability.id }${
									ability.description
										? `: ${ ability.description }`
										: ': No description provided.'
								}` }
								placement="top"
							>
								<Button
									type="button"
									variant="tertiary"
									className="aculect-ai-companion-ability-info-button"
									aria-label={ `View ${ ability.title } slug and description` }
									onClick={ ( event ) =>
										event.preventDefault()
									}
								>
									<Icon icon={ info } size={ 14 } />
								</Button>
							</Tooltip>
						</div>
						<div className="aculect-ai-companion-ability-row-tags">
							<span className="is-source">
								{ ability.sourceLabel }
							</span>
							<span>{ ability.group }</span>
						</div>
					</div>
				),
			},
			{
				id: 'scope',
				label: 'Scope',
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.scope,
				render: ( { item: ability } ) => (
					<div className="aculect-ai-companion-data-view__stack">
						<span
							className={ `aculect-ai-companion-risk-chip ${
								ability.readOnly ? 'is-read-only' : 'is-write'
							}` }
						>
							{ ability.readOnly
								? 'Read-only'
								: 'Can change site' }
						</span>
						<code>{ ability.scope }</code>
					</div>
				),
			},
			{
				id: 'active_connections',
				label: 'Active connections',
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.assignedTo,
				render: ( { item: ability } ) => (
					<div className="aculect-ai-companion-ability-connection-cell">
						<strong>
							{ ability.enabled ? activeConnectionCount : 0 }
						</strong>
						<span>
							{ ability.enabled
								? `active connection${
										activeConnectionCount === 1 ? '' : 's'
								  }`
								: 'Not exposed' }
						</span>
					</div>
				),
			},
			{
				id: 'updated',
				label: 'Updated',
				getValue: ( { item } ) => item.updated,
			},
			{
				id: 'status',
				label: 'Status',
				elements: [
					{ value: 'active', label: 'Active' },
					{ value: 'inactive', label: 'Inactive' },
				],
				filterBy: { operators: [ 'isAny' ], isPrimary: true },
				getValue: ( { item } ) =>
					item.enabled ? 'active' : 'inactive',
			},
			{
				id: 'group',
				label: 'Category',
				elements: categoryOptions,
				filterBy:
					categoryOptions.length > 0
						? { operators: [ 'isAny' ] }
						: false,
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.group,
			},
			{
				id: 'risk',
				label: 'Scope risk',
				elements: [
					{ value: 'read', label: 'Read-only' },
					{ value: 'write', label: 'Can change site' },
				],
				filterBy: { operators: [ 'isAny' ], isPrimary: true },
				getValue: ( { item } ) => ( item.readOnly ? 'read' : 'write' ),
			},
			{
				id: 'source',
				label: 'Source',
				elements: sourceOptions,
				filterBy: { operators: [ 'isAny' ] },
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.source,
			},
			{
				id: 'actions',
				label: 'Actions',
				enableSorting: false,
				enableHiding: false,
				render: ( { item: ability } ) => (
					<Button
						type="button"
						variant="tertiary"
						aria-label={ `Copy ${ ability.title } ability key` }
						onClick={ () =>
							onCopy( ability.id, 'Ability key copied' )
						}
					>
						<Icon icon={ copy } size={ 16 } />
					</Button>
				),
			},
		],
		[
			activeConnectionCount,
			categoryOptions,
			onCopy,
			onToggleAbility,
			onToggleWpAbility,
			sourceOptions,
		]
	);
	const { data: visibleRows, paginationInfo } = useMemo(
		() =>
			filterSortAndPaginateRows
				? filterSortAndPaginateRows( rows, view, fields )
				: {
						data: [],
						paginationInfo: {
							totalItems: rows.length,
							totalPages: 1,
						},
				  },
		[ fields, filterSortAndPaginateRows, rows, view ]
	);

	return (
		<form
			method="post"
			action={ data.actions?.adminPostUrl }
			className="aculect-ai-companion-abilities-dashboard"
		>
			<input
				type="hidden"
				name="action"
				value={ data.actions?.saveAbilitiesAction }
			/>
			<input
				type="hidden"
				name="_wpnonce"
				value={ data.actions?.saveAbilitiesNonce }
			/>
			{ enabledAbilities.map( ( id ) => (
				<input
					key={ id }
					type="hidden"
					name="enabled_abilities[]"
					value={ id }
				/>
			) ) }
			{ enabledWpAbilities.map( ( id ) => (
				<input
					key={ id }
					type="hidden"
					name="enabled_wp_abilities[]"
					value={ id }
				/>
			) ) }
			{ confirmationGroups.map( ( group ) => (
				<input
					key={ group }
					type="hidden"
					name="confirmation_required_groups[]"
					value={ group }
				/>
			) ) }
			<div className="aculect-ai-companion-tab-actions">
				<Button type="submit" variant="primary">
					Save abilities
				</Button>
				<Button
					type="button"
					variant="secondary"
					onClick={ onResetChanges }
					disabled={ ! hasChanges }
					accessibleWhenDisabled
				>
					Discard changes
				</Button>
			</div>

			<div className="aculect-ai-companion-abilities-layout">
				<section className="aculect-ai-companion-abilities-main">
					<div className="aculect-ai-companion-data-view aculect-ai-companion-data-view--abilities">
						{ DataViewsComponent ? (
							<DataViewsComponent
								data={ visibleRows }
								fields={ fields }
								view={ view }
								onChangeView={ setView }
								search
								searchLabel="Search abilities"
								defaultLayouts={ DATA_VIEW_TABLE_LAYOUTS }
								paginationInfo={ paginationInfo }
								getItemId={ ( ability ) => ability.id }
								config={ { perPageSizes: [ 12, 24, 48 ] } }
								onReset={ () => setView( defaultView ) }
								empty={
									<EmptyState title="No matching abilities">
										Adjust the search or filters to show
										ability rows.
									</EmptyState>
								}
							/>
						) : (
							<DataViewLoadingState label="Loading abilities table" />
						) }
					</div>
				</section>

				<aside className="aculect-ai-companion-abilities-side">
					<section className="aculect-ai-companion-abilities-panel">
						<h3>About abilities</h3>
						<p>
							System rows come from the bundled registry.
							WordPress Ability API rows appear only when public
							abilities are registered on this site.
						</p>
					</section>
					<section className="aculect-ai-companion-abilities-panel">
						<h3>Quick actions</h3>
						<div className="aculect-ai-companion-abilities-panel__actions">
							<Button
								type="button"
								variant="secondary"
								onClick={ onEnableAll }
							>
								Enable all
							</Button>
							<Button
								type="button"
								variant="secondary"
								onClick={ onDisableAll }
							>
								Disable all
							</Button>
							<Button
								type="button"
								variant="secondary"
								onClick={ onManageRoleAbilities }
							>
								Manage Role Based Abilities
							</Button>
						</div>
					</section>
					{ confirmationGroupOptions.length > 0 && (
						<section className="aculect-ai-companion-abilities-panel">
							<h3>Confirmation gates</h3>
							<p>
								High-risk actions always require confirmation.
								Selected groups require confirmation for every
								write action.
							</p>
							<div className="aculect-ai-companion-confirmation-groups">
								{ confirmationGroupOptions.map( ( group ) => (
									<CheckboxControl
										key={ group }
										label={ group }
										checked={ confirmationGroups.includes(
											group
										) }
										onChange={ ( checked ) =>
											onToggleConfirmationGroup(
												group,
												Boolean( checked )
											)
										}
									/>
								) ) }
							</div>
						</section>
					) }
					<section className="aculect-ai-companion-abilities-panel">
						<h3>Help</h3>
						<p>
							Disabling an ability removes it from the MCP tool
							list; WordPress still checks user capabilities when
							an enabled tool runs.
						</p>
					</section>
				</aside>
			</div>
		</form>
	);
}

function roleAbilitySearchText( ability ) {
	return [
		ability.title,
		ability.id,
		ability.description,
		ability.group,
		ability.scope,
		ability.toolName,
	]
		.join( ' ' )
		.toLowerCase();
}

function roleAbilityGroups( abilities, roleAllowedIds, globalEnabledIds ) {
	const allowed = new Set( roleAllowedIds );
	const global = new Set( globalEnabledIds );

	return abilities.reduce( ( groups, ability ) => {
		const group = ability.group || 'Other';
		const isGloballyEnabled = global.has( ability.id );
		const row = {
			...ability,
			group,
			enabled: isGloballyEnabled && allowed.has( ability.id ),
			globallyEnabled: isGloballyEnabled,
		};

		return {
			...groups,
			[ group ]: [ ...( groups[ group ] || [] ), row ],
		};
	}, {} );
}

function roleAbilitySourceLabel( ability, activeRole, isChecked ) {
	if ( ! ability.globallyEnabled ) {
		return 'Disabled globally';
	}

	if ( ! activeRole.explicit ) {
		return 'Inherited from global';
	}

	return isChecked ? 'Explicitly enabled' : 'Explicitly disabled';
}

function RoleAbilitiesEditor( {
	data,
	abilities,
	roleAbilityPolicy,
	selectedRole,
	onSelectRole,
	isModal = false,
} ) {
	const roles = Array.isArray( roleAbilityPolicy.roles )
		? roleAbilityPolicy.roles
		: EMPTY_ARRAY;
	const globalEnabledIds = Array.isArray( roleAbilityPolicy.globalEnabledIds )
		? roleAbilityPolicy.globalEnabledIds
		: EMPTY_ARRAY;
	const activeRole =
		roles.find( ( role ) => role.id === selectedRole ) || roles[ 0 ];
	const [ stagedIds, setStagedIds ] = useState(
		activeRole?.allowedIds || []
	);
	const [ search, setSearch ] = useState( '' );
	const [ statusFilter, setStatusFilter ] = useState( 'all' );
	const [ categoryFilter, setCategoryFilter ] = useState( 'all' );
	const [ copyFromRole, setCopyFromRole ] = useState(
		roles.find( ( role ) => role.id !== activeRole?.id )?.id || ''
	);
	const [ expandedGroups, setExpandedGroups ] = useState( [] );
	const [ showAffectedUsers, setShowAffectedUsers ] = useState( false );
	useEffect( () => {
		setStagedIds( activeRole?.allowedIds || [] );
		setCopyFromRole(
			roles.find( ( role ) => role.id !== activeRole?.id )?.id || ''
		);
		setExpandedGroups(
			Object.keys(
				roleAbilityGroups(
					abilities,
					activeRole?.allowedIds || [],
					globalEnabledIds
				)
			).slice( 0, 2 )
		);
		setShowAffectedUsers( false );
	}, [
		activeRole?.id,
		activeRole?.allowedIds,
		abilities,
		globalEnabledIds,
		roles,
	] );

	if ( ! activeRole ) {
		return null;
	}

	const groups = roleAbilityGroups( abilities, stagedIds, globalEnabledIds );
	const categories = Object.keys( groups ).sort();
	const normalizedSearch = search.trim().toLowerCase();
	const filteredGroups = categories.reduce( ( currentGroups, group ) => {
		if ( categoryFilter !== 'all' && group !== categoryFilter ) {
			return currentGroups;
		}

		const items = groups[ group ].filter( ( ability ) => {
			if ( statusFilter === 'enabled' && ! ability.enabled ) {
				return false;
			}

			if ( statusFilter === 'disabled' && ability.enabled ) {
				return false;
			}

			return (
				! normalizedSearch ||
				roleAbilitySearchText( ability ).includes( normalizedSearch )
			);
		} );

		if ( items.length === 0 ) {
			return currentGroups;
		}

		return {
			...currentGroups,
			[ group ]: items,
		};
	}, {} );
	const filteredCategories = Object.keys( filteredGroups );
	const stagedSet = new Set( stagedIds );
	const activeRoleIds = activeRole.allowedIds || [];
	const hasChanges =
		stagedIds.length !== activeRoleIds.length ||
		stagedIds.some( ( id ) => ! activeRoleIds.includes( id ) );

	const toggleAbility = ( abilityId, checked ) => {
		setStagedIds( ( current ) => {
			if ( checked ) {
				return [ ...new Set( [ ...current, abilityId ] ) ];
			}

			return current.filter( ( id ) => id !== abilityId );
		} );
	};

	return (
		<section
			id="aculect-ai-companion-role-abilities"
			className={ `aculect-ai-companion-role-abilities${
				isModal ? ' is-modal' : ''
			}` }
		>
			<div className="aculect-ai-companion-role-abilities__hero">
				<div>
					<span className="aculect-ai-companion-eyebrow">
						Role abilities
					</span>
					<h2>Manage role abilities</h2>
					<p>
						Choose the tools exposed to users in each WordPress
						role. Unknown, unavailable, and globally disabled tools
						remain blocked by the server policy.
					</p>
				</div>
				<div className="aculect-ai-companion-role-abilities__controls">
					<SelectControl
						label="Role"
						value={ activeRole.id }
						options={ roles.map( ( role ) => ( {
							value: role.id,
							label: role.label,
						} ) ) }
						onChange={ onSelectRole }
					/>
				</div>
			</div>

			<div className="aculect-ai-companion-role-abilities__layout">
				<div className="aculect-ai-companion-role-abilities__main">
					<form
						method="post"
						action={ data.actions?.adminPostUrl }
						className="aculect-ai-companion-role-abilities__form"
					>
						<input
							type="hidden"
							name="action"
							value={ data.actions?.saveRoleAbilitiesAction }
						/>
						<input
							type="hidden"
							name="_wpnonce"
							value={ data.actions?.saveRoleAbilitiesNonce }
						/>
						<input
							type="hidden"
							name="role_ability_action"
							value="save"
						/>
						<input
							type="hidden"
							name="role_ability_role"
							value={ activeRole.id }
						/>
						{ stagedIds.map( ( id ) => (
							<input
								key={ id }
								type="hidden"
								name="enabled_role_abilities[]"
								value={ id }
							/>
						) ) }
						<div className="aculect-ai-companion-role-abilities__toolbar">
							<SearchControl
								label="Search role abilities"
								value={ search }
								placeholder="Name, key, scope, or description"
								onChange={ setSearch }
							/>
							<SelectControl
								label="Category"
								value={ categoryFilter }
								options={ [
									{ value: 'all', label: 'All categories' },
									...categories.map( ( group ) => ( {
										value: group,
										label: group,
									} ) ),
								] }
								onChange={ setCategoryFilter }
							/>
							<SelectControl
								label="Status"
								value={ statusFilter }
								options={ [
									{ value: 'all', label: 'All states' },
									{ value: 'enabled', label: 'Enabled' },
									{ value: 'disabled', label: 'Disabled' },
								] }
								onChange={ setStatusFilter }
							/>
						</div>

						{ filteredCategories.length > 0 ? (
							<div className="aculect-ai-companion-role-ability-groups">
								{ filteredCategories.map( ( group ) => {
									const items = filteredGroups[ group ];
									const enabledCount = items.filter(
										( ability ) => ability.enabled
									).length;
									const isExpanded =
										expandedGroups.includes( group );

									return (
										<section
											key={ group }
											className="aculect-ai-companion-role-ability-group"
										>
											<button
												type="button"
												className="aculect-ai-companion-role-ability-group__header"
												aria-expanded={ isExpanded }
												onClick={ () =>
													setExpandedGroups(
														( current ) =>
															current.includes(
																group
															)
																? current.filter(
																		(
																			item
																		) =>
																			item !==
																			group
																  )
																: [
																		...current,
																		group,
																  ]
													)
												}
											>
												<strong>{ group }</strong>
												<span>
													{ enabledCount }/{ ' ' }
													{ items.length } enabled
												</span>
											</button>
											{ isExpanded && (
												<div className="aculect-ai-companion-role-ability-list">
													{ items.map(
														( ability ) => {
															const isChecked =
																stagedSet.has(
																	ability.id
																) &&
																ability.globallyEnabled;
															const sourceLabel =
																roleAbilitySourceLabel(
																	ability,
																	activeRole,
																	isChecked
																);

															return (
																<div
																	key={
																		ability.id
																	}
																	className="aculect-ai-companion-role-ability-row"
																>
																	<ToggleControl
																		label={
																			ability.title
																		}
																		checked={
																			isChecked
																		}
																		disabled={
																			! ability.globallyEnabled
																		}
																		onChange={ (
																			checked
																		) =>
																			toggleAbility(
																				ability.id,
																				Boolean(
																					checked
																				)
																			)
																		}
																	/>
																	<p>
																		{
																			ability.description
																		}
																	</p>
																	<div className="aculect-ai-companion-role-ability-row__meta">
																		<span>
																			{
																				sourceLabel
																			}
																		</span>
																		<span>
																			{ ability.readOnly
																				? 'Read-only'
																				: 'Can change site' }
																		</span>
																		<code>
																			{
																				ability.id
																			}
																		</code>
																	</div>
																</div>
															);
														}
													) }
												</div>
											) }
										</section>
									);
								} ) }
							</div>
						) : (
							<EmptyState title="No role abilities found">
								Adjust the search, category, or status filters
								for this role.
							</EmptyState>
						) }

						<div className="aculect-ai-companion-role-abilities__footer">
							<Button type="submit" variant="primary">
								Save role changes
							</Button>
							<Button
								type="button"
								variant="secondary"
								disabled={ ! hasChanges }
								accessibleWhenDisabled
								onClick={ () =>
									setStagedIds( activeRole.allowedIds || [] )
								}
							>
								Discard changes
							</Button>
						</div>
					</form>
				</div>

				<aside className="aculect-ai-companion-role-abilities__side">
					<section>
						<h3>Role summary</h3>
						<dl>
							<div className="aculect-ai-companion-role-abilities__summary-row">
								<dt>Enabled</dt>
								<dd>{ stagedIds.length }</dd>
							</div>
							<div className="aculect-ai-companion-role-abilities__summary-row">
								<dt>Policy</dt>
								<dd>
									{ activeRole.explicit
										? 'Custom override'
										: roleAbilityPolicy.defaultPolicyName ||
										  'Global ability policy' }
								</dd>
							</div>
							<div className="aculect-ai-companion-role-abilities__summary-row">
								<dt>Affected users</dt>
								<dd>{ activeRole.userCount }</dd>
							</div>
						</dl>
						<Button
							type="button"
							variant="secondary"
							onClick={ () =>
								setShowAffectedUsers( ( current ) => ! current )
							}
						>
							View affected users
						</Button>
						{ showAffectedUsers && (
							<ul className="aculect-ai-companion-role-abilities__users">
								{ activeRole.users.length > 0 ? (
									activeRole.users.map( ( user ) => (
										<li key={ user.id }>
											{ user.label ||
												`User ${ user.id }` }
										</li>
									) )
								) : (
									<li>No users currently have this role.</li>
								) }
							</ul>
						) }
					</section>
					<section>
						<h3>Role actions</h3>
						<ActionForm
							data={ data }
							action={ data.actions?.saveRoleAbilitiesAction }
							nonce={ data.actions?.saveRoleAbilitiesNonce }
							label="Reset to default"
							variant="secondary"
							confirmTitle="Reset role policy"
							confirmMessage="Reset this role to the global ability policy?"
						>
							<input
								type="hidden"
								name="role_ability_action"
								value="reset"
							/>
							<input
								type="hidden"
								name="role_ability_role"
								value={ activeRole.id }
							/>
						</ActionForm>
						<ActionForm
							data={ data }
							action={ data.actions?.saveRoleAbilitiesAction }
							nonce={ data.actions?.saveRoleAbilitiesNonce }
							label="Copy from role"
							variant="secondary"
							disabled={ ! copyFromRole }
							confirmTitle="Copy role policy"
							confirmMessage="Copy ability policy from the selected role?"
						>
							<input
								type="hidden"
								name="role_ability_action"
								value="copy"
							/>
							<input
								type="hidden"
								name="role_ability_role"
								value={ activeRole.id }
							/>
							<SelectControl
								label="Copy from"
								name="copy_from_role"
								value={ copyFromRole }
								options={ roles
									.filter(
										( role ) => role.id !== activeRole.id
									)
									.map( ( role ) => ( {
										value: role.id,
										label: role.label,
									} ) ) }
								onChange={ setCopyFromRole }
							/>
						</ActionForm>
					</section>
					<section>
						<h3>Help</h3>
						<p>
							Role policies narrow the globally enabled ability
							list. WordPress capabilities are still checked for
							each tool execution.
						</p>
					</section>
				</aside>
			</div>
		</section>
	);
}

function isPlainObject( value ) {
	return value && typeof value === 'object' && ! Array.isArray( value );
}

function versionParts( version ) {
	return String( version || '' )
		.split( '.' )
		.map( ( part ) => Number.parseInt( part, 10 ) || 0 );
}

function compareVersionsDescending( firstVersion, secondVersion ) {
	const firstParts = versionParts( firstVersion );
	const secondParts = versionParts( secondVersion );
	const length = Math.max( firstParts.length, secondParts.length );

	for ( let index = 0; index < length; index += 1 ) {
		const firstPart = firstParts[ index ] || 0;
		const secondPart = secondParts[ index ] || 0;

		if ( firstPart !== secondPart ) {
			return secondPart - firstPart;
		}
	}

	return String( secondVersion ).localeCompare( String( firstVersion ) );
}

function releaseType( version ) {
	const parts = versionParts( version );
	const patch = parts[ 2 ] || 0;

	if ( patch > 0 ) {
		return 'Patch release';
	}

	const major = parts[ 0 ] || 0;
	const minor = parts[ 1 ] || 0;

	if ( major > 0 && minor === 0 ) {
		return 'Major release';
	}

	return 'Minor release';
}

function safeExternalUrl( value ) {
	try {
		const url = new URL( String( value || '' ) );

		return [ 'https:', 'http:' ].includes( url.protocol )
			? url.toString()
			: '';
	} catch {
		return '';
	}
}

function normalizeChangelogEntries( changelog ) {
	const entries = Object.entries(
		isPlainObject( changelog ) ? changelog : {}
	)
		.map( ( [ version, entry ] ) => {
			const entryData = isPlainObject( entry ) ? entry : {};
			const date = String(
				entryData.date ||
					entryData.releaseDate ||
					entryData.releasedAt ||
					''
			).trim();
			const type = String( entryData.type || '' ).trim();

			return {
				version,
				type: type || releaseType( version ),
				date,
				groups: Object.entries( entryData )
					.filter(
						( [ title ] ) => ! CHANGELOG_METADATA_KEYS.has( title )
					)
					.map( ( [ title, items ] ) => ( {
						title,
						items: Array.isArray( items )
							? items.filter( ( item ) =>
									String( item || '' ).trim()
							  )
							: [],
					} ) )
					.filter( ( group ) => group.items.length > 0 ),
			};
		} )
		.filter( ( entry ) => entry.version );

	return entries.sort( ( firstEntry, secondEntry ) =>
		compareVersionsDescending( firstEntry.version, secondEntry.version )
	);
}

function ChangelogDashboard( { changelog, metadata } ) {
	const pluginMetadata = isPlainObject( metadata ) ? metadata : {};
	const entries = normalizeChangelogEntries( changelog );
	const latestVersion = entries[ 0 ]?.version || '';
	const installedVersion =
		pluginMetadata.version || pluginMetadata.stableTag || latestVersion;
	const [ selectedVersion, setSelectedVersion ] = useState(
		latestVersion || installedVersion || ''
	);
	const selectedEntry =
		entries.find( ( entry ) => entry.version === selectedVersion ) ||
		entries[ 0 ];
	const wordpressOrgUrl = safeExternalUrl( pluginMetadata.wordpressOrgUrl );
	const supportUrl = safeExternalUrl( pluginMetadata.supportUrl );
	const reviewUrl = safeExternalUrl( pluginMetadata.reviewUrl );
	const releaseDate = selectedEntry?.date || 'Not listed in changelog';
	const metadataRows = [
		{ label: 'Version', value: selectedEntry?.version || '-' },
		{ label: 'Release date', value: releaseDate },
		{ label: 'Type', value: selectedEntry?.type || 'Release' },
		{ label: 'Tested up to', value: pluginMetadata.testedUpTo || '-' },
		{ label: 'Requires WP', value: pluginMetadata.requiresAtLeast || '-' },
		{ label: 'Requires PHP', value: pluginMetadata.requiresPhp || '-' },
	];

	if ( entries.length === 0 ) {
		return (
			<div className="aculect-ai-companion-changelog-dashboard">
				<EmptyState title="No changelog entries">
					Check the bundled changelog file or the WordPress.org
					developer tab for release notes.
				</EmptyState>
			</div>
		);
	}

	return (
		<div className="aculect-ai-companion-changelog-dashboard">
			{ wordpressOrgUrl && (
				<div className="aculect-ai-companion-tab-actions">
					<Button
						href={ wordpressOrgUrl }
						target="_blank"
						rel="noreferrer noopener"
						variant="secondary"
					>
						WordPress.org Changelog
					</Button>
				</div>
			) }

			<div className="aculect-ai-companion-changelog-layout">
				<aside className="aculect-ai-companion-changelog-sidebar">
					<h3 className="aculect-ai-companion-changelog-sidebar__title">
						Versions
					</h3>
					<div className="aculect-ai-companion-changelog-version-list">
						{ entries.map( ( entry ) => {
							const isSelected =
								entry.version === selectedEntry.version;

							return (
								<button
									key={ entry.version }
									type="button"
									className={
										isSelected ? 'is-selected' : ''
									}
									aria-pressed={ isSelected }
									onClick={ () =>
										setSelectedVersion( entry.version )
									}
								>
									<span className="aculect-ai-companion-changelog-version-list__version">
										{ entry.version }
									</span>
									<span className="aculect-ai-companion-changelog-version-list__meta">
										{ entry.date || 'Date not listed' }
									</span>
									<span className="aculect-ai-companion-changelog-version-list__badges">
										{ entry.version === latestVersion && (
											<em>Latest</em>
										) }
										{ entry.version ===
											installedVersion && (
											<em>Installed</em>
										) }
									</span>
								</button>
							);
						} ) }
					</div>
				</aside>

				<section className="aculect-ai-companion-changelog-detail">
					<div className="aculect-ai-companion-changelog-detail__header">
						<div>
							<span className="aculect-ai-companion-eyebrow">
								Selected release
							</span>
							<h3 className="aculect-ai-companion-changelog-detail__version">
								{ selectedEntry.version }
							</h3>
						</div>
						<div className="aculect-ai-companion-changelog-detail__badges">
							{ selectedEntry.version === latestVersion && (
								<span className="aculect-ai-companion-changelog-detail__badge">
									Latest
								</span>
							) }
							{ selectedEntry.version === installedVersion && (
								<span className="aculect-ai-companion-changelog-detail__badge">
									Installed
								</span>
							) }
						</div>
					</div>

					<div className="aculect-ai-companion-changelog-meta-grid">
						{ metadataRows.map( ( item ) => (
							<div
								key={ item.label }
								className="aculect-ai-companion-changelog-meta-grid__item"
							>
								<span className="aculect-ai-companion-changelog-meta-grid__label">
									{ item.label }
								</span>
								<strong className="aculect-ai-companion-changelog-meta-grid__value">
									{ item.value }
								</strong>
							</div>
						) ) }
					</div>

					{ selectedEntry.groups.length > 0 ? (
						<div className="aculect-ai-companion-changelog-notes">
							{ selectedEntry.groups.map( ( group ) => (
								<section
									key={ group.title }
									className="aculect-ai-companion-changelog-notes__group"
								>
									<h4 className="aculect-ai-companion-changelog-notes__title">
										{ group.title }
									</h4>
									<ul className="aculect-ai-companion-changelog-notes__list">
										{ group.items.map( ( item, index ) => (
											<li
												key={ `${ selectedEntry.version }-${ group.title }-${ index }` }
											>
												{ item }
											</li>
										) ) }
									</ul>
								</section>
							) ) }
						</div>
					) : (
						<EmptyState title="No release notes">
							This version exists in the changelog source, but no
							grouped notes were found.
						</EmptyState>
					) }
				</section>
			</div>

			<div className="aculect-ai-companion-changelog-help">
				<div className="aculect-ai-companion-changelog-help__item">
					<h3 className="aculect-ai-companion-changelog-help__title">
						Need help with an update?
					</h3>
					<p className="aculect-ai-companion-changelog-help__copy">
						Use the support forum for release questions,
						compatibility reports, or setup issues.
					</p>
					{ supportUrl && (
						<a
							href={ supportUrl }
							target="_blank"
							rel="noreferrer noopener"
						>
							Open support forum
						</a>
					) }
				</div>
				<div className="aculect-ai-companion-changelog-help__item">
					<h3 className="aculect-ai-companion-changelog-help__title">
						Share release feedback
					</h3>
					<p className="aculect-ai-companion-changelog-help__copy">
						Reviews help prioritize improvements and surface
						compatibility feedback for other WordPress users.
					</p>
					{ reviewUrl && (
						<a
							href={ reviewUrl }
							target="_blank"
							rel="noreferrer noopener"
						>
							Leave a review
						</a>
					) }
				</div>
			</div>
		</div>
	);
}

function SettingsApp() {
	const initialSettingsData = window.aculectAICompanionSettingsData || {};
	const [ settingsData, setSettingsData ] = useState( initialSettingsData );
	const [ loadingTab, setLoadingTab ] = useState( '' );
	const [ tabLoadError, setTabLoadError ] = useState( '' );
	const tabPayloadRequestsRef = useRef( new Set() );
	const data = settingsData;
	const brandIconUrl = data.brandIconUrl || '';
	const pluginMetadata =
		data.pluginMetadata && typeof data.pluginMetadata === 'object'
			? data.pluginMetadata
			: {};
	const brandMarkUrl = data.brandMarkUrl || brandIconUrl;
	const documentationUrl = safeExternalUrl(
		pluginMetadata.documentationUrl || pluginMetadata.wordpressOrgUrl
	);
	const supportUrl = safeExternalUrl( pluginMetadata.supportUrl );
	const providers = Array.isArray( data.providers )
		? data.providers
		: EMPTY_ARRAY;
	const sessions = Array.isArray( data.sessions )
		? data.sessions
		: EMPTY_ARRAY;
	const revokedSessions = Array.isArray( data.revokedSessions )
		? data.revokedSessions
		: EMPTY_ARRAY;
	const abilities = Array.isArray( data.abilities )
		? data.abilities
		: EMPTY_ARRAY;
	const wpAbilities = Array.isArray( data.wpAbilities )
		? data.wpAbilities
		: EMPTY_ARRAY;
	const originalEnabledAbilities = Array.isArray( data.enabledAbilities )
		? data.enabledAbilities
		: EMPTY_ARRAY;
	const originalEnabledWpAbilities = Array.isArray( data.enabledWpAbilities )
		? data.enabledWpAbilities
		: EMPTY_ARRAY;
	const originalConfirmationGroups = Array.isArray( data.confirmationGroups )
		? data.confirmationGroups
		: EMPTY_ARRAY;
	const confirmationGroupOptions = Array.isArray(
		data.confirmationGroupOptions
	)
		? data.confirmationGroupOptions
		: EMPTY_ARRAY;
	const activity =
		data.activity && typeof data.activity === 'object' ? data.activity : {};
	const brandProfile =
		data.brandProfile && typeof data.brandProfile === 'object'
			? data.brandProfile
			: {};
	const brandFields =
		brandProfile.fields && typeof brandProfile.fields === 'object'
			? brandProfile.fields
			: {};
	const brandDefaults =
		brandProfile.defaults && typeof brandProfile.defaults === 'object'
			? brandProfile.defaults
			: {};
	const activityFilters =
		activity.filters && typeof activity.filters === 'object'
			? activity.filters
			: {};
	const diagnostics =
		data.diagnostics && typeof data.diagnostics === 'object'
			? data.diagnostics
			: {};
	const sampleData =
		data.sampleData && typeof data.sampleData === 'object'
			? data.sampleData
			: {};
	const sampleDataTabs = Array.isArray( sampleData.tabs )
		? sampleData.tabs
		: EMPTY_ARRAY;
	const activeSessionCount = Number( data.activeSessionCount || 0 );
	const roleConnections =
		data.roleConnections && typeof data.roleConnections === 'object'
			? data.roleConnections
			: {};
	const roleAbilityPolicy =
		data.roleAbilityPolicy && typeof data.roleAbilityPolicy === 'object'
			? data.roleAbilityPolicy
			: {};
	const connectionHealth =
		data.connectionHealth && typeof data.connectionHealth === 'object'
			? data.connectionHealth
			: {};
	const logs =
		diagnostics.logs && typeof diagnostics.logs === 'object'
			? diagnostics.logs
			: {};
	const activityFilterDefaults = useMemo(
		() => ( {
			range: activityFilters.range || '7d',
			status: activityFilters.status || '',
			user: activityFilters.user_id || '',
			assistant: activityFilters.assistant || '',
			action: activityFilters.action || '',
			search: activityFilters.search || '',
		} ),
		[
			activityFilters.action,
			activityFilters.assistant,
			activityFilters.range,
			activityFilters.search,
			activityFilters.status,
			activityFilters.user_id,
		]
	);
	const [ copied, setCopied ] = useState( '' );
	const [ activityFilterValues, setActivityFilterValues ] = useState(
		activityFilterDefaults
	);
	const [ diagnosticFilter, setDiagnosticFilter ] = useState( 'all' );
	const [ diagnosticsRunning, setDiagnosticsRunning ] = useState( false );
	const [ openProvider, setOpenProvider ] = useState(
		preferredOpenProviderId( providers )
	);
	const [ loggingEnabled, setLoggingEnabled ] = useState(
		Boolean( diagnostics.loggingEnabled )
	);
	const [ roleConnectionsEnabled, setRoleConnectionsEnabled ] = useState(
		Boolean( roleConnections.enabled )
	);
	const [ roleConnectionRoles, setRoleConnectionRoles ] = useState(
		Array.isArray( roleConnections.allowedRoles )
			? roleConnections.allowedRoles
			: []
	);
	const [ enabledAbilities, setEnabledAbilities ] = useState(
		originalEnabledAbilities
	);
	const [ confirmationGroups, setConfirmationGroups ] = useState(
		originalConfirmationGroups
	);
	const [ enabledWpAbilities, setEnabledWpAbilities ] = useState(
		originalEnabledWpAbilities
	);
	const [ selectedRoleAbilityRole, setSelectedRoleAbilityRole ] = useState(
		Array.isArray( roleAbilityPolicy.roles )
			? roleAbilityPolicy.roles[ 0 ]?.id || ''
			: ''
	);
	const [ roleAbilitiesModalOpen, setRoleAbilitiesModalOpen ] =
		useState( false );
	const adminNoticesRef = useRef( null );
	const copyTimeoutRef = useRef( null );
	const isAccessPaused = Boolean( data.accessPaused );
	const hasActiveSessions = activeSessionCount > 0;
	const currentUserId = Number( data.currentUserId || 0 );
	const activeConnectionSessions = useMemo(
		() =>
			sessions.map( ( session ) =>
				normalizeConnectionSession( session, 'active' )
			),
		[ sessions ]
	);
	const revokedConnectionSessions = useMemo(
		() =>
			revokedSessions.map( ( session ) =>
				normalizeConnectionSession( session, 'revoked' )
			),
		[ revokedSessions ]
	);
	const connectionSessions = useMemo(
		() => [ ...activeConnectionSessions, ...revokedConnectionSessions ],
		[ activeConnectionSessions, revokedConnectionSessions ]
	);
	const hasAbilityChanges =
		! sameStringSet( enabledAbilities, originalEnabledAbilities ) ||
		! sameStringSet( enabledWpAbilities, originalEnabledWpAbilities ) ||
		! sameStringSet( confirmationGroups, originalConfirmationGroups );
	const connectionStatus = connectStatusDetails( {
		isAccessPaused,
		hasActiveSessions,
		sessionCount: activeSessionCount,
	} );
	const helpLinks = uniqueHelpLinks( providers );
	const shouldShowAccessControl = Boolean(
		data.actions?.setLockdownAction && data.actions?.setLockdownNonce
	);
	const setActivityFilterValue = ( key ) => ( value ) => {
		setActivityFilterValues( ( current ) => ( {
			...current,
			[ key ]: value,
		} ) );
	};

	useEffect(
		() => () => {
			if ( copyTimeoutRef.current ) {
				clearTimeout( copyTimeoutRef.current );
			}
		},
		[]
	);

	useEffect( () => {
		setActivityFilterValues( activityFilterDefaults );
	}, [ activityFilterDefaults ] );

	useEffect( () => {
		if ( providers.length === 0 ) {
			return;
		}

		setOpenProvider( ( currentProvider ) => {
			const providerIsAvailable = providers.some(
				( provider ) => provider.id === currentProvider
			);

			return providerIsAvailable
				? currentProvider
				: preferredOpenProviderId( providers );
		} );
	}, [ providers ] );

	useEffect( () => {
		const target = adminNoticesRef.current;
		if ( ! target ) {
			return undefined;
		}

		let scheduled = false;
		const moveNotices = () => {
			scheduled = false;
			relocateAdminNotices( target );
		};
		const scheduleMove = () => {
			if ( scheduled ) {
				return;
			}

			scheduled = true;
			window.requestAnimationFrame( moveNotices );
		};
		const observer = new window.MutationObserver( scheduleMove );
		const container =
			document.getElementById( 'wpbody-content' ) || document.body;

		moveNotices();
		observer.observe( container, { childList: true, subtree: true } );

		return () => observer.disconnect();
	}, [] );

	const copyValue = async ( value, label = 'Copied' ) => {
		try {
			await navigator.clipboard.writeText( String( value || '' ) );
			setCopied( label );
			if ( copyTimeoutRef.current ) {
				clearTimeout( copyTimeoutRef.current );
			}
			copyTimeoutRef.current = setTimeout( () => setCopied( '' ), 2000 );
		} catch {
			setCopied( '' );
		}
	};

	const tabs = SETTINGS_TABS;
	const visibleTabs = tabs.filter( ( tab ) => ! tab.hidden );
	const activeTabName = useActiveTabName( tabs );
	const activeTab =
		tabs.find( ( tab ) => tab.name === activeTabName ) || tabs[ 0 ];
	const sampleDataActive = Boolean(
		sampleData.enabled && sampleDataTabs.includes( activeTab.name )
	);
	const hydratedTabKey = Array.isArray( data.hydratedTabs )
		? data.hydratedTabs.join( '|' )
		: '';
	const settingsPayloadUrl = data.settingsPayloadUrl || '';
	const settingsRestNonce = data.settingsRestNonce || '';
	const activeTabHydrated = hasHydratedTab( activeTab.name, data );
	const activeTabLoadFailed =
		! activeTabHydrated && tabLoadError === activeTab.name;

	useEffect( () => {
		document.title = adminTabTitle( activeTab.title );
	}, [ activeTab.title ] );

	useEffect( () => {
		if ( activeTab.name !== 'abilities' ) {
			setRoleAbilitiesModalOpen( false );
		}
	}, [ activeTab.name ] );

	useEffect( () => {
		const tabName = activeTab.name;
		if ( activeTabHydrated ) {
			return;
		}

		if ( ! settingsPayloadUrl ) {
			setTabLoadError( tabName );
			return;
		}

		if ( tabPayloadRequestsRef.current.has( tabName ) ) {
			return;
		}

		tabPayloadRequestsRef.current.add( tabName );
		setLoadingTab( tabName );
		setTabLoadError( '' );

		fetchSettingsPayload(
			{
				settingsPayloadUrl,
				settingsRestNonce,
			},
			tabName
		)
			.then( ( payload ) => {
				setSettingsData( ( currentData ) =>
					mergeSettingsPayload( currentData, payload, tabName )
				);
			} )
			.catch( () => {
				tabPayloadRequestsRef.current.delete( tabName );
				setTabLoadError( tabName );
			} )
			.finally( () => {
				setLoadingTab( ( currentTab ) =>
					currentTab === tabName ? '' : currentTab
				);
			} );
	}, [
		activeTab.name,
		activeTabHydrated,
		hydratedTabKey,
		settingsPayloadUrl,
		settingsRestNonce,
	] );

	const toggleAbility = ( id, checked ) => {
		setEnabledAbilities( ( current ) => {
			if ( checked ) {
				return Array.from( new Set( [ ...current, id ] ) );
			}

			return current.filter( ( item ) => item !== id );
		} );
	};

	const toggleWpAbility = ( abilityId, checked ) => {
		setEnabledWpAbilities( ( current ) => {
			if ( checked ) {
				return [ ...new Set( [ ...current, abilityId ] ) ];
			}

			return current.filter( ( id ) => id !== abilityId );
		} );
	};

	const toggleRoleConnectionRole = ( roleId, checked ) => {
		setRoleConnectionRoles( ( current ) => {
			if ( checked ) {
				return [ ...new Set( [ ...current, roleId ] ) ];
			}

			return current.filter( ( id ) => id !== roleId );
		} );
	};
	const toggleConfirmationGroup = ( group, checked ) => {
		setConfirmationGroups( ( current ) => {
			if ( checked ) {
				return Array.from( new Set( [ ...current, group ] ) );
			}

			return current.filter( ( item ) => item !== group );
		} );
	};

	return (
		<div className="aculect-ai-companion-app-root">
			<header className="aculect-ai-companion-app-header">
				<div className="aculect-ai-companion-app-branding">
					{ brandMarkUrl && (
						<img
							className="aculect-ai-companion-app-icon"
							src={ brandMarkUrl }
							alt=""
							aria-hidden="true"
						/>
					) }
					<div className="aculect-ai-companion-app-heading">
						<div className="aculect-ai-companion-app-title-row">
							<h1 className="aculect-ai-companion-app-title">
								AI Companion
							</h1>
							{ data.version && (
								<span className="aculect-ai-companion-version-badge">
									{ formatVersion( data.version ) }
								</span>
							) }
						</div>
						<p className="aculect-ai-companion-app-subtitle">
							by Aculect
						</p>
					</div>
				</div>
				<div className="aculect-ai-companion-header-actions">
					{ documentationUrl && (
						<a
							className="aculect-ai-companion-header-link"
							href={ documentationUrl }
							target="_blank"
							rel="noreferrer noopener"
						>
							<Icon icon={ page } size={ 20 } />
							<span>Documentation</span>
						</a>
					) }
					{ documentationUrl && supportUrl && (
						<span
							className="aculect-ai-companion-header-divider"
							aria-hidden="true"
						/>
					) }
					{ supportUrl && (
						<a
							className="aculect-ai-companion-header-link"
							href={ supportUrl }
							target="_blank"
							rel="noreferrer noopener"
						>
							<Icon icon={ help } size={ 22 } />
							<span>Support</span>
						</a>
					) }
				</div>
			</header>

			<nav
				className="aculect-ai-companion-tabs"
				aria-label="Aculect AI Companion settings"
			>
				{ visibleTabs.map( ( tab ) => {
					const isActive = activeTab.name === tab.name;

					return (
						<a
							key={ tab.name }
							className={ `aculect-ai-companion-tab ${
								isActive ? 'is-active' : ''
							}` }
							href={ tabUrl( tab.name, data.adminPageUrl ) }
							aria-current={ isActive ? 'page' : undefined }
							onClick={ ( event ) =>
								maybeSelectTab( event, tab.name )
							}
						>
							<span>{ tab.title }</span>
						</a>
					);
				} ) }
			</nav>

			<div
				className="aculect-ai-companion-admin-notices"
				ref={ adminNoticesRef }
				aria-live="polite"
			/>

			{ copied && (
				<Notice status="success" isDismissible={ false }>
					{ copied }
				</Notice>
			) }
			{ sampleDataActive && (
				<Notice status="info" isDismissible={ false }>
					{ sampleData.message ||
						'Local sample data is available because WP_ENVIRONMENT_TYPE is local. Empty listing views can show non-persistent sample rows.' }
				</Notice>
			) }
			{ data.status === 'abilities_saved' && (
				<Notice status="success" isDismissible={ false }>
					Abilities saved.
				</Notice>
			) }
			{ data.status === 'role_abilities_saved' && (
				<Notice status="success" isDismissible={ false }>
					Role ability policy saved.
				</Notice>
			) }
			{ data.status === 'revoked' && (
				<Notice status="warning" isDismissible={ false }>
					AI assistant disconnected.
				</Notice>
			) }
			{ data.status === 'revoked_all' && (
				<Notice status="warning" isDismissible={ false }>
					All AI assistants disconnected.
				</Notice>
			) }
			{ data.status === 'advanced_saved' && (
				<Notice status="success" isDismissible={ false }>
					Advanced settings saved.
				</Notice>
			) }
			{ data.status === 'settings_imported' && (
				<Notice status="success" isDismissible={ false }>
					Settings imported.
				</Notice>
			) }
			{ data.status === 'settings_import_failed' && (
				<Notice status="error" isDismissible={ false }>
					Settings import failed. Choose a valid Aculect AI Companion
					settings JSON file.
				</Notice>
			) }
			{ data.status === 'settings_reset' && (
				<Notice status="warning" isDismissible={ false }>
					Settings reset to defaults.
				</Notice>
			) }
			{ data.status === 'brand_saved' && (
				<Notice status="success" isDismissible={ false }>
					Brand profile saved.
				</Notice>
			) }
			{ data.status === 'logs_cleared' && (
				<Notice status="warning" isDismissible={ false }>
					Diagnostic logs cleared.
				</Notice>
			) }
			{ data.status === 'access_paused' && (
				<Notice status="warning" isDismissible={ false }>
					AI access paused.
				</Notice>
			) }
			{ data.status === 'access_resumed' && (
				<Notice status="success" isDismissible={ false }>
					AI access resumed.
				</Notice>
			) }
			{ data.status === 'session_write_permission_enabled' && (
				<Notice status="success" isDismissible={ false }>
					Direct writes enabled for the connection.
				</Notice>
			) }
			{ data.status === 'session_write_permission_disabled' && (
				<Notice status="success" isDismissible={ false }>
					Write confirmation restored for the connection.
				</Notice>
			) }
			{ data.status === 'session_write_permission_not_updated' && (
				<Notice status="warning" isDismissible={ false }>
					Write permission was not changed because the connection is
					no longer active.
				</Notice>
			) }
			{ data.status === 'diagnostics_run' && (
				<Notice status="success" isDismissible={ false }>
					Connection diagnostics updated.
				</Notice>
			) }

			<main className="aculect-ai-companion-tab-panel">
				{ ( () => {
					const tab = activeTab;

					if ( ! activeTabHydrated ) {
						return (
							<TabLoadingState
								tab={ activeTab }
								failed={ activeTabLoadFailed }
								isLoading={ loadingTab === activeTab.name }
							/>
						);
					}

					if ( tab.name === 'overview' ) {
						return (
							<div className="aculect-ai-companion-overview">
								<section className="aculect-ai-companion-overview-hero">
									<div className="aculect-ai-companion-overview-hero__content">
										<span className="aculect-ai-companion-eyebrow">
											AI assistants. WordPress. Securely
											connected.
										</span>
										<h2 className="aculect-ai-companion-overview-hero__title">
											Bring AI assistants into WordPress
											without giving up control.
										</h2>
										<p className="aculect-ai-companion-overview-hero__copy">
											Connect approved AI tools to
											WordPress through a secure
											permission layer. Draft content,
											manage media, review comments, and
											automate repetitive tasks while
											keeping administrators in control.
										</p>
										<div className="aculect-ai-companion-overview-actions">
											<Button
												href={ tabUrl(
													'connect',
													data.adminPageUrl
												) }
												variant="primary"
												className="aculect-ai-companion-overview-action aculect-ai-companion-overview-action--primary"
												onClick={ ( event ) =>
													maybeSelectTab(
														event,
														'connect'
													)
												}
											>
												Connect Assistant
											</Button>
											<Button
												href={
													documentationUrl ||
													'#aculect-ai-companion-overview-capabilities'
												}
												variant="secondary"
												className="aculect-ai-companion-overview-action aculect-ai-companion-overview-action--secondary"
												{ ...( documentationUrl
													? {
															target: '_blank',
															rel: 'noreferrer noopener',
													  }
													: {} ) }
											>
												View Documentation
											</Button>
										</div>
									</div>
									<OverviewCircuit
										brandIconUrl={ brandIconUrl }
									/>
								</section>

								<section
									id="aculect-ai-companion-overview-capabilities"
									className="aculect-ai-companion-overview-section"
								>
									<h2 className="aculect-ai-companion-overview-section__title">
										Everything your AI assistant can do
									</h2>
									<div className="aculect-ai-companion-feature-grid">
										<OverviewFeatureCard
											icon={ postContent }
											title="Content Management"
										>
											Create and update posts, pages,
											excerpts, and metadata while
											following WordPress permissions.
										</OverviewFeatureCard>
										<OverviewFeatureCard
											icon={ category }
											title="Taxonomies & Structure"
										>
											Organize categories, tags, and
											custom taxonomies without navigating
											multiple admin screens.
										</OverviewFeatureCard>
										<OverviewFeatureCard
											icon={ comment }
											title="Comment Moderation"
										>
											Review, approve, reply to, or remove
											comments through controlled
											workflows.
										</OverviewFeatureCard>
										<OverviewFeatureCard
											icon={ media }
											title="Media Library Access"
										>
											Upload media, locate existing
											assets, and attach files to content
											when permitted.
										</OverviewFeatureCard>
										<OverviewFeatureCard
											icon={ info }
											title="Site Intelligence"
										>
											Retrieve plugin, theme, version, and
											configuration information for
											troubleshooting and audits.
										</OverviewFeatureCard>
										<OverviewFeatureCard
											icon={ settings }
											title="Ability Controls"
										>
											Choose exactly which actions each AI
											assistant can perform and revoke
											access at any time.
										</OverviewFeatureCard>
									</div>
								</section>

								<section className="aculect-ai-companion-control-banner">
									<span
										className="aculect-ai-companion-control-banner__icon"
										aria-hidden="true"
									>
										<Icon icon={ lock } size={ 22 } />
									</span>
									<div className="aculect-ai-companion-control-banner__content">
										<h2>
											WordPress remains the source of
											truth
										</h2>
										<p>
											Every action runs through WordPress
											permissions and capability checks.
											Administrators decide what
											assistants can access, what actions
											are allowed, and when connections
											should be revoked.
										</p>
									</div>
									<a
										className="aculect-ai-companion-control-banner__link"
										href={ tabUrl(
											'abilities',
											data.adminPageUrl
										) }
										onClick={ ( event ) =>
											maybeSelectTab( event, 'abilities' )
										}
									>
										<span>Manage Abilities</span>
										<Icon icon={ arrowRight } size={ 18 } />
									</a>
								</section>
							</div>
						);
					}

					if ( tab.name === 'connect' ) {
						return (
							<div className="aculect-ai-companion-connect">
								<div className="aculect-ai-companion-connect-step-row">
									<section className="aculect-ai-companion-connect-card aculect-ai-companion-connect-card--url">
										<ConnectStepHeading
											number="1"
											title="Copy the connection URL"
										>
											Copy the URL below and paste it into
											your AI assistant when prompted.
										</ConnectStepHeading>
										<div className="aculect-ai-companion-connect-url-panel">
											<CopyField
												label="Connection URL"
												value={ data.mcpUrl }
												onCopy={ ( value ) =>
													copyValue(
														value,
														'Connection URL copied.'
													)
												}
											/>
										</div>
										<div className="aculect-ai-companion-connect-secure-note">
											<Icon icon={ lock } size={ 16 } />
											<span>
												This link is unique to your site
												and can be used by approved AI
												assistants only.
											</span>
										</div>
									</section>
									<ConnectStatusPanel
										status={ connectionStatus }
										connectionsUrl={ tabUrl(
											'connections',
											data.adminPageUrl
										) }
										onNavigate={ ( event ) =>
											maybeSelectTab(
												event,
												'connections'
											)
										}
									/>
								</div>

								<section className="aculect-ai-companion-connect-card aculect-ai-companion-connect-card--providers">
									<ConnectStepHeading
										number="2"
										title="Add the connection in your AI assistant"
									>
										Choose your AI assistant and follow the
										simple steps to add the connection.
									</ConnectStepHeading>
									<div className="aculect-ai-companion-provider-list">
										{ providers.map( ( provider ) => (
											<ConnectProviderCard
												key={ provider.id }
												provider={ provider }
												isOpen={
													openProvider === provider.id
												}
												onToggle={ () =>
													setOpenProvider(
														openProvider ===
															provider.id
															? ''
															: provider.id
													)
												}
												onCopy={ copyValue }
											/>
										) ) }
									</div>

									<section className="aculect-ai-companion-connect-capabilities">
										<h2>What your AI assistant can do</h2>
										<p>
											These actions are always subject to
											your permissions and settings.
										</p>
										<div className="aculect-ai-companion-connect-capability-grid">
											<ConnectCapabilityCard
												icon={ postContent }
												title="Create & edit content"
											>
												Draft and update posts, pages,
												and more.
											</ConnectCapabilityCard>
											<ConnectCapabilityCard
												icon={ category }
												title="Manage content"
												tone="green"
											>
												Organize categories, tags, and
												media.
											</ConnectCapabilityCard>
											<ConnectCapabilityCard
												icon={ comment }
												title="Moderate comments"
												tone="purple"
											>
												Review, reply to, and manage
												comments.
											</ConnectCapabilityCard>
											<ConnectCapabilityCard
												icon={ shield }
												title="Secure by design"
												tone="orange"
											>
												You stay in control and can
												revoke access anytime.
											</ConnectCapabilityCard>
										</div>
									</section>
								</section>

								<section className="aculect-ai-companion-connect-card aculect-ai-companion-connect-card--approval">
									<ConnectStepHeading
										number="3"
										title="Approve the connection in WordPress"
									>
										When your AI assistant tries to connect,
										you will see a request here.
									</ConnectStepHeading>
									<div className="aculect-ai-companion-connect-request-panel">
										<span
											className="aculect-ai-companion-connect-request-panel__icon"
											aria-hidden="true"
										>
											<Icon icon={ lock } size={ 18 } />
										</span>
										<div>
											<strong>
												No connection requests yet
											</strong>
											<p>
												Requests from your AI assistant
												will appear here for your
												review.
											</p>
										</div>
										<Button
											href={ tabUrl(
												'connections',
												data.adminPageUrl
											) }
											variant="secondary"
											className="aculect-ai-companion-connect-request-panel__button"
											onClick={ ( event ) =>
												maybeSelectTab(
													event,
													'connections'
												)
											}
										>
											Review requests
										</Button>
									</div>
								</section>
							</div>
						);
					}

					if ( tab.name === 'connections' ) {
						return (
							<div className="aculect-ai-companion-connections-dashboard">
								{ shouldShowAccessControl && (
									<div
										className={ `aculect-ai-companion-lockdown aculect-ai-companion-lockdown--connections ${
											isAccessPaused ? 'is-paused' : ''
										}` }
									>
										<div className="aculect-ai-companion-lockdown__content">
											<strong>
												{ isAccessPaused
													? 'AI access is paused'
													: 'AI access is active' }
											</strong>
											<p>
												{ isAccessPaused
													? 'Connected assistants keep their sessions, but cannot run actions until access is resumed.'
													: 'Pause access to stop active assistants from running actions without disconnecting their sessions.' }
											</p>
										</div>
										<ActionForm
											data={ data }
											action={
												data.actions?.setLockdownAction
											}
											nonce={
												data.actions?.setLockdownNonce
											}
											label={
												isAccessPaused
													? 'Resume AI Access'
													: 'Pause AI Access'
											}
											destructive={ ! isAccessPaused }
											confirmTitle="Pause AI access"
											confirmMessage={
												isAccessPaused
													? ''
													: 'Pause AI access for all active assistant connections?'
											}
										>
											<input
												type="hidden"
												name="access_paused"
												value={
													isAccessPaused ? '0' : '1'
												}
											/>
										</ActionForm>
									</div>
								) }

								<div className="aculect-ai-companion-connections-layout">
									<section className="aculect-ai-companion-connections-main">
										<ConnectionsDataViews
											sessions={ connectionSessions }
											data={ data }
											isAccessPaused={ isAccessPaused }
											currentUserId={ currentUserId }
											abilities={ abilities }
											enabledAbilityIds={
												enabledAbilities
											}
										/>

										{ activeConnectionSessions.length >
											0 && (
											<div className="aculect-ai-companion-danger-zone aculect-ai-companion-danger-zone--connections">
												<ActionForm
													data={ data }
													action={
														data.actions
															?.revokeAllAction
													}
													nonce={
														data.actions
															?.revokeAllNonce
													}
													label="Disconnect All"
													destructive
													confirmTitle="Disconnect all assistants"
													confirmMessage="Disconnect all active assistants? Their active tokens and refresh tokens will be revoked."
												/>
											</div>
										) }
									</section>
									<ConnectionsSidePanels
										activityUrl={ tabUrl(
											'activity',
											data.adminPageUrl
										) }
										abilitiesUrl={ tabUrl(
											'abilities',
											data.adminPageUrl
										) }
										onActivity={ ( event ) =>
											maybeSelectTab( event, 'activity' )
										}
										onAbilities={ ( event ) =>
											maybeSelectTab( event, 'abilities' )
										}
									/>
								</div>
							</div>
						);
					}

					if ( tab.name === 'diagnostics' ) {
						return (
							<DiagnosticsDashboard
								data={ data }
								health={ connectionHealth }
								activeFilter={ diagnosticFilter }
								onFilterChange={ setDiagnosticFilter }
								isRunning={ diagnosticsRunning }
								onRun={ () => setDiagnosticsRunning( true ) }
								onCopy={ copyValue }
								helpLinks={ helpLinks }
							/>
						);
					}

					if ( tab.name === 'abilities' ) {
						return (
							<>
								<AbilityDashboard
									data={ data }
									abilities={ abilities }
									enabledAbilities={ enabledAbilities }
									wpAbilities={ wpAbilities }
									enabledWpAbilities={ enabledWpAbilities }
									confirmationGroups={ confirmationGroups }
									confirmationGroupOptions={
										confirmationGroupOptions
									}
									activeConnectionCount={ activeSessionCount }
									hasChanges={ hasAbilityChanges }
									onToggleAbility={ toggleAbility }
									onToggleWpAbility={ toggleWpAbility }
									onToggleConfirmationGroup={
										toggleConfirmationGroup
									}
									onEnableAll={ () => {
										setEnabledAbilities(
											abilities.map(
												( ability ) => ability.id
											)
										);
										setEnabledWpAbilities(
											wpAbilities.map(
												( ability ) => ability.id
											)
										);
									} }
									onDisableAll={ () => {
										setEnabledAbilities( [] );
										setEnabledWpAbilities( [] );
									} }
									onManageRoleAbilities={ () =>
										setRoleAbilitiesModalOpen( true )
									}
									onResetChanges={ () => {
										setEnabledAbilities(
											originalEnabledAbilities
										);
										setEnabledWpAbilities(
											originalEnabledWpAbilities
										);
										setConfirmationGroups(
											originalConfirmationGroups
										);
									} }
									onCopy={ copyValue }
								/>
								{ roleAbilitiesModalOpen && (
									<Modal
										title="Manage Role Based Abilities"
										className="aculect-ai-companion-role-abilities-modal"
										onRequestClose={ () =>
											setRoleAbilitiesModalOpen( false )
										}
										shouldCloseOnClickOutside={ false }
									>
										<RoleAbilitiesEditor
											data={ data }
											abilities={ abilities }
											roleAbilityPolicy={
												roleAbilityPolicy
											}
											selectedRole={
												selectedRoleAbilityRole
											}
											onSelectRole={
												setSelectedRoleAbilityRole
											}
											isModal
										/>
									</Modal>
								) }
							</>
						);
					}

					if ( tab.name === 'activity' ) {
						return (
							<div className="aculect-ai-companion-activity-dashboard">
								<div className="aculect-ai-companion-activity-control-banner">
									<span
										className="aculect-ai-companion-activity-control-banner__icon"
										aria-hidden="true"
									>
										<Icon icon={ shield } size={ 20 } />
									</span>
									<div>
										<strong>Sanitized audit log</strong>
										<p>
											Activity records show action
											metadata and safe context only. IP
											addresses, tokens, OAuth codes, and
											raw request bodies are not
											displayed.
										</p>
									</div>
								</div>
								<div className="aculect-ai-companion-activity-layout">
									<section className="aculect-ai-companion-activity-main">
										<form
											method="get"
											action="admin.php"
											className="aculect-ai-companion-activity-filters"
										>
											<input
												type="hidden"
												name="page"
												value="aculect-ai-companion"
											/>
											<input
												type="hidden"
												name="tab"
												value="activity"
											/>
											<SelectControl
												label="Range"
												name="activity_range"
												value={
													activityFilterValues.range
												}
												onChange={ setActivityFilterValue(
													'range'
												) }
												options={ [
													{
														value: '24h',
														label: '24 hours',
													},
													{
														value: '7d',
														label: '7 days',
													},
													{
														value: '30d',
														label: '30 days',
													},
													{
														value: '90d',
														label: '90 days',
													},
													{
														value: 'all',
														label: 'All time',
													},
												] }
											/>
											<SelectControl
												label="Status"
												name="activity_status"
												value={
													activityFilterValues.status
												}
												onChange={ setActivityFilterValue(
													'status'
												) }
												options={ [
													{ value: '', label: 'Any' },
													{
														value: 'success',
														label: 'Success',
													},
													{
														value: 'error',
														label: 'Failed',
													},
												] }
											/>
											<TextControl
												label="User ID"
												type="number"
												min="1"
												name="activity_user"
												value={
													activityFilterValues.user
												}
												onChange={ setActivityFilterValue(
													'user'
												) }
											/>
											<TextControl
												label="Assistant"
												name="activity_assistant"
												value={
													activityFilterValues.assistant
												}
												onChange={ setActivityFilterValue(
													'assistant'
												) }
											/>
											<TextControl
												label="Action Type"
												name="activity_action"
												value={
													activityFilterValues.action
												}
												onChange={ setActivityFilterValue(
													'action'
												) }
											/>
											<TextControl
												label="Search"
												type="search"
												name="activity_search"
												value={
													activityFilterValues.search
												}
												onChange={ setActivityFilterValue(
													'search'
												) }
											/>
											<div className="aculect-ai-companion-activity-filter-actions">
												<Button
													type="submit"
													variant="primary"
												>
													Filter
												</Button>
												<Button
													href={ tabUrl(
														'activity',
														data.adminPageUrl
													) }
													variant="secondary"
												>
													Reset
												</Button>
											</div>
										</form>
										<div className="aculect-ai-companion-activity-table-toolbar">
											<p>
												Showing page{ ' ' }
												{ activity.page || 1 } of{ ' ' }
												{ activity.totalPages || 1 }.
											</p>
											<strong>
												{ activity.total || 0 } total
												records
											</strong>
										</div>
										<ActivityTable activity={ activity } />
										<div className="aculect-ai-companion-log-pagination">
											<Button
												href={
													activity.prevUrl ||
													undefined
												}
												variant="secondary"
												disabled={ ! activity.prevUrl }
												accessibleWhenDisabled
											>
												Previous
											</Button>
											<Button
												href={
													activity.nextUrl ||
													undefined
												}
												variant="secondary"
												disabled={ ! activity.nextUrl }
												accessibleWhenDisabled
											>
												Next
											</Button>
										</div>
									</section>
									<ActivityInsights activity={ activity } />
								</div>
							</div>
						);
					}

					if ( tab.name === 'brand' ) {
						return (
							<Card className="aculect-ai-companion-card aculect-ai-companion-brand-card">
								<CardHeader>Brand Profile</CardHeader>
								<CardBody>
									<form
										method="post"
										action={ data.actions?.adminPostUrl }
										className="aculect-ai-companion-form aculect-ai-companion-form--brand"
									>
										<input
											type="hidden"
											name="action"
											value={
												data.actions?.saveBrandAction
											}
										/>
										<input
											type="hidden"
											name="_wpnonce"
											value={
												data.actions?.saveBrandNonce
											}
										/>
										<div className="aculect-ai-companion-brand-section">
											<h3 className="aculect-ai-companion-section-heading">
												Identity
											</h3>
											<div className="aculect-ai-companion-brand-grid">
												<BrandTextField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="site_name"
													label="Site name"
												/>
												<BrandTextField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="tagline"
													label="Tagline"
												/>
												<BrandTextField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="logo_url"
													label="Logo URL"
													type="url"
												/>
												<BrandTextField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="logo_preference"
													label="Logo preference"
												/>
											</div>
										</div>
										<div className="aculect-ai-companion-brand-section">
											<h3 className="aculect-ai-companion-section-heading">
												Colors
											</h3>
											<div className="aculect-ai-companion-brand-grid aculect-ai-companion-brand-grid--colors">
												<BrandTextField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="primary_color"
													label="Primary color"
													color
												/>
												<BrandTextField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="secondary_color"
													label="Secondary color"
													color
												/>
												<BrandTextField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="accent_color"
													label="Accent color"
													color
												/>
											</div>
										</div>
										<div className="aculect-ai-companion-brand-section">
											<h3 className="aculect-ai-companion-section-heading">
												Editorial Guidance
											</h3>
											<div className="aculect-ai-companion-brand-grid">
												<BrandTextareaField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="image_style"
													label="Image style"
												/>
												<BrandTextareaField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="typography_notes"
													label="Typography notes"
												/>
												<BrandTextareaField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="tone"
													label="Tone"
												/>
												<BrandTextareaField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="audience"
													label="Audience"
												/>
												<BrandTextareaField
													fields={ brandFields }
													defaults={ brandDefaults }
													name="avoid"
													label="Avoid"
												/>
											</div>
										</div>
										<Button type="submit" variant="primary">
											Save Brand Profile
										</Button>
									</form>
								</CardBody>
							</Card>
						);
					}

					if ( tab.name === 'advanced' ) {
						return (
							<AdvancedDashboard
								data={ data }
								diagnostics={ diagnostics }
								roleConnections={ roleConnections }
								loggingEnabled={ loggingEnabled }
								onLoggingChange={ setLoggingEnabled }
								roleConnectionsEnabled={
									roleConnectionsEnabled
								}
								onRoleConnectionsChange={
									setRoleConnectionsEnabled
								}
								roleConnectionRoles={ roleConnectionRoles }
								onToggleRole={ toggleRoleConnectionRole }
								enabledAbilities={ enabledAbilities }
								enabledWpAbilities={ enabledWpAbilities }
							/>
						);
					}

					if ( tab.name === 'logs' ) {
						return (
							<Card className="aculect-ai-companion-card aculect-ai-companion-logs-card">
								<CardHeader>Diagnostic Logs</CardHeader>
								<CardBody>
									<div className="aculect-ai-companion-log-toolbar">
										<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
											Showing page { logs.page || 1 } of{ ' ' }
											{ logs.totalPages || 1 }. Logs are
											pruned after{ ' ' }
											{ diagnostics.retentionDays || 30 }{ ' ' }
											days.
										</p>
										<ActionForm
											data={ data }
											action={
												data.actions?.clearLogsAction
											}
											nonce={
												data.actions?.clearLogsNonce
											}
											label="Clear Logs"
											destructive
											confirmTitle="Clear diagnostic logs"
											confirmMessage="Clear diagnostic logs? This cannot be undone."
										/>
									</div>
									<LogsTable logs={ logs } />
									<div className="aculect-ai-companion-log-pagination">
										<Button
											href={ logs.prevUrl || undefined }
											variant="secondary"
											disabled={ ! logs.prevUrl }
											accessibleWhenDisabled
										>
											Previous
										</Button>
										<Button
											href={ logs.nextUrl || undefined }
											variant="secondary"
											disabled={ ! logs.nextUrl }
											accessibleWhenDisabled
										>
											Next
										</Button>
									</div>
								</CardBody>
							</Card>
						);
					}

					if ( tab.name === 'changelog' ) {
						return (
							<ChangelogDashboard
								changelog={ data.changelog }
								metadata={ pluginMetadata }
							/>
						);
					}

					return null;
				} )() }
			</main>
		</div>
	);
}

const root = document.getElementById(
	'aculect-ai-companion-settings-app-root'
);
if ( root ) {
	render( <SettingsApp />, root );
}
