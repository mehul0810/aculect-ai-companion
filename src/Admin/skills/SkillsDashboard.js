import { SkillEditor } from './SkillEditor';
import { SkillImport } from './SkillImport';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Modal, Notice, TextControl } from '@wordpress/components';

const EMPTY_SKILL = {
	id: 'custom-new-skill',
	skill_md:
		'---\nname: custom-new-skill\ndescription: Describe when this guidance applies\n---\n\n## Purpose\n\nDescribe the task and intended outcome.\n\n## Tools\n\nUse only tools available to this connection. Declare required abilities below.\n\n## Evidence\n\nReport observations, sources, and unknowns before recommendations.\n\n## Confirmation\n\nReview proposed changes with the user and follow existing tool confirmation requirements. This guidance grants no permissions.\n',
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

export function SkillsDashboard( { apiUrl, nonce } ) {
	const [ skills, setSkills ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ editor, setEditor ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );
	const [ importing, setImporting ] = useState( false );
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
				original: payload.skill,
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
				<SkillEditor
					notice={ notice }
					editor={ editor }
					setEditor={ setEditor }
					busy={ busy }
					saveEditor={ saveEditor }
					onClose={ () => setEditor( null ) }
				/>
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
				<SkillImport
					notice={ notice }
					request={ request }
					perform={ perform }
					skills={ skills }
					busy={ busy }
					onClose={ () => setImporting( false ) }
				/>
			) }
		</section>
	);
}
