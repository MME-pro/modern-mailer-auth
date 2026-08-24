import { cn } from '../../lib/utils';

function Table( { className, ...props } ) {
	return (
		<div data-slot="table-container" className="relative w-full overflow-x-auto">
			<table
				data-slot="table"
				className={ cn( 'w-full caption-bottom text-sm border-collapse', className ) }
				{ ...props }
			/>
		</div>
	);
}

function TableHeader( { className, ...props } ) {
	return <thead data-slot="table-header" className={ cn( '[&_tr]:border-b', className ) } { ...props } />;
}

function TableBody( { className, ...props } ) {
	return (
		<tbody
			data-slot="table-body"
			className={ cn( '[&_tr:last-child]:border-0', className ) }
			{ ...props }
		/>
	);
}

function TableRow( { className, ...props } ) {
	return (
		<tr
			data-slot="table-row"
			className={ cn( 'hover:bg-muted/50 border-b transition-colors', className ) }
			{ ...props }
		/>
	);
}

function TableHead( { className, ...props } ) {
	return (
		<th
			data-slot="table-head"
			className={ cn(
				'text-muted-foreground h-9 px-3 text-left align-middle text-xs font-medium uppercase tracking-wide whitespace-nowrap first:pl-0 last:pr-0',
				className
			) }
			{ ...props }
		/>
	);
}

function TableCell( { className, ...props } ) {
	return (
		<td
			data-slot="table-cell"
			className={ cn( 'p-3 align-middle first:pl-0 last:pr-0', className ) }
			{ ...props }
		/>
	);
}

export { Table, TableHeader, TableBody, TableRow, TableHead, TableCell };
