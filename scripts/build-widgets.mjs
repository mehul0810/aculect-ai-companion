#!/usr/bin/env node
// Deterministic single-file MCP Apps fixture build.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { minify } from 'terser';

const root = fileURLToPath( new URL( '..', import.meta.url ) );
const runtime = join( root, 'widgets/runtime/v1' );
const widgets = [
	{
		source: runtime,
		target: 'assets/mcp-apps/runtime/v1/fixture.html',
		template: 'fixture.template.html',
		css: 'fixture.css',
		js: [
			join( runtime, 'state.js' ),
			join( runtime, 'bridge.js' ),
			join( runtime, 'fixture.js' ),
		],
	},
	{
		source: join( root, 'widgets/post-update/v1' ),
		target: 'assets/mcp-apps/post-update/v1/post-update.html',
		template: 'post-update.template.html',
		css: 'post-update.css',
		js: [
			join( runtime, 'bridge.js' ),
			join( root, 'widgets/post-update/v1/contract.js' ),
			join( root, 'widgets/post-update/v1/widget.js' ),
		],
	},
	{
		source: join( root, 'widgets/pattern-picker/v1' ),
		target: 'assets/mcp-apps/pattern-picker/v1/pattern-picker.html',
		template: 'pattern-picker.template.html',
		css: 'pattern-picker.css',
		maxJsBytes: 18 * 1024,
		js: [
			join( runtime, 'bridge.js' ),
			join( root, 'widgets/pattern-picker/v1/contract.js' ),
			join( root, 'widgets/pattern-picker/v1/widget.js' ),
		],
	},
	{
		source: join( root, 'widgets/image-upload/v1' ),
		target: 'assets/mcp-apps/image-upload/v1/image-upload.html',
		template: 'image-upload.template.html',
		css: 'image-upload.css',
		js: [
			join( runtime, 'bridge.js' ),
			join( root, 'widgets/image-upload/v1/widget.js' ),
		],
	},
	...[ 'media', 'content' ].map( ( kind ) => ( {
		source: join( root, `widgets/${ kind }-picker/v1` ),
		target: `assets/mcp-apps/${ kind }-picker/v1/${ kind }-picker.html`,
		template: `${ kind }-picker.template.html`,
		css: '../../reference-picker/v1/picker.css',
		maxJsBytes: 20 * 1024,
		minifyJs: true,
		js: [
			join( runtime, 'bridge.js' ),
			join( root, 'widgets/reference-picker/v1/contract.js' ),
			join( root, 'widgets/reference-picker/v1/widget.js' ),
		],
	} ) ),
];

for ( const widget of widgets ) {
	const template = readFileSync(
		join( widget.source, widget.template ),
		'utf8'
	);
	const css = readFileSync( join( widget.source, widget.css ), 'utf8' );
	let js = widget.js
		.map( ( path ) => readFileSync( path, 'utf8' ) )
		.join( '\n' );
	if ( /<\/(?:script|style)/i.test( js ) || /<\/style/i.test( css ) ) {
		throw new Error( 'Source cannot close its inline HTML element.' );
	}
	if ( widget.minifyJs ) {
		js = (
			await minify( js, {
				compress: true,
				mangle: true,
				format: { comments: false },
			} )
		).code;
	}
	for ( const marker of [
		'__ACULECT_WIDGET_CSS__',
		'__ACULECT_WIDGET_JS__',
	] ) {
		if ( template.split( marker ).length !== 2 ) {
			throw new Error( `Expected exactly one ${ marker } placeholder.` );
		}
	}
	if ( /<\/(?:script|style)/i.test( js ) || /<\/style/i.test( css ) ) {
		throw new Error( 'Source cannot close its inline HTML element.' );
	}
	const maxJsBytes = widget.maxJsBytes ?? 16 * 1024;
	if ( Buffer.byteLength( js ) > maxJsBytes ) {
		throw new Error(
			`Widget JavaScript exceeds its ${ maxJsBytes } byte budget.`
		);
	}
	const html = template
		.replace( '__ACULECT_WIDGET_CSS__', css )
		.replace( '__ACULECT_WIDGET_JS__', js );
	if ( Buffer.byteLength( html ) > 24 * 1024 ) {
		throw new Error( 'Widget exceeds the 24 KiB initial HTML budget.' );
	}
	const target = join( root, widget.target );
	mkdirSync( dirname( target ), { recursive: true } );
	writeFileSync( target, html );
	process.stdout.write(
		`Built ${ target } (${ Buffer.byteLength( html ) } bytes).\n`
	);
}
