const config = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	{
		ignores: [ '.codex/**', 'build/**', 'node_modules/**', 'vendor/**' ],
	},
	...config,
];
