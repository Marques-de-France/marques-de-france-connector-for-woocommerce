import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import Dashboard from './pages/Dashboard';
import Feed from './pages/Feed';
import Sales from './pages/Sales';
import PendingApproval from './pages/PendingApproval';
import Settings from './pages/Settings';

const { pluginUrl } = window.mdfcforwcAdmin || {};

const TABS = [
	{ key: 'dashboard', label: __( 'Dashboard', 'marques-de-france-connector-for-woocommerce' ) },
	{ key: 'feed', label: __( 'Product Feed', 'marques-de-france-connector-for-woocommerce' ) },
	{ key: 'sales', label: __( 'Sales tracking', 'marques-de-france-connector-for-woocommerce' ) },
	{ key: 'settings', label: __( 'Settings', 'marques-de-france-connector-for-woocommerce' ) },
];

const MENU_SLUG = 'marques-de-france-connector-for-woocommerce';

function getInitialTab() {
	const params = new URLSearchParams( window.location.search );
	const page = params.get( 'page' ) || '';

	if ( page.endsWith( '-sales' ) ) {
		return 'sales';
	}
	if ( page.endsWith( '-feed' ) ) {
		return 'feed';
	}
	if ( page.endsWith( '-settings' ) ) {
		return 'settings';
	}

	return window.mdfcforwcAdmin?.initialPage || 'dashboard';
}

export default function App() {
	const [ activeTab, setActiveTab ] = useState( getInitialTab );
	const [ hubStatus, setHubStatus ] = useState( null );
	const [ hubStatusLoading, setHubStatusLoading ] = useState( true );

	useEffect( () => {
		apiFetch( { path: '/mdfcforwc/v1/admin/hub-status' } )
			.then( ( data ) => setHubStatus( data ) )
			.catch( () => setHubStatus( null ) )
			.finally( () => setHubStatusLoading( false ) );
	}, [] );

	useEffect( () => {
		const syncFromUrl = () => {
			setActiveTab( getInitialTab() );
		};

		window.addEventListener( 'popstate', syncFromUrl );
		return () => window.removeEventListener( 'popstate', syncFromUrl );
	}, [] );

	const handleTabClick = ( key ) => {
		const pageSlug = key === 'dashboard' ? MENU_SLUG : `${ MENU_SLUG }-${ key }`;
		const nextUrl = `${ window.location.pathname }?page=${ pageSlug }`;

		setActiveTab( key );
		window.history.pushState( {}, '', nextUrl );
	};

	const renderContent = () => {
		switch ( activeTab ) {
			case 'dashboard':
				return <Dashboard hubStatus={ hubStatus } />;
			case 'feed':
				return <Feed />;
			case 'sales':
				return <Sales />;
			case 'settings':
				return <Settings />;
			default:
				return null;
		}
	};

	if ( hubStatusLoading ) {
		return (
			<div className="mdf-admin-wrap">
				<div className="mdf-admin-header">
					{ pluginUrl && (
						<img
							src={ `${ pluginUrl }admin/images/mdf-logo.svg` }
							alt="Marques de France"
							className="mdf-admin-header__logo"
						/>
					) }
				</div>
			</div>
		);
	}

	if ( hubStatus !== null && hubStatus?.isApproved !== true ) {
		return (
			<div className="mdf-admin-wrap">
				<div className="mdf-admin-header">
					{ pluginUrl && (
						<img
							src={ `${ pluginUrl }admin/images/mdf-logo.svg` }
							alt="Marques de France"
							className="mdf-admin-header__logo"
						/>
					) }
				</div>
				<PendingApproval />

				{ /* A store can be unapproved precisely because its secure token is
				     missing or stale, so the token form has to stay reachable here.
				     Hiding it behind approval would make the only manual recovery
				     path unavailable exactly when it is needed. */ }
				<div className="mdf-admin-content">
					<Settings />
				</div>
			</div>
		);
	}

	return (
		<div className="mdf-admin-wrap">
			<div className="mdf-admin-header">
				{ pluginUrl && (
					<img
						src={ `${ pluginUrl }admin/images/mdf-logo.svg` }
						alt="Marques de France"
						className="mdf-admin-header__logo"
					/>
				) }
			</div>

			<div className="mdf-admin-tabs">
				{ TABS.map( ( tab ) => (
					<button
						key={ tab.key }
						type="button"
						className={ `mdf-admin-tab${ activeTab === tab.key ? ' mdf-admin-tab--active' : '' }` }
						onClick={ () => handleTabClick( tab.key ) }
					>
						{ tab.label }
					</button>
				) ) }
			</div>

			<div className="mdf-admin-content">{ renderContent() }</div>
		</div>
	);
}
