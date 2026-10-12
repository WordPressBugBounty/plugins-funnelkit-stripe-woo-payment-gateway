import {applyMiddleware, combineReducers, createStore, compose} from 'redux';
import createSagaMiddleware from 'redux-saga';
import watcher from './saga';
import apiClientReducer from './reducer';
import {fork, all} from 'redux-saga/effects';

const composeEnhancers =
	(window.__REDUX_DEVTOOLS_EXTENSION_COMPOSE__ &&
		window.__REDUX_DEVTOOLS_EXTENSION_COMPOSE__(
			{
				trace: true,
				traceLimit: 25,
			}
		)) ||
	compose;
const sagaMiddleware   = createSagaMiddleware();
const useApiStore      = (reducerDataArray = [], reduxReducers = {}) => {
	const reducers     = reduxReducers;
	for (const reducerData of reducerDataArray) {
		reducers[reducerData.name] = apiClientReducer( reducerData );
	}

	const store = createStore(
		combineReducers( reducers ),
		composeEnhancers( applyMiddleware( sagaMiddleware ) )
	);

	return store;
};

export default useApiStore;
