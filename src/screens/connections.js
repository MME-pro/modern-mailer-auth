import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, ShieldCheck, Send, AlertTriangle } from 'lucide-react';
import {
	getConnection,
	saveConnection,
	verifyConnection,
	sendTestEmail,
} from '../api/client';
import { useToast } from '../components/toast';
import { Panel, Button, Badge, FormField, Spinner, inputClass } from '../components/ui';
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

const ConnectionPanel = ( { slot, categories } ) => {
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
				title={
					slot === 'primary'
						? __( 'Primary connection', 'modern-mailer-oauth' )
						: __( 'Backup connection', 'modern-mailer-oauth' )
				}
				description={
					slot === 'primary'
						? __( 'Every message is attempted here first.', 'modern-mailer-oauth' )
						: __(
								'Tried immediately when the primary fails. Use a different provider - two connections sharing one identity endpoint go down together.',
								'modern-mailer-oauth'
						  )
				}
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

const Connections = () => {
	const [ slot, setSlot ] = useState( 'primary' );
	const { data } = useQuery( { queryKey: [ 'bootstrap' ] } );
	const categories = data?.categories || {};

	return (
		<div className="grid gap-5">
			<div className="inline-flex p-1 bg-muted rounded-lg self-start">
				{ [
					[ 'primary', __( 'Primary', 'modern-mailer-oauth' ) ],
					[ 'backup', __( 'Backup', 'modern-mailer-oauth' ) ],
				].map( ( [ key, label ] ) => (
					<button
						key={ key }
						type="button"
						onClick={ () => setSlot( key ) }
						className={ `inline-flex items-center gap-1.5 px-3.5 h-8 rounded-md text-[13px] font-medium border-0 cursor-pointer transition-colors ${
							slot === key
								? 'bg-card text-foreground shadow-sm'
								: 'bg-transparent text-muted-foreground hover:text-foreground'
						}` }
					>
						{ label }
						{ key === 'backup' && data?.health?.has_backup && (
							<Badge variant="success">{ __( 'active', 'modern-mailer-oauth' ) }</Badge>
						) }
					</button>
				) ) }
			</div>

			<ConnectionPanel key={ slot } slot={ slot } categories={ categories } />

			{ slot === 'primary' && <TestEmail /> }
		</div>
	);
};

export default Connections;
