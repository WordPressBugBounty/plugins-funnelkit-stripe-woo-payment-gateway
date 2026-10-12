/**
 * External dependencies
 */
import {Suspense} from '@wordpress/element';
import {Provider} from 'react-redux';
import {Route, Switch} from 'react-router-dom';

/**
 * Internal dependencies
 */
import store from './store';
import ErrorBoundary from './utils/error-boundary';
import './assets/css/global.scss';
import BWFHeaderMenu from './components/bwf-header-menu';
import routesList from './routes';

const App = () => {
    return (
        <>
            <Provider store={store}>
                <BWFHeaderMenu/>
                <div className="stripe-wrap">
                    <ErrorBoundary>
                        <Suspense fallback={<></>}>
                            <Switch>
                                {routesList.map((route, indexKey) => {
                                    return (
                                        <Route
                                            key={indexKey}
                                            exact
                                            path={route.path}
                                            render={route.render}
                                        />
                                    );
                                })}
                            </Switch>
                        </Suspense>
                    </ErrorBoundary>
                </div>
            </Provider>
        </>
    );
};
export default App;
