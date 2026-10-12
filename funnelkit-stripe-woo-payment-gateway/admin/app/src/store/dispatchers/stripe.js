import globalGetDispatchers from '../../api-store/dispatcher';

const StripeDispatchers  = () => {
	const {setStateProp} = globalGetDispatchers( 'stripe' );

	return {
		setIsEnableGateways: (data) =>
			setStateProp( 'isEnableGateways', data ),
		setIsEnableWebhook: (data) => setStateProp( 'isEnableWebhook', data ),
		setIsRightHeader: (data) => setStateProp( 'isRightHeader', data ),
	};
};

export default StripeDispatchers;
