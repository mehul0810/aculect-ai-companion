import { Button } from '@wordpress/components';
import { reviewFields } from './skillReviewModel';

export function SkillReview( {
	before,
	after,
	busy,
	onBack,
	onConfirm,
	confirmLabel,
} ) {
	return (
		<section aria-label="Review skill changes">
			<h2>Review { after.id }</h2>
			<p>
				Check the purpose, tools, evidence requirements, and
				confirmation instructions. Guidance cannot grant access or
				replace tool safeguards.
			</p>
			{ before ? (
				<p style={ { overflowWrap: 'anywhere' } }>
					Replacing version { before.version }, digest{ ' ' }
					{ before.digest }. If this saved version changes, the
					request will fail with a conflict.
				</p>
			) : (
				<p>This creates a new custom skill.</p>
			) }
			{ reviewFields( before, after ).map( ( row ) => (
				<details key={ row.label } open={ row.changed }>
					<summary>
						{ row.label } —{ ' ' }
						{ row.changed ? 'Changed' : 'Unchanged' }
					</summary>
					{ before && (
						<>
							<h3>Current</h3>
							<pre
								style={ {
									whiteSpace: 'pre-wrap',
									overflowWrap: 'anywhere',
								} }
							>
								{ row.before }
							</pre>
						</>
					) }
					<h3>Proposed</h3>
					<pre
						style={ {
							whiteSpace: 'pre-wrap',
							overflowWrap: 'anywhere',
						} }
					>
						{ row.after }
					</pre>
				</details>
			) ) }
			<div className="aculect-skills__modal-actions">
				<Button
					variant="secondary"
					disabled={ busy }
					onClick={ onBack }
				>
					Back
				</Button>
				<Button
					variant="primary"
					disabled={ busy }
					isBusy={ busy }
					onClick={ onConfirm }
				>
					{ confirmLabel }
				</Button>
			</div>
		</section>
	);
}
