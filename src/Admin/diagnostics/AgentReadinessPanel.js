import { Button, Icon } from '@wordpress/components';
import { copy } from '@wordpress/icons';
import {
	AGENT_READINESS_STATUSES,
	agentReadinessExportText,
	agentReadinessRows,
	agentReadinessStatusLabel,
} from './agent-readiness-ui.mjs';

export function AgentReadinessPanel( { readiness, isRunning, onCopy } ) {
	const rows = agentReadinessRows( readiness );
	const lastRun = /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(
		readiness?.ranAt || ''
	)
		? readiness.ranAt
		: '';

	return (
		<section
			className="aculect-ai-companion-agent-readiness"
			aria-labelledby="aculect-agent-readiness-title"
			aria-busy={ isRunning }
		>
			<div className="aculect-ai-companion-agent-readiness__header">
				<div>
					<h2 id="aculect-agent-readiness-title">Agent readiness</h2>
					<p>
						Read-only observations about plugin and site-owned
						discovery. Results do not prove that an external
						assistant can reach this site.
					</p>
				</div>
				<Button
					type="button"
					variant="secondary"
					disabled={ rows.length === 0 }
					onClick={ () =>
						onCopy(
							agentReadinessExportText( readiness ),
							'Redacted agent readiness copied.'
						)
					}
				>
					<Icon icon={ copy } size={ 16 } /> Copy redacted report
				</Button>
			</div>
			<p className="aculect-ai-companion-agent-readiness__meta">
				{ lastRun
					? `Last checked ${ lastRun } UTC`
					: 'No saved agent-readiness run yet.' }
				{ isRunning
					? ' Checks are running; saved results remain visible.'
					: '' }
			</p>
			{ rows.length === 0 ? (
				<div
					className="aculect-ai-companion-agent-readiness__empty"
					role="status"
				>
					Run all checks to record agent-readiness observations. This
					does not change site settings.
				</div>
			) : (
				<ul className="aculect-ai-companion-agent-readiness__list">
					{ rows.map( ( row ) => (
						<li
							key={ row.id }
							className="aculect-ai-companion-agent-readiness__row"
						>
							<div className="aculect-ai-companion-agent-readiness__identity">
								<strong>{ row.label }</strong>
								<span>Owner: { row.owner }</span>
							</div>
							<span
								className={ `aculect-ai-companion-agent-readiness__status is-${ row.status }` }
							>
								{ agentReadinessStatusLabel( row.status ) }
							</span>
							<p>{ row.guidance }</p>
							{ row.evidence.length > 0 && (
								<small className="aculect-ai-companion-agent-readiness__evidence">
									{ row.evidence.join( ' · ' ) }
								</small>
							) }
						</li>
					) ) }
				</ul>
			) }
			<div
				className="aculect-ai-companion-agent-readiness__legend"
				role="group"
				aria-label="Readiness status meanings"
			>
				{ AGENT_READINESS_STATUSES.map( ( status ) => (
					<span
						key={ status.id }
						className={ `aculect-ai-companion-agent-readiness__status is-${ status.id }` }
					>
						{ status.label }
					</span>
				) ) }
			</div>
		</section>
	);
}
