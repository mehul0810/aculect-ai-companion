import { useState, useRef } from '@wordpress/element';
import { Button, Modal } from '@wordpress/components';
export function ActionForm( {
	data,
	action,
	nonce,
	label,
	children,
	destructive = false,
	onSubmit,
	isBusy = false,
	busyLabel = '',
	disabled = false,
	variant = '',
	enctype = '',
	confirmMessage = '',
	confirmTitle = 'Confirm action',
	confirmButtonLabel = '',
	buttonContent = null,
	buttonClassName = '',
	formClassName = '',
	accessibleLabel = '',
} ) {
	const [ isConfirmOpen, setIsConfirmOpen ] = useState( false );
	const formRef = useRef( null );
	const confirmedSubmitRef = useRef( false );
	const submitLabel = isBusy && busyLabel ? busyLabel : label;
	const isDisabled = disabled || isBusy;
	const handleSubmit = ( event ) => {
		if ( confirmMessage && ! confirmedSubmitRef.current ) {
			event.preventDefault();
			setIsConfirmOpen( true );
			return false;
		}

		confirmedSubmitRef.current = false;

		if ( onSubmit ) {
			return onSubmit( event );
		}

		return undefined;
	};
	const submitConfirmedAction = () => {
		confirmedSubmitRef.current = true;
		setIsConfirmOpen( false );

		if ( formRef.current?.requestSubmit ) {
			formRef.current.requestSubmit();
			return;
		}

		formRef.current?.submit();
	};

	return (
		<>
			<form
				ref={ formRef }
				method="post"
				action={ data.actions?.adminPostUrl }
				className={ [
					'aculect-ai-companion-action-form',
					formClassName,
				]
					.filter( Boolean )
					.join( ' ' ) }
				onSubmit={ handleSubmit }
				{ ...( enctype ? { encType: enctype } : {} ) }
			>
				<input type="hidden" name="action" value={ action } />
				<input type="hidden" name="_wpnonce" value={ nonce } />
				{ children }
				<Button
					type="submit"
					className={ buttonClassName }
					variant={
						variant || ( destructive ? 'secondary' : 'primary' )
					}
					isDestructive={ destructive }
					isBusy={ isBusy }
					disabled={ isDisabled }
					accessibleWhenDisabled
					aria-label={ accessibleLabel || undefined }
				>
					{ buttonContent || submitLabel }
				</Button>
			</form>
			{ isConfirmOpen && (
				<Modal
					title={ confirmTitle }
					onRequestClose={ () => setIsConfirmOpen( false ) }
				>
					<p>{ confirmMessage }</p>
					<div className="aculect-ai-companion-confirm-dialog__actions">
						<Button
							type="button"
							variant="secondary"
							onClick={ () => setIsConfirmOpen( false ) }
						>
							Cancel
						</Button>
						<Button
							type="button"
							variant="primary"
							isDestructive={ destructive }
							onClick={ submitConfirmedAction }
						>
							{ confirmButtonLabel || label }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}
