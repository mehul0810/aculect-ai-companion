import { useState } from '@wordpress/element';
import { Button, Modal, Notice, ToggleControl } from '@wordpress/components';
import { SkillReview } from './SkillReview';
import { importRequest, parseImportPreview } from './skillReviewModel';

export function SkillImport( {
	request,
	perform,
	skills,
	busy,
	notice,
	onClose,
} ) {
	const [ file, setFile ] = useState( null );
	const [ replace, setReplace ] = useState( false );
	const [ preview, setPreview ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ reading, setReading ] = useState( false );
	async function review() {
		setError( '' );
		setReading( true );
		try {
			if ( ! file || file.size > 270336 ) {
				throw new Error( 'Choose a package no larger than 264 KB.' );
			}
			const fileText = await file.text();
			const after = parseImportPreview( fileText );
			const existing = skills.find(
				( skill ) =>
					skill.provider === 'custom' && skill.id === after.id
			);
			if ( existing && ! replace ) {
				throw new Error(
					'This ID already exists. Enable replacement to review its changes.'
				);
			}
			const before = existing
				? ( await request( `/${ encodeURIComponent( after.id ) }` ) )
						.skill
				: null;
			setPreview( {
				after,
				before,
				body: importRequest( fileText, replace, before ),
			} );
		} catch ( caught ) {
			setError( caught.message );
		} finally {
			setReading( false );
		}
	}
	return (
		<Modal
			title="Import skill"
			onRequestClose={ onClose }
			shouldCloseOnClickOutside={ false }
		>
			<p>
				Imports and replacements are saved disabled. Review every
				instruction and reference before importing, then enable
				separately. No file is sent to Aculect.
			</p>
			{ ( error || notice?.status === 'error' ) && (
				<Notice status="error" isDismissible={ false }>
					{ error || notice.message }
				</Notice>
			) }
			{ preview ? (
				<SkillReview
					before={ preview.before }
					after={ preview.after }
					busy={ busy }
					onBack={ () => setPreview( null ) }
					confirmLabel={
						preview.before
							? 'Replace and disable skill'
							: 'Import disabled skill'
					}
					onConfirm={ () =>
						perform( async () => {
							await request( '/import', 'POST', preview.body );
							onClose();
						}, 'Skill imported as disabled. Review it before enabling.' )
					}
				/>
			) : (
				<>
					<label htmlFor="aculect-skill-import-file">
						Skill package (.json)
					</label>
					<input
						id="aculect-skill-import-file"
						type="file"
						accept=".json,application/json"
						disabled={ reading }
						onChange={ ( event ) =>
							setFile( event.target.files?.[ 0 ] || null )
						}
					/>
					<ToggleControl
						label="Replace an existing custom skill with the same ID"
						checked={ replace }
						disabled={ reading }
						onChange={ setReplace }
						help="Review the current and proposed guidance before replacement."
					/>
					<div className="aculect-skills__modal-actions">
						<Button variant="secondary" onClick={ onClose }>
							Cancel
						</Button>
						<Button
							variant="primary"
							disabled={ busy || reading || ! file }
							isBusy={ reading }
							onClick={ review }
						>
							Review import
						</Button>
					</div>
				</>
			) }
		</Modal>
	);
}
