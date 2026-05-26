import { render, useEffect, useRef, useState } from '@wordpress/element';
import './style.scss';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	TabPanel,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

const TAB_QUERY_PARAM = 'tab';
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
	const defaultTab = tabs[ 0 ]?.name || 'about';

	try {
		const url = new URL( window.location.href );
		const requestedTab = url.searchParams.get( TAB_QUERY_PARAM );

		return requestedTab && hasTab( tabs, requestedTab )
			? requestedTab
			: defaultTab;
	} catch {
		return defaultTab;
	}
}

function persistTabName( tabName ) {
	try {
		const url = new URL( window.location.href );
		url.searchParams.set( TAB_QUERY_PARAM, tabName );
		window.history.replaceState( {}, '', url.toString() );
	} catch {
		// URL state is progressive enhancement; tab navigation still works.
	}
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
					variant="secondary"
					className="aculect-ai-companion-copy-field__button"
					onClick={ () => onCopy( value ) }
					aria-label={ `Copy ${ label }` }
				>
					<span
						className="aculect-ai-companion-copy-field__icon"
						aria-hidden="true"
					>
						<svg
							viewBox="0 0 24 24"
							width="18"
							height="18"
							focusable="false"
						>
							<path d="M16 1H4c-1.1 0-2 .9-2 2v12h2V3h12V1Zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2Zm0 16H8V7h11v14Z" />
						</svg>
					</span>
				</Button>
			</div>
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
			<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
				No diagnostic logs have been recorded yet.
			</p>
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
			<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
				No connected AI activity has been recorded yet.
			</p>
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
							rel="noreferrer"
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

function SettingsApp() {
	const data = window.aculectAICompanionSettingsData || {};
	const brandIconUrl = data.brandIconUrl || '';
	const providers = Array.isArray( data.providers ) ? data.providers : [];
	const sessions = Array.isArray( data.sessions ) ? data.sessions : [];
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
	const adminNoticesRef = useRef( null );
	const copyTimeoutRef = useRef( null );
	const isAccessPaused = Boolean( data.accessPaused );
	const hasActiveSessions = sessions.length > 0;
	const shouldShowAccessControl = isAccessPaused || hasActiveSessions;

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
	const tabs = [
		{ name: 'about', title: 'About' },
		{ name: 'connectors', title: 'Connect' },
		{ name: 'diagnostics', title: 'Diagnostics' },
		{ name: 'connections', title: 'Connections' },
		{ name: 'activity', title: 'Activity' },
		{ name: 'brand', title: 'Brand' },
	];
	if ( data.isConnected ) {
		tabs.push( { name: 'abilities', title: 'Abilities' } );
	}
	tabs.push( { name: 'advanced', title: 'Advanced' } );
	if ( diagnostics.loggingEnabled ) {
		tabs.push( { name: 'logs', title: 'Logs' } );
	}
	tabs.push( { name: 'changelog', title: 'Changelog' } );
	const selectedTab = initialTabName( tabs );
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
			<div className="aculect-ai-companion-app-header">
				<div className="aculect-ai-companion-app-branding">
					<div className="aculect-ai-companion-app-heading">
						<div className="aculect-ai-companion-app-product">
							{ brandIconUrl && (
								<img
									className="aculect-ai-companion-app-icon"
									src={ brandIconUrl }
									alt=""
									aria-hidden="true"
								/>
							) }
							<span>AI Companion</span>
						</div>
						<div className="aculect-ai-companion-app-title-row">
							<h1 className="aculect-ai-companion-app-title">
								Connect your AI Agent
							</h1>
							<span className="aculect-ai-companion-pill aculect-ai-companion-pill--version">
								{ data.version || '0.2.0' }
							</span>
						</div>
						<p className="aculect-ai-companion-app-tagline">
							Automate your WordPress site by connecting it to
							your AI Agent through secure MCP
						</p>
					</div>
				</div>
				<span className={ statusClass }>{ statusText }</span>
			</div>

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

			<TabPanel
				className="aculect-ai-companion-tabs"
				initialTabName={ selectedTab }
				onSelect={ persistTabName }
				tabs={ tabs }
			>
				{ ( tab ) => {
					if ( tab.name === 'about' ) {
						return (
							<Card className="aculect-ai-companion-card aculect-ai-companion-about-card">
								<CardHeader>
									About Aculect AI Companion
								</CardHeader>
								<CardBody>
									<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
										Aculect AI Companion helps you manage
										content, comments, media, and more with
										your AI assistant. You can ask in plain
										English, and Aculect AI Companion turns
										that request into WordPress tasks.
									</p>
									<p className="aculect-ai-companion-copy">
										You stay in control. WordPress asks for
										your approval before an AI assistant can
										connect, you choose which abilities are
										available, and you can disconnect access
										at any time.
									</p>

									<div className="aculect-ai-companion-feature-grid">
										<div className="aculect-ai-companion-feature-card">
											<h3 className="aculect-ai-companion-feature-card__title">
												Create and update content
											</h3>
											<p className="aculect-ai-companion-feature-card__copy">
												Draft posts, update pages,
												change titles, edit excerpts,
												and publish when you are ready.
											</p>
										</div>
										<div className="aculect-ai-companion-feature-card">
											<h3 className="aculect-ai-companion-feature-card__title">
												Organize your site
											</h3>
											<p className="aculect-ai-companion-feature-card__copy">
												Manage categories, tags, and
												other content groups without
												searching through WordPress
												screens.
											</p>
										</div>
										<div className="aculect-ai-companion-feature-card">
											<h3 className="aculect-ai-companion-feature-card__title">
												Handle comments
											</h3>
											<p className="aculect-ai-companion-feature-card__copy">
												Review comments, approve or
												trash them, and prepare replies
												without opening every comment
												manually.
											</p>
										</div>
										<div className="aculect-ai-companion-feature-card">
											<h3 className="aculect-ai-companion-feature-card__title">
												Work with media
											</h3>
											<p className="aculect-ai-companion-feature-card__copy">
												Add images from public URLs and
												find items already in your media
												library.
											</p>
										</div>
										<div className="aculect-ai-companion-feature-card">
											<h3 className="aculect-ai-companion-feature-card__title">
												Check site details
											</h3>
											<p className="aculect-ai-companion-feature-card__copy">
												Ask for safe site information,
												including active plugins,
												themes, and basic settings.
											</p>
										</div>
										<div className="aculect-ai-companion-feature-card">
											<h3 className="aculect-ai-companion-feature-card__title">
												Control what AI can do
											</h3>
											<p className="aculect-ai-companion-feature-card__copy">
												Turn abilities on or off from
												Settings &gt; Aculect AI
												Companion &gt; Abilities and
												disconnect assistants whenever
												needed.
											</p>
										</div>
									</div>
								</CardBody>
							</Card>
						);
					}

					if ( tab.name === 'connectors' ) {
						return (
							<div className="aculect-ai-companion-connectors">
								<Card className="aculect-ai-companion-card aculect-ai-companion-endpoint-card">
									<CardHeader>
										Connect your AI assistant
									</CardHeader>
									<CardBody>
										<ol className="aculect-ai-companion-steps aculect-ai-companion-steps--primary">
											<li>
												Copy your connection URL below.
											</li>
											<li>
												Open your AI tool and add a new
												connector.
											</li>
											<li>
												Paste the URL when prompted.
											</li>
											<li>
												Approve the connection on the
												screen that appears.
											</li>
										</ol>
										<CopyField
											label="Your connection URL"
											value={ data.mcpUrl }
											onCopy={ ( value ) =>
												copyValue(
													value,
													'Connection URL copied.'
												)
											}
										/>
										<p className="aculect-ai-companion-help-text">
											The URL must be publicly reachable
											over HTTPS for your AI tool to
											connect.
										</p>
									</CardBody>
								</Card>

								<div className="aculect-ai-companion-provider-list">
									{ providers.map( ( provider ) => {
										const setupSections = Array.isArray(
											provider.setupSections
										)
											? provider.setupSections
											: [];

										return (
											<Card
												key={ provider.id }
												className={ `aculect-ai-companion-card aculect-ai-companion-provider-card ${
													openProvider === provider.id
														? 'is-open'
														: ''
												}` }
											>
												<CardBody>
													<div className="aculect-ai-companion-provider-card__header">
														<div className="aculect-ai-companion-provider-card__title-wrap">
															<h3 className="aculect-ai-companion-provider-card__title">
																{
																	provider.label
																}
															</h3>
															<p className="aculect-ai-companion-provider-card__description">
																{
																	provider.description
																}
															</p>
														</div>
														<Button
															variant="link"
															onClick={ () =>
																setOpenProvider(
																	openProvider ===
																		provider.id
																		? ''
																		: provider.id
																)
															}
														>
															{ openProvider ===
															provider.id
																? 'Close'
																: 'Configure' }
														</Button>
													</div>

													{ openProvider ===
														provider.id && (
														<div className="aculect-ai-companion-provider-panel">
															<div className="aculect-ai-companion-setup-method-list">
																{ setupSections.map(
																	(
																		section,
																		index
																	) => (
																		<SetupSection
																			key={ `${ provider.id }-${ index }` }
																			provider={
																				provider
																			}
																			section={
																				section
																			}
																			sectionIndex={
																				index
																			}
																			onCopy={
																				copyValue
																			}
																		/>
																	)
																) }
															</div>
														</div>
													) }
												</CardBody>
											</Card>
										);
									} ) }
								</div>
							</div>
						);
					}

					if ( tab.name === 'connections' ) {
						return (
							<Card className="aculect-ai-companion-card aculect-ai-companion-sessions-card">
								<CardHeader>Active Connections</CardHeader>
								<CardBody>
									{ shouldShowAccessControl && (
										<div
											className={ `aculect-ai-companion-lockdown ${
												isAccessPaused
													? 'is-paused'
													: ''
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
														? 'Connected assistants cannot run actions until access is resumed.'
														: 'Pause access to stop connected assistants from running actions without disconnecting them.' }
												</p>
											</div>
											<ActionForm
												data={ data }
												action={
													data.actions
														?.setLockdownAction
												}
												nonce={
													data.actions
														?.setLockdownNonce
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
														isAccessPaused
															? '0'
															: '1'
													}
												/>
											</ActionForm>
										</div>
									) }
									{ sessions.length === 0 ? (
										<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
											No AI assistants are connected yet.
											Add Aculect AI Companion in your AI
											tool with your connection URL, then
											approve the connection on the screen
											that appears.
										</p>
									) : (
										<div className="aculect-ai-companion-session-list">
											{ sessions.map( ( session ) => (
												<div
													key={ session.id }
													className="aculect-ai-companion-session-row"
												>
													<div className="aculect-ai-companion-session-row__main">
														<strong>
															{ session.client_name ||
																'AI Assistant' }
														</strong>
														<span>
															{ session.provider }{ ' ' }
															· { session.user }
														</span>
													</div>
													<ActionForm
														data={ data }
														action={
															data.actions
																?.revokeSessionAction
														}
														nonce={
															data.actions
																?.revokeSessionNonce
														}
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
											) ) }
										</div>
									) }
									{ sessions.length > 0 && (
										<div className="aculect-ai-companion-danger-zone">
											<ActionForm
												data={ data }
												action={
													data.actions
														?.revokeAllAction
												}
												nonce={
													data.actions?.revokeAllNonce
												}
												label="Disconnect All"
												destructive
											/>
										</div>
									) }
								</CardBody>
							</Card>
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
									<form
										method="get"
										action="options-general.php"
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
										<Button type="submit" variant="primary">
											Filter
										</Button>
										<Button
											href="options-general.php?page=aculect-ai-companion&tab=activity"
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
							<Card className="aculect-ai-companion-card aculect-ai-companion-advanced-card">
								<CardHeader>Advanced Settings</CardHeader>
								<CardBody>
									<p className="aculect-ai-companion-copy aculect-ai-companion-copy--first">
										Enable diagnostic logging while testing
										AI assistant connections. Logs keep
										sanitized connection lifecycle events
										for { diagnostics.retentionDays || 30 }{ ' ' }
										days.
									</p>
									<form
										method="post"
										action={ data.actions?.adminPostUrl }
										className="aculect-ai-companion-form aculect-ai-companion-form--advanced"
									>
										<input
											type="hidden"
											name="action"
											value={
												data.actions?.saveAdvancedAction
											}
										/>
										<input
											type="hidden"
											name="_wpnonce"
											value={
												data.actions?.saveAdvancedNonce
											}
										/>
										<input
											type="hidden"
											name="diagnostic_logging_enabled"
											value={ loggingEnabled ? '1' : '0' }
										/>
										<input
											type="hidden"
											name="role_connections_enabled"
											value={
												roleConnectionsEnabled
													? '1'
													: '0'
											}
										/>
										{ roleConnectionRoles.map( ( role ) => (
											<input
												key={ role }
												type="hidden"
												name="role_connection_roles[]"
												value={ role }
											/>
										) ) }
										<div className="aculect-ai-companion-setting-row">
											<ToggleControl
												label="Enable diagnostic logging"
												checked={ loggingEnabled }
												onChange={ ( checked ) =>
													setLoggingEnabled(
														Boolean( checked )
													)
												}
											/>
											<p className="aculect-ai-companion-help-text">
												Stores sanitized OAuth and MCP
												connection events in a custom
												table. Requests blocked before
												WordPress loads will not appear
												here.
											</p>
										</div>
										<div className="aculect-ai-companion-setting-row">
											<ToggleControl
												label="Enable role-based connection entry points"
												checked={
													roleConnectionsEnabled
												}
												onChange={ ( checked ) =>
													setRoleConnectionsEnabled(
														Boolean( checked )
													)
												}
											/>
											<p className="aculect-ai-companion-help-text">
												Allows logged-in users with
												selected roles to copy the MCP
												connection URL from a block,
												shortcode, or PHP template
												function. OAuth and WordPress
												capabilities still limit what
												each connected user can do.
											</p>
										</div>
										{ roleConnectionsEnabled && (
											<div className="aculect-ai-companion-confirmation-settings">
												<h3 className="aculect-ai-companion-section-heading">
													Allowed Roles
												</h3>
												<div className="aculect-ai-companion-confirmation-groups">
													{ (
														roleConnections.roleOptions ||
														[]
													).map( ( role ) => (
														<CheckboxControl
															key={ role.id }
															label={ role.label }
															checked={ roleConnectionRoles.includes(
																role.id
															) }
															onChange={ (
																checked
															) =>
																toggleRoleConnectionRole(
																	role.id,
																	Boolean(
																		checked
																	)
																)
															}
														/>
													) ) }
												</div>
												<div className="aculect-ai-companion-setting-summary">
													<div>
														<span>Shortcode</span>
														<strong>
															{
																roleConnections.shortcode
															}
														</strong>
													</div>
													<div>
														<span>Block</span>
														<strong>
															{
																roleConnections.blockName
															}
														</strong>
													</div>
													<div>
														<span>
															PHP function
														</span>
														<strong>
															{
																roleConnections.functionName
															}
														</strong>
													</div>
												</div>
											</div>
										) }
										<div className="aculect-ai-companion-setting-summary">
											<div>
												<span>Retention</span>
												<strong>
													{ diagnostics.retentionDays ||
														30 }{ ' ' }
													days
												</strong>
											</div>
											<div>
												<span>Stored data</span>
												<strong>
													Sanitized metadata only
												</strong>
											</div>
										</div>
										<Button type="submit" variant="primary">
											Save Advanced Settings
										</Button>
									</form>
								</CardBody>
							</Card>
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
				} }
			</TabPanel>
		</div>
	);
}

const root = document.getElementById(
	'aculect-ai-companion-settings-app-root'
);
if ( root ) {
	render( <SettingsApp />, root );
}
