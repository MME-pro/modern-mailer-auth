import { cn } from '../../lib/utils';

function Input( { className, type = 'text', ...props } ) {
	return (
		<input
			type={ type }
			data-slot="input"
			className={ cn(
				'border-input placeholder:text-muted-foreground/70 flex h-9 w-full min-w-0 rounded-md border bg-card px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none',
				'focus-visible:border-ring focus-visible:ring-ring/40 focus-visible:ring-[3px]',
				'disabled:cursor-not-allowed disabled:opacity-60 disabled:bg-muted',
				'aria-invalid:border-danger aria-invalid:ring-danger/20',
				className
			) }
			{ ...props }
		/>
	);
}

function Textarea( { className, ...props } ) {
	return (
		<textarea
			data-slot="textarea"
			className={ cn(
				'border-input placeholder:text-muted-foreground/70 flex min-h-16 w-full rounded-md border bg-card px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none',
				'focus-visible:border-ring focus-visible:ring-ring/40 focus-visible:ring-[3px]',
				'disabled:cursor-not-allowed disabled:opacity-60 disabled:bg-muted',
				className
			) }
			{ ...props }
		/>
	);
}

export { Input, Textarea };
