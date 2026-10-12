import './style.scss';
import apiFetch from '@wordpress/api-fetch';
import {useState, useEffect} from '@wordpress/element';
import StripeDispatchers from '../../store/dispatchers/stripe';
import {__} from "@wordpress/i18n";
import SVGIcon from '../../components/svg-icon';
import Button from '../../components/bwf-button'

const SuccessPage = () => {
    const [isSaved, setIsSaved] = useState(false);
    const [tab, setTab] = useState('live');

    const {setIsRightHeader} = StripeDispatchers();

    const Success = async () => {
        setIsSaved(true);
        window.location.replace(bwfsg.settings_link);
    };

    useEffect(() => {
        setIsRightHeader(false);
    }, []);

    const Modes = [
        {
            name: 'Live',
            value: 'live',
        },
        {
            name: 'Test',
            value: 'test',
        },
    ];

    const changeTab = (value) => {
        setTab(value);
        try {
            apiFetch({
                path: `fkwcs-onboard/save-mode`,
                method: 'POST',
                data: {webhook: {action: 'save_mode', mode: value}},
            });
        } catch (e) {
            console.log(e);
        }
    };

    return (
        <div className="bwf-container">
            <div className="bwf-success-container">
                <div className="bwf-align-center bwf-mb-16 bwf-mt-72">
                    <img src={`${bwfsg.images_dir}Success.png`} height={ 150 } width={ 150 } />
                </div>
                <div className="bwf-page-title bwf-mb-4 bwf-align-center">
                    {__("Stripe Setup Completed Successfully", 'funnelkit-stripe-woo-payment-gateway')}
                </div>
                <p className="bwf-align-center bwf-description bwf-mb-24">
                    {__("You are ready to accept payments via Stripe", 'funnelkit-stripe-woo-payment-gateway')}
                </p>
                <div className="bwf-stripe-mode-wrap">
                    <div className="bwf-success-mode bwf-ml-4">
                        {__("Current Stripe Mode", 'funnelkit-stripe-woo-payment-gateway')}
                    </div>
                    <div className="bwf-mb-20 bwf-stripe-modes">
                        {Modes.map((data, index) => (
                            <button
                                key={index}
                                className={`bwf-stripe-mode-tab ${data.value === tab ? 'active' : ''
                                } ${data.value === 'test' && 'is-danger'
                                } ${data.value === 'live' && 'is-success'
                                } `}
                                onClick={() => changeTab(data.value)}
                            >
                                {__(data.name, 'funnelkit-stripe-woo-payment-gateway')}
                            </button>
                        ))}
                    </div>
                    {tab === 'test' && (
                        <div className='fkwcs-notice is-error bwf-mb-36'>
                            <SVGIcon icon="info" size="16"  color="#b00c0c" />
                           <span>
                           <strong> {__( "You're in Test Mode ", 'funnelkit-stripe-woo-payment-gateway' )}</strong>
                           {__( "Make sure to switch to Live Mode once you're done testing", 'funnelkit-stripe-woo-payment-gateway' )}
                           </span>
                        </div>
                    )}
                </div>
                <div className="bwf-align-center ">
                    <Button
                        isPrimary
                        className={`bwf-button`}
                        onClick={Success}
                        isBusy={isSaved}
                        disabled={isSaved}
                    >
                        {__("Save and Review Settings", 'funnelkit-stripe-woo-payment-gateway')}
                    </Button>
                </div>
            </div>
        </div>
    );
};

export default SuccessPage;
