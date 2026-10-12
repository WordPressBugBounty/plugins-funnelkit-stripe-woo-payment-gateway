import './style.scss';
import StripeSelectors from '../../store/selectors/stripe';
import SVGIcon from '../svg-icon';
import BWFConfirmationModal from '../bwf-confirmation-modal';
import {useState} from '@wordpress/element';
import {__} from '@wordpress/i18n';

const BWFHeaderMenu = (props) => {
    const {
        getIsConnected,
        getIsEnableGateways,
        getIsEnableWebhook,
        getIsRightHeader,
    } = StripeSelectors();

    const isConnected = getIsConnected();
    const isEnableGateways = getIsEnableGateways();
    const isEnableWebhook = getIsEnableWebhook();
    const isRightHeader = getIsRightHeader();
    const [modalData, setModelData] = useState({});

    const getHeaderData = (status, label) => {
        if (status == 'inactive') {
            return <span className="bwf-inactive-header-data">{label}</span>;
        } else if (status == 'success') {
            return (
                <span className="bwf-header-success">
					<SVGIcon icon={'tick'}/>
				</span>
            );
        } else if (status == 'active') {
            return <span className="bwf-active-header-data">{label}</span>;
        }
    };

    const confirmModal = (modalContentProps) => {
        setModelData({
            ...modalContentProps,
            disableConfirm: true,
        });
        window.location.replace(bwfsg.settings_link);
    };

    const connectLink = () => {
        const modalContentProps = {
            modalTitle: __('Exit Onboarding Process'),
            content: __(
                'You are about to exit onboarding process . Cancel to stop, Confirm to proceed.'
            ),
            cancelButtonText: __('Cancel'),
            confirmButtonText: __('Confirm'),
            status: true,
        };
        setModelData({
            ...modalContentProps,
            onConfirm: () => {
                confirmModal(modalContentProps);
            },
        });
    };

    return (
        <div className="bwfan_header">
            <div className="bwfan_header_l1">
                <div className="bwfan_header_l">
                    <div>
                        <img
                            src={`${bwfsg.images_dir}funnelkit-logo.svg`}
                        />
                    </div>
                </div>
                <div className="bwfan_header_r">
                    {isRightHeader &&
                        <div className="bwfan_navigation">
                            <div className="bwf-mr-24">
								<span
                                    className={`${isConnected == 'inactive'
                                        ? 'bwf-inactive-header'
                                        : 'bwf-active-header'
                                    } bwf-mr-8`}
                                >
									{getHeaderData(isConnected, 1)}
								</span>
                                <span className="bwf-header-text">
									Stripe Connected
								</span>
                            </div>
                            <div className="bwf-mr-24">
								<span
                                    className={`${isEnableGateways == 'inactive'
                                        ? 'bwf-inactive-header'
                                        : 'bwf-active-header'
                                    } bwf-mr-8`}
                                >
									{getHeaderData(isEnableGateways, 2)}
								</span>
                                <span className="bwf-header-text">
									Enable Gateways
								</span>
                            </div>
                            <div className="bwf-mr-24">
								<span
                                    className={`${isEnableWebhook == 'inactive'
                                        ? 'bwf-inactive-header'
                                        : 'bwf-active-header'
                                    } bwf-mr-8`}
                                >
									{getHeaderData(isEnableWebhook, 3)}
								</span>
                                <span className="bwf-header-text">Webhook</span>
                            </div>
                        </div>}
                    <div className='bwf-gray-left-border'>
                        <div
                            className="bwf-header-menu bwf-ml-16 bwf-cursor-pointer"
                            onClick={connectLink}
                        >
                            <SVGIcon icon="exit"/>
                        </div>
                    </div>
                </div>
            </div>
            <BWFConfirmationModal
                className={'bwf-admin-modal-squeezy'}
                isLoading={modalData.loading}
                successMessage={modalData.success}
                errorMessage={modalData.error}
                isOpen={modalData.status}
                autoHide={8000}
                closeIcon={false}
                content={modalData.content}
                confirmText={modalData.confirmText}
                confirmButtonText={modalData.confirmButtonText}
                cancelButtonText={modalData.cancelButtonText}
                onConfirm={modalData.onConfirm}
                onCancel={modalData.onCancel}
                modalTitle={modalData.modalTitle}
                disableConfirm={modalData.disableConfirm}
                onRequestClose={() => setModelData({status: false})}
            />
        </div>
    );
};

export default BWFHeaderMenu;
