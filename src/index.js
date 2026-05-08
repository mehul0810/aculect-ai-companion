import { render, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Flex,
	FlexBlock,
	FlexItem,
	Notice,
	TabPanel,
	TextControl,
} from '@wordpress/components';

function SettingsApp() {
	const data = window.quarkSettingsData || {};
	const [copied, setCopied] = useState(false);
	const isConnected = Boolean(data.isConnected);

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

	const renderActionForm = (url, label, destructive = false) => (
		<form method="post" action={url}>
			<Button variant={destructive ? 'secondary' : 'primary'} isDestructive={destructive} type="submit">
				{label}
			</Button>
		</form>
	);

	return (
		<div style={{ maxWidth: 960 }}>
			<div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
				<div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
					<h1 style={{ margin: 0 }}>Quark</h1>
					<span style={{ padding: '2px 8px', border: '1px solid #dcdcde', borderRadius: 999 }}>
						{data.version || '0.1.0'}
					</span>
				</div>
				<span style={{ padding: '2px 8px', border: '1px solid #dcdcde', borderRadius: 999 }}>
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

			<TabPanel
				className="quark-provider-tabs"
				tabs={[
					{ name: 'chatgpt', title: 'ChatGPT' },
					{ name: 'codex', title: 'Codex' },
					{ name: 'claude', title: 'Claude' },
				]}
			>
				{(tab) => {
					if (tab.name !== 'chatgpt') {
						return (
							<Card>
								<CardBody>Provider integration UI is coming soon for this tab.</CardBody>
							</Card>
						);
					}

					if (isConnected) {
						return (
							<Card>
								<CardBody>
									<h2 style={{ marginTop: 0 }}>ChatGPT connection active</h2>
									{renderActionForm(data.actions?.revoke, 'Revoke Connection', true)}
								</CardBody>
							</Card>
						);
					}

					return (
						<div style={{ display: 'grid', gap: 12 }}>
							<Card>
								<CardBody>
									<h2 style={{ marginTop: 0 }}>Step 1: Open setup</h2>
									<Button href={data.createAppUrl} variant="primary" target="_blank">
										Connect to ChatGPT
									</Button>
								</CardBody>
							</Card>

							<Card>
								<CardBody>
									<h2 style={{ marginTop: 0 }}>Step 2: Copy setup details</h2>
									<p style={{ marginTop: 0 }}>
										In ChatGPT app creation, choose OAuth 2.1 with Dynamic Client Registration and PKCE.
										Use these values exactly.
									</p>
									<div style={{ display: 'grid', gap: 10 }}>
										{configFields.map((field) => (
											<Flex key={field.key} align="flex-end" gap={2}>
												<FlexBlock>
													<TextControl label={field.label} value={String(field.value ?? '')} readOnly />
												</FlexBlock>
												<FlexItem>
													<Button
														label={`Copy ${field.label}`}
														icon="admin-page"
														onClick={() => copyValue(field.value)}
														variant="secondary"
													/>
												</FlexItem>
											</Flex>
										))}
									</div>
									<div style={{ marginTop: 8 }}>
										<Button onClick={copyConfig} variant="tertiary">
											{copied ? 'Copied' : 'Copy All'}
										</Button>
									</div>
								</CardBody>
							</Card>

							<Card>
								<CardBody>
									<h2 style={{ marginTop: 0 }}>Step 3: Validate OAuth and confirm</h2>
									<p style={{ marginTop: 0 }}>
										After adding the app, complete one OAuth authorization in ChatGPT to verify
										DCR + PKCE token exchange, then confirm below.
									</p>
									{renderActionForm(data.actions?.markConnected, 'Added The App')}
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
