import { resolve } from 'node:path';
import { defineConfig, type Plugin } from 'vite';

function assertStandaloneWordPressScripts(): Plugin {
	return {
		name: 'assert-standalone-wordpress-scripts',
		generateBundle( _options, bundle ) {
			for ( const output of Object.values( bundle ) ) {
				if (
					output.type === 'chunk' &&
					(
						output.imports.length > 0 ||
						output.dynamicImports.length > 0 ||
						output.exports.length > 0
					)
				) {
					this.error(
						`${ output.fileName } is not a standalone classic WordPress script.`,
					);
				}
			}
		},
	};
}

export default defineConfig( ( { mode } ) => {
	const isDevelopment = mode === 'development';
	const isUnminified = process.env.NO_MIN === 'true';
	const suffix = isUnminified ? '' : '.min';

	return {
		plugins: [ assertStandaloneWordPressScripts() ],
		build: {
			copyPublicDir: false,
			cssCodeSplit: true,
			emptyOutDir: ! isUnminified,
			minify: isDevelopment || isUnminified ? false : 'oxc',
			modulePreload: false,
			outDir: 'assets',
			rolldownOptions: {
				input: {
					assistant: resolve( import.meta.dirname, 'src/assistant/styles.css' ),
					'debug-log': resolve( import.meta.dirname, 'src/debugLog/styles.css' ),
					'plugins-screen': resolve( import.meta.dirname, 'src/pluginsScreen/index.ts' ),
					setting: resolve( import.meta.dirname, 'src/setting/styles.css' ),
					'support-user': resolve( import.meta.dirname, 'src/supportUser/index.ts' ),
				},
				output: {
					assetFileNames: `[name]${ suffix }.[ext]`,
					chunkFileNames: `[name]${ suffix }.js`,
					entryFileNames: `[name]${ suffix }.js`,
				},
			},
			sourcemap: isDevelopment,
		},
	};
} );
