import { useState } from '@wordpress/element';
import {
	Button,
	Modal,
	SelectControl,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { Icon, check, trash } from '@wordpress/icons';
import { ActionForm } from '../shared/ActionForm';
import { connectionDateValue } from '../shared/value-utils.mjs';
import {
	LEARNING_DOMAIN_LABELS,
	LEARNING_STATUS_LABELS,
	LEARNING_CONFIDENCE_LABELS,
	learningDomainLabel,
	learningStatusLabel,
	learningConfidenceLabel,
} from '../shared/learning-labels.mjs';
function MemoryIdentityInputs( { record } ) {
	return (
		<>
			<input
				type="hidden"
				name="memory_item[namespace]"
				value={ record.namespace || '' }
			/>
			<input
				type="hidden"
				name="memory_item[expected_version]"
				value={ record.version || '' }
			/>
		</>
	);
}
function MemoryRecordHiddenInputs( { record, action } ) {
	return (
		<>
			<input type="hidden" name="memory_key" value={ record.key || '' } />
			<input type="hidden" name="memory_action" value={ action } />
			<MemoryIdentityInputs record={ record } />
			<input
				type="hidden"
				name="memory_item[visibility]"
				value={ record.visibility || 'private' }
			/>
			<input
				type="hidden"
				name="memory_item[key]"
				value={ record.key || '' }
			/>
			<input
				type="hidden"
				name="memory_item[domain]"
				value={ record.domain || 'content' }
			/>
			<input
				type="hidden"
				name="memory_item[value]"
				value={ record.value || '' }
			/>
			<input
				type="hidden"
				name="memory_item[evidence]"
				value={ record.evidence || '' }
			/>
			<input
				type="hidden"
				name="memory_item[confidence]"
				value={ record.confidence || 'medium' }
			/>
			<input
				type="hidden"
				name="memory_item[source]"
				value={ record.source || 'admin' }
			/>
		</>
	);
}

function MemoryRecordReviewForm( {
	data,
	record,
	action,
	label,
	icon,
	destructive = false,
} ) {
	return (
		<ActionForm
			data={ data }
			action={ data.actions?.reviewMemoryAction }
			nonce={ data.actions?.reviewMemoryNonce }
			label={ label }
			variant={ destructive ? 'secondary' : 'primary' }
			destructive={ destructive }
			confirmMessage={ destructive ? `${ label } this memory item?` : '' }
			confirmTitle="Review memory"
			buttonContent={
				<>
					<Icon icon={ icon } size={ 16 } />
					<span>{ label }</span>
				</>
			}
			disabled={
				! data.actions?.reviewMemoryAction ||
				! data.actions?.reviewMemoryNonce
			}
		>
			<MemoryRecordHiddenInputs record={ record } action={ action } />
		</ActionForm>
	);
}

function MemoryRecordEditModal( { data, record, onClose } ) {
	const [ formValues, setFormValues ] = useState( {
		key: record.key || '',
		domain: record.domain || 'content',
		value: record.value || '',
		evidence: record.evidence || '',
		confidence: record.confidence || 'medium',
		status: record.status || 'pending',
		visibility: record.visibility || 'private',
		source: record.source || 'admin',
	} );
	const updateValue = ( key ) => ( value ) => {
		setFormValues( ( current ) => ( {
			...current,
			[ key ]: value,
		} ) );
	};

	return (
		<Modal title="Edit memory item" onRequestClose={ onClose }>
			<form
				method="post"
				action={ data.actions?.adminPostUrl }
				className="aculect-ai-companion-learning-edit-form"
			>
				<input
					type="hidden"
					name="action"
					value={ data.actions?.reviewMemoryAction }
				/>
				<input
					type="hidden"
					name="_wpnonce"
					value={ data.actions?.reviewMemoryNonce }
				/>
				<input
					type="hidden"
					name="memory_key"
					value={ record.key || '' }
				/>
				<input type="hidden" name="memory_action" value="update" />
				<MemoryIdentityInputs record={ record } />
				<TextControl
					label="Key"
					name="memory_item[key]"
					value={ formValues.key }
					readOnly
				/>
				<SelectControl
					label="Domain"
					name="memory_item[domain]"
					value={ formValues.domain }
					options={ Object.entries( LEARNING_DOMAIN_LABELS ).map(
						( [ value, label ] ) => ( { value, label } )
					) }
					onChange={ updateValue( 'domain' ) }
				/>
				<TextareaControl
					label="Value"
					name="memory_item[value]"
					value={ formValues.value }
					onChange={ updateValue( 'value' ) }
					rows={ 4 }
				/>
				<TextareaControl
					label="Evidence"
					name="memory_item[evidence]"
					value={ formValues.evidence }
					onChange={ updateValue( 'evidence' ) }
					rows={ 3 }
				/>
				<SelectControl
					label="Confidence"
					name="memory_item[confidence]"
					value={ formValues.confidence }
					options={ Object.entries( LEARNING_CONFIDENCE_LABELS ).map(
						( [ value, label ] ) => ( { value, label } )
					) }
					onChange={ updateValue( 'confidence' ) }
				/>
				<SelectControl
					label="Status"
					name="memory_item[status]"
					value={ formValues.status }
					options={ Object.entries( LEARNING_STATUS_LABELS ).map(
						( [ value, label ] ) => ( { value, label } )
					) }
					onChange={ updateValue( 'status' ) }
				/>
				<SelectControl
					label="Memory sharing"
					help="Approval does not share private memory. Choose site sharing explicitly to make this guidance available to connected AI tools."
					name="memory_item[visibility]"
					value={ formValues.visibility }
					onChange={ updateValue( 'visibility' ) }
					options={ [
						{
							value: 'private',
							label: 'Private — stay on this site',
						},
						{
							value: 'site',
							label: 'Share with connected AI tools',
						},
						...( record.visibility === 'connection'
							? [
									{
										value: 'connection',
										label: 'Existing connection visibility',
									},
							  ]
							: [] ),
					] }
				/>
				<input
					type="hidden"
					name="memory_item[source]"
					value={ formValues.source }
				/>
				<div className="aculect-ai-companion-confirm-dialog__actions">
					<Button
						type="button"
						variant="secondary"
						onClick={ onClose }
					>
						Cancel
					</Button>
					<Button
						type="submit"
						variant="primary"
						disabled={
							! data.actions?.reviewMemoryAction ||
							! data.actions?.reviewMemoryNonce
						}
						accessibleWhenDisabled
					>
						Save Memory
					</Button>
				</div>
			</form>
		</Modal>
	);
}

export function MemoryRecordCard( { data, record } ) {
	const [ isEditing, setIsEditing ] = useState( false );
	const status = record.status || 'pending';
	const domain = record.domain || 'content';
	const isApproved = status === 'approved';
	const isDismissed = status === 'dismissed';

	return (
		<article
			className={ `aculect-ai-companion-learning-card aculect-ai-companion-memory-card is-${ status }` }
		>
			<div className="aculect-ai-companion-learning-card__header">
				<div>
					<h3>{ record.key || 'Memory item' }</h3>
					<p className="aculect-ai-companion-learning-source">
						<span>{ record.source || 'manual' }</span>
						<code>{ record.namespace || 'site' }</code>
						<span>
							{ {
								site: 'Shared with AI tools',
								connection: 'Connection visibility',
							}[ record.visibility ] || 'Private' }
						</span>
					</p>
				</div>
				<div className="aculect-ai-companion-learning-card__badges">
					<span
						className={ `aculect-ai-companion-learning-pill is-${ domain }` }
					>
						{ learningDomainLabel( domain ) }
					</span>
					<span
						className={ `aculect-ai-companion-learning-pill is-${ status }` }
					>
						{ learningStatusLabel( status ) }
					</span>
					<span className="aculect-ai-companion-learning-pill is-confidence">
						{ learningConfidenceLabel( record.confidence ) }
					</span>
				</div>
			</div>
			<div className="aculect-ai-companion-learning-card__body">
				<dl className="aculect-ai-companion-learning-details">
					<div>
						<dt>Value</dt>
						<dd>{ record.value || '-' }</dd>
					</div>
					{ record.evidence && (
						<div>
							<dt>Evidence</dt>
							<dd>{ record.evidence }</dd>
						</div>
					) }
				</dl>
			</div>
			<div className="aculect-ai-companion-learning-card__footer">
				<span className="aculect-ai-companion-learning-card__date">
					{ connectionDateValue( record.updated_at, 'Updated' ) }
				</span>
				<div className="aculect-ai-companion-learning-actions">
					<Button
						type="button"
						variant="secondary"
						onClick={ () => setIsEditing( true ) }
					>
						Edit
					</Button>
					{ ! isApproved && (
						<MemoryRecordReviewForm
							data={ data }
							record={ record }
							action="approve"
							label="Approve"
							icon={ check }
						/>
					) }
					{ ! isDismissed && (
						<MemoryRecordReviewForm
							data={ data }
							record={ record }
							action="dismiss"
							label="Dismiss"
							icon={ trash }
							destructive
						/>
					) }
					<MemoryRecordReviewForm
						data={ data }
						record={ record }
						action="delete"
						label="Delete"
						icon={ trash }
						destructive
					/>
				</div>
			</div>
			{ isEditing && (
				<MemoryRecordEditModal
					data={ data }
					record={ record }
					onClose={ () => setIsEditing( false ) }
				/>
			) }
		</article>
	);
}
