import {Icon} from '@wordpress/components';
import {Component} from '@wordpress/element';
import {__} from '@wordpress/i18n';
import Button from '../components/bwf-button'

export default class ErrorBoundary extends Component {
	constructor(props) {
		super( props );
		this.state = {error: ''};
	}

	static getDerivedStateFromError(error) {
		// Update state so the next render will show the fallback UI.
		return {error};
	}

	render() {
		if (this.state.error) {
			// You can render any custom fallback UI
			return (
				< >
					< div
						className                         = "bwf-c-global-error"
						style                             = {{
							display: 'flex',
							alignItems: 'center',
							justifyContent: 'center',
							height: '60vh',
							}}
					>
						< div
							className                     = "bwf-c-global-error-center"
							style                         = {{
								textAlign: 'center',
								backgroundColor: '#fff',
								width: 500,
								padding: 50,
								}}
						>
							< Icon
								style                     = {{
									fontSize: 70,
									height: 70,
									width: 70,
									}}
								icon                      = "warning"
							/ >
							< p >
								{__(
									'It seems there are some technical difficulties. Press F12 to open the console, take a screenshot of the error and send it to support.',
									'funn'
								)}
							< / p >
							< Button
								onClick                   = {() =>
									(window.location.href =
										window.location.href)
								}
								isPrimary
								icon = "image-rotate"
							>
								& nbsp;
								{__( 'Refresh', )}
							< / Button >
						< / div >
					< / div >
				< / >
			);
		}

		return this.props.children;
	}
}
