import {useEffect, useState} from '@wordpress/element';
import './style.scss';
import apiFetch from '@wordpress/api-fetch';
import StripeDispatchers from '../../store/dispatchers/stripe';
import {__} from "@wordpress/i18n";
import Button from '../../components/bwf-button'

const FailedPage = () => {
    const {setIsRightHeader} = StripeDispatchers();
    const [currentUrl, setCurrentUrl] = useState('');
    const [isSaved, setIsSaved] = useState(false);

    useEffect(() => {
        setIsRightHeader(false);
    }, []);

    useEffect(() => {
        try {
            apiFetch({
                path: `fkwcs-onboard/error-stripe`,
                method: 'get',
            }).then((response) => {
                if (response.status) {
                    setCurrentUrl(response.data.connect_url);
                }
            });
        } catch (e) {
            console.log(e);
        }
    }, []);

    const tryAgain = () => {
        setIsSaved(true);
        window.location.replace(currentUrl);
    };

    return (
        <div className="bwf-container">
            <div className="bwf-failed-container">
                <div className="bwf-align-center bwf-mb-16 bwf-mt-72">
                    <img src={`${bwfsg.images_dir}Failed.png`}/>
                </div>
                <div className="bwf-page-title bwf-mb-4 bwf-align-center">
                    {__("Oops! Unable to connect", 'funnelkit-stripe-woo-payment-gateway')}
                </div>
                <p className="bwf-align-center bwf-description bwf-mb-16">
                    {__("Please give it another try!", 'funnelkit-stripe-woo-payment-gateway')}
                </p>
                <div className="bwf-align-center ">
                    <Button
                        isPrimary
                        className={`bwf-button`}
                        onClick={tryAgain}
                        isBusy={isSaved}
                        disabled={isSaved}
                    >
                        {__("Try Again", 'funnelkit-stripe-woo-payment-gateway')}
                    </Button>
                    <br/>
                    <div className="bwf-need-help-text">
                        {/* translators: %s: Support link */}
                        {__('Need help? ', 'funnelkit-stripe-woo-payment-gateway')}
                        <a href="https://funnelkit.com/support/" target="_blank">
                            {__('Contact Support', 'funnelkit-stripe-woo-payment-gateway')}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default FailedPage;
