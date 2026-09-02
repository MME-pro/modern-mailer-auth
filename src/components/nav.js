import { __ } from '@wordpress/i18n';
import { useRef, useState, useLayoutEffect } from '@wordpress/element';
import { NavLink, useLocation } from 'react-router-dom';
import {
	LayoutDashboard,
	Plug,
	ScrollText,
	Settings2,
	Route as RouteIcon,
	TriangleAlert,
	CircleCheck,
	Clock,
	Sun,
	Moon,
} from 'lucide-react';
import { cn } from '../lib/utils';
import { useTheme } from '../lib/use-theme';
import Guilloche from './guilloche';

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
 * In the header rather than on the dashboard, deliberately. The failure this
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
			text: __( 'No provider configured', 'modern-mailer-oauth' ),
			detail: __( 'WordPress is using the server mail function.', 'modern-mailer-oauth' ),
		};
	}

	if ( health.failing ) {
		return {
			tone: 'danger',
			icon: TriangleAlert,
			text: __( 'Not delivering', 'modern-mailer-oauth' ),
			detail: health.last_error || '',
		};
	}

	if ( queue?.failed > 0 ) {
		return {
			tone: 'danger',
			icon: TriangleAlert,
			text: __( 'Mail was lost', 'modern-mailer-oauth' ),
			detail: __( 'Some messages exhausted every retry.', 'modern-mailer-oauth' ),
		};
	}

	if ( queue?.pending > 0 ) {
		return {
			tone: 'warning',
			icon: Clock,
			text: __( 'Queued for retry', 'modern-mailer-oauth' ),
			detail: __( 'Nothing is lost, but sending is not healthy.', 'modern-mailer-oauth' ),
		};
	}

	return {
		tone: 'success',
		icon: CircleCheck,
		text: __( 'Sending normally', 'modern-mailer-oauth' ),
		detail: '',
	};
};

/*
 * Status on the ink band needs its own values. The semantic tokens are tuned
 * for contrast against a white sheet; on near-black they sit far too dark.
 */
const TONE = {
	success: 'text-[#7fb8a0]',
	warning: 'text-[#d8b45f]',
	danger: 'text-[#d98a9c]',
};

/**
 * The tab row.
 *
 * One brass rule slides between tabs rather than each tab owning its own. It is
 * the difference between a marker that moves through the row and five markers
 * taking turns to appear - the first reads as a single object being carried
 * across, which is what a set of tabs actually is.
 *
 * The indicator is measured rather than derived from a percentage, because the
 * labels are different lengths and translated builds change them again. Width
 * is measured, not assumed.
 */
const Tabs = () => {
	const { pathname } = useLocation();
	const listRef = useRef( null );
	const [ marker, setMarker ] = useState( null );

	const active = Math.max(
		0,
		TABS.findIndex( ( tab ) => pathname.startsWith( tab.to ) )
	);

	useLayoutEffect( () => {
		const list = listRef.current;

		if ( ! list ) {
			return;
		}

		const measure = () => {
			const el = list.children[ active ];

			if ( el ) {
				setMarker( { left: el.offsetLeft, width: el.offsetWidth } );
			}
		};

		measure();

		// Labels reflow when the band wraps, the sidebar folds, or a webfont
		// finally lands - all of which move the tab the marker is under.
		const observer = new ResizeObserver( measure );
		observer.observe( list );

		if ( document.fonts?.ready ) {
			document.fonts.ready.then( measure ).catch( () => {} );
		}

		return () => observer.disconnect();
	}, [ active, pathname ] );

	return (
		<div
			ref={ listRef }
			className="relative -mb-px flex gap-1 overflow-x-auto ink-scroll"
		>
			{ TABS.map( ( { to, label, icon: Icon }, i ) => (
				<NavLink
					key={ to }
					to={ to }
					className={ cn(
						'group relative inline-flex shrink-0 items-center gap-2 rounded-t-md px-4 py-3.5',
						'text-[13px] font-medium tracking-[0.01em] no-underline',
						'transition-colors duration-200',
						i === active
							? 'text-brand'
							: 'text-ink-muted hover:bg-white/[0.03] hover:text-ink-foreground'
					) }
				>
					<Icon
						className={ cn(
							'size-4 transition-transform duration-200',
							i === active
								? 'scale-105'
								: 'opacity-70 group-hover:opacity-100'
						) }
					/>
					{ label }
				</NavLink>
			) ) }

			{ /* The travelling rule. Rendered once, positioned from measurement,
			     and given a soft brass bloom so it reads as lit metal rather
			     than a border. */ }
			{ marker && (
				<span
					aria-hidden="true"
					className="pointer-events-none absolute bottom-0 h-[2px] rounded-full bg-brand transition-[left,width] duration-[320ms] ease-[cubic-bezier(0.22,1,0.36,1)]"
					style={ {
						left: marker.left + 12,
						width: Math.max( 0, marker.width - 24 ),
						boxShadow: '0 0 12px 1px color-mix(in oklab, var(--brand) 55%, transparent)',
					} }
				/>
			) }
		</div>
	);
};

const ThemeToggle = () => {
	const { theme, toggle } = useTheme();
	const dark = theme === 'dark';

	return (
		<button
			type="button"
			onClick={ toggle }
			aria-pressed={ dark }
			aria-label={
				dark
					? __( 'Switch to light', 'modern-mailer-oauth' )
					: __( 'Switch to dark', 'modern-mailer-oauth' )
			}
			className={ cn(
				'inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full',
				'border border-ink-line bg-transparent text-ink-muted',
				'transition-colors duration-200 hover:border-brand/50 hover:text-brand'
			) }
		>
			{ dark ? <Moon className="size-4" /> : <Sun className="size-4" /> }
		</button>
	);
};

const Nav = ( { health, queue } ) => {
	const status = statusOf( health, queue );
	const StatusIcon = status?.icon;

	return (
		<header className="relative overflow-hidden bg-ink text-ink-foreground">
			{ /* THE SIGNATURE. Engine-turned rosette in brass, pinned right so
			     it reads as a watermark on the band rather than a halo behind
			     the wordmark. */ }
			<Guilloche
				id="mmoa-guilloche-header"
				className="pointer-events-none absolute -top-[420px] -right-[180px] h-[900px] w-[900px] text-brand opacity-[0.16]"
			/>

			{ /* The join between ink and paper, and the only rule in the chrome. */ }
			<div
				aria-hidden="true"
				className="absolute inset-x-0 bottom-0 h-px bg-linear-to-r from-transparent via-brand/45 to-transparent"
			/>

			<div className="relative px-6 pt-6 sm:px-8">
				<div className="flex flex-wrap items-start gap-x-6 gap-y-3">
					<div className="min-w-0">
						<h1 className="font-display text-[26px] font-normal leading-none tracking-[-0.02em] text-ink-foreground">
							{ __( 'MME-Mail to SMTP', 'modern-mailer-oauth' ) }
						</h1>
						<p className="mt-1.5 mb-0 text-xs tracking-[0.14em] text-ink-muted uppercase">
							{ __( 'Authenticated delivery', 'modern-mailer-oauth' ) }
							<span className="mx-2 text-ink-line">/</span>
							<span className="tracking-normal normal-case">
								{ window.mmoa?.version }
							</span>
						</p>
					</div>

					<div className="ml-auto flex items-start gap-4">
						{ status && (
							<div className="flex items-start gap-2.5 text-right">
								<StatusIcon
									className={ cn( 'mt-0.5 size-4 shrink-0', TONE[ status.tone ] ) }
								/>
								<div className="min-w-0">
									<p
										className={ cn(
											'm-0 text-[13px] leading-tight font-medium',
											TONE[ status.tone ]
										) }
									>
										{ status.text }
									</p>
									{ status.detail && (
										<p className="m-0 max-w-[42ch] truncate text-xs text-ink-muted">
											{ status.detail }
										</p>
									) }
								</div>
							</div>
						) }

						<ThemeToggle />
					</div>
				</div>

				<div className="mt-6">
					<Tabs />
				</div>
			</div>
		</header>
	);
};

export default Nav;
