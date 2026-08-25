import { useEffect } from '@wordpress/element';
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { getBootstrap } from './api/client';
import Nav from './components/nav';
import { Spinner } from './components/ui';
import { ToastProvider, useToast } from './components/toast';
import Dashboard from './screens/dashboard';
import Connections from './screens/connections';
import Logs from './screens/logs';
import Routing from './screens/routing';
import Settings from './screens/settings';

const queryClient = new QueryClient( {
	defaultOptions: {
		queries: {
			refetchOnWindowFocus: false,
			retry: 1,
			staleTime: 10_000,
		},
	},
} );

/**
 * Surface the result of a flow that left the site and came back.
 *
 * The Google handshake is a full page navigation, so the app is remounted with
 * no memory of it. The server puts the outcome in the query string; this shows
 * it once and then strips it, so a refresh does not repeat a stale message.
 */
const useRedirectMessage = () => {
	const toast = useToast();

	useEffect( () => {
		const params = new URLSearchParams( window.location.search );
		const message = params.get( 'mmoa_msg' );

		if ( ! message ) {
			return;
		}

		toast( decodeURIComponent( message ), params.get( 'mmoa_status' ) === 'error' ? 'bad' : 'ok' );

		params.delete( 'mmoa_msg' );
		params.delete( 'mmoa_status' );

		window.history.replaceState(
			{},
			'',
			`${ window.location.pathname }?${ params.toString() }${ window.location.hash }`
		);
	}, [ toast ] );
};

const Shell = () => {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'bootstrap' ],
		queryFn: getBootstrap,
	} );

	useRedirectMessage();

	return (
		<div id="mmoa-app">
			<Nav health={ data?.health } queue={ data?.queue } />

			<main className="px-6 py-6 max-w-[1180px]">
				{ isLoading ? (
					<Spinner />
				) : (
					<Routes>
						<Route path="/dashboard" element={ <Dashboard /> } />
						<Route path="/connections" element={ <Connections /> } />
						<Route path="/routing" element={ <Routing /> } />
						<Route path="/logs" element={ <Logs /> } />
						<Route path="/settings" element={ <Settings /> } />
						<Route
							path="*"
							element={ <Navigate to="/dashboard" replace /> }
						/>
					</Routes>
				) }
			</main>
		</div>
	);
};

const App = () => (
	<QueryClientProvider client={ queryClient }>
		<ToastProvider>
			<HashRouter>
				<Shell />
			</HashRouter>
		</ToastProvider>
	</QueryClientProvider>
);

export default App;
