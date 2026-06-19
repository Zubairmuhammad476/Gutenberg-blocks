/**
 * webpack.config.js — MFD Dashboard Widget
 *
 * Extends the default @wordpress/scripts webpack configuration.
 *
 * Customizations applied:
 *   1. Multiple entry points — index.js (editor) and frontend.js (view).
 *   2. PostCSS loader injected for Tailwind CSS compilation with the mfd- prefix.
 *   3. Output to /build directory (default wp-scripts behaviour — unchanged).
 *
 * We extend rather than replace the default config to inherit:
 *   - @wordpress/dependency-extraction-webpack-plugin (assets.php generation)
 *   - @wordpress/scripts source maps, production minimization, etc.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path          = require( 'path' );

module.exports = {
    ...defaultConfig,

    // -----------------------------------------------------------------------
    // Entry points
    // -----------------------------------------------------------------------
    entry: {
        // Editor bundle — loaded only inside the Gutenberg editor.
        // Registers the block type and renders the InspectorControls panel.
        index: path.resolve( __dirname, 'src/js/index.js' ),

        // Frontend view bundle — loaded only on the public-facing page.
        // Hydrates the server-rendered HTML shell with live React components.
        frontend: path.resolve( __dirname, 'src/js/frontend.js' ),
    },

    // -----------------------------------------------------------------------
    // Module rules — extend default rules with PostCSS for Tailwind.
    // -----------------------------------------------------------------------
    module: {
        ...defaultConfig.module,
        rules: [
            // Preserve all default @wordpress/scripts rules (JS, SVG, etc.)
            ...defaultConfig.module.rules.filter(
                // Remove the default CSS rule so we can override it with PostCSS.
                ( rule ) => ! ( rule.test && rule.test.toString().includes( 'css' ) )
            ),

            // Custom CSS rule — handles .css files through PostCSS + Tailwind.
            {
                test: /\.css$/i,
                use: [
                    // MiniCssExtractPlugin is already in the default config — reuse it.
                    ...( defaultConfig.module.rules.find(
                        ( r ) => r.test && r.test.toString().includes( 'css' )
                    )?.use || [] ).filter( ( loader ) => {
                        // Keep everything except the default postcss-loader
                        // so we can inject our own postcss.config.js.
                        const loaderPath = typeof loader === 'string'
                            ? loader
                            : loader?.loader || '';
                        return ! loaderPath.includes( 'postcss-loader' );
                    } ),

                    // Our project-level postcss.config.js — picks up tailwind.config.js.
                    {
                        loader: 'postcss-loader',
                        options: {
                            postcssOptions: {
                                config: path.resolve( __dirname, 'postcss.config.js' ),
                            },
                        },
                    },
                ],
            },
        ],
    },
};
