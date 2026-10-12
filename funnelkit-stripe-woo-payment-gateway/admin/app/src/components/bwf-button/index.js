import classNames from 'classnames';

import { Button as WpButton } from '@wordpress/components';
import BWFLoadingRing from '../bwf-loading-ring';
import './style.scss';

const Button              = ( { isBusy, isGray, className, children, ...props } ) => {
	return (
		< WpButton
			{ ...props }
			isBusy        = { isBusy }
			className     = { classNames(
				className,
				{
					'is-gray': isGray,
				}
			) }
		>
			< span
				className = { classNames(
					{
						'bwf-visibility-hidden': isBusy,
					}
				) }
			>
				{ children }
			< / span >
			{ ! ! isBusy && (
				< BWFLoadingRing
					color = {
						props.isSecondary
							? '#0073aa'
							: isGray
							? '#353030'
							: '#ffffff'
					}
				/ >
			) }
		< / WpButton >
	);
};

export default Button;
