import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
	Plus,
	Trash2,
	ChevronUp,
	ChevronDown,
	Route,
	Lightbulb,
	TriangleAlert,
} from 'lucide-react';
import { getRouting, saveRouting } from '../api/client';
import { useToast } from '../components/toast';
import {
	Panel,
	Button,
	ToggleRow,
	Label,
	Input,
	Spinner,
	EmptyState,
	Alert,
	AlertDescription,
	Separator,
	inputClass,
} from '../components/ui';

/**
 * Smart routing.
 *
 * Rules are evaluated top to bottom and the first match wins, so order is
 * meaningful and movable. With overlapping rules, "first match" is the only
 * ordering an author can reason about without simulating the whole set.
 *
 * Conditions inside a group are ANDed; groups are ORed. Anything that does not
 * match a rule goes out through the primary connection, which is stated on the
 * screen rather than left to be inferred.
 */

const newCondition = () => ( {
	field: 'subject',
	operator: 'contains',
	value: '',
} );

const newRule = ( connection = '' ) => ( {
	connection,
	groups: [ [ newCondition() ] ],
} );

const ConditionRow = ( { condition, vocabulary, onChange, onRemove, canRemove, isLast } ) => (
	<div className="flex flex-wrap items-center gap-2">
		<select
			aria-label={ __( 'Field', 'modern-mailer-oauth' ) }
			className={ `${ inputClass } w-auto min-w-[150px]` }
			value={ condition.field }
			onChange={ ( e ) => onChange( { ...condition, field: e.target.value } ) }
		>
			{ Object.entries( vocabulary.fields ).map( ( [ value, label ] ) => (
				<option key={ value } value={ value }>
					{ label }
				</option>
			) ) }
		</select>

		<select
			aria-label={ __( 'Operator', 'modern-mailer-oauth' ) }
			className={ `${ inputClass } w-auto min-w-[160px]` }
			value={ condition.operator }
			onChange={ ( e ) => onChange( { ...condition, operator: e.target.value } ) }
		>
			{ Object.entries( vocabulary.operators ).map( ( [ value, label ] ) => (
				<option key={ value } value={ value }>
					{ label }
				</option>
			) ) }
		</select>

		<Input
			aria-label={ __( 'Value', 'modern-mailer-oauth' ) }
			className="w-auto flex-1 min-w-[180px]"
			placeholder={ __( 'Value to match', 'modern-mailer-oauth' ) }
			value={ condition.value }
			onChange={ ( e ) => onChange( { ...condition, value: e.target.value } ) }
		/>

		<span className="text-xs font-medium text-muted-foreground w-8 text-center">
			{ isLast ? '' : __( 'and', 'modern-mailer-oauth' ) }
		</span>

		<Button
			variant="ghost"
			size="icon"
			disabled={ ! canRemove }
			aria-label={ __( 'Remove condition', 'modern-mailer-oauth' ) }
			onClick={ onRemove }
		>
			<Trash2 className="text-muted-foreground" />
		</Button>
	</div>
);

const RuleCard = ( {
	rule,
	index,
	total,
	connections,
	vocabulary,
	onChange,
	onRemove,
	onMove,
} ) => {
	const setGroup = ( groupIndex, group ) => {
		const groups = [ ...rule.groups ];
		groups[ groupIndex ] = group;
		onChange( { ...rule, groups } );
	};

	const target = connections.find( ( c ) => c.id === rule.connection );
	const unusable = rule.connection && target && ! target.configured;

	return (
		<div className="rounded-xl border bg-card">
			<div className="flex flex-wrap items-center gap-3 px-4 py-3 border-b">
				<span className="inline-flex size-6 items-center justify-center rounded-md bg-muted text-xs font-semibold tabular-nums">
					{ index + 1 }
				</span>

				<Label className="text-muted-foreground font-normal">
					{ __( 'Send with', 'modern-mailer-oauth' ) }
				</Label>

				<select
					aria-label={ __( 'Connection', 'modern-mailer-oauth' ) }
					className={ `${ inputClass } w-auto min-w-[200px]` }
					value={ rule.connection }
					onChange={ ( e ) => onChange( { ...rule, connection: e.target.value } ) }
				>
					<option value="">
						{ __( '— Select a connection —', 'modern-mailer-oauth' ) }
					</option>
					{ connections.map( ( c ) => (
						<option key={ c.id } value={ c.id }>
							{ c.name }
							{ ! c.configured ? __( ' (not configured)', 'modern-mailer-oauth' ) : '' }
						</option>
					) ) }
				</select>

				<span className="text-sm text-muted-foreground italic">
					{ __( 'if the following conditions are met…', 'modern-mailer-oauth' ) }
				</span>

				<div className="ml-auto flex items-center gap-0.5">
					<Button
						variant="ghost"
						size="icon"
						disabled={ index === 0 }
						aria-label={ __( 'Move up', 'modern-mailer-oauth' ) }
						onClick={ () => onMove( -1 ) }
					>
						<ChevronUp />
					</Button>
					<Button
						variant="ghost"
						size="icon"
						disabled={ index === total - 1 }
						aria-label={ __( 'Move down', 'modern-mailer-oauth' ) }
						onClick={ () => onMove( 1 ) }
					>
						<ChevronDown />
					</Button>
					<Button
						variant="ghost"
						size="icon"
						aria-label={ __( 'Remove rule', 'modern-mailer-oauth' ) }
						onClick={ onRemove }
					>
						<Trash2 className="text-danger" />
					</Button>
				</div>
			</div>

			<div className="p-4 grid gap-3">
				{ unusable && (
					<Alert variant="warning">
						<TriangleAlert />
						<AlertDescription>
							{ __(
								'This connection has no provider set, so nothing can be sent through it. Messages matching this rule fall back to the primary connection until it is configured.',
								'modern-mailer-oauth'
							) }
						</AlertDescription>
					</Alert>
				) }

				{ rule.groups.map( ( group, groupIndex ) => (
					<div key={ groupIndex } className="grid gap-2">
						{ groupIndex > 0 && (
							<div className="flex items-center gap-3 py-1">
								<Separator className="flex-1" />
								<span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
									{ __( 'or', 'modern-mailer-oauth' ) }
								</span>
								<Separator className="flex-1" />
							</div>
						) }

						{ group.map( ( condition, conditionIndex ) => (
							<ConditionRow
								key={ conditionIndex }
								condition={ condition }
								vocabulary={ vocabulary }
								isLast={ conditionIndex === group.length - 1 }
								canRemove={ group.length > 1 || rule.groups.length > 1 }
								onChange={ ( next ) => {
									const updated = [ ...group ];
									updated[ conditionIndex ] = next;
									setGroup( groupIndex, updated );
								} }
								onRemove={ () => {
									const updated = group.filter( ( _, i ) => i !== conditionIndex );

									if ( updated.length ) {
										setGroup( groupIndex, updated );
										return;
									}

									onChange( {
										...rule,
										groups: rule.groups.filter( ( _, i ) => i !== groupIndex ),
									} );
								} }
							/>
						) ) }

						<div>
							<Button
								variant="ghost"
								size="sm"
								onClick={ () => setGroup( groupIndex, [ ...group, newCondition() ] ) }
							>
								<Plus />
								{ __( 'And', 'modern-mailer-oauth' ) }
							</Button>
						</div>
					</div>
				) ) }

				<div>
					<Button
						variant="outline"
						size="sm"
						onClick={ () =>
							onChange( { ...rule, groups: [ ...rule.groups, [ newCondition() ] ] } )
						}
					>
						<Plus />
						{ __( 'Add new group', 'modern-mailer-oauth' ) }
					</Button>
				</div>
			</div>
		</div>
	);
};

const Routing = () => {
	const toast = useToast();
	const queryClient = useQueryClient();
	const [ enabled, setEnabled ] = useState( false );
	const [ rules, setRules ] = useState( [] );

	const { data, isLoading } = useQuery( { queryKey: [ 'routing' ], queryFn: getRouting } );

	useEffect( () => {
		if ( ! data ) {
			return;
		}

		setEnabled( !! data.enabled );
		setRules( Array.isArray( data.rules ) ? data.rules : [] );
	}, [ data ] );

	const save = useMutation( {
		mutationFn: () => saveRouting( enabled, rules ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'routing' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
			toast( __( 'Routing saved.', 'modern-mailer-oauth' ) );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	if ( isLoading ) {
		return <Spinner />;
	}

	const { vocabulary, connections } = data;
	const sendable = connections.filter( ( c ) => c.id !== 'backup' );

	const setRule = ( index, rule ) => {
		const next = [ ...rules ];
		next[ index ] = rule;
		setRules( next );
	};

	const move = ( index, delta ) => {
		const target = index + delta;

		if ( target < 0 || target >= rules.length ) {
			return;
		}

		const next = [ ...rules ];
		[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
		setRules( next );
	};

	return (
		<div className="grid gap-5">
			<Panel
				title={ __( 'Smart routing', 'modern-mailer-oauth' ) }
				description={ __(
					'Send some email through a different connection based on what it is. Anything that matches no rule goes out through the primary connection.',
					'modern-mailer-oauth'
				) }
				actions={
					<Button
						variant="default"
						busy={ save.isPending }
						onClick={ () => save.mutate() }
					>
						{ __( 'Save routing', 'modern-mailer-oauth' ) }
					</Button>
				}
			>
				<ToggleRow
					id="mmoa-routing-enabled"
					checked={ enabled }
					onChange={ setEnabled }
					label={ __( 'Enable smart routing', 'modern-mailer-oauth' ) }
					help={ __(
						'While this is off the rules below are kept but ignored, and every message goes through the primary connection.',
						'modern-mailer-oauth'
					) }
				/>
			</Panel>

			{ rules.length === 0 ? (
				<Panel>
					<EmptyState
						icon={ Route }
						title={ __( 'No rules yet', 'modern-mailer-oauth' ) }
						action={
							<Button onClick={ () => setRules( [ newRule() ] ) }>
								<Plus />
								{ __( 'Add rule', 'modern-mailer-oauth' ) }
							</Button>
						}
					>
						{ __(
							'A rule sends matching email through a connection of your choosing - receipts through a transactional sender, a newsletter through somewhere else.',
							'modern-mailer-oauth'
						) }
					</EmptyState>
				</Panel>
			) : (
				<div className="grid gap-4">
					{ rules.map( ( rule, index ) => (
						<RuleCard
							key={ index }
							rule={ rule }
							index={ index }
							total={ rules.length }
							connections={ sendable }
							vocabulary={ vocabulary }
							onChange={ ( next ) => setRule( index, next ) }
							onMove={ ( delta ) => move( index, delta ) }
							onRemove={ () => setRules( rules.filter( ( _, i ) => i !== index ) ) }
						/>
					) ) }

					<div>
						<Button variant="outline" onClick={ () => setRules( [ ...rules, newRule() ] ) }>
							<Plus />
							{ __( 'Add rule', 'modern-mailer-oauth' ) }
						</Button>
					</div>
				</div>
			) }

			<Alert variant="info">
				<Lightbulb />
				<AlertDescription>
					{ rules.length > 1
						? __(
								'Rules are checked from the top down and the first match wins, so put the most specific rules first. Anything matching none of them uses the primary connection.',
								'modern-mailer-oauth'
						  )
						: __(
								'Your primary connection is used for every message that matches none of the rules above.',
								'modern-mailer-oauth'
						  ) }
				</AlertDescription>
			</Alert>

			<div className="sticky bottom-0 -mx-6 px-6 py-3 bg-background/80 backdrop-blur border-t">
				<Button variant="default" busy={ save.isPending } onClick={ () => save.mutate() }>
					{ __( 'Save routing', 'modern-mailer-oauth' ) }
				</Button>
			</div>
		</div>
	);
};

export default Routing;
