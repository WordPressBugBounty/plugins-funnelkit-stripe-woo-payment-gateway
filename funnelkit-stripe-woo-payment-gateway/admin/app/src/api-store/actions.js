import actionTypes, {globalActionTypes} from './action-types';

const actions   = (name) => {
	const types = actionTypes( name );
	const {LOADING, SUCCESS, FAILURE, CLEAR_ERROR, SET_STATE_PROP} = types;
	const {REQUEST} = globalActionTypes;
	return {
		setLoading: (isLoading) => ({type: LOADING, isLoading}),
		fetchSuccess: (response, extraData = {}) => ({
			type: SUCCESS,
			response,
			extraData,
		}),
	fetchFailed: (error, extraData = {}) => ({
		type: FAILURE,
		error,
		extraData,
		}),
	fetch: (method, path, data, extraData = {}) => ({
		type: REQUEST,
		method,
		path,
		data,
		reducer: name,
		extraData,
		}),
	clearError: () => ({type: CLEAR_ERROR}),
	setStateProp: (key, value) => ({
		type: SET_STATE_PROP,
		key,
		value,
		}),
	};
};

export default actions;
