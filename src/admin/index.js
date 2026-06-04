import './style.scss';
import { createRoot, flushSync } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './App';

const { restUrl, nonce } = window.mdfcforwcAdmin || {};

if ( restUrl ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( restUrl ) );
}
if ( nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
}

const mountAdminApp = () => {
	const rootEl = document.getElementById( 'mdf-wc-admin' );

	if ( ! rootEl ) {
		return;
	}

	const initialPage = rootEl.getAttribute( 'data-page' ) || 'dashboard';
	const mountNode = document.createElement( 'div' );
	mountNode.className = 'mdf-admin-react-root';
	mountNode.dataset.page = initialPage;
	rootEl.replaceWith( mountNode );

	window.mdfcforwcAdmin = {
		...( window.mdfcforwcAdmin || {} ),
		initialPage,
	};

	flushSync( () => {
		createRoot( mountNode ).render( <App /> );
	} );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountAdminApp, { once: true } );
} else {
	mountAdminApp();
}
