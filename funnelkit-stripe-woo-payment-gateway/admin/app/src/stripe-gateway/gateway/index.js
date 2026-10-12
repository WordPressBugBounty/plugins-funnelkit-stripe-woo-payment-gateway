import {ToggleControl} from '@wordpress/components';
import {useEffect, useState} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import StripeDispatchers from '../../store/dispatchers/stripe';
import BWFPageLoading from '../../components/page-loading';
import {updateQueryString} from '../../utils/navigation';
import {parse} from 'qs';
import {__} from "@wordpress/i18n";
import Button from '../../components/bwf-button'

const StripeGateway = () => {
    const query =
        location && location.search
            ? parse(location.search.substring(1))
            : {};
    const [activeGateway, setActiveGateway] = useState([]);
    const [isDisabled, setIsDisabled] = useState(true);
    const [isSaved, setIsSaved] = useState(false);
    const [isloaded, setIsloaded] = useState(false);

    const {setIsEnableGateways, setIsEnableWebhook, setIsRightHeader} =
        StripeDispatchers();
    const EnableGateway = (id) => {
        activeGateway.map((val) => {
            if (val.id == id) {
                let newActive = !val.enabled;
                val.enabled = newActive;
            }
        });
        let newStatus = JSON.parse(JSON.stringify(activeGateway));
        setActiveGateway(newStatus);
    };

    useEffect(() => {
        let recommendedList = [];
        activeGateway.forEach((data) => {
            if (data.is_recommended) {
                recommendedList.push(data.id);
            }
        });
        if (recommendedList.length > 0) {
            activeGateway.forEach((val) => {
                if (recommendedList.includes(val.id)) {
                    if (val.enabled) {
                        setIsDisabled(false);
                    }
                }
            });
        } else if (recommendedList.length == 0) {
            activeGateway.forEach((value) => {
                if (value.enabled) {
                    setIsDisabled(false);
                }
            });
        }
        return () => {
            setIsDisabled({});
        };
    }, [activeGateway]);

    const getData = async () => {
        try {
            await apiFetch({
                path: `fkwcs-onboard/list-gateways`,
                method: 'get',
            }).then((response) => {
                if (response.status) {
                    setActiveGateway(response.data.gateways);
                    setIsloaded(true);
                }
            });
        } catch (e) {
            console.log(e);
        }
    };

    const saveGateway = async () => {
        let payload = {gateways: activeGateway};
        setIsSaved(true);
        try {
            await apiFetch({
                path: `fkwcs-onboard/list-gateways`,
                method: 'POST',
                data: payload,
            }).then((response) => {
                if (response.status) {
                    updateQueryString({}, `/webhook`, query);
                    setIsEnableGateways('success');
                    setIsEnableWebhook('active');
                    setIsSaved(false);
                }
            });
        } catch (e) {
            console.log(e);
        }
    };

    useEffect(() => {
        getData();
    }, []);

    useEffect(() => {
        setIsRightHeader(true);
    }, []);

    return (
        <>
            {!isloaded ? (
                <div style={{display: 'flex', height: 'calc(100% - 62px)'}}>
                    <BWFPageLoading/>
                </div>
            ) : (
                <div className="bwf-container">
                    <div className={`${activeGateway.length !== 1 ? "bwf-gateway" : ""}`}>
                        <div className="bwf-align-center bwf-mb-16 bwf-mt-48">
                            <img
                                src={`${bwfsg.images_dir}PayNow.png`}
                                className=""
                            />
                        </div>

                        <div className="bwf-title bwf-mb-4">
                            {__("Activate the payment methods", 'funnelkit-stripe-woo-payment-gateway')}
                        </div>
                        <p className="bwf-align-center bwf-description bwf-mb-24">
                            {__("Following payment methods are available in your Stripe account that matches the currency of your store. Enable the methods from which you want to accept payments.", 'funnelkit-stripe-woo-payment-gateway')}
                        </p>
                        <div className={`${activeGateway.length !== 1 ? "bwf-gateway-container" : ""}`}>
                            {activeGateway.map((data, index) => {
                                return (
                                    <div
                                        className="bwf-gateway-card"
                                        key={index}
                                    >
                                        <div className="bwf-card-image">
                                            <img
                                                src={data.icon}
                                                className="bwf-image"
                                            />
                                        </div>
                                        <div className="bwf-card-body">
                                            <div className="bwf-flex bwf-gap-6">
												<span className="bwf-gateway-title">
													{__(data.title, 'funnelkit-stripe-woo-payment-gateway')}
												</span>
                                                {data.is_recommended && (
                                                    <span className="bwf-recommend-tag bwf-mt-4">
														{__("Recommended", 'funnelkit-stripe-woo-payment-gateway')}
													</span>
                                                )}
                                            </div>
                                            <span className="bwf-gateway-description">
												{__(data.description, 'funnelkit-stripe-woo-payment-gateway')}
											</span>
                                        </div>
                                        <div className="bwf-card-toggle">
                                            <ToggleControl
                                                checked={data.enabled}
                                                onChange={() =>
                                                    EnableGateway(data.id)
                                                }
                                            />
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                        <div className="bwf-align-center bwf-mt-36">
                            <Button
                                isPrimary
                                className={`bwf-button ${isDisabled && 'disabled'
                                } bwf-mb-40`}
                                onClick={() => {
                                    !isDisabled && saveGateway();
                                }}
                                isBusy={isSaved}
                                disabled={isSaved}
                            >
                                {__("Save and Continue", 'funnelkit-stripe-woo-payment-gateway')}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
};

export default StripeGateway;
