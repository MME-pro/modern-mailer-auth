import { __, sprintf } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Inbox, Clock, XCircle, ArrowUpRight } from 'lucide-react';
import { getDashboard } from '../api/client';
import { Panel, Badge, Spinner, EmptyState } from '../components/ui';
import Guilloche from '../components/guilloche';
import { cn } from '../lib/utils';

/**
 * Fourteen days of whether mail is arriving.
 *
 * Inline SVG rather than a charting library. Fourteen bars do not justify the
 * dependency, and drawing it by hand is what lets failures stack in a colour
 * that means something instead of whatever the library's second series colour
 * happens to be.
 */
const Chart = ( { series } ) => {
	const peak = Math.max( 1, ...series.map( ( d ) => d.sent + d.failed ) );
	const w = 100 / series.length;
	const hasData = series.some( ( d ) => d.sent + d.failed > 0 );

	// An empty chart is a large void with a faint engraving showing through it,
	// which reads as a rendering fault rather than as "nothing has happened
	// yet". A blank state deserves the same care as a populated one.
	if ( ! hasData ) {
		return (
			<div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-14 text-center">
				<span className="font-display text-[32px] leading-none text-muted-foreground/50">
					—
				</span>
				<p className="mt-3 mb-0 text-sm font-medium">
					{ __( 'No sending activity yet', 'modern-mailer-oauth' ) }
				</p>
				<p className="mt-1 mb-0 max-w-sm text-xs text-muted-foreground">
					{ __(
						'Fourteen days of deliveries and failures will chart here once WordPress starts sending.',
						'modern-mailer-oauth'
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="relative">
			{ /* The signature's second and final appearance - fainter here, and
			     behind data rather than behind type. */ }
			<Guilloche
				id="mmoa-guilloche-chart"
				className="pointer-events-none absolute -top-[120px] left-1/2 h-[420px] w-[420px] -translate-x-1/2 text-brand opacity-[0.07]"
			/>

			<svg
				viewBox="0 0 100 36"
				preserveAspectRatio="none"
				className="relative w-full h-36"
				role="img"
				aria-label={ __(
					'Messages sent and failed per day over the last fourteen days',
					'modern-mailer-oauth'
				) }
			>
				{ /* Baseline in brass: the one rule the data stands on. */ }
				<line
					x1="0"
					y1="33"
					x2="100"
					y2="33"
					stroke="var(--brand)"
					strokeWidth="0.3"
					opacity="0.5"
					vectorEffect="non-scaling-stroke"
				/>

				{ series.map( ( day, i ) => {
					const total = day.sent + day.failed;
					const h = ( total / peak ) * 30;
					const failedH = total ? ( day.failed / total ) * h : 0;
					const x = i * w + w * 0.22;
					const bw = w * 0.56;

					return (
						<g key={ day.day }>
							<rect
								x={ x }
								y={ 33 - h }
								width={ bw }
								height={ Math.max( 0, h - failedH ) }
								className="fill-chart-1"
								opacity="0.85"
							/>
							{ failedH > 0 && (
								<rect
									x={ x }
									y={ 33 - failedH }
									width={ bw }
									height={ failedH }
									className="fill-chart-2"
								/>
							) }
						</g>
					);
				} ) }
			</svg>

			<div className="relative mt-2 flex items-center justify-between text-[11px] text-muted-foreground">
				<span>{ series[ 0 ]?.day }</span>
				<span className="flex items-center gap-4">
					<span className="inline-flex items-center gap-1.5">
						<span className="size-2 rounded-[2px] bg-chart-1" />
						{ __( 'Delivered', 'modern-mailer-oauth' ) }
					</span>
					<span className="inline-flex items-center gap-1.5">
						<span className="size-2 rounded-[2px] bg-chart-2" />
						{ __( 'Failed', 'modern-mailer-oauth' ) }
					</span>
				</span>
				<span>{ series[ series.length - 1 ]?.day }</span>
			</div>
		</div>
	);
};

/**
 * A supporting figure. Deliberately quieter than the hero - no icon chip, no
 * card of its own, just a hairline and a number.
 */
const Stat = ( { label, value, tone, to } ) => {
	const body = (
		<>
			<span
				className={ cn(
					'block font-display text-[28px] leading-none',
					tone === 'danger' && value > 0 && 'text-danger',
					tone === 'warning' && value > 0 && 'text-warning',
					( ! tone || value === 0 ) && 'text-foreground'
				) }
			>
				{ value }
			</span>
			<span className="mt-1.5 flex items-center gap-1 text-xs text-muted-foreground">
				{ label }
				{ to && (
					<ArrowUpRight className="size-3 opacity-0 transition-opacity group-hover:opacity-100" />
				) }
			</span>
		</>
	);

	return (
		<div className="border-t border-border pt-4 first:border-t-0 first:pt-0 sm:border-t-0 sm:border-l sm:pt-0 sm:pl-5 sm:first:border-l-0 sm:first:pl-0">
			{ to ? (
				<Link to={ to } className="group block no-underline text-inherit">
					{ body }
				</Link>
			) : (
				body
			) }
		</div>
	);
};

/**
 * The hero.
 *
 * Deliberately asymmetric. Four equal cards is the default move and it flattens
 * hierarchy - this states the one figure that matters at display size and lets
 * the other three support it, so the screen says what it is about before you
 * read a word.
 */
const Hero = ( { totals, queue, health } ) => {
	const rate = totals.sent + totals.failed;
	const percent = rate ? ( ( totals.sent / rate ) * 100 ).toFixed( 1 ) : null;

	return (
		<Panel className="overflow-hidden">
			<div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] lg:items-end">
				<div>
					<p className="m-0 text-xs tracking-[0.14em] text-muted-foreground uppercase">
						{ __( 'Delivered · last 14 days', 'modern-mailer-oauth' ) }
					</p>

					<p className="mt-3 mb-0 flex items-baseline gap-3">
						<span className="font-display text-[64px] leading-[0.9] tracking-[-0.03em] text-foreground">
							{ totals.sent.toLocaleString() }
						</span>
						{ percent !== null && (
							<span
								className={ cn(
									'font-display text-[20px] leading-none',
									totals.failed > 0 ? 'text-danger' : 'text-success'
								) }
							>
								{ percent }%
							</span>
						) }
					</p>

					<p className="mt-3 mb-0 max-w-[46ch] text-[13px] text-muted-foreground">
						{ health?.active
							? sprintf(
									/* translators: %d: number of failed messages. */
									__(
										'%d failed over the same period. Every failure is recorded with the reason the provider gave.',
										'modern-mailer-oauth'
									),
									totals.failed
							  )
							: __(
									'No provider is configured yet, so WordPress is using the server mail function — which most hosts either block or deliver to spam.',
									'modern-mailer-oauth'
							  ) }
					</p>
				</div>

				<div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
					<Stat
						label={ __( 'Failed', 'modern-mailer-oauth' ) }
						value={ totals.failed }
						tone="danger"
						to="/logs"
					/>
					<Stat
						label={ __( 'Queued for retry', 'modern-mailer-oauth' ) }
						value={ queue.pending }
						tone="warning"
						to="/logs"
					/>
					<Stat
						label={ __( 'Never delivered', 'modern-mailer-oauth' ) }
						value={ queue.failed }
						tone="danger"
						to="/logs"
					/>
				</div>
			</div>
		</Panel>
	);
};

const Dashboard = () => {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'dashboard' ],
		queryFn: getDashboard,
	} );

	if ( isLoading ) {
		return <Spinner />;
	}

	const { totals, series, queue, recent, health } = data;

	return (
		<div className="stagger grid gap-6">
			<Hero totals={ totals } queue={ queue } health={ health } />

			<Panel
				title={ __( 'Sending activity', 'modern-mailer-oauth' ) }
				description={ __(
					'Failures are stacked on top of deliveries, so a bad day reads as a dark cap rather than a missing bar.',
					'modern-mailer-oauth'
				) }
			>
				<Chart series={ series } />
			</Panel>

			<Panel
				title={ __( 'Recent activity', 'modern-mailer-oauth' ) }
				actions={
					<Link
						to="/logs"
						className="self-center text-[13px] text-brand-deep no-underline hover:underline"
					>
						{ __( 'View all', 'modern-mailer-oauth' ) }
					</Link>
				}
			>
				{ recent.length === 0 ? (
					<EmptyState icon={ Inbox } title={ __( 'Nothing sent yet', 'modern-mailer-oauth' ) }>
						{ __(
							'Once WordPress sends its first message it will appear here, with whatever the provider said about it.',
							'modern-mailer-oauth'
						) }
					</EmptyState>
				) : (
					<ul className="m-0 list-none divide-y divide-border p-0">
						{ recent.map( ( row ) => (
							<li
								key={ row.id }
								className="flex items-center gap-3 py-3 transition-colors first:pt-0 last:pb-0 hover:bg-muted/40"
							>
								<Badge
									dot
									variant={ row.status === 'sent' ? 'success' : 'danger' }
								>
									{ row.status === 'sent'
										? __( 'Sent', 'modern-mailer-oauth' )
										: __( 'Failed', 'modern-mailer-oauth' ) }
								</Badge>
								<span className="min-w-0 flex-1 truncate text-[13px]">
									{ row.subject || __( '(no subject)', 'modern-mailer-oauth' ) }
								</span>
								<span className="hidden max-w-[220px] truncate text-xs text-muted-foreground md:block">
									{ row.recipients }
								</span>
								<span className="shrink-0 text-xs text-muted-foreground">
									{ row.created_at.slice( 5, 16 ) }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</Panel>
		</div>
	);
};

export default Dashboard;
