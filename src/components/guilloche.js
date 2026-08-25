import { useMemo } from '@wordpress/element';

/**
 * Engine-turned guilloché — the signature.
 *
 * The rosette engraved on banknotes, share certificates and watch dials. It is
 * the authentic visual language of things that must look trustworthy, it is
 * close to unseen in WordPress admin UI, and it costs one component and no
 * image files.
 *
 * Used exactly twice in the whole interface: at full strength on the dashboard
 * header band, and once more, fainter, behind the chart. Anywhere else and it
 * stops being a signature and becomes wallpaper.
 *
 * The curve is a rose-modulated circle:
 *
 *   r(t) = radius + amplitude · sin(petals · t + phase)
 *
 * Drawing many of those with the radius stepping outward and the phase creeping
 * produces the interference lattice a rose engine cuts mechanically. The phase
 * drift is what does the work - identical rings stacked concentrically read as
 * a target, not an engraving.
 */

const SIZE = 600;
const CENTRE = SIZE / 2;

/** One closed rose-modulated ring as an SVG path. */
const ring = ( radius, petals, amplitude, phase ) => {
	// Enough samples for smooth lobes, not so many that 26 rings bloat the DOM.
	const steps = Math.max( 180, petals * 24 );
	let d = '';

	for ( let i = 0; i <= steps; i++ ) {
		const t = ( i / steps ) * Math.PI * 2;
		const r = radius + amplitude * Math.sin( petals * t + phase );
		const x = CENTRE + r * Math.cos( t );
		const y = CENTRE + r * Math.sin( t );

		d += `${ i === 0 ? 'M' : 'L' }${ x.toFixed( 2 ) } ${ y.toFixed( 2 ) }`;
	}

	return `${ d }Z`;
};

const Guilloche = ( {
	rings = 26,
	petals = 13,
	amplitude = 16,
	strokeWidth = 0.6,
	id = 'mmoa-guilloche',
	className,
} ) => {
	// Deterministic, so it never reflows between renders - and memoised,
	// because this is a few thousand path commands.
	const paths = useMemo( () => {
		const out = [];
		const inner = 62;
		const step = ( CENTRE - inner - amplitude ) / rings;

		for ( let i = 0; i < rings; i++ ) {
			out.push(
				ring(
					inner + i * step,
					petals,
					amplitude,
					// Golden-angle drift: never repeats, and never lines the
					// lobes up into spokes the way a rational fraction of 2π
					// would.
					i * 2.399963
				)
			);
		}

		return out;
	}, [ rings, petals, amplitude ] );

	return (
		<svg
			viewBox={ `0 0 ${ SIZE } ${ SIZE }` }
			className={ className }
			// Decorative by definition: it carries nothing a screen reader
			// could use, and announcing it would be noise.
			aria-hidden="true"
			focusable="false"
		>
			<defs>
				{ /* Fades before it reaches the type, so the engraving never
				     competes with the figure it sits behind. */ }
				<radialGradient id={ `${ id }-fade` }>
					<stop offset="0%" stopColor="white" stopOpacity="0.9" />
					<stop offset="55%" stopColor="white" stopOpacity="0.5" />
					<stop offset="100%" stopColor="white" stopOpacity="0" />
				</radialGradient>
				<mask id={ `${ id }-mask` }>
					<rect width={ SIZE } height={ SIZE } fill={ `url(#${ id }-fade)` } />
				</mask>
			</defs>

			<g
				mask={ `url(#${ id }-mask)` }
				fill="none"
				stroke="currentColor"
				strokeWidth={ strokeWidth }
				vectorEffect="non-scaling-stroke"
			>
				{ paths.map( ( d, i ) => (
					<path key={ i } d={ d } />
				) ) }
			</g>
		</svg>
	);
};

export default Guilloche;
