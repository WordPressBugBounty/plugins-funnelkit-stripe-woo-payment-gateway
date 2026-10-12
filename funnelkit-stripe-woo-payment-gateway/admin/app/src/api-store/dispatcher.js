import {useDispatch} from 'react-redux';
import actions from './actions';

const getDispatchers                                    = (reducer) => {
	const dispatch                                      = useDispatch();
	const reducerActions                                = actions( reducer );
	const {setLoading, fetch, clearError, setStateProp} = reducerActions;

	return {
		setLoading: (isLoading) => dispatch( setLoading( isLoading ) ),
		fetch: (method, path, data, extraData = {}) =>
			dispatch( fetch( method, path, data, extraData ) ),
		clearError: () => dispatch( clearError() ),
		setStateProp: (key, value) => dispatch( setStateProp( key, value ) ),
	};
};

export default getDispatchers;
