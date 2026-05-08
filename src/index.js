import { render, useState } from '@wordpress/element';
import './style.scss';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
	FlexItem,
	Notice,
	SelectControl,
	ToggleControl,
	TabPanel,
	TextControl,
} from '@wordpress/components';

function SettingsApp() {
	const data = window.quarkSettingsData || {};
	const [copied, setCopied] = useState(false);
	const [removeDataOnUninstall, setRemoveDataOnUninstall] = useState(Boolean(data.removeDataOnUninstall));
	const [openConnector, setOpenConnector] = useState(null);
	const [oauthSettings, setOauthSettings] = useState(data.oauthSettings || {});
	const [manualClientSecret, setManualClientSecret] = useState('');
	const isConnected = Boolean(data.isConnected);
	const statusClass = isConnected ? 'quark-pill quark-pill--status is-connected' : 'quark-pill quark-pill--status is-disconnected';
	const changelog = data.changelog && typeof data.changelog === 'object' ? data.changelog : {};

	const copyConfig = async () => {
		try {
			await navigator.clipboard.writeText(data.copyAll || '');
			setCopied(true);
		} catch (error) {
			setCopied(false);
		}
	};

	const configFields = Array.isArray(data.configFields) ? data.configFields : [];

	const copyValue = async (value) => {
		try {
			await navigator.clipboard.writeText(String(value || ''));
			setCopied(true);
		} catch (error) {
			setCopied(false);
		}
	};

	const renderActionForm = (actionName, nonce, label, destructive = false) => (
		<form method="post" action={data.actions?.adminPostUrl} className="quark-action-form">
			<input type="hidden" name="action" value={actionName} />
			<input type="hidden" name="_wpnonce" value={nonce} />
			<Button variant={destructive ? 'secondary' : 'primary'} isDestructive={destructive} type="submit">
				{label}
			</Button>
		</form>
	);

	return (
		<div className="quark-app-root">
			<div className="quark-app-header">
				<div className="quark-app-branding">
					<h1 className="quark-app-title">Quark</h1>
					<span className="quark-pill quark-pill--version">
						{data.version || '0.1.0'}
					</span>
				</div>
				<span className={statusClass}>
					{isConnected ? 'ChatGPT Connected' : 'Not Connected'}
				</span>
			</div>

			{data.status === 'connected' && (
				<Notice status="success" isDismissible={false}>
					ChatGPT connection marked active.
				</Notice>
			)}
			{data.status === 'revoked' && (
				<Notice status="warning" isDismissible={false}>
					ChatGPT connection revoked.
				</Notice>
			)}

			{data.advancedSaved === '1' && (
				<Notice status="success" isDismissible={false}>
					Advanced settings saved.
				</Notice>
			)}
			{data.oauthSaved === '1' && (
				<Notice status="success" isDismissible={false}>
					ChatGPT OAuth settings saved.
				</Notice>
			)}

			<TabPanel className="quark-tabs" tabs={[{ name: 'about', title: 'About' }, { name: 'connectors', title: 'Connectors' }, { name: 'changelog', title: 'Changelog' }, { name: 'advanced', title: 'Advanced' }]}>
				{(tab) => {
					if (tab.name === 'about') {
						return (
							<Card className="quark-card">
								<CardHeader>About Quark</CardHeader>
								<CardBody>
									<p className="quark-copy quark-copy--first">
										Quark connects WordPress with ChatGPT through MCP tools, OAuth, and structured
										content capabilities for posts, taxonomies, media, and site operations.
									</p>
									<p className="quark-copy quark-copy--last">
										Use the Connectors tab to configure integrations and Advanced for lifecycle
										behavior controls.
									</p>
								</CardBody>
							</Card>
						);
					}

					if (tab.name === 'advanced') {
						return (
							<Card className="quark-card">
								<CardHeader>Advanced Settings</CardHeader>
								<CardBody>
									<form method="post" action={data.actions?.adminPostUrl} className="quark-form quark-form--advanced">
										<input type="hidden" name="action" value={data.actions?.saveAdvancedAction} />
										<input type="hidden" name="_wpnonce" value={data.actions?.saveAdvancedNonce} />
										<ToggleControl
											label="Remove Data on Uninstall"
											checked={removeDataOnUninstall}
											onChange={(value) => setRemoveDataOnUninstall(Boolean(value))}
											help="When enabled, Quark deletes stored plugin data during uninstall."
										/>
										<input
											type="hidden"
											name="remove_data_on_uninstall"
											value={removeDataOnUninstall ? '1' : '0'}
										/>
										<div className="quark-form-actions">
											<Button type="submit" variant="primary">
												Save Advanced Settings
											</Button>
										</div>
									</form>
								</CardBody>
							</Card>
						);
					}

					if (tab.name === 'changelog') {
						const versions = Object.entries(changelog).slice(0, 3);
						return (
							<Card className="quark-card">
								<CardHeader>Changelog</CardHeader>
								<CardBody>
									{versions.length === 0 ? (
										<p className="quark-copy quark-copy--first">No changelog entries found.</p>
									) : (
										<div className="quark-changelog">
											{versions.map(([version, groups]) => (
												<div key={version} className="quark-changelog-version">
													<h3 className="quark-changelog-version-title">{version}</h3>
													{Object.entries(groups || {}).map(([group, items]) => (
														<div key={`${version}-${group}`} className="quark-changelog-group">
															<h4 className="quark-changelog-group-title">{group}</h4>
															<ul className="quark-changelog-list">
																{Array.isArray(items) &&
																	items.map((item, index) => (
																		<li key={`${version}-${group}-${index}`}>{item}</li>
																	))}
															</ul>
														</div>
													))}
												</div>
											))}
										</div>
									)}
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

					return (
						<div className="quark-connectors-flow">
							<Card className="quark-card quark-connector-card">
								<CardBody>
									<div className="quark-connector-row">
										<div className="quark-connector-name-wrap">
											<h3 className="quark-connector-name">ChatGPT</h3>
											{isConnected && <span className="quark-connector-state">Connected</span>}
										</div>
										<Button
											variant="link"
											onClick={() => setOpenConnector(openConnector === 'chatgpt' ? null : 'chatgpt')}
										>
											{openConnector === 'chatgpt' ? 'Close' : 'Configure'}
										</Button>
									</div>
									{openConnector === 'chatgpt' && (
										<div className="quark-connector-panel">
											<div className="quark-connector-instructions">
												<h4>Step 1: Open setup</h4>
												<p>Open ChatGPT connector settings from the button below.</p>
												<Button href={data.createAppUrl} variant="primary" target="_blank">
													Connect to ChatGPT
												</Button>
												<h4>Step 2: Select registration method</h4>
												<p>Choose the same registration method in ChatGPT and Quark, then paste values from the right panel.</p>
												<form method="post" action={data.actions?.adminPostUrl} className="quark-oauth-settings-form">
													<input type="hidden" name="action" value={data.actions?.saveOauthAction} />
													<input type="hidden" name="_wpnonce" value={data.actions?.saveOauthNonce} />
													<SelectControl
														label="Registration Method"
														name="registration_method"
														value={oauthSettings.registrationMethod || 'dcr'}
														options={(data.registrationMethods || []).map((method) => ({
															label: method.label,
															value: method.value,
														}))}
														onChange={(value) => setOauthSettings({ ...oauthSettings, registrationMethod: value })}
													/>
													<div className="quark-registration-methods">
														{(data.registrationMethods || []).map((method) => (
															<div
																key={method.value}
																className={`quark-registration-method ${oauthSettings.registrationMethod === method.value ? 'is-active' : ''}`}
															>
																<strong>{method.label}</strong>
																<span>{method.description}</span>
															</div>
														))}
													</div>
													{oauthSettings.registrationMethod === 'user_defined' && (
														<div className="quark-manual-client-fields">
															<TextControl
																label="Client ID"
																name="manual_client_id"
																value={oauthSettings.manualClientId || ''}
																onChange={(value) => setOauthSettings({ ...oauthSettings, manualClientId: value })}
															/>
															<TextControl
																label="Client Secret"
																name="manual_client_secret"
																type="password"
																value={manualClientSecret}
																placeholder={oauthSettings.manualClientSecretPreview ? `Stored: ${oauthSettings.manualClientSecretPreview}` : ''}
																onChange={setManualClientSecret}
															/>
															<SelectControl
																label="Token Endpoint Auth Method"
																name="manual_token_endpoint_auth_method"
																value={oauthSettings.manualTokenEndpointAuthMethod || 'client_secret_post'}
																options={oauthSettings.tokenEndpointAuthMethods || []}
																onChange={(value) => setOauthSettings({ ...oauthSettings, manualTokenEndpointAuthMethod: value })}
															/>
														</div>
													)}
													{oauthSettings.registrationMethod === 'cmid' && (
														<TextControl
															label="Client Identifier Metadata Document URL"
															name="cmid_url"
															value={oauthSettings.cmidUrl || ''}
															onChange={(value) => setOauthSettings({ ...oauthSettings, cmidUrl: value })}
															help="CMID is draft/experimental. Keep DCR enabled for current ChatGPT production compatibility."
														/>
													)}
													<div className="quark-form-actions">
														<Button type="submit" variant="secondary">
															Save OAuth Method
														</Button>
													</div>
												</form>
												<h4>Step 3: Validate and confirm</h4>
												<p>Complete one OAuth authorization in ChatGPT, then confirm here.</p>
												<div className="quark-connector-actions">
													{renderActionForm(data.actions?.markConnectedAction, data.actions?.markConnectedNonce, 'Added The App')}
													{isConnected && renderActionForm(data.actions?.revokeAction, data.actions?.revokeNonce, 'Revoke Connection', true)}
												</div>
											</div>
											<div className="quark-connector-fields">
												<h4>Copy Fields</h4>
												<div className="quark-config-grid">
													{configFields.map((field) => (
														<Flex key={field.key} align="flex-end" gap={2} className="quark-config-row">
															<FlexBlock>
																<TextControl label={field.label} value={String(field.value ?? '')} readOnly />
															</FlexBlock>
															<FlexItem>
																<Button
																	className="quark-copy-button"
																	label={`Copy ${field.label}`}
																	icon="admin-page"
																	onClick={() => copyValue(field.value)}
																	variant="secondary"
																/>
															</FlexItem>
														</Flex>
													))}
												</div>
												<div className="quark-form-actions">
													<Button onClick={copyConfig} variant="tertiary">
														{copied ? 'Copied' : 'Copy All'}
													</Button>
												</div>
											</div>
										</div>
									)}
								</CardBody>
							</Card>
						</div>
					);
				}}
			</TabPanel>
		</div>
	);
}

const root = document.getElementById('quark-settings-app-root');
if (root) {
	render(<SettingsApp />, root);
}
