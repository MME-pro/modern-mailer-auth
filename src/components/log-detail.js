import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';
import { Copy, Check } from 'lucide-react';
import { getLogEntry } from '../api/client';
import {
	Dialog,
	DialogContent,
	DialogHeader,
	DialogBody,
	DialogFooter,
	DialogTitle,
	DialogDescription,
	Button,
	Spinner,
	Badge,
} from './ui';

/**
 * Everything known about one send attempt.
 *
 * The error message on its own is rarely enough to fix anything. "Recipient
 * address rejected" says what the far end thought; it does not say which host
 * was dialled, on which port, with which encryption, or what the server
 * actually replied - and those are the facts that turn a support thread into a
 * fix. So the whole report is here, and the copy button puts it somewhere it
 * can be pasted.
 *
 * Fetched when the modal opens rather than with the list. A report runs to
 * kilobytes, and a page of fifty failures would otherwise carry a megabyte of
 * transcript nobody has asked to read.
 */
const Section = ( { title, children } ) => (
	<section className="grid gap-2">
		<h4 className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground m-0">
			{ title }
		</h4>
		{ children }
	</section>
);

/**
 * A block of name/value pairs.
 *
 * A definition list rather than a table: these are labelled facts, not rows of
 * comparable records, and a screen reader announces the pairing.
 */
const Pairs = ( { items } ) => (
	<dl className="grid gap-x-6 gap-y-1.5 sm:grid-cols-[minmax(0,14rem)_1fr] text-[13px] m-0">
		{ Object.entries( items ).map( ( [ key, value ] ) => (
			<div key={ key } className="contents">
				<dt className="text-muted-foreground">{ key }</dt>
				<dd className="m-0 font-mono text-xs break-all">{ String( value ) }</dd>
			</div>
		) ) }
	</dl>
);

/**
 * Preformatted, scrollable, and wrapping.
 *
 * A transcript has long lines and there is no useful place to break them, so it
 * scrolls sideways inside its own box rather than making the modal do it.
 */
const Pre = ( { children } ) => (
	<pre className="m-0 max-h-[22rem] overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-[11.5px] leading-relaxed whitespace-pre-wrap break-words">
		{ children }
	</pre>
);

/** The report as text, for pasting into a support thread. */
const asText = ( entry ) => {
	const d = entry.diagnostics || {};
	const block = ( title, items ) =>
		items && Object.keys( items ).length
			? `${ title }:\n` +
			  Object.entries( items )
					.map( ( [ k, v ] ) => `${ k }: ${ v }` )
					.join( '\n' ) +
			  '\n\n'
			: '';

	return (
		block( __( 'Versions', 'modern-mailer-oauth' ), d.versions ) +
		block( __( 'Params', 'modern-mailer-oauth' ), d.params ) +
		block( __( 'Server', 'modern-mailer-oauth' ), d.server ) +
		( entry.error ? `${ __( 'Error', 'modern-mailer-oauth' ) }:\n${ entry.error }\n\n` : '' ) +
		( d.transcript
			? `${ __( 'SMTP Debug', 'modern-mailer-oauth' ) }:\n${ d.transcript }\n`
			: '' )
	).trim();
};

const LogDetail = ( { id, onClose } ) => {
	const [ copied, setCopied ] = useState( false );

	const { data: entry, isLoading } = useQuery( {
		queryKey: [ 'log', id ],
		queryFn: () => getLogEntry( id ),
		enabled: null !== id,
	} );

	const copy = async () => {
		try {
			await navigator.clipboard.writeText( asText( entry ) );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch {
			// Clipboard access can be refused - over plain HTTP, or by policy.
			// Everything is on screen and selectable regardless.
		}
	};

	const d = entry?.diagnostics || {};

	return (
		<Dialog open={ null !== id } onOpenChange={ ( open ) => ! open && onClose() }>
			<DialogContent aria-describedby={ undefined }>
				<DialogHeader>
					<DialogTitle>
						{ entry?.subject || __( 'Send details', 'modern-mailer-oauth' ) }
					</DialogTitle>
					<DialogDescription>
						{ entry
							? `${ entry.created_at } · ${ entry.provider } · ${ entry.recipients }`
							: __( 'Loading…', 'modern-mailer-oauth' ) }
					</DialogDescription>
				</DialogHeader>

				<DialogBody>
					{ isLoading || ! entry ? (
						<Spinner />
					) : (
						<div className="grid gap-6">
							<div>
								{ entry.status === 'sent' ? (
									<Badge variant="success">
										{ __( 'Sent', 'modern-mailer-oauth' ) }
									</Badge>
								) : (
									<Badge variant="danger">
										{ __( 'Failed', 'modern-mailer-oauth' ) }
									</Badge>
								) }
							</div>

							{ /* A successful send has nothing to explain, and the
							     report is not written for one. Saying so beats
							     drawing five empty sections. */ }
							{ ! entry.diagnostics ? (
								<p className="text-[13px] text-muted-foreground m-0">
									{ entry.status === 'sent'
										? __(
												'This message was accepted by the provider, so there is no diagnostic report. Reports are kept for failures only.',
												'modern-mailer-oauth'
										  )
										: __(
												'No diagnostic report was recorded for this failure. Entries from before this feature existed have none.',
												'modern-mailer-oauth'
										  ) }
								</p>
							) : (
								<>
									{ d.versions && (
										<Section title={ __( 'Versions', 'modern-mailer-oauth' ) }>
											<Pairs items={ d.versions } />
										</Section>
									) }

									{ d.params && (
										<Section title={ __( 'Params', 'modern-mailer-oauth' ) }>
											<Pairs items={ d.params } />
										</Section>
									) }

									{ d.server && (
										<Section title={ __( 'Server', 'modern-mailer-oauth' ) }>
											<Pairs items={ d.server } />
										</Section>
									) }

									{ entry.error && (
										<Section title={ __( 'Error', 'modern-mailer-oauth' ) }>
											<Pre>{ entry.error }</Pre>
											{ entry.code && (
												<p className="text-xs text-muted-foreground m-0 font-mono">
													{ entry.code }
												</p>
											) }
										</Section>
									) }

									{ d.transcript && (
										<Section title={ __( 'SMTP Debug', 'modern-mailer-oauth' ) }>
											<Pre>{ d.transcript }</Pre>
											<p className="text-xs text-muted-foreground m-0">
												{ __(
													'The conversation with the mail server. Credentials are replaced before this is stored.',
													'modern-mailer-oauth'
												) }
											</p>
										</Section>
									) }
								</>
							) }
						</div>
					) }
				</DialogBody>

				<DialogFooter>
					{ entry?.diagnostics && (
						<Button variant="outline" size="sm" onClick={ copy }>
							{ copied ? <Check className="text-success" /> : <Copy /> }
							{ copied
								? __( 'Copied', 'modern-mailer-oauth' )
								: __( 'Copy report', 'modern-mailer-oauth' ) }
						</Button>
					) }
					<Button size="sm" onClick={ onClose }>
						{ __( 'Close', 'modern-mailer-oauth' ) }
					</Button>
				</DialogFooter>
			</DialogContent>
		</Dialog>
	);
};

export default LogDetail;
