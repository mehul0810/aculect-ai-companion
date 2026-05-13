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
} from '@wordpress/components';

const TAB_QUERY_PARAM = 'tab';

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

function CopyField( { label, value, secret = false, onCopy } ) {
	const inputId = useRef(
		`quark-copy-field-${ String( label )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' ) }-${ Math.random()
			.toString( 36 )
			.slice( 2 ) }`
	);

	return (
		<div className="quark-copy-field">
			<label
				className="quark-copy-field__label"
				htmlFor={ inputId.current }
			>
				{ label }
			</label>
			<div className="quark-copy-field__control">
				<input
					id={ inputId.current }
					className="quark-copy-field__input"
					type={ secret ? 'password' : 'text' }
					value={ String( value || '' ) }
					readOnly
					aria-label={ label }
				/>
				<Button
					variant="secondary"
					className="quark-copy-field__button"
					onClick={ () => onCopy( value ) }
					aria-label={ `Copy ${ label }` }
				>
					<span className="quark-copy-field__icon" aria-hidden="true">
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
			className="quark-action-form"
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

function SetupSection( { provider, section, sectionIndex, onCopy } ) {
	const steps = Array.isArray( section.steps ) ? section.steps : [];
	const copyFields = Array.isArray( section.copyFields )
		? section.copyFields
		: [];

	return (
		<div
			className={ `quark-setup-method ${
				copyFields.length > 0 ? 'has-copy-fields' : ''
			}` }
		>
			<div className="quark-setup-method__content">
				<h4 className="quark-setup-method__title">
					{ section.title || 'Setup' }
				</h4>
				{ section.description && (
					<p className="quark-setup-method__description">
						{ section.description }
					</p>
				) }
				{ steps.length > 0 && (
					<ol className="quark-steps">
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
					<div className="quark-provider-actions">
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
				<div className="quark-setup-method__fields">
					<h5 className="quark-section-heading">Copy</h5>
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
	const data = window.quarkSettingsData || {};
	const providers = Array.isArray( data.providers ) ? data.providers : [];
	const sessions = Array.isArray( data.sessions ) ? data.sessions : [];
	const abilities = Array.isArray( data.abilities ) ? data.abilities : [];
	const [ copied, setCopied ] = useState( '' );
	const [ openProvider, setOpenProvider ] = useState(
		providers[ 0 ]?.id || 'claude'
	);
	const [ enabledAbilities, setEnabledAbilities ] = useState(
		Array.isArray( data.enabledAbilities ) ? data.enabledAbilities : []
	);
	const copyTimeoutRef = useRef( null );

	useEffect(
		() => () => {
			if ( copyTimeoutRef.current ) {
				clearTimeout( copyTimeoutRef.current );
			}
		},
		[]
	);

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

	const statusClass = data.isConnected
		? 'quark-pill quark-pill--status is-connected'
		: 'quark-pill quark-pill--status is-disconnected';
	const tabs = [
		{ name: 'about', title: 'About' },
		{ name: 'connectors', title: 'Connect' },
		{ name: 'connections', title: 'Connections' },
	];
	if ( data.isConnected ) {
		tabs.push( { name: 'abilities', title: 'Abilities' } );
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

	return (
		<div className="quark-app-root">
			<div className="quark-app-header">
				<div className="quark-app-branding">
					<div className="quark-app-heading">
						<p className="quark-app-kicker">Quark</p>
						<h1 className="quark-app-title">
							Connect your AI assistant
						</h1>
						<p className="quark-app-tagline">
							Manage your WordPress site with AI.
						</p>
					</div>
					<span className="quark-pill quark-pill--version">
						{ data.version || '0.1.0' }
					</span>
				</div>
				<span className={ statusClass }>
					{ data.isConnected ? 'Connected' : 'Ready to connect' }
				</span>
			</div>

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

			<TabPanel
				className="quark-tabs"
				initialTabName={ selectedTab }
				onSelect={ persistTabName }
				tabs={ tabs }
			>
				{ ( tab ) => {
					if ( tab.name === 'about' ) {
						return (
							<Card className="quark-card quark-about-card">
								<CardHeader>About Quark</CardHeader>
								<CardBody>
									<p className="quark-copy quark-copy--first">
										Quark lets you use Claude or ChatGPT to
										help manage your WordPress site. You can
										ask in plain English, and Quark turns
										that request into WordPress tasks.
									</p>
									<p className="quark-copy">
										You stay in control. WordPress asks for
										your approval before an AI assistant can
										connect, you choose which abilities are
										available, and you can disconnect access
										at any time.
									</p>

									<div className="quark-feature-grid">
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												Create and update content
											</h3>
											<p className="quark-feature-card__copy">
												Draft posts, update pages,
												change titles, edit excerpts,
												and publish when you are ready.
											</p>
										</div>
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												Organize your site
											</h3>
											<p className="quark-feature-card__copy">
												Manage categories, tags, and
												other content groups without
												searching through WordPress
												screens.
											</p>
										</div>
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												Handle comments
											</h3>
											<p className="quark-feature-card__copy">
												Review comments, approve or
												trash them, and prepare replies
												without opening every comment
												manually.
											</p>
										</div>
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												Work with media
											</h3>
											<p className="quark-feature-card__copy">
												Add images from public URLs and
												find items already in your media
												library.
											</p>
										</div>
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												Check site details
											</h3>
											<p className="quark-feature-card__copy">
												Ask for safe site information,
												including active plugins,
												themes, and basic settings.
											</p>
										</div>
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												Control what AI can do
											</h3>
											<p className="quark-feature-card__copy">
												Turn abilities on or off from
												Settings &gt; Quark &gt;
												Abilities and disconnect
												assistants whenever needed.
											</p>
										</div>
									</div>
								</CardBody>
							</Card>
						);
					}

					if ( tab.name === 'connectors' ) {
						return (
							<div className="quark-connectors">
								<Card className="quark-card quark-endpoint-card">
									<CardHeader>
										Connect your AI assistant
									</CardHeader>
									<CardBody>
										<ol className="quark-steps quark-steps--primary">
											<li>
												Copy your connection URL below.
											</li>
											<li>
												Open Claude or ChatGPT and add a
												new connector.
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
										<p className="quark-help-text">
											The URL must be publicly reachable
											over HTTPS for Claude or ChatGPT to
											connect.
										</p>
									</CardBody>
								</Card>

								<div className="quark-provider-list">
									{ providers.map( ( provider ) => {
										const setupSections = Array.isArray(
											provider.setupSections
										)
											? provider.setupSections
											: [];

										return (
											<Card
												key={ provider.id }
												className={ `quark-card quark-provider-card ${
													openProvider === provider.id
														? 'is-open'
														: ''
												}` }
											>
												<CardBody>
													<div className="quark-provider-card__header">
														<div className="quark-provider-card__title-wrap">
															<h3 className="quark-provider-card__title">
																{
																	provider.label
																}
															</h3>
															<p className="quark-provider-card__description">
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
														<div className="quark-provider-panel">
															<div className="quark-setup-method-list">
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
							<Card className="quark-card quark-sessions-card">
								<CardHeader>Active Connections</CardHeader>
								<CardBody>
									{ sessions.length === 0 ? (
										<p className="quark-copy quark-copy--first">
											No AI assistants are connected yet.
											Add Quark in Claude or ChatGPT with
											your connection URL, then approve
											the connection on the screen that
											appears.
										</p>
									) : (
										<div className="quark-session-list">
											{ sessions.map( ( session ) => (
												<div
													key={ session.id }
													className="quark-session-row"
												>
													<div className="quark-session-row__main">
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
										<div className="quark-danger-zone">
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

					if ( tab.name === 'abilities' ) {
						return (
							<Card className="quark-card quark-abilities-card">
								<CardHeader>What your AI can do</CardHeader>
								<CardBody>
									<p className="quark-copy quark-copy--first">
										Choose which abilities connected AI
										assistants can use. WordPress
										permissions are still checked every time
										your AI assistant asks Quark to do
										something.
									</p>
									<form
										method="post"
										action={ data.actions?.adminPostUrl }
										className="quark-form quark-form--abilities"
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
										<div className="quark-ability-toolbar">
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
										<div className="quark-ability-groups">
											{ Object.entries(
												groupedAbilities
											).map(
												( [
													group,
													groupAbilities,
												] ) => (
													<div
														key={ group }
														className="quark-ability-group"
													>
														<h3 className="quark-section-heading">
															{ group }
														</h3>
														<div className="quark-ability-list">
															{ groupAbilities.map(
																( ability ) => (
																	<div
																		key={
																			ability.id
																		}
																		className="quark-ability-row"
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
																		<p className="quark-ability-row__description">
																			{
																				ability.description
																			}
																		</p>
																	</div>
																)
															) }
														</div>
													</div>
												)
											) }
										</div>
									</form>
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
							<Card className="quark-card">
								<CardHeader>Changelog</CardHeader>
								<CardBody>
									{ versions.length === 0 ? (
										<p className="quark-copy quark-copy--first">
											No changelog entries found.
										</p>
									) : (
										<div className="quark-changelog">
											{ versions.map(
												( [ version, groups ] ) => (
													<div
														key={ version }
														className="quark-changelog-version"
													>
														<h3 className="quark-changelog-version-title">
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
																	className="quark-changelog-group"
																>
																	<h4 className="quark-changelog-group-title">
																		{
																			group
																		}
																	</h4>
																	<ul className="quark-changelog-list">
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
									<p className="quark-changelog-footer">
										<a
											href="https://github.com/mehul0810/quark/blob/main/changelog.json"
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

const root = document.getElementById( 'quark-settings-app-root' );
if ( root ) {
	render( <SettingsApp />, root );
}
