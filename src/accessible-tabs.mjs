import { Button } from '@wordpress/components';
import { useRef } from '@wordpress/element';
import { nextTabIndex } from './accessible-tab-navigation.mjs';

/**
 * Render a keyboard-operable tab list using the ARIA Authoring Practices pattern.
 *
 * @param {Object}   props            Component properties.
 * @param {string}   props.className  Tab-list class name.
 * @param {string}   props.label      Accessible tab-list label.
 * @param {Array}    props.tabs       Available tab descriptors.
 * @param {string}   props.selectedId Selected tab identifier.
 * @param {Function} props.onSelect   Selection callback.
 */
export function AccessibleTabList( {
	className,
	label,
	tabs,
	selectedId,
	onSelect,
} ) {
	const tabRefs = useRef( new Map() );

	function moveFocus( event, index ) {
		const keys = [ 'ArrowLeft', 'ArrowRight', 'Home', 'End' ];
		if ( ! keys.includes( event.key ) ) {
			return;
		}

		event.preventDefault();
		const targetIndex = nextTabIndex( event.key, index, tabs.length );

		const target = tabs[ targetIndex ];
		onSelect( target.id );
		tabRefs.current.get( target.id )?.focus();
	}

	return (
		<div className={ className } role="tablist" aria-label={ label }>
			{ tabs.map( ( tab, index ) => {
				const selected = tab.id === selectedId;
				return (
					<Button
						key={ tab.id }
						ref={ ( node ) => tabRefs.current.set( tab.id, node ) }
						id={ `aculect-learning-tab-${ tab.id }` }
						type="button"
						variant={ selected ? 'primary' : 'secondary' }
						isPressed={ selected }
						role="tab"
						aria-selected={ selected }
						aria-controls={ `aculect-learning-panel-${ tab.id }` }
						tabIndex={ selected ? 0 : -1 }
						onClick={ () => onSelect( tab.id ) }
						onKeyDown={ ( event ) => moveFocus( event, index ) }
					>
						<span>{ tab.label }</span>
						<span className="aculect-ai-companion-learning-surface-nav__count">
							{ tab.count }
						</span>
					</Button>
				);
			} ) }
		</div>
	);
}
