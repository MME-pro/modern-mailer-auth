import { cn } from '../../lib/utils';

function Card( { className, ...props } ) {
	return (
		<div
			data-slot="card"
			className={ cn(
				'bg-card text-card-foreground flex flex-col gap-6 rounded-xl border py-6 shadow-xs',
				className
			) }
			{ ...props }
		/>
	);
}

function CardHeader( { className, ...props } ) {
	return (
		<div
			data-slot="card-header"
			className={ cn(
				'@container/card-header grid auto-rows-min items-start gap-1.5 px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto]',
				className
			) }
			{ ...props }
		/>
	);
}

function CardTitle( { className, ...props } ) {
	return (
		<h3
			data-slot="card-title"
			className={ cn( 'leading-none font-semibold tracking-tight m-0', className ) }
			{ ...props }
		/>
	);
}

function CardDescription( { className, ...props } ) {
	return (
		<p
			data-slot="card-description"
			className={ cn( 'text-muted-foreground text-sm m-0 max-w-prose', className ) }
			{ ...props }
		/>
	);
}

function CardAction( { className, ...props } ) {
	return (
		<div
			data-slot="card-action"
			className={ cn( 'col-start-2 row-span-2 row-start-1 self-start justify-self-end flex gap-2', className ) }
			{ ...props }
		/>
	);
}

function CardContent( { className, ...props } ) {
	return <div data-slot="card-content" className={ cn( 'px-6', className ) } { ...props } />;
}

function CardFooter( { className, ...props } ) {
	return (
		<div
			data-slot="card-footer"
			className={ cn( 'flex items-center gap-2 px-6 [.border-t]:pt-6', className ) }
			{ ...props }
		/>
	);
}

export { Card, CardHeader, CardTitle, CardDescription, CardAction, CardContent, CardFooter };
