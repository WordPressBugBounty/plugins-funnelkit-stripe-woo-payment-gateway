/**
 * WordPress dependencies
 */
import {lazy} from '@wordpress/element';

/**
 * Internal dependencies
 */
const StripeGateway  = lazy( () => import( './stripe-gateway/gateway' ) );
const StripeFailed   = lazy(
	() =>
	import( './stripe-gateway/pages/failed-page' )
);
const SuccessPage     = lazy(
	() =>
	import( './stripe-gateway/pages/success-page' )
);
const StripeDashboard = lazy(
	() =>
	import( './stripe-gateway/pages/dashboard' )
);
const StripeWebhook   = lazy( () => import( './stripe-gateway/enable-webhook' ) );

const routerList = [
	{
		path: '/',
		render: (props) => < StripeDashboard {...props} / > ,
	},
	{
		path: '/gateways',
		render: (props) => < StripeGateway {...props} / > ,
	},
	{
		path: '/webhook',
		render: (props) => < StripeWebhook {...props} / > ,
	},
	{
		path: '/failed',
		render: (props) => < StripeFailed {...props} / > ,
	},
	{
		path: '/success',
		render: (props) => < SuccessPage {...props} / > ,
	},
	];
	export default routerList;
