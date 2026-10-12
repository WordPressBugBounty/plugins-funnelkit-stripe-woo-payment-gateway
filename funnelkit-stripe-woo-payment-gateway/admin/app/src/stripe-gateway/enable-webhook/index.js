import {useEffect, useState} from '@wordpress/element';
import './style.scss';
import ManualModal from './manual-modal';
import apiFetch from '@wordpress/api-fetch';
import StripeDispatchers from '../../store/dispatchers/stripe';
import {updateQueryString} from '../../utils/navigation';
import {parse} from 'qs';
import SVGIcon from '../../components/svg-icon';
import {__} from "@wordpress/i18n";
import Button from '../../components/bwf-button'

const EnableWebHook = () => {
    const query =
        location && location.search
            ? parse(location.search.substring(1))
            : {};
    const [isManual, setIsManual] = useState(false);
    const [isConnect, setIsConnect] = useState(false);
    const [isSaved, setIsSaved] = useState(false);
    const [isFailed, setIsFailed] = useState(false);
    const [errorMsg, setErrorMsg] = useState("");

    const {
        setIsRightHeader,
        setIsEnableGateways,
        setIsEnableWebhook,
    } = StripeDispatchers();
    const webhookList = [
        'Charge Captured',
        'Charge Refunded',
        'Charge Dispute Created',
        'Charge Dispute Closed',
        'Payment Intent Succeded',
        'Payment Intent Amount Capturable Update',
        'Payment Intent Payment Failed',
        'Review Opened',
        'Review Closed',
    ];

    const saveWebHook = async () => {
        setIsSaved(true);
        try {
            let payload = {webhook: {action: 'save_webhook'}};
            await apiFetch({
                path: `fkwcs-onboard/enable-webhook`,
                method: 'POST',
                data: payload,
            }).then((response) => {
                if (response.status) {
                    setIsEnableWebhook('success');
                    setIsConnect(true);
                    setIsSaved(false);
                } else {
                    setIsFailed(true);
                    setIsSaved(false);
                    setErrorMsg(response.msg)
                }
            });
        } catch (e) {
            console.log(e);
        }
    };

    useEffect(() => {
        if (isConnect) {
            updateQueryString({}, `/success`, query);
        }
    }, [isConnect]);

    useEffect(() => {
        setIsRightHeader(true);
        setIsEnableGateways('success');
        setIsEnableWebhook('active');
    }, []);

    const skipStep = () => {
        updateQueryString({}, `/success`, query);
    };

    return (
        <>
            <div className="bwf-container">
                {!isConnect && (
                    <div className="bwf-webhook-container">
                        <div className="bwf-align-center bwf-mb-16 bwf-mt-48">
                            <img
                                src={`${bwfsg.images_dir}WebHook.png`}
                                className=""
                            />
                        </div>
                        <div className="bwf-title bwf-mb-4">
                            {__("Setup Webhook", 'funnelkit-stripe-woo-payment-gateway')}
                        </div>
                        <p className="bwf-description bwf-mb-24 bwf-align-center">
                            {__("Create Webhook automatically and set up to listen to various Events with a single click.", 'funnelkit-stripe-woo-payment-gateway')}
                        </p>
                        <div>
                            <div className="bwf-webhook-title bwf-mb-8">
                                {__("This is the list of the supported webhook events:", 'funnelkit-stripe-woo-payment-gateway')}
                            </div>
                            <div className="bwf-webhook-description">
                                {webhookList.map((data, index) => {
                                    return (
                                        <span
                                            className="bwf-webhook-points"
                                            key={index}
                                        >
											&#8226;
                                            <span className="bwf-ml-6">
												{__(data, 'funnelkit-stripe-woo-payment-gateway')}
											</span>
										</span>
                                    );
                                })}
                            </div>
                        </div>
                        <div className="bwf-align-center bwf-flex bwf-flex-col bwf-mt-24">
                            {!isFailed && (
                                <Button
                                    isPrimary
                                    className={`bwf-button`}
                                    onClick={saveWebHook}
                                    isBusy={isSaved}
                                    disabled={isSaved}
                                >
                                    {__("Setup Webhook Now", 'funnelkit-stripe-woo-payment-gateway')}
                                </Button>
                            )}
                            {isFailed && (
                                <>
                                    <div className="bwf-retry-container bwf-mt-24">
                                        <div className="bwf-flex bwf-gap-10 bwf-float-left bwf-ml-18 bwf-mt-10">
                                            <SVGIcon icon="caution"/>
                                            <div className='bwf-error-msg-container'>
												<span className="bwf-retry-text">
													{__(errorMsg, 'funnelkit-stripe-woo-payment-gateway')}
												</span>
                                            </div>
                                        </div>
                                        <div className="bwf-flex bwf-gap-10 bwf-float-right bwf-mr-16 bwf-mt-8">
                                            <button
                                                className="bwf-manually-btn"
                                                onClick={() =>
                                                    setIsManual(true)
                                                }
                                            >
                                                {__("Do it Manually", 'funnelkit-stripe-woo-payment-gateway')}
                                            </button>
                                            <Button
                                                isBusy={isSaved}
                                                className={`bwf-retry-btn ${isSaved ? "isBusy" : ""}`}
                                                onClick={saveWebHook}
                                            >
                                                {__("Retry", 'funnelkit-stripe-woo-payment-gateway')}
                                            </Button>
                                        </div>
                                    </div>
                                    <Button
                                        className="components-button is-secondary bwf-mt-12"
                                        onClick={skipStep}
                                    >
                                        {__("Skip this Step", 'funnelkit-stripe-woo-payment-gateway')}
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                )}
                {isManual && (
                    <ManualModal
                        onCloseModal={() => setIsManual(false)}
                        onSuccess={() => {
                            setIsManual(false), setIsConnect(true);
                        }}
                    />
                )}
            </div>
        </>
    );
};

export default EnableWebHook;
