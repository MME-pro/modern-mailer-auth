import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * shadcn's class helper: merge conditional classes, then let later Tailwind
 * utilities win over earlier ones. Without the merge step a `className` prop
 * cannot reliably override a component's own defaults, which is the whole
 * reason these components are overridable at the call site.
 */
export function cn( ...inputs ) {
	return twMerge( clsx( inputs ) );
}
