import globalGetSelectors from '../../api-store/selector';

const StripeSelectors    = () => {
	const {getStateProp} = globalGetSelectors( 'stripe' );
	return {
		getIsConnected: () => getStateProp( 'isConnected' ),
		getIsEnableGateways: () => getStateProp( 'isEnableGateways' ),
		getIsEnableWebhook: () => getStateProp( 'isEnableWebhook' ),
		getIsRightHeader: () => getStateProp( 'isRightHeader' ),
	};
};

export default StripeSelectors;
