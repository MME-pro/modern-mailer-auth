import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { getSettings, saveSettings } from '../api/client';
import { useToast } from '../components/toast';
import { Panel, Button, FormField, Spinner, ToggleRow, inputClass } from '../components/ui';

const Settings = () => {
	const toast = useToast();
	const queryClient = useQueryClient();
	const [ values, setValues ] = useState( null );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'settings' ],
		queryFn: getSettings,
	} );

	useEffect( () => {
		if ( data ) {
			setValues( data.values );
		}
	}, [ data ] );

	const save = useMutation( {
		mutationFn: () => saveSettings( values ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'settings' ] } );
			queryClient.invalidateQueries( { queryKey: [ 'bootstrap' ] } );
			toast( __( 'Settings saved.', 'modern-mailer-oauth' ) );
		},
		onError: ( error ) => toast( error.message, 'bad' ),
	} );

	if ( isLoading || ! values ) {
		return <Spinner />;
	}

	const locked = data.locked;
	const set = ( key, value ) => setValues( ( s ) => ( { ...s, [ key ]: value } ) );

	return (
		<div className="grid gap-5">
			<Panel
				title={ __( 'Sender', 'modern-mailer-oauth' ) }
				description={ __(
					'Applies to every connection. The address must be one the connected identity is permitted to send as.',
					'modern-mailer-oauth'
				) }
			>
				<div className="grid gap-4 sm:grid-cols-2">
					<FormField
						label={ __( 'From address', 'modern-mailer-oauth' ) }
						locked={ locked.from_email }
						htmlFor="mmoa-from-email"
					>
						<input
							id="mmoa-from-email"
							type="email"
							disabled={ locked.from_email }
							className={ inputClass }
							value={ values.from_email || '' }
							onChange={ ( e ) => set( 'from_email', e.target.value ) }
						/>
					</FormField>

					<FormField
						label={ __( 'From name', 'modern-mailer-oauth' ) }
						locked={ locked.from_name }
						htmlFor="mmoa-from-name"
					>
						<input
							id="mmoa-from-name"
							type="text"
							disabled={ locked.from_name }
							className={ inputClass }
							value={ values.from_name || '' }
							onChange={ ( e ) => set( 'from_name', e.target.value ) }
						/>
					</FormField>

					<div className="sm:col-span-2">
						<ToggleRow
							id="mmoa-force-from"
							checked={ values.force_from }
							onChange={ ( v ) => set( 'force_from', v ) }
							label={ __(
								'Override the From address set by other plugins',
								'modern-mailer-oauth'
							) }
							help={ __(
								'Recommended. Both APIs reject or silently rewrite a From address the authenticated identity may not use.',
								'modern-mailer-oauth'
							) }
						/>
					</div>
				</div>
			</Panel>

			<Panel title={ __( 'Reliability', 'modern-mailer-oauth' ) }>
				<div className="grid gap-4">
					<ToggleRow
						id="mmoa-queue-enabled"
						checked={ values.queue_enabled }
						onChange={ ( v ) => set( 'queue_enabled', v ) }
						label={ __(
							'Hold on to messages that failed for a temporary reason and retry them',
							'modern-mailer-oauth'
						) }
						help={ __(
							'Strongly recommended. The failure that actually loses mail is a brief network or DNS fault at your host, which clears in minutes - far longer than a single page request can wait. A queued message is stored complete, body included, until it is delivered; delivered ones are removed immediately, abandoned ones after seven days.',
							'modern-mailer-oauth'
						) }
					/>
				</div>
			</Panel>

			<Panel
				title={ __( 'Logging and alerts', 'modern-mailer-oauth' ) }
				description={ __(
					'Almost no WordPress code checks what wp_mail() returned, so an alert is usually the only way anyone finds out sending has stopped.',
					'modern-mailer-oauth'
				) }
			>
				<div className="grid gap-4">
					<ToggleRow
						id="mmoa-log-enabled"
						checked={ values.log_enabled }
						onChange={ ( v ) => set( 'log_enabled', v ) }
						label={ __( 'Record the outcome of every send', 'modern-mailer-oauth' ) }
						help={ __(
							'Envelope details and errors only. Message bodies are never stored.',
							'modern-mailer-oauth'
						) }
					/>

					<div className="grid gap-4 sm:grid-cols-3">
						<FormField
							label={ __( 'Keep entries for (days)', 'modern-mailer-oauth' ) }
							htmlFor="mmoa-retention"
						>
							<input
								id="mmoa-retention"
								type="number"
								min="1"
								className={ inputClass }
								value={ values.log_retention }
								onChange={ ( e ) => set( 'log_retention', e.target.value ) }
							/>
						</FormField>

						<FormField
							label={ __( 'Alert after N failures', 'modern-mailer-oauth' ) }
							help={ __( 'Consecutive failures.', 'modern-mailer-oauth' ) }
							htmlFor="mmoa-threshold"
						>
							<input
								id="mmoa-threshold"
								type="number"
								min="1"
								className={ inputClass }
								value={ values.alert_threshold }
								onChange={ ( e ) => set( 'alert_threshold', e.target.value ) }
							/>
						</FormField>

						<FormField
							label={ __( 'Alert address', 'modern-mailer-oauth' ) }
							help={ __(
								'Sent with the server mail function, not the API - the API is what has failed.',
								'modern-mailer-oauth'
							) }
							locked={ locked.alert_email }
							htmlFor="mmoa-alert-email"
						>
							<input
								id="mmoa-alert-email"
								type="email"
								disabled={ locked.alert_email }
								className={ inputClass }
								value={ values.alert_email || '' }
								onChange={ ( e ) => set( 'alert_email', e.target.value ) }
							/>
						</FormField>
					</div>
				</div>
			</Panel>

			<div className="sticky bottom-0 -mx-6 px-6 py-3 bg-background/80 backdrop-blur border-t border-border">
				<Button
					variant="default"
					busy={ save.isPending }
					onClick={ () => save.mutate() }
				>
					{ __( 'Save settings', 'modern-mailer-oauth' ) }
				</Button>
			</div>
		</div>
	);
};

export default Settings;
