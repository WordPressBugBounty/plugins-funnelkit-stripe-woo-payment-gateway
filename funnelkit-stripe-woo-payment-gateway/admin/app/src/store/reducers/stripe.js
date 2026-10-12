const defaultStateVars = {
	isConnected: 'success',
	isEnableGateways: 'active',
	isEnableWebhook: 'inactive',
	isRightHeader: false,
};

const onResponseSuccess = (state) => state;
const onResponseFailed  = onResponseSuccess;

const StripeReducer = {
	name: 'stripe',
	defaultStateVars,
	onResponseSuccess,
	onResponseFailed,
};

export default StripeReducer;
