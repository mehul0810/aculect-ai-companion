import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

export function ReferenceEditor( { references, onChange } ) {
	const entries = Object.entries( references || {} );
	const [ pathError, setPathError ] = useState( '' );
	return (
		<div className="aculect-skills-references">
			<div className="aculect-skills-references__heading">
				<h3>Markdown references</h3>
				<Button
					variant="secondary"
					onClick={ () => {
						const next = { ...references };
						let index = entries.length + 1;
						while ( next[ `references/reference-${ index }.md` ] ) {
							index += 1;
						}
						next[ `references/reference-${ index }.md` ] = '';
						onChange( next );
					} }
				>
					Add reference
				</Button>
			</div>
			<p>
				References are Markdown files packaged with this skill. They are
				instructions, not executable code.
			</p>
			{ pathError && (
				<Notice status="warning" isDismissible={ false }>
					{ pathError }
				</Notice>
			) }
			{ entries.map( ( [ path, content ], index ) => (
				<div className="aculect-skills-reference" key={ index }>
					<TextControl
						label={ `Reference ${ index + 1 } path` }
						help="Use a relative path such as references/checklist.md."
						value={ path }
						onChange={ ( nextPath ) => {
							if (
								nextPath !== path &&
								Object.prototype.hasOwnProperty.call(
									references,
									nextPath
								)
							) {
								setPathError(
									'A different reference already uses that path.'
								);
								return;
							}
							setPathError( '' );
							const next = Object.fromEntries(
								entries.map(
									(
										[ itemPath, itemContent ],
										itemIndex
									) => [
										itemIndex === index
											? nextPath
											: itemPath,
										itemContent,
									]
								)
							);
							onChange( next );
						} }
					/>
					<TextareaControl
						label={ `Reference ${ index + 1 } Markdown` }
						value={ content }
						onChange={ ( nextContent ) =>
							onChange( { ...references, [ path ]: nextContent } )
						}
					/>
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () => {
							const next = { ...references };
							delete next[ path ];
							onChange( next );
						} }
					>
						Remove reference
					</Button>
				</div>
			) ) }
		</div>
	);
}
