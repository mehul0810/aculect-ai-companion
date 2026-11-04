import { useState } from '@wordpress/element';
import {
	Button,
	Notice,
	Modal,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { ReferenceEditor } from './ReferenceEditor';
import { SkillReview } from './SkillReview';
import { editorRecord } from './skillReviewModel';

export function SkillEditor( {
	editor,
	setEditor,
	busy,
	saveEditor,
	notice,
	onClose,
} ) {
	const [ reviewing, setReviewing ] = useState( false );
	return (
		<Modal
			title={ editor.isNew ? 'Create skill' : `Edit ${ editor.id }` }
			onRequestClose={ () => onClose() }
			shouldCloseOnClickOutside={ false }
			className="aculect-skills__editor"
		>
			{ notice?.status === 'error' && (
				<Notice status="error" isDismissible={ false }>
					{ notice.message }
				</Notice>
			) }
			{ ! reviewing ? (
				<>
					<p>
						Only declarative Markdown and bounded reference files
						are accepted. Use existing WordPress abilities; this
						does not create a new action channel.
					</p>
					<TextControl
						label="Skill ID"
						help="Start with custom- and use lowercase letters, numbers, and hyphens. This ID cannot be changed after creation."
						value={ editor.id }
						disabled={ ! editor.isNew }
						onChange={ ( id ) =>
							setEditor( {
								...editor,
								id,
								skill_md: editor.skill_md.replace(
									`name: ${ editor.id }\n`,
									`name: ${ id }\n`
								),
							} )
						}
					/>
					<TextareaControl
						label="SKILL.md"
						help="Include name and description frontmatter, then Purpose, Tools, Evidence, and Confirmation sections. Declare dependencies below."
						value={ editor.skill_md || '' }
						onChange={ ( content ) =>
							setEditor( { ...editor, skill_md: content } )
						}
						rows={ 16 }
					/>
					<ReferenceEditor
						references={ editor.references || {} }
						onChange={ ( references ) =>
							setEditor( { ...editor, references } )
						}
					/>
					<TextControl
						label="Required abilities"
						help="Optional comma-separated Aculect ability IDs, such as site.get_info. A skill is hidden from connections without every required ability."
						value={ editor.abilitiesText }
						onChange={ ( value ) =>
							setEditor( { ...editor, abilitiesText: value } )
						}
					/>
					<TextControl
						label="Required plugins"
						help="Optional comma-separated WordPress plugin basenames, such as example/example.php. A skill is hidden until each plugin is active."
						value={ editor.pluginsText }
						onChange={ ( value ) =>
							setEditor( { ...editor, pluginsText: value } )
						}
					/>
					{ editor.isNew && (
						<ToggleControl
							label="Enabled"
							checked={ Boolean( editor.enabled ) }
							onChange={ ( enabled ) =>
								setEditor( { ...editor, enabled } )
							}
						/>
					) }
					<div className="aculect-skills__modal-actions">
						<Button variant="secondary" onClick={ () => onClose() }>
							Cancel
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={
								busy || ! editor.id || ! editor.skill_md
							}
							onClick={ () => setReviewing( true ) }
						>
							Review changes
						</Button>
					</div>
				</>
			) : (
				<SkillReview
					before={ editor.original }
					after={ editorRecord( editor ) }
					busy={ busy }
					onBack={ () => setReviewing( false ) }
					onConfirm={ saveEditor }
					confirmLabel="Save skill"
				/>
			) }
		</Modal>
	);
}
