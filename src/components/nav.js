import { __ } from '@wordpress/i18n';
import { NavLink } from 'react-router-dom';
import {
	Send,
	LayoutDashboard,
	Plug,
	ScrollText,
	Settings2,
	Route as RouteIcon,
	TriangleAlert,
	CircleCheck,
	Clock,
} from 'lucide-react';
import { cn } from '../lib/utils';
import { Badge } from './ui';

const TABS = [
	{ to: '/dashboard', label: __( 'Dashboard', 'modern-mailer-oauth' ), icon: LayoutDashboard },
	{ to: '/connections', label: __( 'Connections', 'modern-mailer-oauth' ), icon: Plug },
	{ to: '/routing', label: __( 'Routing', 'modern-mailer-oauth' ), icon: RouteIcon },
	{ to: '/logs', label: __( 'Email Logs', 'modern-mailer-oauth' ), icon: ScrollText },
	{ to: '/settings', label: __( 'Settings', 'modern-mailer-oauth' ), icon: Settings2 },
];

/**
 * One line that says whether mail is arriving.
 *
 * Deliberately in the header rather than on the dashboard. The failure this
 * plugin exists to prevent is email quietly not being delivered, so its state
 * belongs on every screen - not on one an admin has to think to visit.
 */
const statusOf = ( health, queue ) => {
	if ( ! health ) {
		return null;
	}

	if ( ! health.active ) {
		return {
			tone: 'warning',
			icon: TriangleAlert,
			text: __( 'No provider configured - WordPress is using the server mail function.', 'modern-mailer-oauth' ),
		};
	}

	if ( health.failing ) {
		return {
			tone: 'danger',
			icon: TriangleAlert,
			text: health.last_error || __( 'Email is not being delivered.', 'modern-mailer-oauth' ),
		};
	}

	if ( queue?.failed > 0 ) {
		return {
			tone: 'danger',
			icon: TriangleAlert,
			text: __( 'Some messages exhausted every retry and were never delivered.', 'modern-mailer-oauth' ),
		};
	}

	if ( queue?.pending > 0 ) {
		return {
			tone: 'warning',
			icon: Clock,
			text: __( 'Messages are queued for retry. Nothing is lost, but sending is not healthy.', 'modern-mailer-oauth' ),
		};
	}

	return {
		tone: 'success',
		icon: CircleCheck,
		text: __( 'Sending normally.', 'modern-mailer-oauth' ),
	};
};

const TONE_CLASS = {
	success: 'text-success',
	warning: 'text-warning',
	danger: 'text-danger',
};

const Nav = ( { health, queue } ) => {
	const status = statusOf( health, queue );
	const StatusIcon = status?.icon;

	return (
		<header className="relative border-b bg-card">
			{ /* The one purely decorative element on the page: a faint dot grid
			     fading out under the header, so the chrome reads as a surface
			     rather than a flat band. */ }
			<div
				aria-hidden="true"
				className="mmoa-grid pointer-events-none absolute inset-0 opacity-[0.35]"
			/>

			<div className="relative px-6 pt-5">
				<div className="flex items-center gap-3">
					<span className="inline-flex size-9 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-sm">
						<Send className="size-4" />
					</span>

					<div className="min-w-0">
						<h1 className="text-base font-semibold tracking-tight m-0 leading-tight">
							{ __( 'Modern Mailer', 'modern-mailer-oauth' ) }
						</h1>
						<p className="text-xs text-muted-foreground m-0">
							{ __( 'OAuth email delivery for WordPress', 'modern-mailer-oauth' ) }
						</p>
					</div>

					<div className="ml-auto flex items-center gap-3">
						{ status && (
							<span
								className={ cn(
									'hidden items-center gap-1.5 text-xs font-medium sm:inline-flex',
									TONE_CLASS[ status.tone ]
								) }
							>
								<StatusIcon className="size-3.5" />
								<span className="max-w-[38ch] truncate">{ status.text }</span>
							</span>
						) }

						<Badge variant="outline" className="font-mono text-[11px] text-muted-foreground">
							{ window.mmoa?.version }
						</Badge>
					</div>
				</div>

				<nav className="mt-4 flex gap-1 -mb-px">
					{ TABS.map( ( { to, label, icon: Icon } ) => (
						<NavLink
							key={ to }
							to={ to }
							className={ ( { isActive } ) =>
								cn(
									'group relative inline-flex items-center gap-2 rounded-t-md px-3 py-2.5 text-sm font-medium no-underline transition-colors',
									isActive
										? 'text-foreground'
										: 'text-muted-foreground hover:text-foreground'
								)
							}
						>
							{ ( { isActive } ) => (
								<>
									<Icon
										className={ cn(
											'size-4 transition-colors',
											isActive ? 'text-brand' : 'text-muted-foreground/70'
										) }
									/>
									{ label }
									{ /* The active marker is a bar rather than a
									     background so the tab row stays flush with
									     the border below it. */ }
									<span
										className={ cn(
											'absolute inset-x-2 -bottom-px h-0.5 rounded-full transition-all',
											isActive ? 'bg-brand' : 'bg-transparent'
										) }
									/>
								</>
							) }
						</NavLink>
					) ) }
				</nav>
			</div>
		</header>
	);
};

export default Nav;
