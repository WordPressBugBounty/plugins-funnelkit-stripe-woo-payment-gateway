/**
 * External dependencies
 */
import {Router} from 'react-router-dom';
/**
 * WordPress dependencies
 */
import {render} from '@wordpress/element';

/**
 * Internal dependencies
 */
import App from './app';
import {getHistory} from './utils/navigation';
import {setPublicPath} from './utils';

setPublicPath();

render(
	< Router history = {getHistory()} >
		< App / >
	< / Router > ,
	document.getElementById( 'bwfsg-page' )
);
