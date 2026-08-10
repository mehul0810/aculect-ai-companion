export function connectToolFilteringViewModel( provider ) {
	const guidance = provider?.toolFiltering;

	if ( ! guidance || ! Array.isArray( guidance.toolSets ) ) {
		return null;
	}

	return {
		provider,
		guidance,
		copyFields: Array.isArray( guidance.copyFields )
			? guidance.copyFields
			: [],
	};
}

export function copyToolFilteringField( field, onCopy ) {
	onCopy( field.value, field.copiedMessage );
}
