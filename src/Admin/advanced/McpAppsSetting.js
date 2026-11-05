import { ToggleControl } from '@wordpress/components';
import { plugins } from '@wordpress/icons';
import { AdvancedSection, AdvancedSettingRow } from './AdvancedLayout';

export function McpAppsSetting( { settings, enabled, onChange, formId } ) {
	const overridden =
		typeof settings?.effectiveEnabled === 'boolean' &&
		settings.effectiveEnabled !== settings.enabled;

	return (
		<AdvancedSection
			icon={ plugins }
			title="MCP Apps"
			description="Interactive responses in compatible AI assistants."
		>
			<AdvancedSettingRow
				title="Interactive cards and pickers"
				description="Show visual results and selection tools when your assistant supports them. Text responses remain available."
				status="Experimental"
			>
				<input
					type="hidden"
					name="mcp_apps_enabled"
					value={ enabled ? '1' : '0' }
					form={ formId }
				/>
				<ToggleControl
					label="Enable MCP Apps"
					checked={ enabled }
					onChange={ ( checked ) => onChange( Boolean( checked ) ) }
					help="Save your changes, then reconnect your assistant to refresh its supported features."
				/>
				{ overridden && (
					<p className="aculect-ai-companion-help-text" role="status">
						{ settings.effectiveEnabled
							? 'Site code currently keeps MCP Apps enabled, overriding the saved preference.'
							: 'Site code currently keeps MCP Apps disabled, overriding the saved preference.' }
					</p>
				) }
			</AdvancedSettingRow>
		</AdvancedSection>
	);
}
