import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Dashboard from './pages/Dashboard';
import Sales from './pages/Sales';
import Settings from './pages/Settings';

const { pluginUrl, feedAdminUrl } = window.mdfcforwcAdmin || {};

const TABS = [
	{ key: 'dashboard', label: __( 'Dashboard', 'marques-de-france-connector-for-woocommerce' ) },
	{ key: 'feed', label: __( 'Product Feed', 'marques-de-france-connector-for-woocommerce' ) },
	{ key: 'sales', label: __( 'Sales tracking', 'marques-de-france-connector-for-woocommerce' ) },
	{ key: 'settings', label: __( 'Settings', 'marques-de-france-connector-for-woocommerce' ) },
];

export default function App() {
	const initialPage = window.mdfcforwcAdmin?.initialPage || 'dashboard';
	const [ activeTab, setActiveTab ] = useState( initialPage );

	const handleTabClick = ( key ) => {
		if ( key === 'feed' ) {
			if ( feedAdminUrl ) {
				window.location.href = feedAdminUrl;
			}
			return;
		}
		setActiveTab( key );
	};

	const renderContent = () => {
		switch ( activeTab ) {
			case 'dashboard':
				return <Dashboard />;
			case 'sales':
				return <Sales />;
			case 'settings':
				return <Settings />;
			default:
				return null;
		}
	};

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
