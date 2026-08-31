import apiFetch from '@wordpress/api-fetch';

const NS = 'modern-mailer/v1';

/**
 * One place that talks to the REST API.
 *
 * Every call goes through here so the nonce, the namespace and error handling
 * are decided once. WordPress's apiFetch rejects with the decoded body rather
 * than an Error, which reads as an empty message if you let it through - so
 * failures are normalised into something with a `message` a component can show.
 */
const request = async ( path, options = {} ) => {
	try {
		return await apiFetch( { path: `/${ NS }${ path }`, ...options } );
	} catch ( error ) {
		throw {
			message:
				error?.message ||
				'The request failed. Check that you are still signed in.',
			code: error?.code || 'unknown',
		};
	}
};

export const getBootstrap = () => request( '/bootstrap' );
export const getSettings = () => request( '/settings' );
export const getDashboard = () => request( '/dashboard' );
export const getLogs = ( limit = 50 ) => request( `/logs?limit=${ limit }` );

/**
 * One entry with its diagnostic report.
 *
 * Its own request, because a report runs to kilobytes and a page of fifty
 * failures would otherwise carry a megabyte nobody has asked to read.
 */
export const getLogEntry = ( id ) => request( `/logs/${ id }` );
export const getQueue = () => request( '/queue' );
export const getConnection = ( slot ) => request( `/connections/${ slot }` );

export const saveSettings = ( data ) =>
	request( '/settings', { method: 'POST', data } );

export const saveConnection = ( slot, data ) =>
	request( `/connections/${ slot }`, { method: 'POST', data } );

export const verifyConnection = ( slot ) =>
	request( `/connections/${ slot }/verify`, { method: 'POST' } );

/** Clear a connection: its provider, credentials and any grant it holds. */
export const disconnectConnection = ( slot ) =>
	request( `/connections/${ slot }/disconnect`, { method: 'POST' } );

export const sendTestEmail = ( to ) =>
	request( '/test-email', { method: 'POST', data: { to } } );

export const queueAction = ( action ) =>
	request( `/queue/${ action }`, { method: 'POST' } );

export const listConnections = () => request( '/connections' );

export const addConnection = ( name ) =>
	request( '/connections', { method: 'POST', data: { name } } );

export const renameConnection = ( id, name ) =>
	request( `/connections/${ id }/manage`, { method: 'POST', data: { name } } );

export const deleteConnection = ( id ) =>
	request( `/connections/${ id }/manage`, { method: 'DELETE' } );

export const getRouting = () => request( '/routing' );

export const saveRouting = ( enabled, rules ) =>
	request( '/routing', { method: 'POST', data: { enabled, rules } } );
