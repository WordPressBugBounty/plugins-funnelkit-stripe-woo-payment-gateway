import classNames from 'classnames';
import { Modal} from '@wordpress/components';
import {useEffect} from '@wordpress/element';
import {noop} from 'lodash';
import Button from '../bwf-button'

const BWFConfirmationModal = (props) => {
    const {
        confirmText = '',
        confirmButtonText = '',
        cancelButtonText = '',
        onConfirm = () => {
        },
        onCancel = noop,
        isLoading = false,
        successMessage = '',
        errorMessage = '',
        content = '',
        onRequestClose,
        isOpen = false,
        autoHide = 2000,
        modalTitle = '',
        closeIcon = false,
        disableConfirm,
        className,
    } = props;

    useEffect(() => {
        let timeout = null;
        if ((!!successMessage || !!errorMessage) && autoHide) {
            timeout = setTimeout(() => {
                !!onRequestClose && onRequestClose();
            }, autoHide);
        }
        return () => {
            !!timeout && clearTimeout(timeout);
        };
    }, [successMessage, errorMessage]);

    const onSecondaryClick = () => {
        !!onRequestClose && onRequestClose();
        !!onCancel && onCancel();
    };

    const onPrimaryClick = () => {
        !!onConfirm && onConfirm();
    };

    return isOpen ? (
        <Modal
            onRequestClose={() => onRequestClose()}
            className={classNames(
                'bwf-c-s-delete-model bwf-admin-modal bwf-admin-modal-medium bwf-admin-modal-no-header',
                className
            )}
            isDismissible={closeIcon}
        >
            <div className={'bwf-modal-header'}>
                <div className="bwf-modal-heading">{modalTitle}</div>
            </div>
            { /** Misc Message */}
            {!!content && !!!confirmText && (
                <div
                    className="bwf-modal-content"
                    style={{fontSize: '18px', margin: '10px'}}
                >
                    {content}
                </div>
            )}

            {!!confirmText && (
                <div className="bwf-modal-confirm bwf-h1 bwf-t-center">
                    {confirmText}
                </div>
            )}

            {!!content && !!confirmText && (
                <div
                    className="bwf-modal-content bwf-t-center"
                    style={{
                        fontSize: '16px',
                        lineHeight: '1.5',
                        margin: '10px 0 0',
                    }}
                >
                    {content}
                </div>
            )}
            { /** Initial State */}
            {(cancelButtonText || confirmButtonText) && (
                <>
                    {!isLoading && !successMessage && !errorMessage && (
                        <div className="bwf-t-center bwf-form-buttons">
                            <div className="fkwcs_clear_20"/>
                            <div className="bwf-modal-action wf_funnel_right_align">
                                {cancelButtonText && (
                                    <Button
                                        className={'bwf-modal-cancel-btn'}
                                        onClick={onSecondaryClick}
                                    >
                                        {cancelButtonText}
                                    </Button>
                                )}

                                {confirmButtonText && (
                                    <Button
                                        isPrimary
                                        onClick={onPrimaryClick}
                                        isBusy={disableConfirm}
                                        disabled={disableConfirm}
                                    >
                                        {confirmButtonText}
                                    </Button>
                                )}
                            </div>
                        </div>
                    )}
                </>
            )}
        </Modal>
    ) : null;
};

export default BWFConfirmationModal;
