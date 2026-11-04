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
	'@hono/node-server': '2.1.3',
	'@modelcontextprotocol/sdk': '1.32.2',
	'@puppeteer/browsers': '3.2.6',
	axios: '1.20.0',
	colord: '2.9.4',
	compression: '1.8.2',
	'fast-uri': '3.1.9',
	'follow-redirects': '1.16.2',
	hono: '4.13.11',
	'ip-address': '10.7.4',
	joi: '18.3.1',
	katex: '0.18.2',
	'launch-editor': '2.14.2',
	'markdown-it': '14.3.3',
	moment: '2.31.0',
	'postcss-selector-parser': '7.1.6',
	'proxy-addr': '2.0.8',
	'puppeteer-core': '25.16.0',
	qs: '6.16.1',
	'serialize-javascript': '7.1.3',
	'shell-quote': '1.11.0',
	'smol-toml': '1.9.0',
	'source-map-js': '1.2.2',
	zod: '4.6.6',
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
	for ( const [ path, entry ] of copies( packages, 'body-parser' ) ) {
		const minimum = { 1: '1.20.9', 2: '2.4.0' }[
			entry.version.split( '.' )[ 0 ]
		];
		assert.ok( minimum, `${ path } must retain a supported parser major` );
		assertMinimum( entry.version, minimum, path );
	}
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

test( 'installed tooling copies match the audited lockfile', () => {
	const names = [ ...Object.keys( minimums ), 'body-parser', 'zod' ];
	for ( const name of names ) {
		for ( const [ path, entry ] of copies( lock.packages, name ) ) {
			const installed = JSON.parse(
				readFileSync( join( root, path, 'package.json' ), 'utf8' )
			);
			assert.equal( installed.version, entry.version, path );
		}
	}
	for ( const consumer of [
		'@modelcontextprotocol/sdk',
		'eslint-plugin-react-hooks',
	] ) {
		const consumerRequire = createRequire(
			join( root, 'node_modules', consumer, 'package.json' )
		);
		assertMinimum(
			consumerRequire( 'zod/package.json' ).version,
			'4.6.6',
			consumer
		);
	}
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

test( 'patched Markdown and query parsers retain tooling input behavior', () => {
	const MarkdownIt = toolingRequire( 'markdown-it' );
	const markdown = new MarkdownIt( { html: true } );
	const html = markdown.render(
		'# Fixture\n\n[reference][target]\n\n[target]: https://example.test/docs\n\n<strong>safe fixture</strong>\n'
	);
	assert.match( html, /<h1>Fixture<\/h1>/ );
	assert.match( html, /href="https:\/\/example\.test\/docs"/ );
	assert.match( html, /<strong>safe fixture<\/strong>/ );
	const qs = toolingRequire( 'qs' );
	const input = { filters: { status: [ 'draft', 'publish' ] } };
	assert.deepEqual( qs.parse( qs.stringify( input ) ), input );
	assert.equal(
		qs.stringify( { tags: [ 'one', 'two' ] }, { arrayFormat: 'comma' } ),
		'tags=one%2Ctwo'
	);
	assert.doesNotThrow( () =>
		qs.stringify( { tags: [ { toString: 1 } ] }, { arrayFormat: 'comma' } )
	);
} );

test( 'schema-utils retains URI reference validation through patched fast-uri', () => {
	const { validate } = toolingRequire( 'schema-utils' );
	const schema = {
		type: 'object',
		properties: { value: { $ref: '#/definitions/value' } },
		definitions: { value: { type: 'integer', minimum: 1 } },
		required: [ 'value' ],
		additionalProperties: false,
	};
	assert.doesNotThrow( () => validate( schema, { value: 2 } ) );
	assert.throws( () => validate( schema, { value: 0 } ), /should be >= 1/ );
	assert.throws( () => validate( schema, { value: '2' } ), /integer/ );
} );

test( 'wait-on validation and copy-webpack serialization retain their consumer APIs', async () => {
	const waitOn = toolingRequire( 'wait-on' );
	await waitOn( {
		resources: [ join( root, 'package.json' ) ],
		timeout: 2000,
		interval: 10,
		window: 20,
	} );
	await assert.rejects( waitOn( { resources: [ 42 ] } ), /string/ );
	const copyRequire = createRequire(
		toolingRequire.resolve( 'copy-webpack-plugin/package.json' )
	);
	const serialize = copyRequire( 'serialize-javascript' );
	const cacheKey = serialize( {
		content: '</script><script>fixture</script>',
		transform: ( content ) => content.toUpperCase(),
	} );
	assert.doesNotMatch( cacheKey, /<\/script>/i );
	assert.match( cacheKey, /toUpperCase/ );
} );

test( 'the patched MCP SDK initializes, discovers and calls tools over an in-memory transport', async ( t ) => {
	const { Client } = await import(
		pathToFileURL(
			projectRequire.resolve(
				'@modelcontextprotocol/sdk/client/index.js'
			)
		).href
	);
	const { Server } = await import(
		pathToFileURL(
			projectRequire.resolve(
				'@modelcontextprotocol/sdk/server/index.js'
			)
		).href
	);
	const { InMemoryTransport } = projectRequire(
		'@modelcontextprotocol/sdk/inMemory.js'
	);
	const { ListToolsRequestSchema, CallToolRequestSchema } = projectRequire(
		'@modelcontextprotocol/sdk/types.js'
	);
	const client = new Client( {
		name: 'dependency-fixture',
		version: '1.0.0',
	} );
	const server = new Server(
		{ name: 'dependency-fixture', version: '1.0.0' },
		{ capabilities: { tools: {} } }
	);
	server.setRequestHandler( ListToolsRequestSchema, async () => ( {
		tools: [ { name: 'fixture_echo', inputSchema: { type: 'object' } } ],
	} ) );
	server.setRequestHandler( CallToolRequestSchema, async ( request ) => ( {
		content: [ { type: 'text', text: request.params.arguments.message } ],
	} ) );
	const [ clientTransport, serverTransport ] =
		InMemoryTransport.createLinkedPair();
	t.after( async () => {
		await client.close();
		await server.close();
	} );
	await server.connect( serverTransport );
	await client.connect( clientTransport );
	assert.equal(
		( await client.listTools() ).tools[ 0 ].name,
		'fixture_echo'
	);
	const result = await client.callTool( {
		name: 'fixture_echo',
		arguments: { message: 'fixture response' },
	} );
	assert.deepEqual( result.content, [
		{ type: 'text', text: 'fixture response' },
	] );
} );

test( 'WordPress can load Puppeteer and its BiDi parser accepts valid protocol inputs', async () => {
	const { getPuppeteer } = toolingRequire(
		'@wordpress/scripts/config/jest-environment-puppeteer/config.js'
	);
	const puppeteer = getPuppeteer( { browser: 'chromium' } );
	assert.equal( typeof puppeteer.launch, 'function' );
	assert.equal( typeof puppeteer.connect, 'function' );
	const argumentsList = await puppeteer.defaultArgs( { headless: true } );
	assert.ok(
		argumentsList.some( ( argument ) =>
			argument.startsWith( '--headless' )
		)
	);
	const bidiUrl = pathToFileURL( projectRequire.resolve( 'chromium-bidi' ) );
	const { BrowsingContext, Script, Session } = await import(
		new URL( './protocol-parser/protocol-parser.js', bidiUrl ).href
	);
	const navigation = {
		context: 'fixture-context',
		url: 'https://example.test/fixture',
		wait: 'complete',
	};
	assert.deepEqual(
		BrowsingContext.parseNavigateParams( navigation ),
		navigation
	);
	assert.throws( () =>
		BrowsingContext.parseNavigateParams( { ...navigation, context: 42 } )
	);
	const evaluation = {
		expression: '1 + 1',
		target: { context: 'fixture-context' },
		awaitPromise: true,
		userActivation: false,
	};
	assert.deepEqual( Script.parseEvaluateParams( evaluation ), evaluation );
	assert.throws( () =>
		Script.parseEvaluateParams( { ...evaluation, awaitPromise: 'yes' } )
	);
	const subscription = { events: [ 'browsingContext.load' ] };
	assert.deepEqual(
		Session.parseSubscribeParams( subscription ),
		subscription
	);
	assert.throws( () => Session.parseSubscribeParams( { events: [ 42 ] } ) );
} );
