import { cva } from 'class-variance-authority';
import { cn } from '../../lib/utils';

/**
 * Persistent messages.
 *
 * Anything the reader has to act on belongs here rather than in a toast - an
 * error naming the exact misconfiguration is the last thing that should
 * disappear after three seconds.
 */
const alertVariants = cva(
	'relative w-full rounded-lg border px-4 py-3 text-sm grid has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] grid-cols-[0_1fr] has-[>svg]:gap-x-3 gap-y-0.5 items-start [&>svg]:size-4 [&>svg]:translate-y-0.5',
	{
		variants: {
			variant: {
				default: 'bg-card text-card-foreground',
				info: 'border-brand/25 bg-brand-subtle text-foreground [&>svg]:text-brand',
				success:
					'border-success/25 bg-success-subtle text-foreground [&>svg]:text-success',
				warning:
					'border-warning/30 bg-warning-subtle text-foreground [&>svg]:text-warning',
				danger:
					'border-danger/25 bg-danger-subtle text-foreground [&>svg]:text-danger',
			},
		},
		defaultVariants: { variant: 'default' },
	}
);

function Alert( { className, variant, ...props } ) {
	return (
		<div
			data-slot="alert"
			role="alert"
			className={ cn( alertVariants( { variant } ), className ) }
			{ ...props }
		/>
	);
}

function AlertTitle( { className, ...props } ) {
	return (
		<div
			data-slot="alert-title"
			className={ cn( 'col-start-2 line-clamp-1 min-h-4 font-medium tracking-tight', className ) }
			{ ...props }
		/>
	);
}

function AlertDescription( { className, ...props } ) {
	return (
		<div
			data-slot="alert-description"
			className={ cn(
				'text-muted-foreground col-start-2 grid justify-items-start gap-1 text-sm [&_p]:leading-relaxed [&_p]:m-0',
				className
			) }
			{ ...props }
		/>
	);
}

export { Alert, AlertTitle, AlertDescription };
