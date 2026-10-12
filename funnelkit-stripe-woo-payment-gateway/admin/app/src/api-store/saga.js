import {put, takeEvery} from 'redux-saga/effects';
import {stringify} from 'qs';
import apiFetch from '@wordpress/api-fetch';
import {size} from 'lodash';
import {globalActionTypes} from './action-types';
import actions from './actions';

const {REQUEST} = globalActionTypes;

function * onRequest( action ) {
	const {method, path, data, reducer, extraData} = action;
	const {fetchSuccess, fetchFailed, setLoading}  = actions( reducer );

	const {isFormData = false} = ! ! extraData ? extraData : {};

	yield put( setLoading( true ) );
	const args = {
		method,
		path:
			method === 'GET' && size( data ) > 0
				? path + '?' + stringify( data )
				: path,
	};
	method !== 'GET' && ! isFormData && (args.data = data);
	method !== 'GET' && isFormData && (args.body = data);

	try {
		const resp = yield apiFetch( args );
		yield put( fetchSuccess( resp, extraData ) );
	} catch (e) {
		// const error = e.hasOwnProperty('message') ? e.message : 'HTTP Request Failed';
		// const emptyUI = e.hasOwnProperty('code') && 404 === e.code;
		yield put( fetchFailed( e, extraData ) );
	}

	yield put( setLoading( false ) );
}

export default function * watcher() {
	yield takeEvery( REQUEST, onRequest );
}
