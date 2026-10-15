import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Notice,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

const EMPTY_SKILL = {
	id: 'custom-new-skill',
	skill_md:
		'---\nname: custom-new-skill\ndescription: Describe when this guidance applies\n---\n\n# Workflow\n\nDescribe the steps to take using the abilities available to this connection.\n',
	references: {},
	abilitiesText: '',
	pluginsText: '',
	enabled: true,
};

function commaSeparated( values ) {
	return Array.isArray( values ) ? values.join( ', ' ) : '';
}

function parseCommaSeparated( value ) {
	return value
		.split( ',' )
		.map( ( item ) => item.trim() )
		.filter( Boolean );
}

function skillLabel( skill ) {
	return skill?.name || skill?.id || 'Untitled skill';
}

function errorMessage( payload, fallback ) {
	return payload?.message || payload?.error || fallback;
}

function ReferenceEditor( { references, onChange } ) {
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

export function SkillsDashboard( { apiUrl, nonce } ) {
	const [ skills, setSkills ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ editor, setEditor ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );
	const [ importing, setImporting ] = useState( false );
	const [ importFile, setImportFile ] = useState( null );
	const [ replaceImport, setReplaceImport ] = useState( false );
	const [ duplicate, setDuplicate ] = useState( null );
	const [ duplicateId, setDuplicateId ] = useState( '' );

	const request = useCallback(
		async ( path = '', method = 'GET', body ) => {
			const response = await fetch( `${ apiUrl }${ path }`, {
				method,
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': nonce,
					...( body ? { 'Content-Type': 'application/json' } : {} ),
				},
				...( body ? { body: JSON.stringify( body ) } : {} ),
			} );
			const payload = await response.json().catch( () => ( {} ) );
			if ( ! response.ok || payload.success === false ) {
				throw new Error(
					errorMessage( payload, 'The skill request failed.' )
				);
			}
			return payload;
		},
		[ apiUrl, nonce ]
	);

	async function refresh() {
		const payload = await request();
		setSkills( Array.isArray( payload.skills ) ? payload.skills : [] );
	}

	useEffect( () => {
		let active = true;
		if ( ! apiUrl || ! nonce ) {
			setLoading( false );
			return undefined;
		}
		request()
			.then( ( payload ) => {
				if ( active ) {
					setSkills(
						Array.isArray( payload.skills ) ? payload.skills : []
					);
				}
			} )
			.catch( ( error ) => {
				if ( active ) {
					setNotice( { status: 'error', message: error.message } );
				}
			} )
			.finally( () => {
				if ( active ) {
					setLoading( false );
				}
			} );
		return () => {
			active = false;
		};
	}, [ apiUrl, nonce, request ] );

	async function perform( action, successMessage ) {
		setBusy( true );
		setNotice( null );
		try {
			await action();
			await refresh();
			setNotice( { status: 'success', message: successMessage } );
		} catch ( error ) {
			setNotice( { status: 'error', message: error.message } );
		} finally {
			setBusy( false );
		}
	}

	async function openEditor( skill ) {
		if ( ! skill ) {
			setEditor( { ...EMPTY_SKILL, isNew: true } );
			return;
		}
		setBusy( true );
		try {
			const payload = await request(
				`/${ encodeURIComponent( skill.id ) }`
			);
			setEditor( {
				...payload.skill,
				abilitiesText: commaSeparated(
					payload.skill.required_abilities
				),
				pluginsText: commaSeparated( payload.skill.required_plugins ),
				isNew: false,
			} );
		} catch ( error ) {
			setNotice( { status: 'error', message: error.message } );
		} finally {
			setBusy( false );
		}
	}

	async function saveEditor() {
		const isNew = editor.isNew;
		await perform(
			async () => {
				await request(
					isNew ? '' : `/${ encodeURIComponent( editor.id ) }`,
					isNew ? 'POST' : 'PUT',
					{
						id: editor.id,
						skill_md: editor.skill_md,
						references: editor.references,
						required_abilities: parseCommaSeparated(
							editor.abilitiesText
						),
						required_plugins: parseCommaSeparated(
							editor.pluginsText
						),
						enabled: editor.enabled,
						...( isNew
							? {}
							: {
									expected_version: editor.version,
									expected_digest: editor.digest,
							  } ),
					}
				);
				setEditor( null );
			},
			isNew ? 'Skill created.' : 'Skill saved.'
		);
	}

	async function exportSkill( skill ) {
		setBusy( true );
		try {
			const payload = await request(
				`/${ encodeURIComponent( skill.id ) }/export`
			);
			const blob = new Blob( [ payload.package ], {
				type: 'application/json',
			} );
			const url = URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = `${ skill.id }.aculect-skill.json`;
			link.click();
			setTimeout( () => URL.revokeObjectURL( url ), 30000 );
		} catch ( error ) {
			setNotice( { status: 'error', message: error.message } );
		} finally {
			setBusy( false );
		}
	}

	async function importSkill() {
		if ( ! importFile ) {
			return;
		}
		if ( importFile.size > 270336 ) {
			setNotice( {
				status: 'error',
				message: 'The package is too large. The limit is 264 KB.',
			} );
			return;
		}
		await perform( async () => {
			const fileText = await importFile.text();
			let packageData;
			try {
				packageData = JSON.parse( fileText );
			} catch {
				throw new Error( 'Skill package must be valid JSON.' );
			}
			const existing = skills.find(
				( skill ) =>
					skill.provider === 'custom' &&
					skill.id === packageData?.skill?.id
			);
			await request( '/import', 'POST', {
				package: fileText,
				replace: replaceImport,
				...( replaceImport && existing
					? {
							expected_version: existing.version,
							expected_digest: existing.digest,
					  }
					: {} ),
			} );
			setImporting( false );
			setImportFile( null );
			setReplaceImport( false );
		}, 'Skill imported as disabled. Review it before enabling.' );
	}

	return (
		<section
			className="aculect-skills"
			aria-labelledby="aculect-skills-title"
		>
			<header className="aculect-skills__header">
				<div>
					<p className="aculect-skills__eyebrow">
						Site-local guidance
					</p>
					<h1 id="aculect-skills-title">Skills</h1>
					<p>
						Give compatible assistants reusable WordPress
						instructions. A skill never grants an ability or
						bypasses your connection permissions.
					</p>
				</div>
				<div className="aculect-skills__header-actions">
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () => setImporting( true ) }
					>
						Import skill
					</Button>
					<Button
						variant="primary"
						disabled={ busy }
						onClick={ () => openEditor( null ) }
					>
						Create skill
					</Button>
				</div>
			</header>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			{ loading ? (
				<p role="status">Loading skills…</p>
			) : (
				<div className="aculect-skills__grid">
					{ skills.length === 0 && (
						<p>No skills are available on this site yet.</p>
					) }
					{ skills.map( ( skill ) => (
						<article
							className="aculect-skills__card"
							key={ `${ skill.provider }-${ skill.id }` }
						>
							<div className="aculect-skills__card-top">
								<span className="aculect-skills__provider">
									{ skill.provider || 'custom' }
								</span>
								<span
									className={ `aculect-skills__state ${
										skill.enabled ? 'is-enabled' : ''
									}` }
								>
									{ skill.enabled ? 'Enabled' : 'Disabled' }
								</span>
							</div>
							<h2>{ skillLabel( skill ) }</h2>
							<p>
								{ skill.description ||
									'No description provided.' }
							</p>
							{ skill.availability_reason && (
								<p className="aculect-skills__availability">
									{ skill.availability_reason }
								</p>
							) }
							<dl>
								<div>
									<dt>Version</dt>
									<dd>{ skill.version || '1' }</dd>
								</div>
								<div>
									<dt>Digest</dt>
									<dd title={ skill.digest || '' }>
										{ skill.digest
											? `${ skill.digest.slice(
													0,
													19
											  ) }…`
											: 'Not available' }
									</dd>
								</div>
							</dl>
							{ skill.provider === 'custom' && (
								<div className="aculect-skills__card-actions">
									<Button
										variant="secondary"
										disabled={ busy }
										onClick={ () => openEditor( skill ) }
									>
										Edit
									</Button>
									<Button
										variant="tertiary"
										disabled={ busy }
										onClick={ () =>
											perform(
												() =>
													request(
														`/${ encodeURIComponent(
															skill.id
														) }/enabled`,
														'POST',
														{
															enabled:
																! skill.enabled,
															expected_version:
																skill.version,
															expected_digest:
																skill.digest,
														}
													),
												skill.enabled
													? 'Skill disabled.'
													: 'Skill enabled.'
											)
										}
									>
										{ skill.enabled ? 'Disable' : 'Enable' }
									</Button>
									<Button
										variant="tertiary"
										disabled={ busy }
										onClick={ () => {
											setDuplicate( skill );
											setDuplicateId(
												`${ skill.id }-copy`
											);
										} }
									>
										Duplicate
									</Button>
									<Button
										variant="tertiary"
										disabled={ busy }
										onClick={ () => exportSkill( skill ) }
									>
										Export
									</Button>
									<Button
										variant="tertiary"
										isDestructive
										disabled={ busy }
										onClick={ () => setDeleting( skill ) }
									>
										Delete
									</Button>
								</div>
							) }
						</article>
					) ) }
				</div>
			) }
			{ editor && (
				<Modal
					title={
						editor.isNew ? 'Create skill' : `Edit ${ editor.id }`
					}
					onRequestClose={ () => setEditor( null ) }
					shouldCloseOnClickOutside={ false }
					className="aculect-skills__editor"
				>
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
						help="Include name and description frontmatter, followed by instructions and declared dependencies."
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
						<Button
							variant="secondary"
							onClick={ () => setEditor( null ) }
						>
							Cancel
						</Button>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={
								busy || ! editor.id || ! editor.skill_md
							}
							onClick={ saveEditor }
						>
							Save skill
						</Button>
					</div>
				</Modal>
			) }
			{ duplicate && (
				<Modal
					title={ `Duplicate ${ duplicate.id }` }
					onRequestClose={ () => setDuplicate( null ) }
					shouldCloseOnClickOutside={ false }
				>
					<TextControl
						label="New skill ID"
						value={ duplicateId }
						onChange={ setDuplicateId }
					/>
					<div className="aculect-skills__modal-actions">
						<Button
							variant="secondary"
							onClick={ () => setDuplicate( null ) }
						>
							Cancel
						</Button>
						<Button
							variant="primary"
							disabled={ busy || ! duplicateId }
							isBusy={ busy }
							onClick={ () =>
								perform( async () => {
									await request(
										`/${ encodeURIComponent(
											duplicate.id
										) }/duplicate`,
										'POST',
										{ id: duplicateId }
									);
									setDuplicate( null );
								}, 'Skill duplicated.' )
							}
						>
							Duplicate skill
						</Button>
					</div>
				</Modal>
			) }
			{ deleting && (
				<Modal
					title="Delete skill?"
					onRequestClose={ () => setDeleting( null ) }
					shouldCloseOnClickOutside={ false }
				>
					<p>
						This removes <strong>{ skillLabel( deleting ) }</strong>{ ' ' }
						from this site. Connected assistants will no longer be
						able to discover it.
					</p>
					<div className="aculect-skills__modal-actions">
						<Button
							variant="secondary"
							onClick={ () => setDeleting( null ) }
						>
							Cancel
						</Button>
						<Button
							variant="primary"
							isDestructive
							disabled={ busy }
							isBusy={ busy }
							onClick={ () =>
								perform( async () => {
									await request(
										`/${ encodeURIComponent(
											deleting.id
										) }`,
										'DELETE',
										{
											expected_version: deleting.version,
											expected_digest: deleting.digest,
										}
									);
									setDeleting( null );
								}, 'Skill deleted.' )
							}
						>
							Delete skill
						</Button>
					</div>
				</Modal>
			) }
			{ importing && (
				<Modal
					title="Import skill"
					onRequestClose={ () => setImporting( false ) }
					shouldCloseOnClickOutside={ false }
				>
					<p>
						Choose an Aculect skill JSON package. Import validates
						every field and reference before saving it to this site.
						The imported skill stays disabled until you review and
						enable it. Replacing a skill also disables it. No file
						is sent to Aculect.
					</p>
					<label
						className="aculect-skills__file-label"
						htmlFor="aculect-skill-import-file"
					>
						Skill package (.json)
					</label>
					<input
						id="aculect-skill-import-file"
						type="file"
						accept=".json,application/json"
						onChange={ ( event ) =>
							setImportFile( event.target.files?.[ 0 ] || null )
						}
					/>
					<ToggleControl
						label="Replace an existing custom skill with the same ID"
						checked={ replaceImport }
						onChange={ setReplaceImport }
						help="Leave off to reject an ID conflict without changing the existing skill."
					/>
					<div className="aculect-skills__modal-actions">
						<Button
							variant="secondary"
							onClick={ () => setImporting( false ) }
						>
							Cancel
						</Button>
						<Button
							variant="primary"
							disabled={ busy || ! importFile }
							isBusy={ busy }
							onClick={ importSkill }
						>
							Import skill
						</Button>
					</div>
				</Modal>
			) }
		</section>
	);
}
