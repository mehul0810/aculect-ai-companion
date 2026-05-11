/* global MutationObserver, navigator */

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

	useEffect( () => {
		const target = document.getElementById( 'wpbody-content' );
		if ( ! target ) {
			return undefined;
		}

		const removeExternalAdminNotices = () => {
			const notices = document.querySelectorAll(
				[
					'#wpbody-content > .notice',
					'#wpbody-content > .updated',
					'#wpbody-content > .error',
					'#wpbody-content > .update-nag',
					'.quark-settings-wrap > .notice',
					'.quark-settings-wrap > .updated',
					'.quark-settings-wrap > .error',
					'.quark-settings-wrap > .update-nag',
					'.quark-app-root .notice',
					'.quark-app-root .updated',
					'.quark-app-root .error',
					'.quark-app-root .update-nag',
				].join( ',' )
			);

			notices.forEach( ( notice ) => {
				if (
					notice.closest( '.components-notice' ) ||
					notice.getAttribute( 'data-quark-notice' ) === 'true'
				) {
					return;
				}

				notice.remove();
			} );
		};

		let scheduled = false;
		const scheduleMove = () => {
			if ( scheduled ) {
				return;
			}

			scheduled = true;
			window.requestAnimationFrame( () => {
				scheduled = false;
				removeExternalAdminNotices();
			} );
		};

		removeExternalAdminNotices();

		const observer = new MutationObserver( scheduleMove );
		observer.observe( target, {
			childList: true,
			subtree: true,
		} );

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
		} catch ( error ) {
			setCopied( '' );
		}
	};

	const statusClass = data.isConnected
		? 'quark-pill quark-pill--status is-connected'
		: 'quark-pill quark-pill--status is-disconnected';
	const tabs = [
		{ name: 'about', title: 'About' },
		{ name: 'connectors', title: 'Connectors' },
		{ name: 'connections', title: 'Connections' },
	];
	if ( data.isConnected ) {
		tabs.push( { name: 'abilities', title: 'Abilities' } );
	}
	tabs.push( { name: 'changelog', title: 'Changelog' } );
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
					<h1 className="quark-app-title">Quark</h1>
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
					Connection revoked.
				</Notice>
			) }
			{ data.status === 'revoked_all' && (
				<Notice status="warning" isDismissible={ false }>
					All sessions revoked.
				</Notice>
			) }

			<TabPanel className="quark-tabs" tabs={ tabs }>
				{ ( tab ) => {
					if ( tab.name === 'about' ) {
						return (
							<Card className="quark-card quark-about-card">
								<CardHeader>About Quark</CardHeader>
								<CardBody>
									<p className="quark-copy quark-copy--first">
										Quark turns WordPress into a secure MCP
										server for AI assistants, so tools like
										ChatGPT and Claude can work with your
										site through a controlled WordPress
										consent flow.
									</p>
									<p className="quark-copy">
										It is designed for practical site
										operations: connecting assistants,
										exposing approved abilities, and letting
										site owners decide exactly what AI
										clients are allowed to read or change.
									</p>

									<div className="quark-feature-grid">
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												Endpoint-only setup
											</h3>
											<p className="quark-feature-card__copy">
												Connect supported assistants
												with one MCP endpoint, then
												complete approval inside
												WordPress.
											</p>
										</div>
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												OAuth protected access
											</h3>
											<p className="quark-feature-card__copy">
												Connections use user-approved
												OAuth sessions with scoped
												access and revocation controls.
											</p>
										</div>
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												Content abilities
											</h3>
											<p className="quark-feature-card__copy">
												Enable read, create, and update
												actions for posts, pages, custom
												post types, categories, tags,
												and custom taxonomies.
											</p>
										</div>
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												Site-owner controls
											</h3>
											<p className="quark-feature-card__copy">
												Review active connections,
												revoke assistant sessions, and
												turn individual abilities on or
												off from the admin screen.
											</p>
										</div>
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												WordPress-native permissions
											</h3>
											<p className="quark-feature-card__copy">
												Tool actions respect WordPress
												capabilities, scoped tokens,
												pagination limits, and safe
												response shapes.
											</p>
										</div>
										<div className="quark-feature-card">
											<h3 className="quark-feature-card__title">
												Built to maintain
											</h3>
											<p className="quark-feature-card__copy">
												The plugin targets PHP 8.2, uses
												WordPress components, and
												follows the project standards
												for linting, static analysis,
												and production builds.
											</p>
										</div>
									</div>
								</CardBody>
							</Card>
						);
					}

					if ( tab.name === 'connectors' ) {
						return (
							<div className="quark-provider-list">
								{ providers.map( ( provider ) => (
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
														{ provider.label }
													</h3>
													<p className="quark-provider-card__description">
														{ provider.description }
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

											{ openProvider === provider.id && (
												<div className="quark-provider-panel">
													<div className="quark-provider-steps">
														<h4 className="quark-section-heading">
															Setup Steps
														</h4>
														<ol className="quark-steps">
															{ (
																provider.setupSteps ||
																[]
															).map(
																(
																	step,
																	index
																) => (
																	<li
																		key={ `${ provider.id }-${ index }` }
																	>
																		{ step }
																	</li>
																)
															) }
														</ol>
														<div className="quark-provider-actions">
															<Button
																href={
																	provider.primaryActionUrl
																}
																target="_blank"
																rel="noreferrer"
																variant="primary"
															>
																{ provider.id ===
																'chatgpt'
																	? 'Open ChatGPT'
																	: 'Open Docs' }
															</Button>
														</div>
													</div>
													<div className="quark-provider-fields">
														<h4 className="quark-section-heading">
															Copy
														</h4>
														{ (
															provider.copyFields ||
															[]
														).map( ( field ) => (
															<CopyField
																key={ `${ provider.id }-${ field.label }` }
																label={
																	field.label
																}
																value={
																	field.value
																}
																onCopy={ (
																	value
																) =>
																	copyValue(
																		value,
																		`${ field.label } copied.`
																	)
																}
															/>
														) ) }
													</div>
												</div>
											) }
										</CardBody>
									</Card>
								) ) }
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
											No active assistant sessions yet.
											Add the MCP endpoint to Claude or
											ChatGPT and approve the WordPress
											consent screen.
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
																'MCP Client' }
														</strong>
														<span>
															{ session.provider }{ ' ' }
															· { session.user }
														</span>
														<code>
															{ (
																session.scopes ||
																[]
															).join( ' ' ) }
														</code>
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
														label="Revoke"
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
												label="Revoke All Sessions"
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
								<CardHeader>AI Abilities</CardHeader>
								<CardBody>
									<p className="quark-copy quark-copy--first">
										Choose which MCP abilities connected AI
										apps can see and call. WordPress
										capabilities are still checked on every
										request.
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
												Enable All
											</Button>
											<Button
												type="button"
												variant="secondary"
												onClick={ () =>
													setEnabledAbilities( [] )
												}
											>
												Disable All
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
																		<div className="quark-ability-row__meta">
																			<code>
																				{
																					ability.id
																				}
																			</code>
																			<span>
																				{
																					ability.scope
																				}
																			</span>
																			<span>
																				{ ability.readOnly
																					? 'Read'
																					: 'Write' }
																			</span>
																		</div>
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
