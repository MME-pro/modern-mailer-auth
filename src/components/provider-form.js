import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Copy, Check } from 'lucide-react';
import { cn } from '../lib/utils';
import { FormField, Input, Textarea, Switch, Label, inputClass } from './ui';

/**
 * A settings form generated from whatever the chosen provider declared.
 *
 * Nothing here knows what a tenant ID or an SMTP port is. Providers publish
 * their fields through the REST API and this renders them, which is what keeps
 * the provider list maintainable and means a provider registered by another
 * plugin gets a proper form for free.
 *
 * Three pieces of declared metadata do the work that would otherwise force a
 * hand-written panel per provider:
 *
 * - `width` places fields on a row together.
 * - `sets` lets choosing one option fill in another field, which is how picking
 *   an encryption also picks the port.
 * - `depends` disables a field while another one makes it irrelevant.
 */

// A six-column grid, so thirds and halves both land cleanly.
const SPAN = {
	third: 'sm:col-span-2',
	half: 'sm:col-span-3',
	full: 'sm:col-span-6',
};

/**
 * Radios rather than a select for short, mutually exclusive choices.
 *
 * Three options are all visible at once, which matters for encryption: the
 * consequence of picking the wrong one is a connection that fails in a way the
 * error message cannot fully explain, so seeing all of them side by side beats
 * discovering them inside a dropdown.
 */
const RadioGroup = ( { field, value, disabled, onChange } ) => (
	<div
		role="radiogroup"
		aria-label={ field.label }
		className="flex flex-wrap items-center gap-2"
	>
		{ Object.entries( field.options ).map( ( [ optionValue, optionLabel ] ) => {
			const id = `mmoa-${ field.key }-${ optionValue }`;
			const checked = String( value ) === String( optionValue );

			return (
				<label
					key={ optionValue }
					htmlFor={ id }
					className={ cn(
						'inline-flex h-9 cursor-pointer select-none items-center gap-2 rounded-md border px-3 text-sm transition-colors',
						checked
							? 'border-brand bg-brand-subtle text-foreground ring-1 ring-brand'
							: 'bg-card hover:bg-muted',
						disabled && 'pointer-events-none opacity-60'
					) }
				>
					<input
						type="radio"
						id={ id }
						name={ `mmoa-${ field.key }` }
						value={ optionValue }
						checked={ checked }
						disabled={ disabled }
						onChange={ () => onChange( optionValue ) }
						className="sr-only"
					/>
					<span
						aria-hidden="true"
						className={ cn(
							'flex size-3.5 items-center justify-center rounded-full border',
							checked ? 'border-brand' : 'border-input'
						) }
					>
						{ checked && <span className="size-1.5 rounded-full bg-brand-deep" /> }
					</span>
					{ optionLabel }
				</label>
			);
		} ) }
	</div>
);

/**
 * Whether the field this one depends on currently says it does not apply.
 *
 * Such a field is hidden, not greyed out. These were disabled at first, to stop
 * a three-column row collapsing whenever a toggle moved - but a mode selector
 * makes that trade the wrong way round: choosing one-click setup left two dead
 * credential boxes sitting under it, which reads as something still to fill in
 * rather than something that no longer applies. A row that reflows is a smaller
 * cost than a form that looks unfinished.
 *
 * `locked` is deliberately not part of this. A value pinned by a wp-config.php
 * constant is still in force and still worth seeing, so it stays visible with
 * the explanation attached.
 */
export const isDependencyUnmet = ( provider, values, field ) => {
	if ( ! field.depends?.field ) {
		return false;
	}

	const controller = provider.fields.find( ( f ) => f.key === field.depends.field );
	const current =
		values[ field.depends.field ] !== undefined
			? values[ field.depends.field ]
			: controller?.value ?? controller?.default ?? '';

	return String( current ) !== String( field.depends.value );
};

/**
 * Whether a field is shown but not editable.
 *
 * At module scope because the same rule has to decide two things: what the form
 * greys out, and what the required check below is entitled to demand. A field
 * that is irrelevant right now - hidden or merely locked - must not be able to
 * block a connection.
 */
export const isFieldDisabled = ( provider, values, field ) =>
	!! field.locked || isDependencyUnmet( provider, values, field );

/**
 * The required fields this provider still has nothing for.
 *
 * Every provider has always declared which fields it cannot work without, and
 * the REST API has always published that flag - nothing read it. So a
 * connection could be saved with a hole in it and only say so at verification
 * time, one missing field per attempt, with the form showing no sign that
 * anything was outstanding.
 *
 * A secret counts as present if the form holds one or the server says one is
 * stored. `is_set` still matters: a value pinned by a wp-config.php constant is
 * in force without the form carrying it.
 *
 * @return {Array<Object>} The offending field definitions, in form order.
 */
export const missingRequired = ( provider, values ) =>
	( provider?.fields || [] ).filter( ( field ) => {
		if ( ! field.required || isFieldDisabled( provider, values, field ) ) {
			return false;
		}

		if ( field.secret ) {
			return ! field.is_set && '' === String( values[ field.key ] ?? '' ).trim();
		}

		const value = values[ field.key ] !== undefined ? values[ field.key ] : field.value;

		return '' === String( value ?? '' ).trim();
	} );

/**
 * A credential field, masked, with a copy button.
 *
 * A saved credential is never drawn in plain text. Copying is what checking
 * one is actually for - pasting it beside the value in the provider's own
 * console - and it does that without putting the secret on a screen that may
 * be shared, screenshotted, or looked over.
 *
 * What is being typed right now is left visible in the field it came from,
 * because hiding it would stop anyone spotting a typo in the thing they are
 * currently typing.
 */
const SecretInput = ( { id, field, value, disabled, placeholder, onChange } ) => {
	const [ copied, setCopied ] = useState( false );
	const [ dirty, setDirty ] = useState( false );

	// A saved credential is never drawn in plain text. It can be copied, which
	// is what checking one is actually for - pasting it back beside the value
	// in the provider's own console - and copying puts it on the clipboard
	// without putting it on a screen somebody may be sharing.
	//
	// What is being typed right now is a different matter: it is already
	// visible in the keystrokes, and hiding it would stop anyone seeing a typo
	// in the thing they are typing.
	const stored = ! dirty && '' !== String( value ?? '' );

	const copy = async () => {
		try {
			await navigator.clipboard.writeText( String( value ) );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch {
			// Clipboard access can be refused - over plain HTTP, or by policy.
			// Nothing is lost; the value is unchanged and still saved.
		}
	};

	return (
		<div className="relative">
			<Input
				id={ id }
				type="password"
				autoComplete="new-password"
				spellCheck={ false }
				disabled={ disabled }
				placeholder={ placeholder }
				className={ cn( stored && ! disabled && 'pr-10' ) }
				value={ value }
				onChange={ ( e ) => {
					setDirty( true );
					onChange( e.target.value );
				} }
			/>

			{ stored && ! disabled && (
				<button
					type="button"
					onClick={ copy }
					aria-label={ __( 'Copy', 'modern-mailer-oauth' ) }
					title={ __( 'Copy to clipboard', 'modern-mailer-oauth' ) }
					className="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-md text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/40"
				>
					{ copied ? <Check className="size-4 text-success" /> : <Copy className="size-4" /> }
				</button>
			) }
		</div>
	);
};

const ProviderForm = ( { provider, values, onChange } ) => {
	// A provider can genuinely have nothing to fill in - Outlook chooses its
	// mailbox at Microsoft's own prompt. Rendering the grid anyway leaves an
	// empty gap above the buttons that reads as a form that failed to load.
	if ( ! provider.fields?.length ) {
		return null;
	}

	const valueOf = ( field ) =>
		values[ field.key ] !== undefined ? values[ field.key ] : field.value ?? '';

	const isDisabled = ( field ) => isFieldDisabled( provider, values, field );

	const handle = ( field, value ) => {
		onChange( field.key, value );

		// One choice can fill in another field. Applied as a plain change so it
		// is editable afterwards - a provider on a non-standard port must still
		// be able to say so.
		const linked = field.sets?.[ value ];

		if ( linked ) {
			Object.entries( linked ).forEach( ( [ key, linkedValue ] ) =>
				onChange( key, linkedValue )
			);
		}
	};

	return (
		<div className="grid gap-4 sm:grid-cols-6">
			{ provider.fields.map( ( field ) => {
				// A field belonging to a mode that is not selected is not
				// shown at all. Nothing here is lost by hiding it: the value
				// stays stored, and choosing that mode again brings it back
				// with whatever was in it.
				if ( isDependencyUnmet( provider, values, field ) ) {
					return null;
				}

				const id = `mmoa-${ field.key }`;
				const disabled = isDisabled( field );
				const value = valueOf( field );

				// A stored secret never reaches the browser, so the placeholder
				// is the only way the form can say "there is something here".
				const placeholder =
					field.secret && field.is_set
						? __( 'Stored. Leave blank to keep it.', 'modern-mailer-oauth' )
						: field.placeholder;

				return (
					<div
						key={ field.key }
						className={ cn( 'col-span-full', SPAN[ field.width ] || SPAN.full ) }
					>
						<FormField
							label={ field.label }
							help={ field.help }
							locked={ field.locked }
							required={ field.required && ! disabled }
							htmlFor={ field.type === 'radio' ? undefined : id }
						>
							{ field.type === 'radio' && (
								<RadioGroup
									field={ field }
									value={ value }
									disabled={ disabled }
									onChange={ ( v ) => handle( field, v ) }
								/>
							) }

							{ field.type === 'checkbox' && (
								<div className="flex h-9 items-center gap-2">
									<Switch
										id={ id }
										checked={ !! value }
										disabled={ disabled }
										onCheckedChange={ ( v ) => handle( field, v ) }
									/>
									<Label htmlFor={ id } className="text-muted-foreground">
										{ __( 'Enabled', 'modern-mailer-oauth' ) }
									</Label>
								</div>
							) }

							{ field.type === 'select' && (
								<select
									id={ id }
									disabled={ disabled }
									className={ inputClass }
									value={ value }
									onChange={ ( e ) => handle( field, e.target.value ) }
								>
									{ Object.entries( field.options ).map( ( [ v, label ] ) => (
										<option key={ v } value={ v }>
											{ label }
										</option>
									) ) }
								</select>
							) }

							{ field.type === 'textarea' && (
								<Textarea
									id={ id }
									rows={ 5 }
									disabled={ disabled }
									placeholder={ placeholder }
									className="font-mono text-xs"
									value={ value }
									onChange={ ( e ) => handle( field, e.target.value ) }
								/>
							) }

							{ ! [ 'radio', 'checkbox', 'select', 'textarea' ].includes(
								field.type
							) && (
								field.secret ? (
									<SecretInput
										id={ id }
										field={ field }
										value={ value }
										disabled={ disabled }
										placeholder={ placeholder }
										onChange={ ( v ) => handle( field, v ) }
									/>
								) : (
									<Input
										id={ id }
										type={ field.type === 'number' ? 'number' : 'text' }
										inputMode={ field.type === 'number' ? 'numeric' : undefined }
										autoComplete="off"
										disabled={ disabled }
										placeholder={ placeholder }
										value={ value }
										onChange={ ( e ) => handle( field, e.target.value ) }
									/>
								)
							) }
						</FormField>
					</div>
				);
			} ) }
		</div>
	);
};

export default ProviderForm;
