const development = 'development';
const production = 'production';
export const appMode = envMode;

export const setPublicPath = () => {
    if (appMode === production) {
        __webpack_public_path__ = appMode === production ? bwfsg.app_data : '';
    }
};
