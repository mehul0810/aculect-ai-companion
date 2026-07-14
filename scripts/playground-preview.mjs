#!/usr/bin/env node

/* eslint-disable no-console -- CLI command intentionally writes status and output. */

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const DEFAULT_LANDING_PAGE =
	'/wp-admin/options-general.php?page=aculect-ai-companion';

const PLAYGROUND_URL = 'https://playground.wordpress.net/';
const BLUEPRINT_SCHEMA =
	'https://playground.wordpress.net/blueprint-schema.json';

export function createPreviewBlueprint( {
	pluginUrl,
	landingPage = DEFAULT_LANDING_PAGE,
	title = 'Aculect AI Companion PR Preview',
	description = 'Preview a pull-request build of Aculect AI Companion in WordPress Playground.',
} = {} ) {
	if ( ! pluginUrl ) {
		throw new Error( 'Missing required plugin package URL.' );
	}

	const url = new URL( pluginUrl );

	if ( ! [ 'http:', 'https:' ].includes( url.protocol ) ) {
		throw new Error( 'Plugin package URL must use http or https.' );
	}

	if ( ! url.pathname.endsWith( '.zip' ) ) {
		throw new Error( 'Plugin package URL must point to a .zip file.' );
	}

	if ( ! landingPage.startsWith( '/' ) ) {
		throw new Error( 'Landing page must be a root-relative path.' );
	}

	return {
		$schema: BLUEPRINT_SCHEMA,
		landingPage,
		meta: {
			title,
			author: 'Aculect',
			description,
			categories: [ 'plugins', 'productivity' ],
		},
		preferredVersions: {
			php: '8.3',
			wp: 'latest',
		},
		steps: [
			{
				step: 'installPlugin',
				pluginData: {
					resource: 'url',
					url: url.toString(),
				},
				options: {
					activate: true,
				},
				ifAlreadyInstalled: 'overwrite',
			},
			{
				step: 'login',
				username: 'admin',
				password: 'password',
			},
		],
	};
}

export function createPlaygroundUrl( blueprint ) {
	const encodedBlueprint = encodeURIComponent( JSON.stringify( blueprint ) );

	return `${ PLAYGROUND_URL }?blueprint=${ encodedBlueprint }`;
}

export function validateBlueprintFile( filePath ) {
	const blueprintPath = resolve( filePath );
	const blueprint = JSON.parse( readFileSync( blueprintPath, 'utf8' ) );

	if ( ! blueprint.$schema ) {
		throw new Error( `${ blueprintPath } is missing $schema.` );
	}

	if (
		! blueprint.landingPage ||
		! blueprint.landingPage.startsWith( '/' )
	) {
		throw new Error(
			`${ blueprintPath } must define a root-relative landingPage.`
		);
	}

	if ( ! Array.isArray( blueprint.steps ) || 0 === blueprint.steps.length ) {
		throw new Error( `${ blueprintPath } must define at least one step.` );
	}

	return blueprint;
}

function parseArgs( args ) {
	const options = {
		validateBlueprints: [],
		landingPage: DEFAULT_LANDING_PAGE,
	};

	for ( let index = 0; index < args.length; index += 1 ) {
		const arg = args[ index ];

		switch ( arg ) {
			case '--package-url':
				options.pluginUrl = args[ ++index ];
				break;
			case '--landing-page':
				options.landingPage = args[ ++index ];
				break;
			case '--out':
				options.out = args[ ++index ];
				break;
			case '--validate-blueprint':
				options.validateBlueprints.push( args[ ++index ] );
				break;
			case '--help':
				options.help = true;
				break;
			default:
				throw new Error( `Unknown argument: ${ arg }` );
		}
	}

	return options;
}

function printHelp() {
	console.log( `Usage:
  node scripts/playground-preview.mjs --package-url https://example.test/aculect-ai-companion-pr.zip
  node scripts/playground-preview.mjs --validate-blueprint .wordpress-org/blueprints/blueprint.json

Options:
  --package-url <url>        Public http(s) URL to a PR plugin ZIP package.
  --landing-page <path>      Root-relative admin path to open after login.
  --out <path>               Write the generated PR blueprint JSON to a file.
  --validate-blueprint <path> Validate a blueprint JSON file. Repeatable.
` );
}

function runCli() {
	const options = parseArgs( process.argv.slice( 2 ) );

	if ( options.help ) {
		printHelp();
		return;
	}

	for ( const blueprintPath of options.validateBlueprints ) {
		validateBlueprintFile( blueprintPath );
		console.error( `Validated ${ blueprintPath }` );
	}

	if ( ! options.pluginUrl ) {
		if ( 0 < options.validateBlueprints.length ) {
			return;
		}

		throw new Error( 'Provide --package-url or --validate-blueprint.' );
	}

	const blueprint = createPreviewBlueprint( {
		pluginUrl: options.pluginUrl,
		landingPage: options.landingPage,
	} );
	const previewUrl = createPlaygroundUrl( blueprint );

	if ( options.out ) {
		const outPath = resolve( options.out );

		mkdirSync( dirname( outPath ), { recursive: true } );
		writeFileSync(
			outPath,
			`${ JSON.stringify( blueprint, null, '\t' ) }\n`
		);
		console.error( `Wrote ${ options.out }` );
	}

	console.log( previewUrl );
}

const entrypoint = process.argv[ 1 ] ? basename( process.argv[ 1 ] ) : '';
const thisFile = basename( fileURLToPath( import.meta.url ) );

if ( entrypoint === thisFile ) {
	try {
		runCli();
	} catch ( error ) {
		console.error(
			error instanceof Error ? error.message : String( error )
		);
		process.exitCode = 1;
	}
}
