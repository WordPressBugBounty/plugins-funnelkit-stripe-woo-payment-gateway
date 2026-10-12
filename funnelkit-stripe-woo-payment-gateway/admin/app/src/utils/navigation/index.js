/**
 * External dependencies
 */
import {addQueryArgs} from '@wordpress/url';
import {parse} from 'qs';

/**
 * Internal dependencies
 */
import {getHistory} from './history';

// Expose history so all uses get the same history object.
export {getHistory};


/**
 * Get the current path from history.
 *
 * @return {string}  Current path.
 */
export const getPath = () => {
	return getHistory().location.pathname
};


/**
 * Return a URL with set query parameters.
 *
 * @param {Object} query object of params to be updated.
 * @param {string} path Relative path (defaults to current path).
 * @param {Object} currentQuery object of current query params (defaults to current querystring).
 * @return {string}  Updated URL merging query params into existing params.
 */
export function getNewPath(
	query,
	path = getPath(),
	currentQuery = getQuery()
) {
	const args = {page: 'wc-admin', ...currentQuery, ...query};
	if (path !== '/') {
		args.path = path;
	}
	return addQueryArgs( 'admin.php', args );
}

/**
 * Get the current query string, parsed into an object, from history.
 *
 * @return {Object}  Current query object, defaults to empty object.
 */
export function getQuery() {
	const search = getHistory().location.search;
	if (search.length) {
		return parse( search.substring( 1 ) ) || {};
	}
	return {};
}


/**
 * Updates the query parameters of the current page.
 *
 * @param {Object} query object of params to be updated.
 * @param {string} path Relative path (defaults to current path).
 * @param {Object} currentQuery object of current query params (defaults to current querystring).
 */
export function updateQueryString(
	query,
	path = getPath(),
	currentQuery = getQuery()
) {
	const newPath = getNewPath( query, path, currentQuery );
	getHistory().push( decodeURIComponent( newPath ) );
}
