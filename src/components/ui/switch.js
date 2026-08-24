import * as SwitchPrimitive from '@radix-ui/react-switch';
import { cn } from '../../lib/utils';

function Switch( { className, ...props } ) {
	return (
		<SwitchPrimitive.Root
			data-slot="switch"
			className={ cn(
				'peer inline-flex h-5 w-9 shrink-0 items-center rounded-full border-2 border-transparent shadow-xs transition-all outline-none',
				'focus-visible:ring-ring/40 focus-visible:ring-[3px]',
				'data-[state=checked]:bg-brand data-[state=unchecked]:bg-input',
				'disabled:cursor-not-allowed disabled:opacity-50',
				className
			) }
			{ ...props }
		>
			<SwitchPrimitive.Thumb
				className={ cn(
					'pointer-events-none block size-4 rounded-full bg-white shadow-sm ring-0 transition-transform',
					'data-[state=checked]:translate-x-4 data-[state=unchecked]:translate-x-0'
				) }
			/>
		</SwitchPrimitive.Root>
	);
}

export { Switch };
