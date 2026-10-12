const actionTypes = (name) => ({
	SUCCESS: name + '_success',
	FAILURE: name + '_failure',
	LOADING: name + '_loading',
	CLEAR_ERROR: name + '_clear_error',
	SET_STATE_PROP: name + '_state_prop',
});

export const globalActionTypes = {
	REQUEST: 'fkwcs_api_request',
};

export default actionTypes;
