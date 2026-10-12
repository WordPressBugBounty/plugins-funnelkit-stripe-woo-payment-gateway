import useApiStore from '../api-store';
import StripeReducer from './reducers/stripe';

const reducers = [StripeReducer];
const store    = useApiStore( reducers );

export default store;
