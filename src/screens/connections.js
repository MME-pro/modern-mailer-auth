import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, ShieldCheck, Send, AlertTriangle, Plus, Trash2 } from 'lucide-react';
import {
	getConnection,
	saveConnection,
	verifyConnection,
	sendTestEmail,
	listConnections,
	addConnection,
	deleteConnection,
} from '../api/client';
import { useToast } from '../components/toast';
import { Panel, Button, Badge, FormField, Spinner, Input, inputClass } from '../components/ui';
import { cn } from '../lib/utils';
import GoogleConnect from '../components/google-connect';
import ProviderForm from '../components/provider-form';

const ProviderPicker = ( { providers, categories, selected, onSelect } ) => (
	<div className="grid gap-5">
		{ Object.entries( categories ).map( ( [ key, title ] ) => {
			const inGroup = providers.filter( ( p ) => p.category === key );

			if ( inGroup.length === 0 ) {
				return null;
			}

			return (
				<div key={ key }>
					<h3 className="text-[12px] font-semibold uppercase tracking-wide text-muted-foreground m-0 mb-2">
						{ title }
					</h3>
					<div className="grid gap-2.5 grid-cols-[repeat(auto-fill,minmax(250px,1fr))]">
						{ inGroup.map( ( provider ) => {
							const active = selected === provider.slug;

							return (
								<button
									key={ provider.slug }
									type="button"
									onClick={ () => onSelect( provider.slug ) }
									className={ `text-left p-3.5 rounded-xl border transition-colors cursor-pointer ${
										active
											? 'border-brand bg-brand-subtle ring-1 ring-brand'
											: 'border-border bg-card hover:border-input'
									}` }
								>
									<div className="flex items-center justify-between gap-2">
										<span className="text-[13px] font-semibold text-foreground">
											{ provider.label }
										</span>
										{ active && (
											<Check size={ 15 } className="text-brand shrink-0" />
										) }
									</div>
									<p className="text-[12px] text-muted-foreground m-0 mt-1">
										{ provider.summary }
									</p>
								</button>
							);
						} ) }
					</div>
				</div>
			);
		} ) }
	</div>
);

/**
 * What each connection is for.
 *
 * The two built-ins have fixed meanings worth stating on the screen; an
 * additional connection means whatever the routing rules make it mean, so it
 * gets pointed at the screen that decides that.
 */
const describeSlot = ( slot ) => {
	if ( 'primary' === slot ) {
		return __(
			'Every message is attempted here first, unless a routing rule sends it elsewhere.',
			'modern-mailer-oauth'
		);
	}

	if ( 'backup' === slot ) {
		return __(
			'Tried immediately when the connection that was chosen fails. Use a different provider - two connections sharing one identity endpoint go down together.',
			'modern-mailer-oauth'
		);
	}

	return __(
		'Used only for messages a routing rule sends here. Set those up under Routing.',
		'modern-mailer-oauth'
	);
};

const ConnectionPanel = ( { slot, categories, title } ) => {
	const toast = useToast();
	const queryClient = useQueryClient();
	const [ provider, setProvider ] = useState( '' );
	const [ values, setValues ] = useState( {} );
	const [ verifyResult, setVerifyResult ] = useState( null );
	const [ dirty, setDirty ] = useState( false );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'connection', slot ],
		queryFn: () => getConnection( slot ),
	} );

	useEffect( () => {
		if ( ! data ) {
			return;
		}

		setProvider( data.provider );

		const chosen = data.providers.find( ( p ) => p.slug === data.provider );
		const seed = {};

		( chosen?.fields || [] ).forEach( ( field ) => {
			if ( ! field.secret ) {
				seed[ field.key ] = field.value ?? '';
			}
		} );

		setValues( seed );
		setDirty( false );
	}, [ data ] );

	const current = data?.providers.find( ( p ) => p.slug === provider );

	const save = useMutation( {
		mutationFn: () => saveConnection( slot, { provider, ...values } ),
		onSuccess: () => {
			// Secrets are write-only. Clearing them from local state after a
			// save keeps the field honest: what is stored can no longer be read
			// back, so it must not keep looking like an editable value.
			setValues( ( previous ) => {
				const next = { ...previous };

				( current?.fields || [] ).forEach( ( field ) => {
					if ( field.secret ) {
						delete next[ field.key ];
					}
				} );

				return next;
			} );

			setVerifyResult( null );
			setDirty( false );
			queryClient.invalidateQueries( { queryKey: [ 'connection', slot ] } );
			queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
			toast( __( 'Connection saved.', 'modern-mailer-oauth' ) );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	const verify = useMutation( {
		mutationFn: () => verifyConnection( slot ),
		// The result is kept in the page rather than shown as a toast: a
		// verification failure names the exact misconfiguration, and that is
		// the last thing that should vanish after three seconds.
		onSuccess: ( result ) => setVerifyResult( result ),
		onError: ( error ) => setVerifyResult( { ok: false, message: error.message } ),
	} );

	if ( isLoading ) {
		return <Spinner />;
	}

	return (
		<div className="grid gap-5">
			<Panel
				title={ title || __( 'Connection', 'modern-mailer-oauth' ) }
				description={ describeSlot( slot ) }
			>
				<ProviderPicker
					providers={ data.providers }
					categories={ categories }
					selected={ provider }
					onSelect={ ( slug ) => {
						setProvider( slug );
						setValues( {} );
						setVerifyResult( null );
						setDirty( true );
					} }
				/>
			</Panel>

			{ current && (
				<Panel
					title={ current.label }
					description={ current.summary }
					actions={
						current.docs ? (
							<a
								href={ current.docs }
								target="_blank"
								rel="noreferrer"
								className="text-[13px] text-brand no-underline hover:underline self-center"
							>
								{ __( 'Documentation', 'modern-mailer-oauth' ) }
							</a>
						) : null
					}
				>
					<ProviderForm
						provider={ current }
						values={ values }
						onChange={ ( key, value ) =>
							{
								setDirty( true );
								setValues( ( state ) => ( { ...state, [ key ]: value } ) );
							}
						}
					/>

					<div className="flex flex-wrap items-center gap-2 mt-5 pt-5 border-t border-border">
						<Button
							variant="default"
							busy={ save.isPending }
							onClick={ () => save.mutate() }
						>
							{ __( 'Save connection', 'modern-mailer-oauth' ) }
						</Button>
						<Button busy={ verify.isPending } onClick={ () => verify.mutate() }>
							<ShieldCheck size={ 14 } />
							{ __( 'Verify', 'modern-mailer-oauth' ) }
						</Button>
					</div>

					{ /* Keyed on the provider currently selected, not the saved
					     one. Someone setting Gmail up needs the redirect URI and
					     the reason they cannot sign in yet before they have ever
					     saved - hiding the section until then hides it exactly
					     when it is wanted. */ }
					{ provider === 'gmail_oauth' && (
						<GoogleConnect
							oauth={ data.oauth }
							dirty={ dirty || data.provider !== 'gmail_oauth' }
						/>
					) }

					{ verifyResult && (
						<div
							className={ `flex items-start gap-2 mt-3 p-3 rounded-lg text-[13px] ${
								verifyResult.ok ? 'bg-success-subtle' : 'bg-danger-subtle'
							}` }
						>
							{ verifyResult.ok ? (
								<Check size={ 15 } className="shrink-0 mt-0.5 text-success" />
							) : (
								<AlertTriangle
									size={ 15 }
									className="shrink-0 mt-0.5 text-danger"
								/>
							) }
							<span className="text-foreground">{ verifyResult.message }</span>
						</div>
					) }
				</Panel>
			) }
		</div>
	);
};

const TestEmail = () => {
	const toast = useToast();
	const [ to, setTo ] = useState( window.mmoa?.currentUserEmail || '' );

	const send = useMutation( {
		mutationFn: () => sendTestEmail( to ),
		onSuccess: ( result ) => toast( result.message, result.ok ? 'ok' : 'bad' ),
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	return (
		<Panel
			title={ __( 'Send a test message', 'modern-mailer-oauth' ) }
			description={ __(
				'Goes out over the primary connection. There is deliberately no test through the backup, because the backup is only reached when the primary fails.',
				'modern-mailer-oauth'
			) }
		>
			<div className="flex flex-wrap gap-3 items-end">
				<div className="flex-1 min-w-[240px]">
					<FormField
						label={ __( 'Recipient', 'modern-mailer-oauth' ) }
						htmlFor="mmoa-test-to"
					>
						<input
							id="mmoa-test-to"
							type="email"
							className={ inputClass }
							value={ to }
							onChange={ ( e ) => setTo( e.target.value ) }
						/>
					</FormField>
				</div>
				<Button busy={ send.isPending } onClick={ () => send.mutate() }>
					<Send size={ 14 } />
					{ __( 'Send test', 'modern-mailer-oauth' ) }
				</Button>
			</div>
		</Panel>
	);
};

/**
 * The list of connections down the side.
 *
 * Primary and Backup are fixed - one is what sends by default and the other is
 * the fallback, so neither can be removed or renamed without the words meaning
 * something else. Everything after them exists to give a routing rule somewhere
 * to point.
 */
const ConnectionList = ( { connections, selected, max, onSelect } ) => {
	const toast = useToast();
	const queryClient = useQueryClient();
	const [ adding, setAdding ] = useState( false );
	const [ name, setName ] = useState( '' );

	const refresh = () => {
		queryClient.invalidateQueries( { queryKey: [ 'connection-list' ] } );
		queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
		queryClient.invalidateQueries( { queryKey: [ 'routing' ] } );
	};

	const add = useMutation( {
		mutationFn: () => addConnection( name ),
		onSuccess: ( result ) => {
			if ( ! result.ok ) {
				toast( result.message, 'bad' );
				return;
			}

			setAdding( false );
			setName( '' );
			refresh();
			onSelect( result.id );
			toast( __( 'Connection added. Choose a provider for it.', 'modern-mailer-oauth' ) );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	const remove = useMutation( {
		mutationFn: deleteConnection,
		onSuccess: ( result ) => {
			toast( result.message, result.ok ? 'ok' : 'bad' );
			refresh();
			onSelect( 'primary' );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	const additional = connections.filter( ( c ) => ! c.builtin );
	const full = additional.length >= max;

	return (
		<div className="grid gap-2 content-start">
			{ connections.map( ( connection ) => {
				const active = selected === connection.id;

				return (
					<div
						key={ connection.id }
						className={ cn(
							'group flex items-center gap-2 rounded-lg border px-3 py-2 transition-colors',
							active ? 'border-brand bg-brand-subtle' : 'bg-card hover:bg-muted'
						) }
					>
						<button
							type="button"
							onClick={ () => onSelect( connection.id ) }
							className="flex-1 min-w-0 text-left bg-transparent border-0 cursor-pointer p-0"
						>
							<span className="block text-sm font-medium truncate">
								{ connection.name }
							</span>
							<span className="block text-xs text-muted-foreground truncate">
								{ connection.configured
									? connection.provider
									: __( 'Not configured', 'modern-mailer-oauth' ) }
							</span>
						</button>

						{ connection.configured && (
							<span
								aria-hidden="true"
								className="size-1.5 rounded-full bg-success shrink-0"
							/>
						) }

						{ ! connection.builtin && (
							<Button
								variant="ghost"
								size="icon"
								className="opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
								aria-label={ sprintf(
									/* translators: %s: connection name. */
									__( 'Remove %s', 'modern-mailer-oauth' ),
									connection.name
								) }
								busy={ remove.isPending }
								onClick={ () => {
									// Deleting takes the stored credentials with
									// it, which is not recoverable from here.
									// eslint-disable-next-line no-alert
									if (
										window.confirm(
											sprintf(
												/* translators: %s: connection name. */
												__(
													'Remove %s and its stored credentials? Any routing rule using it will be deleted too.',
													'modern-mailer-oauth'
												),
												connection.name
											)
										)
									) {
										remove.mutate( connection.id );
									}
								} }
							>
								<Trash2 className="text-danger" />
							</Button>
						) }
					</div>
				);
			} ) }

			{ adding ? (
				<div className="grid gap-2 rounded-lg border p-3">
					<Input
						autoFocus
						placeholder={ __( 'Connection name', 'modern-mailer-oauth' ) }
						value={ name }
						onChange={ ( e ) => setName( e.target.value ) }
						onKeyDown={ ( e ) => e.key === 'Enter' && add.mutate() }
					/>
					<div className="flex gap-2">
						<Button size="sm" variant="default" busy={ add.isPending } onClick={ () => add.mutate() }>
							{ __( 'Add', 'modern-mailer-oauth' ) }
						</Button>
						<Button size="sm" variant="ghost" onClick={ () => setAdding( false ) }>
							{ __( 'Cancel', 'modern-mailer-oauth' ) }
						</Button>
					</div>
				</div>
			) : (
				<Button
					variant="outline"
					size="sm"
					disabled={ full }
					onClick={ () => setAdding( true ) }
				>
					<Plus />
					{ full
						? __( 'Connection limit reached', 'modern-mailer-oauth' )
						: __( 'Add connection', 'modern-mailer-oauth' ) }
				</Button>
			) }
		</div>
	);
};

const Connections = () => {
	const [ selected, setSelected ] = useState( 'primary' );
	const { data: bootstrap } = useQuery( { queryKey: [ 'bootstrap' ] } );
	const { data: list, isLoading } = useQuery( {
		queryKey: [ 'connection-list' ],
		queryFn: listConnections,
	} );

	const categories = bootstrap?.categories || {};

	if ( isLoading ) {
		return <Spinner />;
	}

	const connections = list?.connections || [];
	const current = connections.find( ( c ) => c.id === selected );

	// A connection can disappear underneath the selection - deleted here, or in
	// another tab - so fall back rather than rendering an empty panel.
	const activeId = current ? selected : 'primary';

	return (
		<div className="grid gap-5 lg:grid-cols-[260px_1fr] items-start">
			<ConnectionList
				connections={ connections }
				selected={ activeId }
				max={ list?.max ?? 10 }
				onSelect={ setSelected }
			/>

			<div className="grid gap-5 min-w-0">
				<ConnectionPanel
					key={ activeId }
					slot={ activeId }
					categories={ categories }
					title={ ( current || connections[ 0 ] )?.name }
				/>

				{ 'primary' === activeId && <TestEmail /> }
			</div>
		</div>
	);
};

export default Connections;
