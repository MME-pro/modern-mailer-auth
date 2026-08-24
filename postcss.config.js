/**
 * Tailwind v4 runs entirely through PostCSS - there is no tailwind.config.js.
 * The design tokens live in src/styles.css instead, under @theme.
 */
module.exports = {
	plugins: {
		'@tailwindcss/postcss': {},
	},
};
