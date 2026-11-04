import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync(
	new URL( '../../src/Admin/skills/skillReviewModel.js', import.meta.url ),
	'utf8'
);
const { reviewFields, parseImportPreview, importRequest, editorRecord } =
	await import(
		`data:text/javascript;base64,${ Buffer.from( source ).toString(
			'base64'
		) }`
	);
const before = {
	id: 'custom-review',
	skill_md: 'Old instructions',
	enabled: true,
	references: { 'references/old.md': 'Old', 'references/keep.md': 'Same' },
	required_abilities: [ 'site.get_info' ],
	required_plugins: [],
	version: 4,
	digest: 'sha256:old',
};

test( 'review includes changed and removed guidance, dependencies, and activation', () => {
	const after = {
		...before,
		skill_md: 'New',
		references: {
			'references/keep.md': 'Same',
			'references/new.md': 'New',
		},
		enabled: false,
		required_abilities: [],
	};
	const rows = reviewFields( before, after );
	assert.equal(
		rows.find( ( row ) => row.label === 'references/old.md' ).after,
		'(not present)'
	);
	assert.equal(
		rows.find( ( row ) => row.label === 'references/keep.md' ).changed,
		false
	);
	assert.equal(
		rows.find( ( row ) => row.label === 'Enabled' ).after,
		'false'
	);
	assert.equal(
		rows.find( ( row ) => row.label === 'Required abilities' ).changed,
		true
	);
	assert.ok(
		rows.find( ( row ) => row.label === 'references/new.md' ).changed
	);
} );

test( 'import review forces disabled state and binds original bytes to reviewed CAS version', () => {
	const bytes = JSON.stringify( {
		format: 'aculect-mcp-skill',
		format_version: 1,
		skill: before,
	} );
	const after = parseImportPreview( bytes );
	assert.equal( after.enabled, false );
	const request = importRequest( bytes, true, before );
	before.version = 5;
	assert.equal( request.expected_version, 4 );
	assert.equal( request.expected_digest, 'sha256:old' );
	assert.equal( request.package, bytes );
	assert.equal(
		importRequest( bytes, false, null ).expected_version,
		undefined
	);
	assert.throws( () => parseImportPreview( '{' ), /valid JSON/ );
	assert.throws(
		() => parseImportPreview( '{"skill":{}}' ),
		/valid Aculect/
	);
} );

test( 'editor preview matches fields passed to save without adding an execution channel', () => {
	const after = editorRecord( {
		...before,
		abilitiesText: 'site.get_info, site.get_health',
		pluginsText: '',
	} );
	assert.deepEqual( after.required_abilities, [
		'site.get_info',
		'site.get_health',
	] );
	assert.deepEqual( after.required_plugins, [] );
	assert.equal( after.skill_md, before.skill_md );
	assert.equal( after.version, undefined );
} );

// Run the real component handlers with a tiny hook/element harness. This verifies
// the review gate and exact request binding; it is not rendered browser proof.
async function componentHarness( filename, name ) {
	// Babel is provided by the locked @wordpress/scripts test/build toolchain.
	// eslint-disable-next-line import/no-extraneous-dependencies
	const { transformSync } = await import( '@babel/core' );
	const { runInNewContext } = await import( 'node:vm' );
	const code = transformSync(
		readFileSync(
			new URL( `../../src/Admin/skills/${ filename }`, import.meta.url ),
			'utf8'
		),
		{
			babelrc: false,
			configFile: false,
			plugins: [
				[
					'@babel/plugin-transform-react-jsx',
					{ pragma: 'h', pragmaFrag: 'Fragment' },
				],
				'@babel/plugin-transform-modules-commonjs',
			],
		}
	).code;
	const state = [];
	let cursor = 0;
	const exports = {};
	const h = ( type, props, ...children ) => ( {
		type,
		props: props || {},
		children: children.flat( Infinity ),
	} );
	runInNewContext( code, {
		exports,
		h,
		Fragment: 'Fragment',
		require( module ) {
			if ( module === '@wordpress/element' ) {
				return {
					useState( initial ) {
						const index = cursor++;
						if ( ! ( index in state ) ) {
							state[ index ] = initial;
						}
						return [
							state[ index ],
							( next ) => {
								state[ index ] = next;
							},
						];
					},
				};
			}
			if ( module === '@wordpress/components' ) {
				return Object.fromEntries(
					[
						'Button',
						'Notice',
						'Modal',
						'TextControl',
						'TextareaControl',
						'ToggleControl',
					].map( ( key ) => [ key, key ] )
				);
			}
			return {
				ReferenceEditor: 'ReferenceEditor',
				SkillReview: 'SkillReview',
				editorRecord,
				parseImportPreview,
				importRequest,
			};
		},
	} );
	return ( props ) => {
		cursor = 0;
		return exports[ name ]( props );
	};
}
function findNode( tree, predicate ) {
	if ( ! tree || typeof tree !== 'object' ) {
		return null;
	}
	if ( predicate( tree ) ) {
		return tree;
	}
	for ( const child of tree.children || [] ) {
		const found = findNode( child, predicate );
		if ( found ) {
			return found;
		}
	}
	return null;
}

test( 'editor requires review before save and going back does not save', async () => {
	const render = await componentHarness( 'SkillEditor.js', 'SkillEditor' );
	let saves = 0;
	const props = {
		editor: {
			...before,
			original: before,
			abilitiesText: '',
			pluginsText: '',
		},
		saveEditor: () => {
			saves++;
		},
		busy: false,
	};
	let tree = render( props );
	assert.equal(
		findNode( tree, ( node ) => node.type === 'SkillReview' ),
		null
	);
	findNode(
		tree,
		( node ) =>
			node.type === 'Button' && node.children.includes( 'Review changes' )
	).props.onClick();
	assert.equal( saves, 0 );
	tree = render( props );
	const review = findNode( tree, ( node ) => node.type === 'SkillReview' );
	assert.equal( review.props.before.digest, before.digest );
	review.props.onBack();
	assert.equal( saves, 0 );
	findNode(
		render( props ),
		( node ) =>
			node.type === 'Button' && node.children.includes( 'Review changes' )
	).props.onClick();
	findNode(
		render( props ),
		( node ) => node.type === 'SkillReview'
	).props.onConfirm();
	assert.equal( saves, 1 );
} );

test( 'import reads replacement before preview and writes only after explicit confirmation', async () => {
	const render = await componentHarness( 'SkillImport.js', 'SkillImport' );
	const bytes = JSON.stringify( {
		format: 'aculect-mcp-skill',
		format_version: 1,
		skill: before,
	} );
	const calls = [];
	const props = {
		skills: [ { ...before, provider: 'custom' } ],
		busy: false,
		onClose() {},
		perform: ( action ) => action(),
		request: async ( ...args ) => {
			calls.push( args );
			return {
				skill: { ...before, version: 7, digest: 'sha256:reviewed' },
			};
		},
	};
	let tree = render( props );
	findNode( tree, ( node ) => node.type === 'input' ).props.onChange( {
		target: { files: [ { size: bytes.length, text: async () => bytes } ] },
	} );
	findNode( tree, ( node ) => node.type === 'ToggleControl' ).props.onChange(
		true
	);
	tree = render( props );
	await findNode(
		tree,
		( node ) =>
			node.type === 'Button' && node.children.includes( 'Review import' )
	).props.onClick();
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ][ 0 ], '/custom-review' );
	const review = findNode(
		render( props ),
		( node ) => node.type === 'SkillReview'
	);
	assert.equal( review.props.after.enabled, false );
	await review.props.onConfirm();
	assert.equal( calls[ 1 ][ 0 ], '/import' );
	assert.equal( calls[ 1 ][ 1 ], 'POST' );
	assert.equal( calls[ 1 ][ 2 ].expected_version, 7 );
	assert.equal( calls[ 1 ][ 2 ].expected_digest, 'sha256:reviewed' );
	assert.equal( calls[ 1 ][ 2 ].package, bytes );
} );
