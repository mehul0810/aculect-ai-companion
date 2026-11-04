export function editorRecord( editor ) {
	const list = ( value ) =>
		value
			.split( ',' )
			.map( ( item ) => item.trim() )
			.filter( Boolean );
	return {
		id: editor.id,
		skill_md: editor.skill_md,
		references: editor.references || {},
		required_abilities: list( editor.abilitiesText ),
		required_plugins: list( editor.pluginsText ),
		enabled: Boolean( editor.enabled ),
	};
}

export function reviewFields( before, after ) {
	const fields = [
		[ 'skill_md', 'SKILL.md' ],
		[ 'required_abilities', 'Required abilities' ],
		[ 'required_plugins', 'Required plugins' ],
		[ 'enabled', 'Enabled' ],
	];
	const display = ( value ) => {
		if ( value === undefined ) {
			return '(not present)';
		}
		return typeof value === 'string'
			? value
			: JSON.stringify( value, null, 2 );
	};
	const rows = fields.map( ( [ key, label ] ) => ( {
		label,
		before: display( before?.[ key ] ),
		after: display( after[ key ] ),
	} ) );
	const paths = new Set( [
		...Object.keys( before?.references || {} ),
		...Object.keys( after.references || {} ),
	] );
	for ( const path of paths ) {
		rows.push( {
			label: path,
			before: display( before?.references?.[ path ] ),
			after: display( after.references?.[ path ] ),
		} );
	}
	return rows.map( ( row ) => ( {
		...row,
		changed: row.before !== row.after,
	} ) );
}

export function parseImportPreview( fileText ) {
	let value;
	try {
		value = JSON.parse( fileText );
	} catch {
		throw new Error( 'Skill package must be valid JSON.' );
	}
	const skill = value?.skill;
	if (
		value?.format !== 'aculect-mcp-skill' ||
		value.format_version !== 1 ||
		! skill ||
		typeof skill.id !== 'string' ||
		! skill.id.startsWith( 'custom-' ) ||
		typeof skill.skill_md !== 'string' ||
		! skill.references ||
		typeof skill.references !== 'object' ||
		! Array.isArray( skill.required_abilities ) ||
		! Array.isArray( skill.required_plugins ) ||
		Object.values( skill.references ).some(
			( content ) => typeof content !== 'string'
		)
	) {
		throw new Error(
			'Choose a valid Aculect custom skill package. WordPress validates the complete package when you confirm.'
		);
	}
	return { ...skill, enabled: false };
}

export function importRequest( fileText, replace, before ) {
	return {
		package: fileText,
		replace,
		...( replace && before
			? {
					expected_version: before.version,
					expected_digest: before.digest,
			  }
			: {} ),
	};
}
