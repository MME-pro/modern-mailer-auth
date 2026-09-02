import * as SwitchPrimitive from '@radix-ui/react-switch';
import { cn } from '../../lib/utils';

/**
 * An on/off control.
 *
 * Three things this gets deliberately, because the obvious version of each is
 * wrong here:
 *
 * The off track has its own colour rather than reusing --input. That token is a
 * border colour at 92% lightness, and a white thumb on it is invisible - the
 * switch reads as a blank pill that gives no clue it is a control, let alone
 * which way it is set.
 *
 * It is 44x24 rather than the 36x20 shadcn ships. This sits in a settings row
 * next to a paragraph of explanation, and it is the only thing on that row you
 * can click; a 20px-tall target is uncomfortable with a mouse and worse on a
 * touchscreen.
 *
 * And the thumb carries a shadow and a hairline ring, so it stays legible
 * against both the pale off track and the saturated brand colour when on -
 * without which it disappears at one end or the other.
 */
function Switch( { className, ...props } ) {
	return (
		<SwitchPrimitive.Root
			data-slot="switch"
			className={ cn(
				'peer group relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full',
				'border-2 border-transparent p-0 outline-none transition-colors',
				// WordPress gives every bare <button> its own box model and font.
				// Neutralised here so the track cannot inherit a stray padding
				// or background image from the admin stylesheet.
				'appearance-none bg-none',
				'data-[state=checked]:bg-brand data-[state=unchecked]:bg-switch-off',
				'hover:data-[state=unchecked]:bg-switch-off/80',
				'focus-visible:ring-[3px] focus-visible:ring-ring/40',
				'disabled:cursor-not-allowed disabled:opacity-50',
				className
			) }
			{ ...props }
		>
			<SwitchPrimitive.Thumb
				className={ cn(
					'pointer-events-none block size-5 rounded-full bg-switch-thumb',
					'shadow-[0_1px_2px_rgba(18,18,18,0.25)] ring-1 ring-black/5',
					'transition-transform duration-150 ease-out',
					'data-[state=checked]:translate-x-5 data-[state=unchecked]:translate-x-0'
				) }
			/>
		</SwitchPrimitive.Root>
	);
}

export { Switch };
