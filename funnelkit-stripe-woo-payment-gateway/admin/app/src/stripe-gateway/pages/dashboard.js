import './style.scss';
import { useEffect } from '@wordpress/element';
import StripeDispatchers from '../../store/dispatchers/stripe';
import { __ } from '@wordpress/i18n';
import SVGIcon from '../../components/svg-icon';


const DashboardPage = () => {
	const { setIsRightHeader } = StripeDispatchers();

	const Success = () => {
		window.location.replace( bwfsg.connect_link );
	};

	const setting = () => {
		window.location.replace( bwfsg.settings_link );
	};

	useEffect( () => {
		setIsRightHeader( false );
	}, [] );

	return (
		<div className="bwf-container">
			<div className="bwf-dashboard-container">
				<div className="bwf-align-center bwf-mb-16 bwf-mt-72">
					<img src={ `${ bwfsg.images_dir }Success.png` } height={ 150 } width={ 150 } />
				</div>
				<div className="bwf-page-title bwf-mb-4 bwf-align-center">
					{ __(
						'Connect Your Store With Stripe',
						'funnelkit-stripe-woo-payment-gateway'
					) }
				</div>
				<p className="bwf-align-center bwf-description bwf-mb-16">
					{ __(
						'Click the button below to start the onboarding process.',
						'funnelkit-stripe-woo-payment-gateway'
					) }
				</p>
				<div className="bwf-align-center bwf-flex bwf-flex-col ">
					<div className={ `fkwcs_connect_btn` } onClick={ Success }>
						<svg
							xmlns="http://www.w3.org/2000/svg"
							width="16"
							height="24"
							viewBox="0 0 16 24"
							fill="none"
						>
							<path
								fill-rule="evenodd"
								clip-rule="evenodd"
								d="M6.3748 7.34233C6.3748 6.35539 7.1811 5.9758 8.51654 5.9758C10.4315 5.9758 12.8504 6.55784 14.7654 7.59539V1.64846C12.674 0.813354 10.6079 0.484375 8.51654 0.484375C3.40158 0.484375 0 3.16682 0 7.646C0 14.6305 9.5748 13.517 9.5748 16.5284C9.5748 17.6925 8.56693 18.0721 7.15591 18.0721C5.06457 18.0721 2.3937 17.2117 0.277165 16.0476V22.0705C2.62047 23.0827 4.98898 23.5129 7.15591 23.5129C12.3969 23.5129 16 20.9064 16 16.3766C15.9748 8.83539 6.3748 10.1766 6.3748 7.34233Z"
								fill="white"
							/>
						</svg>

						{ __(
							'Connect with Stripe',
							'funnelkit-stripe-woo-payment-gateway'
						) }
					</div>
					<div className="bwf-redirect-text">
						<SVGIcon icon="shield" width={ 16 } height={ 18 } />
						{ __(
							'You will be redirected to Stripe for Secure Onboarding',
							'funnelkit-stripe-woo-payment-gateway'
						) }
					</div>
				</div>
			</div>
		</div>
	);
};

export default DashboardPage;
