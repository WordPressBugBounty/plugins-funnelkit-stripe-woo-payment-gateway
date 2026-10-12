import classNames from 'classnames';
import './style.scss';

/**
 *
 * @param {string} className
 * @param {string} size // avaiable size l, xl, xxl
 * @return
 */
const BWFLoadingRing = ( {
	className        = '',
	size             = '',
	style            = {},
	color            = '#0073aa',
} ) => {
	return (
		< div
			className = { classNames(
				'bwf-loading-ring',
				className,
				{
					[ `bwf - loading - ${ size }` ]: size,
				}
			) }
			style     = { style }
		>
			< div
				style = { {
					borderColor: `${ color } transparent transparent transparent`,
					} }
			> < / div >
			< div
				style = { {
					borderColor: `${ color } transparent transparent transparent`,
					} }
			> < / div >
			< div
				style = { {
					borderColor: `${ color } transparent transparent transparent`,
					} }
			> < / div >
			< div
				style = { {
					borderColor: `${ color } transparent transparent transparent`,
					} }
			> < / div >
		< / div >
	);
};

export default BWFLoadingRing;
