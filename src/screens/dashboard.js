import { __, sprintf } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Inbox, CheckCircle2, XCircle, Clock } from 'lucide-react';
import { getDashboard } from '../api/client';
import { Panel, Badge, Spinner, EmptyState } from '../components/ui';

/**
 * A fourteen-day view of whether mail is arriving.
 *
 * The chart is inline SVG rather than a charting library. Fourteen bars do not
 * justify a dependency, and hand-drawing it means failures can be stacked in a
 * colour that means something instead of whatever the library's second series
 * colour happens to be.
 */
const Chart = ( { series } ) => {
	const peak = Math.max( 1, ...series.map( ( d ) => d.sent + d.failed ) );
	const w = 100 / series.length;

	return (
		<div>
			<svg
				viewBox="0 0 100 34"
				preserveAspectRatio="none"
				className="w-full h-32"
				role="img"
				aria-label={ __( 'Messages sent and failed per day', 'modern-mailer-oauth' ) }
			>
				{ series.map( ( day, i ) => {
					const total = day.sent + day.failed;
					const h = ( total / peak ) * 30;
					const failedH = total ? ( day.failed / total ) * h : 0;
					const x = i * w + w * 0.18;
					const bw = w * 0.64;

					return (
						<g key={ day.day }>
							<rect
								x={ x }
								y={ 32 - h }
								width={ bw }
								height={ Math.max( 0, h - failedH ) }
								rx="0.6"
								className="fill-chart-1"
							/>
							{ failedH > 0 && (
								<rect
									x={ x }
									y={ 32 - failedH }
									width={ bw }
									height={ failedH }
									rx="0.6"
									className="fill-chart-2"
								/>
							) }
						</g>
					);
				} ) }
			</svg>
			<div className="flex justify-between text-[11px] text-muted-foreground/60 mt-1.5 font-mono">
				<span>{ series[ 0 ]?.day }</span>
				<span>{ series[ series.length - 1 ]?.day }</span>
			</div>
		</div>
	);
};

const Stat = ( { icon: Icon, tone, label, value, to } ) => {
	const body = (
		<div className="flex items-center gap-3">
			<span
				className={ `inline-flex items-center justify-center w-9 h-9 rounded-lg shrink-0 ${
					tone === 'ok'
						? 'bg-success-subtle text-success'
						: tone === 'bad'
						? 'bg-danger-subtle text-danger'
						: tone === 'warn'
						? 'bg-warning-subtle text-warning'
						: 'bg-muted text-muted-foreground'
				}` }
			>
				<Icon size={ 17 } />
			</span>
			<div className="min-w-0">
				<div className="text-[22px] leading-tight font-semibold text-foreground tabular-nums">
					{ value }
				</div>
				<div className="text-[12px] text-muted-foreground">{ label }</div>
			</div>
		</div>
	);

	return (
		<div className="bg-card border border-border rounded-xl p-4">
			{ to ? (
				<Link to={ to } className="no-underline block">
					{ body }
				</Link>
			) : (
				body
			) }
		</div>
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

	const { totals, series, queue, recent } = data;

	return (
		<div className="grid gap-5">
			<div className="grid gap-4 grid-cols-[repeat(auto-fit,minmax(190px,1fr))]">
				<Stat
					icon={ CheckCircle2 }
					variant="success"
					value={ totals.sent }
					label={ __( 'Delivered, last 14 days', 'modern-mailer-oauth' ) }
				/>
				<Stat
					icon={ XCircle }
					tone={ totals.failed > 0 ? 'bad' : 'neutral' }
					value={ totals.failed }
					label={ __( 'Failed, last 14 days', 'modern-mailer-oauth' ) }
					to="/logs"
				/>
				<Stat
					icon={ Clock }
					tone={ queue.pending > 0 ? 'warn' : 'neutral' }
					value={ queue.pending }
					label={ __( 'Queued for retry', 'modern-mailer-oauth' ) }
					to="/logs"
				/>
				<Stat
					icon={ Inbox }
					tone={ queue.failed > 0 ? 'bad' : 'neutral' }
					value={ queue.failed }
					label={ __( 'Never delivered', 'modern-mailer-oauth' ) }
					to="/logs"
				/>
			</div>

			<Panel
				title={ __( 'Sending activity', 'modern-mailer-oauth' ) }
				description={ __(
					'Failures are stacked on top of deliveries, so a bad day is visible as a red cap rather than a missing bar.',
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
						className="text-[13px] text-brand no-underline hover:underline self-center"
					>
						{ __( 'View all', 'modern-mailer-oauth' ) }
					</Link>
				}
			>
				{ recent.length === 0 ? (
					<EmptyState icon={ Inbox } title={ __( 'Nothing sent yet', 'modern-mailer-oauth' ) }>
						{ __(
							'Once WordPress sends its first message it will appear here.',
							'modern-mailer-oauth'
						) }
					</EmptyState>
				) : (
					<ul className="m-0 p-0 list-none divide-y divide-border">
						{ recent.map( ( row ) => (
							<li
								key={ row.id }
								className="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
							>
								<Badge
									dot
									variant={ row.status === 'sent' ? 'success' : 'danger' }
								>
									{ row.status === 'sent'
										? __( 'Sent', 'modern-mailer-oauth' )
										: __( 'Failed', 'modern-mailer-oauth' ) }
								</Badge>
								<span className="text-[13px] text-foreground truncate flex-1 min-w-0">
									{ row.subject || __( '(no subject)', 'modern-mailer-oauth' ) }
								</span>
								<span className="text-[12px] text-muted-foreground truncate max-w-[220px] hidden md:block">
									{ row.recipients }
								</span>
								<span className="text-[12px] text-muted-foreground/60 font-mono shrink-0">
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
