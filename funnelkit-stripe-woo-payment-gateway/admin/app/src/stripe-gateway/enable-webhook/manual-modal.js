import {Modal, TextControl} from '@wordpress/components';
import {useEffect, useState} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import './style.scss';
import SVGIcon from '../../components/svg-icon';
import StripeDispatchers from '../../store/dispatchers/stripe';
import Form from '../../components/form';
import {__} from "@wordpress/i18n";
import Button from '../../components/bwf-button'

const ManualModal = ({onCloseModal, onSuccess}) => {
    const [tab, setTab] = useState('test');
    const [webHookUrl, setWebHookUrl] = useState('');
    const [isSaved, setIsSaved] = useState(false);

    const {setIsEnableWebhook} = StripeDispatchers();

    const onPopupClose = () => {
        onCloseModal();
    };

    const Modes = [
        {
            name: 'Test',
            value: 'test',
        },
        {
            name: 'Live',
            value: 'live',
        },
    ];

    const handleCopy = () => {
        var copyText = document.getElementById('stepLink');
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        document.execCommand('copy');
    };

    const saveManually = (values) => {
        try {
            setIsSaved(true);
            apiFetch({
                path: `fkwcs-onboard/webhook-modal`,
                method: 'POST',
                data: {webhook: {mode: tab, secret: values?.key}},
            }).then((response) => {
                if (response.status) {
                    setIsEnableWebhook('success');
                    setIsSaved(false);
                    onSuccess();
                }
            });
        } catch (e) {
            console.log(e);
        }
    };

    useEffect(() => {
        setWebHookUrl(bwfsg.webhook_url);
    }, []);

    let initialValues = {
        key: '',
    };

    const validate = (values) => {
        const errors = {};
        if (!values.key) {
            errors.key = 'Key is required';
        }
        return errors;
    };

    return (
        <>
            <Modal className="bwf-admin-modal bwf-admin-modal-medium bwf-modal-edit-page">
                <div className={'bwf-modal-header'}>
                    <div className="bwf-modal-heading">
                        {__('Enable Webhook', 'funnelkit-stripe-woo-payment-gateway')}
                    </div>
                    <span
                        onClick={() => onCloseModal()}
                        className={'bwf-modal-close'}
                    >
						<SVGIcon icon={'close'} color={'#353030'}/>
					</span>
                </div>
                <Form
                    validate={validate}
                    onSubmitCallback={(values) => {
                        saveManually(values);
                    }}
                    initialValues={initialValues}
                >
                    {({
                          getInputProps,
                          errors,
                          touched,
                          values,
                          handleSubmit,
                      }) => (
                        <>
                            <p className="bwf-desc bwf-mb-16">
                                {__('WEB URL is called by stripe, when event occur in your account, like a source becomes chargeable. Webhook guide or create secret on stripe dashboard', 'funnelkit-stripe-woo-payment-gateway')}
                            </p>
                            <div className="bwf-manual-option bwf-mb-24">
                                <p className="bwf-number-list">1</p>
                                <p className="bwf-manually-title">
                                    {__("Copy Webhook URL", 'funnelkit-stripe-woo-payment-gateway')}
                                </p>
                                <div className="bwf-copy fkwcs_w_full">
									<span className="bwf-code-copy">
										<input
                                            readOnly="readonly"
                                            id="stepLink"
                                            type="text"
                                            value={webHookUrl}
                                        />
									</span>
                                    <div
                                        onClick={handleCopy}
                                        className="bwf-code-copy-icon"
                                    >
                                        <SVGIcon
                                            icon="duplicate"
                                            width={20}
                                            height={20}
                                            color="#0073aa"
                                        />
                                    </div>
                                </div>
                            </div>
                            <div className="bwf-manual-option bwf-mb-24">
                                <p className="bwf-number-list">
                                    2
                                </p>
                                <p className="bwf-manually-title">
                                    {__("Select Mode", 'funnelkit-stripe-woo-payment-gateway')}
                                </p>
                                <div className="bwf-flex">
                                    {Modes.map((data, index) => (
                                        <button
                                            key={index}
                                            className={`bwf-modes-tab ${data.value === tab
                                                ? 'active'
                                                : ''
                                            } ${data.value === 'test' &&
                                            'bwf-left-radius'
                                            } ${data.value === 'live' &&
                                            'bwf-right-radius'
                                            } `}
                                            onClick={() =>
                                                setTab(data.value)
                                            }
                                        >
                                            {__(data.name, 'funnelkit-stripe-woo-payment-gateway')}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="bwf-manual-option bwf-mb-24">
                                <p className="bwf-number-list">3</p>
                                <p className="bwf-manually-title">
                                    {__("Secret Key", 'funnelkit-stripe-woo-payment-gateway')}
                                </p>
                                <div className="bwf-flex bwf-flex-col">
                                    <div
                                        className={` ${touched.key && errors.key
                                            ? 'bwf-validation'
                                            : ''
                                        } `}
                                    >
                                        <TextControl
                                            placeholder="Enter key here..."
                                            {...getInputProps('key')}
                                            autoComplete="off"
                                        />
                                    </div>
                                    <div className="bwf-hint bwf-mt-8">
                                        {__("secret key is used to authenticate webhooks sent from stripe. It ensure nobody else can send you events pretending to be stripe.", 'funnelkit-stripe-woo-payment-gateway')}
                                    </div>
                                </div>
                            </div>
                            <div className="bwf-float-right">
                                <Button
                                    onClick={onPopupClose}
                                    className="bwf-default-btn"
                                >
                                    {__("Cancel", 'funnelkit-stripe-woo-payment-gateway')}
                                </Button>
                                <Button
                                    isPrimary
                                    className="bwf-save-btn"
                                    onClick={handleSubmit}
                                    isBusy={isSaved}
                                    disabled={isSaved}
                                >
                                    {__("Save", 'funnelkit-stripe-woo-payment-gateway')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Modal>
        </>
    );
};

export default ManualModal;
