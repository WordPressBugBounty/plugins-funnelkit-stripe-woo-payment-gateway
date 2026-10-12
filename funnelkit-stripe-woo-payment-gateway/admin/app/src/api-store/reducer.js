import actionTypes from './action-types';

const apiClientReducer                        = ({
							  name,
							  defaultStateVars,
							  onResponseSuccess,
							  onResponseFailed,
						  }) => {
								const actions = actionTypes( name );

								const defaultState = {
									isLoading: false,
									error: null,
									...defaultStateVars,
								};

								return (state = defaultState, action) => {
									const {
										SUCCESS,
										FAILURE,
										CLEAR_ERROR,
										LOADING,
										SET_STATE_PROP,
									} = actions;
									switch (action.type) {
										case SUCCESS:
											return onResponseSuccess(
												state,
												action.response,
												action.extraData
											);
										case FAILURE:
											return onResponseFailed(
												state,
												action.error,
												action.extraData
											);
										case CLEAR_ERROR:
											return {...state, error: null};
										case LOADING:
											return {...state, isLoading: action.isLoading};
										case SET_STATE_PROP:
											const {key, value} = action;
											if ( ! state.hasOwnProperty( key ) || value !== state[key]) {
												return {...state, [key]: value};
											}
											return state;
										default:
											return state;
									}
								};
							};

							export default apiClientReducer;
