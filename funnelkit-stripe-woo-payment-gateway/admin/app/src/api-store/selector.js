import {useSelector} from 'react-redux';

const getSelectors       = (reducer) => {
	const hasReducer     = (state) => state.hasOwnProperty( reducer );
	const hasReducerProp = (state, key) =>
		hasReducer( state ) && state[reducer].hasOwnProperty( key );

	return {
		getLoading: () =>
			useSelector(
				(state) =>
				hasReducer( state ) ? state[reducer].isLoading : null
			),
	getError: () =>
			useSelector(
				(state) =>
				hasReducer( state ) ? state[reducer].error : null
			),
	getStateProp: (key) =>
			useSelector(
				(state) =>
				hasReducerProp( state, key ) ? state[reducer][key] : null
			),
	};
};

export default getSelectors;
