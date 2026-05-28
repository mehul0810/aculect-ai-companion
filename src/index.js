import { render, useEffect, useRef, useState } from '@wordpress/element';
import './style.scss';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import {
	Icon,
	category,
	chartBar,
	check,
	cog,
	comment,
	copy,
	desktop,
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
	seen,
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
const TAB_ALIASES = {
	about: 'overview',
	connectors: 'connect',
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

function normalizeTabName( tabName ) {
	return TAB_ALIASES[ tabName ] || tabName;
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
			? 'admin.php?page=aculect-ai-companion'
			: `admin.php?page=aculect-ai-companion&tab=${ normalizedTabName }`;
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

function tabSlug( tabName ) {
	return tabName === 'overview'
		? 'aculect-ai-companion'
		: `aculect-ai-companion&tab=${ tabName }`;
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

function updateAdminSubmenuSelection( tabName ) {
	const submenu = document.querySelector(
		'#toplevel_page_aculect-ai-companion .wp-submenu'
	);
	if ( ! submenu ) {
		return;
	}

	const activeSlug = tabSlug( tabName );
	submenu.querySelectorAll( 'li' ).forEach( ( item ) => {
		const linkNode = item.querySelector( 'a[href]' );
		const href = linkNode?.getAttribute( 'href' ) || '';
		let isActive =
			href.includes( `page=${ activeSlug }` ) ||
			href.includes( `page=${ encodeURIComponent( activeSlug ) }` );

		try {
			const url = new URL( href, window.location.href );
			const urlTab = normalizeTabName(
				url.searchParams.get( TAB_QUERY_PARAM ) || 'overview'
			);
			isActive =
				url.searchParams.get( 'page' ) === 'aculect-ai-companion' &&
				urlTab === tabName;
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

	return (
		<div className="aculect-ai-companion-copy-field">
			<label
				className="aculect-ai-companion-copy-field__label"
				htmlFor={ inputId.current }
			>
				{ label }
			</label>
			<div className="aculect-ai-companion-copy-field__control">
				<input
					id={ inputId.current }
					className="aculect-ai-companion-copy-field__input"
					type={ secret ? 'password' : 'text' }
					value={ String( value || '' ) }
					readOnly
					aria-label={ label }
				/>
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

function EmptyState( { title, children } ) {
	return (
		<div className="aculect-ai-companion-empty-state">
			<strong>{ title }</strong>
			{ children && <p>{ children }</p> }
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
} ) {
	return (
		<form
			method="post"
			action={ data.actions?.adminPostUrl }
			className="aculect-ai-companion-action-form"
		>
			<input type="hidden" name="action" value={ action } />
			<input type="hidden" name="_wpnonce" value={ nonce } />
			{ children }
			<Button
				type="submit"
				variant={ destructive ? 'secondary' : 'primary' }
				isDestructive={ destructive }
			>
				{ label }
			</Button>
		</form>
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
					defaultValue={ value }
				/>
			</div>
			<BrandDefaultValue value={ defaultValue } color={ color } />
		</div>
	);
}

function BrandTextareaField( { fields, defaults, name, label } ) {
	return (
		<div className="aculect-ai-companion-brand-field">
			<TextareaControl
				label={ label }
				name={ `brand_profile[${ name }]` }
				defaultValue={ brandFieldValue( fields, name ) }
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
		<div className="aculect-ai-companion-log-table-wrap">
			<table className="widefat striped aculect-ai-companion-log-table">
				<thead>
					<tr>
						<th>Time</th>
						<th>Status</th>
						<th>Action</th>
						<th>Assistant</th>
						<th>User</th>
						<th>Target</th>
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
									className={ `aculect-ai-companion-activity-status is-${ item.status }` }
								>
									{ item.status || 'success' }
								</span>
							</td>
							<td>
								<code>{ item.action }</code>
							</td>
							<td>
								{ item.client_name ||
									item.provider ||
									item.client_id ||
									'-' }
							</td>
							<td>{ item.user_id || '-' }</td>
							<td>
								{ item.target_type || '-' }
								{ item.target_id
									? ` #${ item.target_id }`
									: '' }
							</td>
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

function ActivitySummary( { summary } ) {
	const data = summary && typeof summary === 'object' ? summary : {};
	const items = [
		{
			label: 'Actions',
			value: data.total || 0,
		},
		{
			label: 'Failures',
			value: data.failures || 0,
			tone: data.failures > 0 ? 'is-error' : '',
		},
		{
			label: 'Assistants',
			value: data.assistants || 0,
		},
		{
			label: 'High risk',
			value: data.highRisk || 0,
			tone: data.highRisk > 0 ? 'is-warning' : '',
		},
	];

	return (
		<div className="aculect-ai-companion-activity-summary">
			{ items.map( ( item ) => (
				<div
					key={ item.label }
					className={ `aculect-ai-companion-activity-summary-item ${
						item.tone || ''
					}` }
				>
					<span>{ item.label }</span>
					<strong>{ item.value }</strong>
				</div>
			) ) }
		</div>
	);
}

function StatusBadge( { status } ) {
	const normalizedStatus = [ 'pass', 'warn', 'fail' ].includes( status )
		? status
		: 'warn';
	const labels = {
		pass: 'Pass',
		warn: 'Warning',
		fail: 'Fail',
	};

	return (
		<span
			className={ `aculect-ai-companion-health-status is-${ normalizedStatus }` }
		>
			{ labels[ normalizedStatus ] }
		</span>
	);
}

function ConnectionHealthChecks( { health } ) {
	const items = Array.isArray( health?.items ) ? health.items : [];

	if ( items.length === 0 ) {
		return (
			<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
				Run diagnostics to check whether your connection URL,
				authorization metadata, and approval screen are reachable.
			</p>
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
					{ items.map( ( item ) => (
						<tr key={ item.id }>
							<td>
								<code>{ item.id }</code>
							</td>
							<td>
								<StatusBadge status={ item.status } />
							</td>
							<td>{ item.message || '-' }</td>
							<td>{ item.remediation || '-' }</td>
							<td>
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

function ConnectFlowGraphic( { brandIconUrl } ) {
	return (
		<div className="aculect-ai-companion-connect-flow" aria-hidden="true">
			<div className="aculect-ai-companion-connect-flow__node is-assistant">
				<Icon icon={ desktop } size={ 28 } />
			</div>
			<span className="aculect-ai-companion-connect-flow__path" />
			<div className="aculect-ai-companion-connect-flow__node is-site">
				{ brandIconUrl ? (
					<img src={ brandIconUrl } alt="" aria-hidden="true" />
				) : (
					<Icon icon={ link } size={ 28 } />
				) }
			</div>
			<span className="aculect-ai-companion-connect-flow__path is-muted" />
			<div className="aculect-ai-companion-connect-flow__node is-wordpress">
				<Icon icon={ globe } size={ 28 } />
			</div>
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
			'Copy the connection URL into an AI assistant, then approve the OAuth consent screen in WordPress.',
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

function ConnectStatusPanel( { status } ) {
	return (
		<div
			className={ `aculect-ai-companion-side-panel aculect-ai-companion-connection-status ${ status.tone }` }
		>
			<span className="aculect-ai-companion-side-panel__icon">
				<Icon icon={ seen } size={ 20 } />
			</span>
			<div>
				<h3>{ status.title }</h3>
				<p>{ status.description }</p>
				<strong>{ status.meta }</strong>
			</div>
		</div>
	);
}

function RequirementsPanel( { health, diagnosticsUrl, onNavigate } ) {
	const items = Array.isArray( health?.items ) ? health.items : [];

	return (
		<div className="aculect-ai-companion-side-panel">
			<div className="aculect-ai-companion-side-panel__heading">
				<span className="aculect-ai-companion-side-panel__icon">
					<Icon icon={ check } size={ 20 } />
				</span>
				<h3>Connection requirements</h3>
			</div>
			{ items.length > 0 ? (
				<ul className="aculect-ai-companion-requirements-list">
					{ items.map( ( item ) => (
						<li key={ item.id }>
							<StatusBadge status={ item.status } />
							<span>{ item.message || item.id }</span>
						</li>
					) ) }
				</ul>
			) : (
				<div className="aculect-ai-companion-requirement-empty">
					<span className="aculect-ai-companion-health-status is-unavailable">
						Unavailable
					</span>
					<p>
						Run diagnostics to verify HTTPS, discovery metadata, and
						the MCP authorization challenge.
					</p>
				</div>
			) }
			<a
				className="aculect-ai-companion-side-panel__link"
				href={ diagnosticsUrl }
				onClick={ onNavigate }
			>
				Review diagnostics
			</a>
		</div>
	);
}

function HelpLinksPanel( { links } ) {
	if ( links.length === 0 ) {
		return null;
	}

	return (
		<div className="aculect-ai-companion-side-panel">
			<div className="aculect-ai-companion-side-panel__heading">
				<span className="aculect-ai-companion-side-panel__icon">
					<Icon icon={ help } size={ 20 } />
				</span>
				<h3>Setup links</h3>
			</div>
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
		</div>
	);
}

function normalizeConnectionSession( session, status ) {
	return {
		...session,
		status: session.status || status,
	};
}

function connectionUserCount( sessions ) {
	return new Set(
		sessions
			.map( ( session ) => Number( session.user_id || 0 ) )
			.filter( ( userId ) => userId > 0 )
	).size;
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

function connectionSearchHaystack( session, abilities, enabledAbilityIds ) {
	return [
		session.client_name,
		session.client_id,
		session.provider,
		session.user,
		session.resource,
		session.status,
		...( Array.isArray( session.user_roles ) ? session.user_roles : [] ),
		...( Array.isArray( session.scopes ) ? session.scopes : [] ),
		...connectionAbilityLabels( session, abilities, enabledAbilityIds ),
	]
		.join( ' ' )
		.toLowerCase();
}

function isCurrentUserSession( session, currentUserId ) {
	return (
		Number( currentUserId || 0 ) > 0 &&
		Number( session.user_id || 0 ) === Number( currentUserId || 0 )
	);
}

function connectionMatchesFilter(
	session,
	filter,
	isAccessPaused,
	currentUserId
) {
	const status = session.status || 'active';

	if ( filter === 'active' ) {
		return status === 'active' && ! isAccessPaused;
	}

	if ( filter === 'paused' ) {
		return status === 'active' && isAccessPaused;
	}

	if ( filter === 'revoked' ) {
		return status === 'revoked';
	}

	if ( filter === 'pending' ) {
		return false;
	}

	if ( filter === 'my' ) {
		return (
			status === 'active' &&
			isCurrentUserSession( session, currentUserId )
		);
	}

	if ( filter === 'team' ) {
		return (
			status === 'active' &&
			Number( currentUserId || 0 ) > 0 &&
			Number( session.user_id || 0 ) > 0 &&
			! isCurrentUserSession( session, currentUserId )
		);
	}

	return true;
}

function connectionFilters(
	connectionSessions,
	activeSessions,
	revokedSessions,
	isAccessPaused,
	currentUserId
) {
	const myCount = activeSessions.filter( ( session ) =>
		isCurrentUserSession( session, currentUserId )
	).length;
	const teamCount = activeSessions.filter(
		( session ) =>
			Number( currentUserId || 0 ) > 0 &&
			Number( session.user_id || 0 ) > 0 &&
			! isCurrentUserSession( session, currentUserId )
	).length;

	return [
		{
			name: 'all',
			label: 'All',
			count: connectionSessions.length,
		},
		{
			name: 'active',
			label: 'Active',
			count: isAccessPaused ? 0 : activeSessions.length,
		},
		{
			name: 'my',
			label: 'My',
			count: myCount,
		},
		{
			name: 'team',
			label: 'Team',
			count: teamCount,
		},
		{
			name: 'paused',
			label: 'Paused',
			count: isAccessPaused ? activeSessions.length : 0,
		},
		{
			name: 'pending',
			label: 'Pending',
			count: 0,
		},
		{
			name: 'revoked',
			label: 'Revoked',
			count: revokedSessions.length,
		},
	];
}

function filterConnectionSessions( {
	connectionSessions,
	filter,
	searchTerm,
	isAccessPaused,
	currentUserId,
	abilities,
	enabledAbilityIds,
} ) {
	const normalizedSearch = String( searchTerm || '' )
		.trim()
		.toLowerCase();

	return connectionSessions.filter( ( session ) => {
		if (
			! connectionMatchesFilter(
				session,
				filter,
				isAccessPaused,
				currentUserId
			)
		) {
			return false;
		}

		if ( ! normalizedSearch ) {
			return true;
		}

		return connectionSearchHaystack(
			session,
			abilities,
			enabledAbilityIds
		).includes( normalizedSearch );
	} );
}

function ConnectionMetricCard( {
	icon,
	label,
	value,
	description,
	tone = '',
} ) {
	return (
		<div className={ `aculect-ai-companion-connection-metric ${ tone }` }>
			<span
				className="aculect-ai-companion-connection-metric__icon"
				aria-hidden="true"
			>
				<Icon icon={ icon } size={ 18 } />
			</span>
			<div>
				<span>{ label }</span>
				<strong>{ value }</strong>
				<p>{ description }</p>
			</div>
		</div>
	);
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

function connectionEmptyStateCopy( filter, hasSearch, hasConnections ) {
	if ( hasSearch ) {
		return {
			title: 'No matching connections',
			copy: 'Try a different assistant, WordPress user, role, ability, or provider search.',
		};
	}

	if ( filter === 'pending' ) {
		return {
			title: 'No pending requests',
			copy: 'Approval happens during the OAuth consent screen. There are no pending approval requests stored for this site.',
		};
	}

	if ( filter === 'revoked' ) {
		return {
			title: 'No revoked sessions',
			copy: 'Disconnected sessions that are still retained by the token store will appear here.',
		};
	}

	if ( filter === 'paused' ) {
		return {
			title: 'AI access is not paused',
			copy: 'Use the pause control when you need to stop active assistants without disconnecting them.',
		};
	}

	if ( filter === 'my' ) {
		return {
			title: 'No connections for your user',
			copy: 'Connections approved by your WordPress account will appear here.',
		};
	}

	if ( filter === 'team' ) {
		return {
			title: 'No team connections',
			copy: 'Connections approved by other WordPress users will appear here when available.',
		};
	}

	return {
		title: hasConnections
			? 'No connections in this state'
			: 'No connections',
		copy: 'Add the connection URL to an AI assistant, then approve the connection in WordPress.',
	};
}

function ConnectionsTable( {
	sessions: connectionSessions,
	data,
	filter,
	searchTerm,
	isAccessPaused,
	abilities,
	enabledAbilityIds,
	hasConnections,
} ) {
	if ( connectionSessions.length === 0 ) {
		const emptyState = connectionEmptyStateCopy(
			filter,
			Boolean( String( searchTerm || '' ).trim() ),
			hasConnections
		);

		return (
			<EmptyState title={ emptyState.title }>
				{ emptyState.copy }
			</EmptyState>
		);
	}

	return (
		<div className="aculect-ai-companion-connections-table-wrap">
			<table className="widefat striped aculect-ai-companion-connections-table">
				<thead>
					<tr>
						<th>Assistant</th>
						<th>WordPress User</th>
						<th>Role</th>
						<th>Granted Abilities</th>
						<th>Last Activity</th>
						<th>Status</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					{ connectionSessions.map( ( session ) => (
						<tr key={ `${ session.status }-${ session.id }` }>
							<td>
								<strong className="aculect-ai-companion-connections-table__primary">
									{ session.client_name || 'AI Assistant' }
								</strong>
								<span className="aculect-ai-companion-connections-table__secondary">
									{ session.provider || 'mcp' }
								</span>
							</td>
							<td>
								<strong className="aculect-ai-companion-connections-table__primary">
									{ session.user || 'Unknown user' }
								</strong>
								<span className="aculect-ai-companion-connections-table__secondary">
									{ session.resource || 'Default resource' }
								</span>
							</td>
							<td>
								{ Array.isArray( session.user_roles ) &&
								session.user_roles.length > 0
									? session.user_roles.join( ', ' )
									: 'Unknown' }
							</td>
							<td>
								<ConnectionAbilityChips
									session={ session }
									abilities={ abilities }
									enabledAbilityIds={ enabledAbilityIds }
								/>
							</td>
							<td>
								<strong className="aculect-ai-companion-connections-table__primary">
									{ connectionDateValue(
										session.last_used_at
									) }
								</strong>
								<span className="aculect-ai-companion-connections-table__secondary">
									Created{ ' ' }
									{ connectionDateValue(
										session.created_at,
										'Unknown'
									) }
								</span>
							</td>
							<td>
								<ConnectionStatusChip
									session={ session }
									isAccessPaused={ isAccessPaused }
								/>
								<span className="aculect-ai-companion-connections-table__secondary">
									Expires{ ' ' }
									{ connectionDateValue(
										session.expires_at,
										'Unknown'
									) }
								</span>
							</td>
							<td>
								<ConnectionActionsMenu
									session={ session }
									data={ data }
								/>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
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

function AdvancedStatusCard( { icon, label, value, description, tone = '' } ) {
	return (
		<div className={ `aculect-ai-companion-advanced-status ${ tone }` }>
			<span
				className="aculect-ai-companion-advanced-status__icon"
				aria-hidden="true"
			>
				<Icon icon={ icon } size={ 18 } />
			</span>
			<div>
				<span className="aculect-ai-companion-advanced-status__label">
					{ label }
				</span>
				<strong className="aculect-ai-companion-advanced-status__value">
					{ value }
				</strong>
				<p className="aculect-ai-companion-advanced-status__description">
					{ description }
				</p>
			</div>
		</div>
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
	disabled = false,
} ) {
	return (
		<div
			className={ `aculect-ai-companion-advanced-setting ${
				disabled ? 'is-disabled' : ''
			}` }
		>
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

function AdvancedDisabledToggle( { label, checked = false } ) {
	return (
		<ToggleControl
			label={ label }
			checked={ checked }
			disabled
			onChange={ () => {} }
		/>
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

function AdvancedIntegrationList( { roleConnections } ) {
	const integrations = [
		{ label: 'Shortcode', value: roleConnections.shortcode },
		{ label: 'Block', value: roleConnections.blockName },
		{ label: 'PHP function', value: roleConnections.functionName },
	];

	return (
		<div className="aculect-ai-companion-advanced-integration-list">
			{ integrations.map( ( item ) => (
				<div
					key={ item.label }
					className="aculect-ai-companion-advanced-integration-list__item"
				>
					<span className="aculect-ai-companion-advanced-integration-list__label">
						{ item.label }
					</span>
					<code className="aculect-ai-companion-advanced-integration-list__value">
						{ item.value || 'Unavailable' }
					</code>
				</div>
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
	abilities,
	enabledAbilities,
	wpAbilities,
	enabledWpAbilities,
	onCopy,
} ) {
	const retentionDays = diagnostics.retentionDays || 30;
	const availableAbilityCount = abilities.length + wpAbilities.length;
	const enabledAbilityCount =
		enabledAbilities.length + enabledWpAbilities.length;
	const selectedRoleCount = roleConnectionRoles.length;
	const abilitiesUrl = tabUrl( 'abilities', data.adminPageUrl );
	const diagnosticsUrl = tabUrl( 'diagnostics', data.adminPageUrl );

	return (
		<form
			method="post"
			action={ data.actions?.adminPostUrl }
			className="aculect-ai-companion-form aculect-ai-companion-form--advanced-dashboard"
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

			<section className="aculect-ai-companion-advanced-hero">
				<div>
					<span className="aculect-ai-companion-eyebrow">
						Advanced settings
					</span>
					<h2 className="aculect-ai-companion-advanced-hero__title">
						Security, permissions, and developer controls
					</h2>
					<p className="aculect-ai-companion-advanced-hero__copy">
						Manage the advanced settings backed by WordPress
						options. Controls that need new server behavior are
						locked until that behavior exists.
					</p>
				</div>
				<Button type="submit" variant="primary">
					Save Advanced Settings
				</Button>
			</section>

			<div className="aculect-ai-companion-advanced-status-grid">
				<AdvancedStatusCard
					icon={ chartBar }
					label="Diagnostic logging"
					value={ loggingEnabled ? 'On' : 'Off' }
					description="Sanitized OAuth and MCP events"
					tone={ loggingEnabled ? 'is-active' : '' }
				/>
				<AdvancedStatusCard
					icon={ people }
					label="Role entry points"
					value={ roleConnectionsEnabled ? 'On' : 'Off' }
					description={ `${ selectedRoleCount } allowed role${
						selectedRoleCount === 1 ? '' : 's'
					}` }
					tone={ roleConnectionsEnabled ? 'is-active' : '' }
				/>
				<AdvancedStatusCard
					icon={ plugins }
					label="Enabled abilities"
					value={ `${ enabledAbilityCount }/${ availableAbilityCount }` }
					description="MCP and WordPress ability policy"
				/>
				<AdvancedStatusCard
					icon={ lock }
					label="Audit retention"
					value={ `${ retentionDays } days` }
					description="Diagnostic log pruning window"
				/>
			</div>

			<div className="aculect-ai-companion-advanced-layout">
				<div className="aculect-ai-companion-advanced-main">
					<AdvancedSection
						icon={ shield }
						title="Security and access"
						description="Connection approval, session lifetime, and user-facing entry points."
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
							title="Session timeout"
							description="Access tokens expire after 1 hour. Refresh tokens expire after 30 days unless the session is revoked first."
							status="Fixed"
						/>
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
						description="Default and future custom ability boundaries for connected assistants."
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
						<AdvancedSettingRow
							title="Custom capability sets"
							description="Multiple named ability profiles are not available until server-side policy storage exists."
							status="Not available"
							disabled
						>
							<Button type="button" variant="secondary" disabled>
								Create Set
							</Button>
						</AdvancedSettingRow>
					</AdvancedSection>

					<AdvancedSection
						icon={ globe }
						title="Integrations"
						description="Endpoint configuration and integration entry points."
					>
						<AdvancedSettingRow
							title="MCP endpoint"
							description="Copy the generated endpoint used by supported AI assistants for OAuth discovery and MCP requests."
							status="Generated"
						>
							<CopyField
								label="MCP endpoint"
								value={ data.mcpUrl }
								onCopy={ ( value ) =>
									onCopy( value, 'MCP endpoint copied.' )
								}
							/>
						</AdvancedSettingRow>
						<AdvancedSettingRow
							title="Connection entry points"
							description="Use these entry points when role-based connection sharing is enabled."
							status={
								roleConnectionsEnabled ? 'Available' : 'Off'
							}
						>
							<AdvancedIntegrationList
								roleConnections={ roleConnections }
							/>
						</AdvancedSettingRow>
						<AdvancedSettingRow
							title="Webhook URL"
							description="Incoming webhook configuration is not active in 0.5.0 and has no server-side handler yet."
							status="Not available"
							disabled
						>
							<TextControl
								label="Webhook URL"
								value="Not configured"
								disabled
								__next40pxDefaultSize
								onChange={ () => {} }
							/>
						</AdvancedSettingRow>
					</AdvancedSection>
				</div>

				<aside className="aculect-ai-companion-advanced-sidebar">
					<AdvancedSection
						icon={ settings }
						title="Performance"
						description="Runtime controls that are fixed in this release."
					>
						<AdvancedSettingRow
							title="Background processing"
							description="OAuth and diagnostic storage pruning already run through bounded maintenance tasks."
							status="Automatic"
						/>
						<AdvancedSettingRow
							title="Rate limiting"
							description="No configurable rate limit option exists in 0.5.0."
							status="Not available"
							disabled
						>
							<AdvancedDisabledToggle label="Enable rate limiting" />
						</AdvancedSettingRow>
						<AdvancedSettingRow
							title="Caching controls"
							description="Authorization checks read current token state directly so revocation remains immediate."
							status="Fixed"
						/>
					</AdvancedSection>

					<AdvancedSection
						icon={ lock }
						title="Data and privacy"
						description="What the plugin stores and exposes to support workflows."
					>
						<AdvancedSettingRow
							title="Data sharing"
							description="The plugin does not add a separate data-sharing toggle in 0.5.0."
							status="No option"
						/>
						<AdvancedSettingRow
							title="Encrypted communication"
							description="Hosted assistants require a public HTTPS endpoint; OAuth tokens and signing keys remain server-side."
							status="Protocol enforced"
						/>
						<AdvancedSettingRow
							title="Anonymization"
							description="A configurable anonymization mode is not available until log redaction policy is specified."
							status="Not available"
							disabled
						>
							<AdvancedDisabledToggle label="Anonymize logs" />
						</AdvancedSettingRow>
					</AdvancedSection>

					<AdvancedSection
						icon={ cog }
						title="Developer"
						description="Diagnostics and maintenance controls for troubleshooting."
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
						<AdvancedSettingRow
							title="Log level"
							description="The current logger records sanitized lifecycle events without a configurable verbosity level."
							status="Fixed"
						/>
						<AdvancedSettingRow
							title="Export settings"
							description="Disabled until a sanitized export endpoint exists that excludes secrets, salts, OAuth keys, tokens, and private payloads."
							status="Not available"
							disabled
						>
							<Button type="button" variant="secondary" disabled>
								Export Settings
							</Button>
						</AdvancedSettingRow>
						<AdvancedSettingRow
							title="Reset settings"
							description="Disabled until a confirmed, capability-checked reset action exists."
							status="Not available"
							statusTone="is-danger"
							disabled
						>
							<Button
								type="button"
								variant="secondary"
								isDestructive
								disabled
							>
								Reset Settings
							</Button>
						</AdvancedSettingRow>
					</AdvancedSection>
				</aside>
			</div>
		</form>
	);
}

function ConnectProviderCard( { provider, isOpen, onToggle, onCopy } ) {
	const setupSections = Array.isArray( provider.setupSections )
		? provider.setupSections
		: [];
	const panelId = `aculect-ai-companion-provider-panel-${ provider.id }`;

	return (
		<Card
			key={ provider.id }
			className={ `aculect-ai-companion-card aculect-ai-companion-provider-card ${
				isOpen ? 'is-open' : ''
			}` }
		>
			<CardBody>
				<div className="aculect-ai-companion-provider-card__header">
					<div className="aculect-ai-companion-provider-card__title-wrap">
						<h3 className="aculect-ai-companion-provider-card__title">
							{ provider.label }
						</h3>
						<p className="aculect-ai-companion-provider-card__description">
							{ provider.description }
						</p>
					</div>
					<Button
						variant="secondary"
						onClick={ onToggle }
						aria-expanded={ isOpen }
						aria-controls={ panelId }
					>
						{ isOpen ? 'Hide setup' : 'Show setup' }
					</Button>
				</div>

				{ isOpen && (
					<div
						id={ panelId }
						className="aculect-ai-companion-provider-panel"
					>
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
					</div>
				) }
			</CardBody>
		</Card>
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
			<h3 className="aculect-ai-companion-feature-card__title">
				{ title }
			</h3>
			<p className="aculect-ai-companion-feature-card__copy">
				{ children }
			</p>
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
} ) {
	const roles = Array.isArray( roleAbilityPolicy.roles )
		? roleAbilityPolicy.roles
		: [];
	const globalEnabledIds = Array.isArray( roleAbilityPolicy.globalEnabledIds )
		? roleAbilityPolicy.globalEnabledIds
		: [];
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
	}, [ activeRole?.id ] );

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
			className="aculect-ai-companion-role-abilities"
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
					<label htmlFor="aculect-ai-companion-role-selector">
						<span>Role</span>
						<select
							id="aculect-ai-companion-role-selector"
							value={ activeRole.id }
							onChange={ ( event ) =>
								onSelectRole( event.target.value )
							}
						>
							{ roles.map( ( role ) => (
								<option key={ role.id } value={ role.id }>
									{ role.label }
								</option>
							) ) }
						</select>
					</label>
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
							<TextControl
								label="Search role abilities"
								value={ search }
								placeholder="Name, key, scope, or description"
								onChange={ setSearch }
							/>
							<label htmlFor="aculect-ai-companion-role-ability-category">
								<span>Category</span>
								<select
									id="aculect-ai-companion-role-ability-category"
									value={ categoryFilter }
									onChange={ ( event ) =>
										setCategoryFilter( event.target.value )
									}
								>
									<option value="all">All categories</option>
									{ categories.map( ( group ) => (
										<option key={ group } value={ group }>
											{ group }
										</option>
									) ) }
								</select>
							</label>
							<label htmlFor="aculect-ai-companion-role-ability-status">
								<span>Status</span>
								<select
									id="aculect-ai-companion-role-ability-status"
									value={ statusFilter }
									onChange={ ( event ) =>
										setStatusFilter( event.target.value )
									}
								>
									<option value="all">All states</option>
									<option value="enabled">Enabled</option>
									<option value="disabled">Disabled</option>
								</select>
							</label>
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
						<form
							method="post"
							action={ data.actions?.adminPostUrl }
							onSubmit={ () => {
								// eslint-disable-next-line no-alert
								return window.confirm(
									'Reset this role to the global ability policy?'
								);
							} }
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
								value="reset"
							/>
							<input
								type="hidden"
								name="role_ability_role"
								value={ activeRole.id }
							/>
							<Button type="submit" variant="secondary">
								Reset to default
							</Button>
						</form>
						<form
							method="post"
							action={ data.actions?.adminPostUrl }
							onSubmit={ () => {
								// eslint-disable-next-line no-alert
								return window.confirm(
									'Copy ability policy from the selected role?'
								);
							} }
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
								value="copy"
							/>
							<input
								type="hidden"
								name="role_ability_role"
								value={ activeRole.id }
							/>
							<label htmlFor="aculect-ai-companion-copy-role">
								<span className="aculect-ai-companion-role-abilities__side-label">
									Copy from
								</span>
								<select
									id="aculect-ai-companion-copy-role"
									name="copy_from_role"
									value={ copyFromRole }
									onChange={ ( event ) =>
										setCopyFromRole( event.target.value )
									}
								>
									{ roles
										.filter(
											( role ) =>
												role.id !== activeRole.id
										)
										.map( ( role ) => (
											<option
												key={ role.id }
												value={ role.id }
											>
												{ role.label }
											</option>
										) ) }
								</select>
							</label>
							<Button
								type="submit"
								variant="secondary"
								disabled={ ! copyFromRole }
							>
								Copy from role
							</Button>
						</form>
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

function SettingsApp() {
	const data = window.aculectAICompanionSettingsData || {};
	const brandIconUrl = data.brandIconUrl || '';
	const providers = Array.isArray( data.providers ) ? data.providers : [];
	const sessions = Array.isArray( data.sessions ) ? data.sessions : [];
	const revokedSessions = Array.isArray( data.revokedSessions )
		? data.revokedSessions
		: [];
	const abilities = Array.isArray( data.abilities ) ? data.abilities : [];
	const wpAbilities = Array.isArray( data.wpAbilities )
		? data.wpAbilities
		: [];
	const confirmationGroupOptions = Array.isArray(
		data.confirmationGroupOptions
	)
		? data.confirmationGroupOptions
		: [];
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
	const [ copied, setCopied ] = useState( '' );
	const [ openProvider, setOpenProvider ] = useState(
		providers[ 0 ]?.id || 'claude'
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
		Array.isArray( data.enabledAbilities ) ? data.enabledAbilities : []
	);
	const [ confirmationGroups, setConfirmationGroups ] = useState(
		Array.isArray( data.confirmationGroups ) ? data.confirmationGroups : []
	);
	const [ enabledWpAbilities, setEnabledWpAbilities ] = useState(
		Array.isArray( data.enabledWpAbilities ) ? data.enabledWpAbilities : []
	);
	const [ selectedRoleAbilityRole, setSelectedRoleAbilityRole ] = useState(
		Array.isArray( roleAbilityPolicy.roles )
			? roleAbilityPolicy.roles[ 0 ]?.id || ''
			: ''
	);
	const [ connectionFilter, setConnectionFilter ] = useState( 'all' );
	const [ connectionSearch, setConnectionSearch ] = useState( '' );
	const adminNoticesRef = useRef( null );
	const copyTimeoutRef = useRef( null );
	const isAccessPaused = Boolean( data.accessPaused );
	const hasActiveSessions = sessions.length > 0;
	const currentUserId = Number( data.currentUserId || 0 );
	const activeConnectionSessions = sessions.map( ( session ) =>
		normalizeConnectionSession( session, 'active' )
	);
	const revokedConnectionSessions = revokedSessions.map( ( session ) =>
		normalizeConnectionSession( session, 'revoked' )
	);
	const connectionSessions = [
		...activeConnectionSessions,
		...revokedConnectionSessions,
	];
	const connectionFilterItems = connectionFilters(
		connectionSessions,
		activeConnectionSessions,
		revokedConnectionSessions,
		isAccessPaused,
		currentUserId
	);
	const connectionFilterCounts = connectionFilterItems.reduce(
		( counts, item ) => ( {
			...counts,
			[ item.name ]: item.count,
		} ),
		{}
	);
	const filteredConnectionSessions = filterConnectionSessions( {
		connectionSessions,
		filter: connectionFilter,
		searchTerm: connectionSearch,
		isAccessPaused,
		currentUserId,
		abilities,
		enabledAbilityIds: enabledAbilities,
	} );
	const connectionUserTotal = connectionUserCount( activeConnectionSessions );
	const connectionStatus = connectStatusDetails( {
		isAccessPaused,
		hasActiveSessions,
		sessionCount: sessions.length,
	} );
	const helpLinks = uniqueHelpLinks( providers );
	const shouldShowAccessControl = Boolean(
		data.actions?.setLockdownAction && data.actions?.setLockdownNonce
	);

	useEffect(
		() => () => {
			if ( copyTimeoutRef.current ) {
				clearTimeout( copyTimeoutRef.current );
			}
		},
		[]
	);

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

	let statusClass =
		'aculect-ai-companion-pill aculect-ai-companion-pill--status is-disconnected';
	let statusText = 'Ready to connect';

	if ( data.isConnected ) {
		statusClass =
			'aculect-ai-companion-pill aculect-ai-companion-pill--status is-connected';
		statusText = 'Connected';
	}

	if ( isAccessPaused ) {
		statusClass =
			'aculect-ai-companion-pill aculect-ai-companion-pill--status is-paused';
		statusText = 'Paused';
	}
	const tabs = SETTINGS_TABS;
	const visibleTabs = tabs.filter( ( tab ) => ! tab.hidden );
	const activeTabName = useActiveTabName( tabs );
	const activeTab =
		tabs.find( ( tab ) => tab.name === activeTabName ) || tabs[ 0 ];

	useEffect( () => {
		document.title = adminTabTitle( activeTab.title );
	}, [ activeTab.title ] );

	const groupedAbilities = abilities.reduce( ( groups, ability ) => {
		const group = ability.group || 'Other';
		return {
			...groups,
			[ group ]: [ ...( groups[ group ] || [] ), ability ],
		};
	}, {} );
	const toggleAbility = ( id, checked ) => {
		setEnabledAbilities( ( current ) => {
			if ( checked ) {
				return Array.from( new Set( [ ...current, id ] ) );
			}

			return current.filter( ( item ) => item !== id );
		} );
	};
	const setGroupAbilities = ( groupAbilities, checked ) => {
		const groupIds = groupAbilities.map( ( ability ) => ability.id );

		setEnabledAbilities( ( current ) => {
			if ( checked ) {
				return Array.from( new Set( [ ...current, ...groupIds ] ) );
			}

			return current.filter( ( id ) => ! groupIds.includes( id ) );
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
					{ brandIconUrl && (
						<img
							className="aculect-ai-companion-app-icon"
							src={ brandIconUrl }
							alt=""
							aria-hidden="true"
						/>
					) }
					<h1 className="aculect-ai-companion-app-title">
						Aculect AI Companion
					</h1>
					{ data.version && (
						<span className="aculect-ai-companion-pill aculect-ai-companion-pill--version">
							{ formatVersion( data.version ) }
						</span>
					) }
				</div>
				<span className={ statusClass }>
					<span
						className="aculect-ai-companion-status-dot"
						aria-hidden="true"
					/>
					{ statusText }
				</span>
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
							<Icon icon={ tab.icon } size={ 18 } />
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
			{ data.status === 'diagnostics_run' && (
				<Notice status="success" isDismissible={ false }>
					Connection diagnostics updated.
				</Notice>
			) }

			<main className="aculect-ai-companion-tab-panel">
				{ ( () => {
					const tab = activeTab;

					if ( tab.name === 'overview' ) {
						return (
							<div className="aculect-ai-companion-overview">
								<section className="aculect-ai-companion-overview-hero">
									<div className="aculect-ai-companion-overview-hero__content">
										<span className="aculect-ai-companion-eyebrow">
											Ready for secure AI workflows
										</span>
										<h2 className="aculect-ai-companion-overview-hero__title">
											Connect your AI Assistant to
											WordPress
										</h2>
										<p className="aculect-ai-companion-overview-hero__copy">
											Aculect AI Companion gives approved
											AI tools a secure WordPress
											connection for drafting content,
											organizing your site, handling
											comments, managing media, and
											reviewing safe site details.
										</p>
										<div className="aculect-ai-companion-overview-actions">
											<Button
												href={ tabUrl(
													'connect',
													data.adminPageUrl
												) }
												variant="primary"
												onClick={ ( event ) =>
													maybeSelectTab(
														event,
														'connect'
													)
												}
											>
												Connect AI Assistant
											</Button>
											<Button
												href="#aculect-ai-companion-overview-capabilities"
												variant="secondary"
											>
												Learn More
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
									<div className="aculect-ai-companion-section-title-row">
										<div>
											<span className="aculect-ai-companion-eyebrow">
												What you can do
											</span>
											<h2 className="aculect-ai-companion-section-title">
												Manage common WordPress work
												from your AI assistant
											</h2>
										</div>
									</div>
									<div className="aculect-ai-companion-feature-grid">
										<OverviewFeatureCard
											icon={ postContent }
											title="Create and update content"
										>
											Draft posts, update pages, change
											titles, edit excerpts, schedule
											content, and publish when you are
											ready.
										</OverviewFeatureCard>
										<OverviewFeatureCard
											icon={ category }
											title="Organize your site"
										>
											Manage categories, tags, and other
											content groups without jumping
											between WordPress screens.
										</OverviewFeatureCard>
										<OverviewFeatureCard
											icon={ comment }
											title="Handle comments"
										>
											Review comments, approve or trash
											them, and prepare replies with
											WordPress permission checks in
											place.
										</OverviewFeatureCard>
										<OverviewFeatureCard
											icon={ media }
											title="Work with media"
										>
											Add images from public URLs, find
											existing library items, and attach
											media to content workflows.
										</OverviewFeatureCard>
										<OverviewFeatureCard
											icon={ info }
											title="Check site details"
										>
											Ask for safe site information,
											including active plugins, themes,
											locale, and basic public settings.
										</OverviewFeatureCard>
										<OverviewFeatureCard
											icon={ settings }
											title="Control what AI can do"
										>
											Turn abilities on or off from AI
											Companion &gt; Abilities and
											disconnect assistants whenever
											needed.
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
											You stay in control from WordPress
										</h2>
										<p>
											Every assistant connection needs
											WordPress approval. You choose which
											abilities are available, and you can
											pause or disconnect access at any
											time.
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
										Review Controls
									</a>
								</section>
							</div>
						);
					}

					if ( tab.name === 'connect' ) {
						return (
							<div className="aculect-ai-companion-connect">
								<section className="aculect-ai-companion-connect-hero">
									<div className="aculect-ai-companion-connect-hero__content">
										<span className="aculect-ai-companion-eyebrow">
											Connect setup
										</span>
										<h2 className="aculect-ai-companion-connect-hero__title">
											Add Aculect AI Companion to your AI
											assistant
										</h2>
										<p className="aculect-ai-companion-connect-hero__copy">
											Use the secure MCP endpoint below,
											then approve the OAuth consent
											screen in WordPress. The connection
											URL is generated from this site at
											runtime.
										</p>
									</div>
									<ConnectFlowGraphic
										brandIconUrl={ brandIconUrl }
									/>
								</section>

								<div className="aculect-ai-companion-connect-layout">
									<div className="aculect-ai-companion-connect-main">
										<Card className="aculect-ai-companion-card aculect-ai-companion-endpoint-card">
											<CardHeader>
												Step 1: Copy the connection URL
											</CardHeader>
											<CardBody>
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
												<p className="aculect-ai-companion-help-text">
													This endpoint starts MCP and
													OAuth discovery. It is not a
													password, bearer token, or
													secret.
												</p>
												<p className="aculect-ai-companion-help-text">
													Hosted AI tools need a
													publicly reachable HTTPS
													URL. Localhost URLs are
													useful for local testing
													only.
												</p>
											</CardBody>
										</Card>

										<section className="aculect-ai-companion-connect-section">
											<div className="aculect-ai-companion-section-title-row">
												<div>
													<span className="aculect-ai-companion-eyebrow">
														Step 2
													</span>
													<h2 className="aculect-ai-companion-section-title">
														Add the endpoint to an
														AI assistant
													</h2>
												</div>
											</div>
											<div className="aculect-ai-companion-provider-list">
												{ providers.map(
													( provider ) => (
														<ConnectProviderCard
															key={ provider.id }
															provider={
																provider
															}
															isOpen={
																openProvider ===
																provider.id
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
													)
												) }
											</div>
										</section>

										<section className="aculect-ai-companion-authorization-panel">
											<span
												className="aculect-ai-companion-authorization-panel__icon"
												aria-hidden="true"
											>
												<Icon
													icon={ shield }
													size={ 22 }
												/>
											</span>
											<div>
												<span className="aculect-ai-companion-eyebrow">
													Step 3
												</span>
												<h2>
													Approve the assistant in
													WordPress
												</h2>
												<p>
													After the AI assistant
													starts the connection,
													WordPress shows a consent
													screen. Review the assistant
													name, scopes, and connected
													user before approving
													access.
												</p>
											</div>
											<a
												className="aculect-ai-companion-authorization-panel__link"
												href={ tabUrl(
													'abilities',
													data.adminPageUrl
												) }
												onClick={ ( event ) =>
													maybeSelectTab(
														event,
														'abilities'
													)
												}
											>
												Review Controls
											</a>
										</section>
									</div>

									<aside className="aculect-ai-companion-connect-sidebar">
										<ConnectStatusPanel
											status={ connectionStatus }
										/>
										<RequirementsPanel
											health={ connectionHealth }
											diagnosticsUrl={ tabUrl(
												'diagnostics',
												data.adminPageUrl
											) }
											onNavigate={ ( event ) =>
												maybeSelectTab(
													event,
													'diagnostics'
												)
											}
										/>
										<HelpLinksPanel links={ helpLinks } />
									</aside>
								</div>
							</div>
						);
					}

					if ( tab.name === 'connections' ) {
						return (
							<div className="aculect-ai-companion-connections-dashboard">
								<section className="aculect-ai-companion-connections-hero">
									<div>
										<span className="aculect-ai-companion-eyebrow">
											Assistant lifecycle
										</span>
										<h2 className="aculect-ai-companion-section-title">
											Connections
										</h2>
										<p>
											Review approved AI assistants,
											connected WordPress users, granted
											abilities, session status, and
											available lifecycle actions.
										</p>
									</div>
								</section>

								<div className="aculect-ai-companion-connection-metrics">
									<ConnectionMetricCard
										icon={ people }
										label="Total"
										value={ connectionSessions.length }
										description="Active and retained revoked sessions."
									/>
									<ConnectionMetricCard
										icon={ seen }
										label="My"
										value={ connectionFilterCounts.my || 0 }
										description="Approved by your WordPress user."
									/>
									<ConnectionMetricCard
										icon={ shield }
										label="Team"
										value={
											connectionFilterCounts.team || 0
										}
										description="Approved by other WordPress users."
									/>
									<ConnectionMetricCard
										icon={ lock }
										label="Access"
										value={
											isAccessPaused ? 'Paused' : 'Active'
										}
										description={ `${ connectionUserTotal } connected user${
											connectionUserTotal === 1 ? '' : 's'
										}` }
										tone={
											isAccessPaused ? 'is-paused' : ''
										}
									/>
								</div>

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
										<div className="aculect-ai-companion-connections-toolbar">
											<div
												className="aculect-ai-companion-connection-filters"
												role="tablist"
												aria-label="Connection states"
											>
												{ connectionFilterItems.map(
													( filter ) => {
														const isActive =
															connectionFilter ===
															filter.name;

														return (
															<button
																key={
																	filter.name
																}
																type="button"
																className={ `aculect-ai-companion-connection-filter ${
																	isActive
																		? 'is-active'
																		: ''
																}` }
																role="tab"
																aria-selected={
																	isActive
																}
																onClick={ () =>
																	setConnectionFilter(
																		filter.name
																	)
																}
															>
																<span>
																	{
																		filter.label
																	}
																</span>
																<strong>
																	{
																		filter.count
																	}
																</strong>
															</button>
														);
													}
												) }
											</div>
											<TextControl
												label="Search connections"
												value={ connectionSearch }
												placeholder="Assistant, user, role, ability, or provider"
												onChange={ setConnectionSearch }
											/>
										</div>

										<ConnectionsTable
											sessions={
												filteredConnectionSessions
											}
											data={ data }
											filter={ connectionFilter }
											searchTerm={ connectionSearch }
											isAccessPaused={ isAccessPaused }
											abilities={ abilities }
											enabledAbilityIds={
												enabledAbilities
											}
											hasConnections={
												connectionSessions.length > 0
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
							<Card className="aculect-ai-companion-card aculect-ai-companion-health-card">
								<CardHeader>Connection Diagnostics</CardHeader>
								<CardBody>
									<div className="aculect-ai-companion-health-toolbar">
										<div>
											<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
												Run checks from WordPress to see
												whether your connection URL,
												metadata, and authorization
												challenge are reachable by AI
												tools.
											</p>
											{ connectionHealth.ranAt && (
												<p className="aculect-ai-companion-help-text">
													Last run:{ ' ' }
													{ connectionHealth.ranAt }
												</p>
											) }
										</div>
										<ActionForm
											data={ data }
											action={
												data.actions
													?.runDiagnosticsAction
											}
											nonce={
												data.actions
													?.runDiagnosticsNonce
											}
											label="Run Diagnostics"
										/>
									</div>
									{ connectionHealth.summary && (
										<div className="aculect-ai-companion-health-summary">
											<span>Overall status</span>
											<StatusBadge
												status={
													connectionHealth.summary
												}
											/>
										</div>
									) }
									<ConnectionHealthChecks
										health={ connectionHealth }
									/>
									{ connectionHealth.details &&
										Object.keys( connectionHealth.details )
											.length > 0 && (
											<div className="aculect-ai-companion-health-details">
												<h3 className="aculect-ai-companion-section-heading">
													Developer Details
												</h3>
												<LogContext
													context={
														connectionHealth.details
													}
												/>
											</div>
										) }
								</CardBody>
							</Card>
						);
					}

					if ( tab.name === 'abilities' ) {
						return (
							<Card className="aculect-ai-companion-card aculect-ai-companion-abilities-card">
								<CardHeader>What your AI can do</CardHeader>
								<CardBody>
									<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
										Choose which abilities connected AI
										assistants can use. WordPress
										permissions are still checked every time
										your AI assistant asks Aculect AI
										Companion to do something.
									</p>
									<RoleAbilitiesEditor
										data={ data }
										abilities={ abilities }
										roleAbilityPolicy={ roleAbilityPolicy }
										selectedRole={ selectedRoleAbilityRole }
										onSelectRole={
											setSelectedRoleAbilityRole
										}
									/>
									<form
										method="post"
										action={ data.actions?.adminPostUrl }
										className="aculect-ai-companion-form aculect-ai-companion-form--abilities"
									>
										<input
											type="hidden"
											name="action"
											value={
												data.actions
													?.saveAbilitiesAction
											}
										/>
										<input
											type="hidden"
											name="_wpnonce"
											value={
												data.actions?.saveAbilitiesNonce
											}
										/>
										{ enabledAbilities.map( ( id ) => (
											<input
												key={ id }
												type="hidden"
												name="enabled_abilities[]"
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
										{ enabledWpAbilities.map( ( id ) => (
											<input
												key={ id }
												type="hidden"
												name="enabled_wp_abilities[]"
												value={ id }
											/>
										) ) }
										<div className="aculect-ai-companion-ability-toolbar">
											<Button
												type="button"
												variant="secondary"
												onClick={ () =>
													setEnabledAbilities(
														abilities.map(
															( ability ) =>
																ability.id
														)
													)
												}
											>
												Enable All Abilities
											</Button>
											<Button
												type="button"
												variant="secondary"
												onClick={ () =>
													setEnabledAbilities( [] )
												}
											>
												Disable All Abilities
											</Button>
											<Button
												type="submit"
												variant="primary"
											>
												Save Abilities
											</Button>
										</div>
										{ confirmationGroupOptions.length >
											0 && (
											<div className="aculect-ai-companion-confirmation-settings">
												<h3 className="aculect-ai-companion-section-heading">
													Confirmation Gates
												</h3>
												<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
													High-risk actions always
													require confirmation. Select
													groups that should require
													confirmation for every write
													action.
												</p>
												<div className="aculect-ai-companion-confirmation-groups">
													{ confirmationGroupOptions.map(
														( group ) => (
															<CheckboxControl
																key={ group }
																label={ group }
																checked={ confirmationGroups.includes(
																	group
																) }
																onChange={ (
																	checked
																) =>
																	toggleConfirmationGroup(
																		group,
																		Boolean(
																			checked
																		)
																	)
																}
															/>
														)
													) }
												</div>
											</div>
										) }
										<div className="aculect-ai-companion-ability-groups">
											{ Object.entries(
												groupedAbilities
											).map(
												( [
													group,
													groupAbilities,
												] ) => (
													<div
														key={ group }
														className="aculect-ai-companion-ability-group"
													>
														<div className="aculect-ai-companion-ability-group__header">
															<div>
																<h3 className="aculect-ai-companion-section-heading">
																	{ group }
																</h3>
																<p className="aculect-ai-companion-ability-group__summary">
																	{
																		groupAbilities.filter(
																			(
																				ability
																			) =>
																				enabledAbilities.includes(
																					ability.id
																				)
																		).length
																	}
																	/{ ' ' }
																	{
																		groupAbilities.length
																	}{ ' ' }
																	enabled
																</p>
															</div>
															<div className="aculect-ai-companion-ability-group__actions">
																<Button
																	type="button"
																	variant="secondary"
																	onClick={ () =>
																		setGroupAbilities(
																			groupAbilities,
																			true
																		)
																	}
																>
																	Enable Group
																</Button>
																<Button
																	type="button"
																	variant="secondary"
																	onClick={ () =>
																		setGroupAbilities(
																			groupAbilities,
																			false
																		)
																	}
																>
																	Disable
																	Group
																</Button>
															</div>
														</div>
														<div className="aculect-ai-companion-ability-list">
															{ groupAbilities.map(
																( ability ) => (
																	<div
																		key={
																			ability.id
																		}
																		className="aculect-ai-companion-ability-row"
																	>
																		<CheckboxControl
																			label={
																				ability.title
																			}
																			checked={ enabledAbilities.includes(
																				ability.id
																			) }
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
																		<p className="aculect-ai-companion-ability-row__description">
																			{
																				ability.description
																			}
																		</p>
																		<div className="aculect-ai-companion-ability-row__meta">
																			<span
																				className={ `aculect-ai-companion-risk-chip ${
																					ability.readOnly
																						? 'is-read-only'
																						: 'is-write'
																				}` }
																			>
																				{ ability.readOnly
																					? 'Read-only'
																					: 'Can change site' }
																			</span>
																			<span>
																				{ ability.scope ||
																					'content:read' }
																			</span>
																			<code>
																				{
																					ability.toolName
																				}
																			</code>
																		</div>
																	</div>
																)
															) }
														</div>
													</div>
												)
											) }
										</div>
										{ wpAbilities.length > 0 && (
											<div className="aculect-ai-companion-ability-group">
												<div className="aculect-ai-companion-ability-group__header">
													<div>
														<h3 className="aculect-ai-companion-section-heading">
															WordPress Abilities
														</h3>
														<p className="aculect-ai-companion-ability-group__summary">
															{
																wpAbilities.filter(
																	(
																		ability
																	) =>
																		enabledWpAbilities.includes(
																			ability.id
																		)
																).length
															}
															/{ ' ' }
															{
																wpAbilities.length
															}{ ' ' }
															allowed
														</p>
													</div>
												</div>
												<div className="aculect-ai-companion-ability-list">
													{ wpAbilities.map(
														( ability ) => (
															<div
																key={
																	ability.id
																}
																className="aculect-ai-companion-ability-row"
															>
																<CheckboxControl
																	label={
																		ability.title ||
																		ability.id
																	}
																	checked={ enabledWpAbilities.includes(
																		ability.id
																	) }
																	onChange={ (
																		checked
																	) =>
																		toggleWpAbility(
																			ability.id,
																			Boolean(
																				checked
																			)
																		)
																	}
																/>
																<p className="aculect-ai-companion-ability-row__description">
																	{
																		ability.description
																	}
																</p>
																<div className="aculect-ai-companion-ability-row__meta">
																	<span
																		className={ `aculect-ai-companion-risk-chip ${
																			ability.readOnly
																				? 'is-read-only'
																				: 'is-write'
																		}` }
																	>
																		{ ability.readOnly
																			? 'Read-only'
																			: 'Can change site' }
																	</span>
																	<span>
																		{ ability.category ||
																			'uncategorized' }
																	</span>
																	<code>
																		{
																			ability.id
																		}
																	</code>
																</div>
															</div>
														)
													) }
												</div>
											</div>
										) }
									</form>
								</CardBody>
							</Card>
						);
					}

					if ( tab.name === 'activity' ) {
						return (
							<Card className="aculect-ai-companion-card aculect-ai-companion-activity-card">
								<CardHeader>AI Activity</CardHeader>
								<CardBody>
									<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
										Review write actions requested by
										connected AI assistants. Read-only
										actions are not logged in this version.
									</p>
									<ActivitySummary
										summary={ activity.summary }
									/>
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
										<label htmlFor="aculect-ai-companion-activity-action">
											<span>Action</span>
											<input
												id="aculect-ai-companion-activity-action"
												type="text"
												name="activity_action"
												defaultValue={
													activityFilters.action || ''
												}
											/>
										</label>
										<label htmlFor="aculect-ai-companion-activity-status">
											<span>Status</span>
											<select
												id="aculect-ai-companion-activity-status"
												name="activity_status"
												defaultValue={
													activityFilters.status || ''
												}
											>
												<option value="">Any</option>
												<option value="success">
													Success
												</option>
												<option value="error">
													Error
												</option>
											</select>
										</label>
										<label htmlFor="aculect-ai-companion-activity-user">
											<span>User ID</span>
											<input
												id="aculect-ai-companion-activity-user"
												type="number"
												min="1"
												name="activity_user"
												defaultValue={
													activityFilters.user_id ||
													''
												}
											/>
										</label>
										<label htmlFor="aculect-ai-companion-activity-assistant">
											<span>Assistant</span>
											<input
												id="aculect-ai-companion-activity-assistant"
												type="text"
												name="activity_assistant"
												defaultValue={
													activityFilters.assistant ||
													''
												}
											/>
										</label>
										<label htmlFor="aculect-ai-companion-activity-range">
											<span>Range</span>
											<select
												id="aculect-ai-companion-activity-range"
												name="activity_range"
												defaultValue={
													activityFilters.range ||
													'7d'
												}
											>
												<option value="24h">
													24 hours
												</option>
												<option value="7d">
													7 days
												</option>
												<option value="30d">
													30 days
												</option>
												<option value="90d">
													90 days
												</option>
												<option value="all">
													All time
												</option>
											</select>
										</label>
										<Button type="submit" variant="primary">
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
									</form>
									<div className="aculect-ai-companion-log-toolbar">
										<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
											Showing page { activity.page || 1 }{ ' ' }
											of { activity.totalPages || 1 }.
										</p>
									</div>
									<ActivityTable activity={ activity } />
									<div className="aculect-ai-companion-log-pagination">
										<Button
											href={
												activity.prevUrl || undefined
											}
											variant="secondary"
											disabled={ ! activity.prevUrl }
										>
											Previous
										</Button>
										<Button
											href={
												activity.nextUrl || undefined
											}
											variant="secondary"
											disabled={ ! activity.nextUrl }
										>
											Next
										</Button>
									</div>
								</CardBody>
							</Card>
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
								abilities={ abilities }
								enabledAbilities={ enabledAbilities }
								wpAbilities={ wpAbilities }
								enabledWpAbilities={ enabledWpAbilities }
								onCopy={ copyValue }
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
										/>
									</div>
									<LogsTable logs={ logs } />
									<div className="aculect-ai-companion-log-pagination">
										<Button
											href={ logs.prevUrl || undefined }
											variant="secondary"
											disabled={ ! logs.prevUrl }
										>
											Previous
										</Button>
										<Button
											href={ logs.nextUrl || undefined }
											variant="secondary"
											disabled={ ! logs.nextUrl }
										>
											Next
										</Button>
									</div>
								</CardBody>
							</Card>
						);
					}

					if ( tab.name === 'changelog' ) {
						const changelog =
							data.changelog && typeof data.changelog === 'object'
								? data.changelog
								: {};
						const versions = Object.entries( changelog ).slice(
							0,
							3
						);
						return (
							<Card className="aculect-ai-companion-card">
								<CardHeader>Changelog</CardHeader>
								<CardBody>
									{ versions.length === 0 ? (
										<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
											No changelog entries found.
										</p>
									) : (
										<div className="aculect-ai-companion-changelog">
											{ versions.map(
												( [ version, groups ] ) => (
													<div
														key={ version }
														className="aculect-ai-companion-changelog-version"
													>
														<h3 className="aculect-ai-companion-changelog-version-title">
															{ version }
														</h3>
														{ Object.entries(
															groups || {}
														).map(
															( [
																group,
																items,
															] ) => (
																<div
																	key={ `${ version }-${ group }` }
																	className="aculect-ai-companion-changelog-group"
																>
																	<h4 className="aculect-ai-companion-changelog-group-title">
																		{
																			group
																		}
																	</h4>
																	<ul className="aculect-ai-companion-changelog-list">
																		{ Array.isArray(
																			items
																		) &&
																			items.map(
																				(
																					item,
																					index
																				) => (
																					<li
																						key={ `${ version }-${ group }-${ index }` }
																					>
																						{
																							item
																						}
																					</li>
																				)
																			) }
																	</ul>
																</div>
															)
														) }
													</div>
												)
											) }
										</div>
									) }
									<p className="aculect-ai-companion-changelog-footer">
										<a
											href="https://github.com/mehul0810/aculect-ai-companion/blob/main/changelog.json"
											target="_blank"
											rel="noopener noreferrer"
										>
											View full changelog.json on GitHub
										</a>
									</p>
								</CardBody>
							</Card>
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
