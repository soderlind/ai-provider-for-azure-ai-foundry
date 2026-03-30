import { defineConfig } from 'vitest/config';
import path from 'path';

export default defineConfig( {
	resolve: {
		alias: {
			'@wordpress/connectors': path.resolve(
				__dirname,
				'tests/js/__mocks__/@wordpress/connectors.js'
			),
		},
	},
	test: {
		globals: true,
		environment: 'jsdom',
		include: [ 'tests/js/**/*.test.js' ],
		setupFiles: [ 'tests/js/setup-globals.js' ],
	},
} );
