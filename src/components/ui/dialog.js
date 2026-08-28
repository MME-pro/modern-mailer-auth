import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '../../lib/utils';

/**
 * A modal, on Radix.
 *
 * Radix rather than a hand-rolled overlay because a dialog has more to get
 * right than it looks: focus has to move in and be trapped, Escape has to
 * close, the page behind must not scroll or be reachable by a screen reader,
 * and focus has to return to whatever opened it. All of that is behaviour
 * people notice only when it is missing.
 */
const Dialog = DialogPrimitive.Root;
const DialogTrigger = DialogPrimitive.Trigger;
const DialogClose = DialogPrimitive.Close;

const DialogOverlay = ( { className, ...props } ) => (
	<DialogPrimitive.Overlay
		className={ cn(
			'fixed inset-0 z-[100000] bg-black/50 backdrop-blur-[2px]',
			'data-[state=open]:animate-in data-[state=closed]:animate-out',
			'data-[state=open]:fade-in-0 data-[state=closed]:fade-out-0',
			className
		) }
		{ ...props }
	/>
);

/**
 * z-index is deliberately enormous. This renders in a portal at the end of
 * <body>, above wp-admin's own furniture - the admin bar sits at 99999 and an
 * unstyled dialog disappears behind it.
 */
const DialogContent = ( { className, children, ...props } ) => (
	<DialogPrimitive.Portal>
		<DialogOverlay />
		<DialogPrimitive.Content
			className={ cn(
				'fixed left-1/2 top-1/2 z-[100001] w-[min(56rem,calc(100vw-2rem))]',
				'max-h-[calc(100vh-4rem)] -translate-x-1/2 -translate-y-1/2',
				'flex flex-col overflow-hidden rounded-xl border bg-card shadow-2xl',
				'data-[state=open]:animate-in data-[state=closed]:animate-out',
				'data-[state=open]:fade-in-0 data-[state=closed]:fade-out-0',
				'data-[state=open]:zoom-in-95 data-[state=closed]:zoom-out-95',
				className
			) }
			{ ...props }
		>
			{ children }

			<DialogPrimitive.Close className="absolute right-4 top-4 rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/40">
				<X className="size-4" />
				<span className="sr-only">Close</span>
			</DialogPrimitive.Close>
		</DialogPrimitive.Content>
	</DialogPrimitive.Portal>
);

const DialogHeader = ( { className, ...props } ) => (
	<div className={ cn( 'grid gap-1 border-b px-6 py-4 pr-14', className ) } { ...props } />
);

const DialogBody = ( { className, ...props } ) => (
	<div className={ cn( 'min-h-0 flex-1 overflow-y-auto px-6 py-5', className ) } { ...props } />
);

const DialogFooter = ( { className, ...props } ) => (
	<div className={ cn( 'flex flex-wrap items-center justify-end gap-2 border-t px-6 py-3', className ) } { ...props } />
);

const DialogTitle = ( { className, ...props } ) => (
	<DialogPrimitive.Title className={ cn( 'text-base font-semibold', className ) } { ...props } />
);

const DialogDescription = ( { className, ...props } ) => (
	<DialogPrimitive.Description
		className={ cn( 'text-[13px] text-muted-foreground', className ) }
		{ ...props }
	/>
);

export {
	Dialog,
	DialogTrigger,
	DialogClose,
	DialogContent,
	DialogHeader,
	DialogBody,
	DialogFooter,
	DialogTitle,
	DialogDescription,
};
