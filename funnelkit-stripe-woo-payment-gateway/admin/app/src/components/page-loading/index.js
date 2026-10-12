import classNames from "classnames"
import './style.scss'

const BWFPageLoading            = ({className = '', size = 'xl', style = {}, spaceClass = 'fkwcs_clear_30', white = false}) => {
	return (
		< >
			< div className     = {spaceClass} / >
			< div
				className       = {
					classNames( 'bwf-loading-ring', className, {[`bwf - loading - ${size}`]: size} )
				}
				style           = {style}
			>
				< div className = {white ? 'color-white' : ''} > < / div >
				< div className = {white ? 'color-white' : ''} > < / div >
				< div className = {white ? 'color-white' : ''} > < / div >
				< div className = {white ? 'color-white' : ''} > < / div >
			< / div >
			< div className     = {spaceClass} / >
		< / >
	)
};

export default BWFPageLoading;
