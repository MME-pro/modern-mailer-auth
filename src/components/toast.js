import { createContext, useContext, useState, useCallback } from '@wordpress/element';
import { CircleCheck, CircleX, X } from 'lucide-react';
import { cn } from '../lib/utils';

const ToastContext = createContext( () => {} );

export const useToast = () => useContext( ToastContext );

/**
 * Transient confirmations, in the sonner shape shadcn uses.
 *
 * Pinned top-right, offset to clear the WordPress admin bar - which is fixed
 * at the top of the viewport at 46px on small screens and 32px above that.
 * A plain top-6 would put every confirmation underneath it.
 *
 * Only for things the reader does not need to act on - "Saved", "Connected".
 * Anything that has to be read and acted upon goes into the page as an Alert
 * instead, because a message that disappears after five seconds is the wrong
 * place to keep the only copy of an error.
 */
export const ToastProvider = ( { children } ) => {
	const [ toasts, setToasts ] = useState( [] );

	const push = useCallback( ( message, tone = 'ok' ) => {
		const id = Math.random().toString( 36 ).slice( 2 );

		setToasts( ( current ) => [ ...current, { id, message, tone } ] );

		setTimeout(
			() => setToasts( ( current ) => current.filter( ( t ) => t.id !== id ) ),
			6000
		);
	}, [] );

	const dismiss = ( id ) =>
		setToasts( ( current ) => current.filter( ( t ) => t.id !== id ) );

	return (
		<ToastContext.Provider value={ push }>
			{ children }

			<div
				className="pointer-events-none fixed top-[54px] right-6 z-[99999] flex w-[min(400px,calc(100vw-3rem))] flex-col gap-2 sm:top-11"
				role="region"
				aria-live="polite"
			>
				{ toasts.map( ( toast ) => {
					const Icon = toast.tone === 'bad' ? CircleX : CircleCheck;

					return (
						<div
							key={ toast.id }
							className={ cn(
								'pointer-events-auto flex items-start gap-3 rounded-lg border bg-popover p-4 text-popover-foreground shadow-lg',
								'animate-in slide-in-from-top-2 fade-in duration-200'
							) }
						>
							<Icon
								className={ cn(
									'size-4 shrink-0 mt-0.5',
									toast.tone === 'bad' ? 'text-danger' : 'text-success'
								) }
							/>
							<span className="min-w-0 flex-1 text-sm">{ toast.message }</span>
							<button
								type="button"
								onClick={ () => dismiss( toast.id ) }
								aria-label="Dismiss"
								className="shrink-0 rounded-sm text-muted-foreground transition-colors hover:text-foreground cursor-pointer border-0 bg-transparent p-0"
							>
								<X className="size-3.5" />
							</button>
						</div>
					);
				} ) }
			</div>
		</ToastContext.Provider>
	);
};
