/**
 * postcss.config.js — MFD Dashboard Widget
 *
 * PostCSS plugin pipeline for Tailwind CSS compilation.
 * Used exclusively by webpack.config.js — not a standalone CLI config.
 */

module.exports = {
    plugins: {
        // Tailwind CSS — processes @tailwind directives in CSS source files.
        // Configuration is loaded from tailwind.config.js in the project root.
        tailwindcss: {},

        // Autoprefixer — adds vendor prefixes based on browserslist config
        // in package.json ("extends @wordpress/browserslist-config").
        autoprefixer: {},
    },
};
