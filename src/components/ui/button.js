import { Slot } from '@radix-ui/react-slot';
import { cva } from 'class-variance-authority';
import { Loader2 } from 'lucide-react';
import { cn } from '../../lib/utils';

/**
 * shadcn Button.
 *
 * `asChild` is what makes the OAuth control possible: the Google sign-in has to
 * be a real anchor, because it navigates the browser away rather than calling
 * the API. Radix's Slot merges these props onto whatever child is passed, so an
 * <a> gets the button's appearance without a <button> nested inside it - which
 * would be invalid markup and would break keyboard activation.
 */
const buttonVariants = cva(
	"inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/40 aria-disabled:pointer-events-none aria-disabled:opacity-50 no-underline",
	{
		variants: {
			variant: {
				default:
					'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90 active:scale-[0.98]',
				brand:
					'bg-brand text-brand-foreground shadow-sm hover:bg-brand/90 active:scale-[0.98]',
				destructive:
					'bg-danger text-white shadow-sm hover:bg-danger/90 active:scale-[0.98]',
				outline:
					'border bg-card shadow-xs hover:bg-muted hover:text-accent-foreground',
				secondary:
					'bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/70',
				ghost: 'hover:bg-muted hover:text-accent-foreground',
				link: 'text-brand underline-offset-4 hover:underline',
			},
			size: {
				default: 'h-9 px-4 py-2 has-[>svg]:px-3',
				sm: 'h-8 rounded-md gap-1.5 px-3 has-[>svg]:px-2.5 text-[13px]',
				lg: 'h-10 rounded-md px-6 has-[>svg]:px-4',
				icon: 'size-9',
			},
		},
		defaultVariants: {
			variant: 'default',
			size: 'default',
		},
	}
);

function Button( {
	className,
	variant,
	size,
	asChild = false,
	busy = false,
	disabled,
	children,
	...props
} ) {
	const Comp = asChild ? Slot : 'button';

	return (
		<Comp
			data-slot="button"
			className={ cn( buttonVariants( { variant, size, className } ) ) }
			// A slotted child may be an anchor, which has no disabled attribute -
			// aria-disabled plus the pointer-events rule above is what stands in
			// for it there.
			{ ...( asChild
				? { 'aria-disabled': disabled || busy || undefined }
				: { type: 'button', disabled: disabled || busy } ) }
			{ ...props }
		>
			{ busy ? (
				<>
					<Loader2 className="animate-spin" />
					{ children }
				</>
			) : (
				children
			) }
		</Comp>
	);
}

export { Button, buttonVariants };
