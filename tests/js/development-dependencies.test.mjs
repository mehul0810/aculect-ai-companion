import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = fileURLToPath( new URL( '../../', import.meta.url ) );
const projectRequire = createRequire( import.meta.url );
// Probe the existing toolchain's dependencies without adding them to the product.
const toolingRequire = createRequire(
	projectRequire.resolve( '@wordpress/scripts/package.json' )
);
const lock = JSON.parse(
	readFileSync( join( root, 'package-lock.json' ), 'utf8' )
);
const minimums = {
	'@modelcontextprotocol/sdk': '1.31.0',
	axios: '1.20.0',
	colord: '2.9.4',
	compression: '1.8.2',
	joi: '18.2.9',
	katex: '0.18.2',
	'markdown-it': '14.3.1',
	moment: '2.31.0',
	'postcss-selector-parser': '7.1.6',
	'proxy-addr': '2.0.8',
	'shell-quote': '1.11.0',
	'smol-toml': '1.9.0',
	'source-map-js': '1.2.2',
};

function copies( packages, name ) {
	return Object.entries( packages ).filter( ( [ path ] ) =>
		( '/' + path ).endsWith( '/node_modules/' + name )
	);
}

function assertMinimum( version, minimum, path ) {
	assert.match( version, /^\d+\.\d+\.\d+$/, path );
	const actual = version.split( '.' ).map( Number );
	const expected = minimum.split( '.' ).map( Number );
	const different = actual.findIndex(
		( part, index ) => part !== expected[ index ]
	);
	assert.ok(
		different === -1 || actual[ different ] > expected[ different ],
		`${ path }: ${ version } must be at least ${ minimum }`
	);
}

function assertPatchedCopies( packages ) {
	for ( const [ name, minimum ] of Object.entries( minimums ) ) {
		const entries = copies( packages, name );
		assert.ok(
			entries.length,
			`${ name } must remain in the tooling graph`
		);
		for ( const [ path, entry ] of entries ) {
			assertMinimum( entry.version, minimum, path );
			assert.equal(
				entry.dev,
				true,
				`${ path } must remain development-only`
			);
		}
	}
	assert.equal( copies( packages, 'sprintf-js' ).length, 0 );
	const yamlCopies = copies( packages, 'js-yaml' );
	assert.ok(
		yamlCopies.length,
		'YAML configuration support must remain available'
	);
	for ( const [ path, entry ] of yamlCopies ) {
		const minimum = { 4: '4.3.2', 5: '5.4.1' }[
			entry.version.split( '.' )[ 0 ]
		];
		assert.ok(
			minimum,
			`${ path } must not restore the YAML 3 / sprintf-js chain`
		);
		assertMinimum( entry.version, minimum, path );
	}
}

test( 'every locked copy meets the development dependency security floors', () => {
	assertPatchedCopies( lock.packages );
	assert.deepEqual( lock.packages[ '' ].dependencies ?? {}, {} );
} );

test( 'security floors reject a vulnerable nested copy and the unpatched sprintf chain', () => {
	const packages = structuredClone( lock.packages );
	packages[ 'node_modules/fixture/node_modules/axios' ] = {
		version: '1.19.0',
		dev: true,
	};
	assert.throws( () => assertPatchedCopies( packages ), /axios.*1\.20\.0/ );
	delete packages[ 'node_modules/fixture/node_modules/axios' ];
	packages[ 'node_modules/fixture/node_modules/sprintf-js' ] = {
		version: '1.1.3',
		dev: true,
	};
	assert.throws( () => assertPatchedCopies( packages ) );
} );

test( 'Istanbul still loads YAML configuration, inheritance and scalar lists', async ( t ) => {
	const directory = mkdtempSync( join( tmpdir(), 'aculect-nyc-config-' ) );
	t.after( () => rmSync( directory, { recursive: true, force: true } ) );
	writeFileSync( join( directory, 'package.json' ), '{}' );
	writeFileSync(
		join( directory, 'base.yml' ),
		'check-coverage: true\nbranches: 80\n'
	);
	writeFileSync(
		join( directory, '.nycrc.yml' ),
		'extends: ./base.yml\ninclude: src/**/*.js\nexclude:\n  - tests/**\nreporter: text\n'
	);
	const { loadNycConfig } = toolingRequire( '@istanbuljs/load-nyc-config' );
	const configuration = await loadNycConfig( { cwd: directory } );
	assert.equal( configuration.checkCoverage, true );
	assert.equal( configuration.branches, 80 );
	assert.deepEqual( configuration.include, [ 'src/**/*.js' ] );
	assert.deepEqual( configuration.exclude, [ 'tests/**' ] );
	assert.equal( configuration.reporter, 'text' );
	assert.equal( configuration.cwd, directory );
	writeFileSync(
		join( directory, '.nycrc.yml' ),
		'include: [unterminated\n'
	);
	await assert.rejects(
		loadNycConfig( { cwd: directory } ),
		/end of the stream|flow collection/
	);
} );

test( 'both YAML consumer majors bound empty merge sources without breaking normal merges', () => {
	for ( const consumer of [
		'@istanbuljs/load-nyc-config',
		'markdownlint-cli',
	] ) {
		const consumerRequire = createRequire(
			join( root, 'node_modules', consumer, 'package.json' )
		);
		const yaml = consumerRequire( 'js-yaml' );
		const schema = yaml.DEFAULT_SCHEMA ?? yaml.YAML11_SCHEMA;
		const document =
			'empty: &empty {}\nmerged:\n  <<: [' +
			Array( 20 ).fill( '*empty' ).join( ', ' ) +
			']\n';
		assert.throws(
			() => yaml.load( document, { schema, maxTotalMergeKeys: 8 } ),
			/merge|maxTotalMergeKeys/i
		);
		assert.deepEqual(
			yaml.load(
				'base: &base { branches: 80 }\nmerged: { <<: *base, lines: 90 }',
				{ schema }
			).merged,
			{ branches: 80, lines: 90 }
		);
	}
} );

test( 'shell quoting rejects comment line breaks and preserves ordinary argument round trips', () => {
	const shell = toolingRequire( 'shell-quote' );
	for ( const terminator of [ '\n', '\r', '\u2028', '\u2029' ] ) {
		assert.throws(
			() =>
				shell.quote( [
					'echo',
					{ comment: 'fixture' },
					terminator + 'echo fixture',
				] ),
			/line terminators/
		);
		assert.throws(
			() => shell.quote( [ { comment: 'fixture' + terminator } ] ),
			/line terminators/
		);
	}
	const argumentsList = [
		'npm',
		'run',
		'build',
		'name with spaces',
		'dollar$sign',
		"quote'and!",
	];
	assert.deepEqual(
		shell.parse( shell.quote( argumentsList ) ),
		argumentsList
	);
} );

test( 'selector parsing retains CSS minifier APIs and handles long flat selectors', async () => {
	const parser = toolingRequire( 'postcss-selector-parser' );
	for ( const prefix of [ '.fixture', '#fixture' ] ) {
		const flat = prefix.repeat( 12000 );
		assert.equal( parser().processSync( flat ), flat );
	}
	const selector = '.fixture:hover > [data-name="a,b"], :is(.a, .b)::before';
	assert.equal( parser().processSync( selector ), selector );
	const postcss = toolingRequire( 'postcss' );
	const plugins = [
		toolingRequire( 'postcss-calc' )( { selectors: true } ),
		toolingRequire( 'postcss-merge-rules' )(),
		toolingRequire( 'postcss-minify-selectors' )(),
		toolingRequire( 'postcss-unique-selectors' )(),
		toolingRequire( 'stylehacks' )(),
	];
	const css = '.b, .a, .a { width: calc(1px + 2px) } .c { width: 3px }';
	const result = await postcss( plugins ).process( css, { from: undefined } );
	assert.match( result.css, /width:\s*3px/ );
	assert.match( result.css, /\.a/ );
	assert.match( result.css, /\.b/ );
	assert.match( result.css, /\.c/ );
	assert.doesNotMatch( result.css, /calc\(|\.a,\s*\.a/ );
} );

test( 'the patched KaTeX remains compatible with the markdown math extension', async () => {
	const { micromark } = await import(
		pathToFileURL( toolingRequire.resolve( 'micromark' ) ).href
	);
	const { math, mathHtml } = await import(
		pathToFileURL( toolingRequire.resolve( 'micromark-extension-math' ) )
			.href
	);
	const html = micromark( 'Inline $x^2$ and display:\n\n$$\nx + y\n$$\n', {
		extensions: [ math() ],
		htmlExtensions: [ mathHtml() ],
	} );
	assert.match( html, /math-inline/ );
	assert.match( html, /math-display/ );
	assert.match( html, /katex/ );
} );
