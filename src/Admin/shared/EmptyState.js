export function EmptyState( { title, children } ) {
	return (
		<div className="aculect-ai-companion-empty-state">
			<strong>{ title }</strong>
			{ children && <p>{ children }</p> }
		</div>
	);
}
