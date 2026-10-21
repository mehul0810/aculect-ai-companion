import { Icon } from '@wordpress/icons';

export function AdvancedSection( { icon, title, description, children } ) {
	return (
		<section className="aculect-ai-companion-advanced-section">
			<div className="aculect-ai-companion-advanced-section__header">
				<span
					className="aculect-ai-companion-advanced-section__icon"
					aria-hidden="true"
				>
					<Icon icon={ icon } size={ 20 } />
				</span>
				<div>
					<h3 className="aculect-ai-companion-advanced-section__title">
						{ title }
					</h3>
					<p className="aculect-ai-companion-advanced-section__description">
						{ description }
					</p>
				</div>
			</div>
			<div className="aculect-ai-companion-advanced-section__body">
				{ children }
			</div>
		</section>
	);
}

export function AdvancedSettingRow( {
	title,
	description,
	status,
	statusTone = '',
	children,
} ) {
	return (
		<div className="aculect-ai-companion-advanced-setting">
			<div className="aculect-ai-companion-advanced-setting__content">
				<div className="aculect-ai-companion-advanced-setting__title">
					<h4 className="aculect-ai-companion-advanced-setting__heading">
						{ title }
					</h4>
					{ status && (
						<span
							className={ `aculect-ai-companion-advanced-setting__status ${ statusTone }` }
						>
							{ status }
						</span>
					) }
				</div>
				<p className="aculect-ai-companion-advanced-setting__description">
					{ description }
				</p>
			</div>
			{ children && (
				<div className="aculect-ai-companion-advanced-setting__control">
					{ children }
				</div>
			) }
		</div>
	);
}
