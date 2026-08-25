/**
 * Compatibility surface plus the few pieces shadcn does not ship.
 *
 * Screens import from here rather than reaching into components/ui/* directly,
 * so swapping an implementation is one edit in one file.
 */
import { Loader2 } from 'lucide-react';
import { cn } from '../lib/utils';
import { Skeleton } from './ui/skeleton';
import { Label } from './ui/label';
import { Switch } from './ui/switch';
import {
	Card,
	CardHeader,
	CardTitle,
	CardDescription,
	CardAction,
	CardContent,
	CardFooter,
} from './ui/card';

export { Button, buttonVariants } from './ui/button';
export { Badge, badgeVariants } from './ui/badge';
export { Input, Textarea } from './ui/input';
export { Label } from './ui/label';
export { Switch } from './ui/switch';
export { Separator } from './ui/separator';
export { Skeleton } from './ui/skeleton';
export { Alert, AlertTitle, AlertDescription } from './ui/alert';
export { Tabs, TabsList, TabsTrigger, TabsContent } from './ui/tabs';
export {
	Table,
	TableHeader,
	TableBody,
	TableRow,
	TableHead,
	TableCell,
} from './ui/table';
export {
	Card,
	CardHeader,
	CardTitle,
	CardDescription,
	CardAction,
	CardContent,
	CardFooter,
} from './ui/card';

/**
 * The card shape almost every screen uses: heading, optional blurb, optional
 * actions, body.
 *
 * shadcn's Card is deliberately compositional, which is right for one-off
 * layouts and repetitive for a settings interface where every section is the
 * same shape. This is a thin arrangement of those parts, not a replacement -
 * anything that needs a different structure still imports Card directly.
 */
export const Panel = ( { title, description, actions, footer, children, className } ) => (
	<Card className={ className }>
		{ ( title || actions ) && (
			<CardHeader>
				{ title && <CardTitle>{ title }</CardTitle> }
				{ description && <CardDescription>{ description }</CardDescription> }
				{ actions && <CardAction>{ actions }</CardAction> }
			</CardHeader>
		) }
		<CardContent>{ children }</CardContent>
		{ footer && <CardFooter className="border-t pt-6">{ footer }</CardFooter> }
	</Card>
);

/**
 * A setting that is on or off, with room for the explanation it needs.
 *
 * The label is clickable but is deliberately NOT wired with `htmlFor`. Radix
 * renders a switch as a <button>, and a label pointing at a button does not
 * reliably activate it across browsers - the text looks clickable and does
 * nothing. So the label toggles through its own handler, and the switch is tied
 * to it with aria-labelledby instead, which is what screen readers need anyway.
 *
 * Doing both would toggle twice in any browser that does forward the click.
 */
export const ToggleRow = ( { id, checked, onChange, disabled, label, help, className } ) => {
	const labelId = `${ id }-label`;

	return (
		<div
			className={ cn(
				'flex items-start justify-between gap-6 rounded-lg border p-4 transition-colors',
				! disabled && 'hover:bg-muted/40',
				className
			) }
		>
			<div className="min-w-0 grid gap-1">
				<label
					id={ labelId }
					onClick={ () => ! disabled && onChange( ! checked ) }
					className={ cn(
						'text-sm font-medium leading-none w-fit',
						disabled ? 'opacity-60' : 'cursor-pointer'
					) }
				>
					{ label }
				</label>
				{ help && (
					<p className="text-xs text-muted-foreground m-0 max-w-prose leading-relaxed">
						{ help }
					</p>
				) }
			</div>

			<Switch
				id={ id }
				aria-labelledby={ labelId }
				checked={ !! checked }
				disabled={ disabled }
				onCheckedChange={ onChange }
				className="mt-0.5 shrink-0"
			/>
		</div>
	);
};

/**
 * A labelled form control.
 *
 * `locked` is not decoration: a value pinned by a wp-config.php constant is
 * genuinely uneditable, and saying so is better than a disabled input with no
 * explanation.
 */
export const FormField = ( { label, help, locked, error, htmlFor, children, className } ) => (
	<div className={ cn( 'grid gap-2', className ) }>
		{ label && <Label htmlFor={ htmlFor }>{ label }</Label> }
		{ children }
		{ error ? (
			<p className="text-xs text-danger m-0">{ error }</p>
		) : locked ? (
			<p className="text-xs text-muted-foreground m-0">
				Set in wp-config.php, so it cannot be edited here.
			</p>
		) : (
			help && <p className="text-xs text-muted-foreground m-0 max-w-prose">{ help }</p>
		) }
	</div>
);

/**
 * The Input component's classes, for the handful of places that render a raw
 * <input> - a number spinner, a search box with an icon inside it.
 *
 * Exported from the same string the component uses rather than copied, so the
 * two cannot drift apart.
 */
export const inputClass = cn(
	'border-input placeholder:text-muted-foreground/70 flex h-9 w-full min-w-0 rounded-md border bg-card px-3 py-1 text-sm shadow-xs transition-[color,box-shadow] outline-none',
	'focus-visible:border-ring focus-visible:ring-ring/40 focus-visible:ring-[3px]',
	'disabled:cursor-not-allowed disabled:opacity-60 disabled:bg-muted'
);

export const Spinner = ( { className } ) => (
	<div className={ cn( 'flex items-center justify-center py-16 text-muted-foreground', className ) }>
		<Loader2 className="size-5 animate-spin" />
	</div>
);

/**
 * Skeletons rather than a spinner where the shape of what is loading is
 * already known - it keeps the layout from jumping when the data lands.
 */
export const CardSkeleton = ( { rows = 3 } ) => (
	<div className="grid gap-3">
		<Skeleton className="h-5 w-40" />
		{ Array.from( { length: rows } ).map( ( _, i ) => (
			<Skeleton key={ i } className="h-9 w-full" />
		) ) }
	</div>
);

export const EmptyState = ( { icon: Icon, title, children, action } ) => (
	<div className="text-center py-14 px-6">
		{ Icon && (
			<div className="mx-auto mb-4 inline-flex size-12 items-center justify-center rounded-xl border bg-muted/50 text-muted-foreground">
				<Icon className="size-5" />
			</div>
		) }
		<p className="font-medium m-0">{ title }</p>
		{ children && (
			<p className="text-sm text-muted-foreground mt-1.5 mb-0 max-w-md mx-auto">
				{ children }
			</p>
		) }
		{ action && <div className="mt-4">{ action }</div> }
	</div>
);
