import React, { createElement } from 'react';
import { render } from 'react-dom';
import { AgentReadinessPanel } from '../../src/Admin/diagnostics/AgentReadinessPanel';

window.React = React;
window.renderAgentReadiness = ( readiness, isRunning = false ) => {
	render(
		createElement( AgentReadinessPanel, {
			readiness,
			isRunning,
			onCopy: ( value ) => { window.agentReadinessCopied = value; },
		} ),
		document.getElementById( 'fixture-root' )
	);
};
