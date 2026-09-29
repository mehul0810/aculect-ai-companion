import { Icon } from '@wordpress/components';

export function OverviewFeatureCard( { icon, title, children } ) {
	return (
		<div className="aculect-ai-companion-feature-card">
			<span
				className="aculect-ai-companion-feature-card__icon"
				aria-hidden="true"
			>
				<Icon icon={ icon } size={ 20 } />
			</span>
			<div className="aculect-ai-companion-feature-card__body">
				<h3 className="aculect-ai-companion-feature-card__title">
					{ title }
				</h3>
				<p className="aculect-ai-companion-feature-card__copy">
					{ children }
				</p>
			</div>
		</div>
	);
}

export function OverviewCircuit( { brandIconUrl } ) {
	return (
		<div
			className="aculect-ai-companion-overview-circuit"
			aria-hidden="true"
		>
			<span className="aculect-ai-companion-overview-circuit__line is-horizontal" />
			<span className="aculect-ai-companion-overview-circuit__line is-vertical" />
			<span className="aculect-ai-companion-overview-circuit__node is-top" />
			<span className="aculect-ai-companion-overview-circuit__node is-left" />
			<span className="aculect-ai-companion-overview-circuit__node is-right" />
			<span className="aculect-ai-companion-overview-circuit__node is-bottom" />
			<div className="aculect-ai-companion-overview-circuit__logo">
				{ brandIconUrl && (
					<img src={ brandIconUrl } alt="" aria-hidden="true" />
				) }
			</div>
		</div>
	);
}
