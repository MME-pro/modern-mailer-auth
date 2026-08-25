import { Slot } from '@radix-ui/react-slot';
import { cva } from 'class-variance-authority';
import { cn } from '../../lib/utils';

/**
 * Status is never colour alone.
 *
 * Every status variant pairs its colour with a word, and the dot is a second
 * non-colour cue. "Did my mail arrive" must not depend on telling red from
 * green.
 */
const badgeVariants = cva(
	'inline-flex items-center justify-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 transition-colors',
	{
		variants: {
			variant: {
				default: 'border-transparent bg-primary text-primary-foreground',
				secondary: 'border-transparent bg-secondary text-secondary-foreground',
				outline: 'text-foreground',
				success:
					'border-success/20 bg-success-subtle text-success',
				warning:
					'border-warning/25 bg-warning-subtle text-warning',
				danger: 'border-danger/20 bg-danger-subtle text-danger',
				brand: 'border-brand/25 bg-brand-subtle text-brand-deep',
			},
		},
		defaultVariants: { variant: 'default' },
	}
);

function Badge( { className, variant, asChild = false, dot = false, children, ...props } ) {
	const Comp = asChild ? Slot : 'span';

	return (
		<Comp
			data-slot="badge"
			className={ cn( badgeVariants( { variant } ), className ) }
			{ ...props }
		>
			{ dot && (
				<span
					aria-hidden="true"
					className="size-1.5 rounded-full bg-current opacity-70"
				/>
			) }
			{ children }
		</Comp>
	);
}

export { Badge, badgeVariants };
