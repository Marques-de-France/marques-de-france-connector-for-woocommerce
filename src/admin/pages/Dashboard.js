import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';
import LoadingState from '../components/LoadingState';
import KpiCard from '../components/KpiCard';
import RevenueChart from '../components/RevenueChart';

const { configured, settingsUrl } = window.mdfcforwcAdmin || {};



export default function Dashboard() {
	const [ stats, setStats ] = useState( null );
	const [ chartData, setChartData ] = useState( null );
	const [ hubStatus, setHubStatus ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		const fetchAll = async () => {
			try {
				setLoading( true );
				const requests = [
					apiFetch( { path: '/mdfcforwc/v1/admin/stats' } ),
					apiFetch( {
						path: '/mdfcforwc/v1/admin/analytics?granularity=month',
					} ),
				];
				if ( configured ) {
					requests.push(
						apiFetch( { path: '/mdfcforwc/v1/admin/hub-status' } )
					);
				}
				const results = await Promise.all( requests );
				setStats( results[ 0 ] );
				setChartData( results[ 1 ] );
				if ( results[ 2 ] ) {
					setHubStatus( results[ 2 ] );
				}
			} catch {
				setError(
					__(
						'Failed to load data.',
						'marques-de-france-connector-for-woocommerce'
					)
				);
			} finally {
				setLoading( false );
			}
		};
		fetchAll();
	}, [] );

	if ( loading ) {
		return (
			<LoadingState
				style={{ minHeight: 160 }}
			/>
		);
	}

	if ( error ) {
		return <div className="mdf-error">{ error }</div>;
	}

	// Show onboarding if plugin not configured
	if ( ! stats?.configured ) {
		return (
			<div className="mdf-page">
				<div className="mdf-onboarding">
					<h2 className="mdf-onboarding__title">
						{ __(
							'Activate your store',
							'marques-de-france-connector-for-woocommerce'
						) }
					</h2>
					<div className="mdf-onboarding__steps">
						<div className="mdf-onboarding__step mdf-onboarding__step--done">
							<div className="mdf-onboarding__step-badge">✓</div>
							<div className="mdf-onboarding__step-label">
								{ __(
									'Store registered',
									'marques-de-france-connector-for-woocommerce'
								) }
							</div>
						</div>
						<div className="mdf-onboarding__connector" />
						<div className="mdf-onboarding__step mdf-onboarding__step--active">
							<div className="mdf-onboarding__step-badge">2</div>
							<div className="mdf-onboarding__step-label">
								{ __(
									'Enter your activation code',
									'marques-de-france-connector-for-woocommerce'
								) }
							</div>
						</div>
					</div>
					<Button
						variant="primary"
						className="mdf-onboarding__cta"
						href={ settingsUrl }
					>
						{ __(
							'Enter my activation code',
							'marques-de-france-connector-for-woocommerce'
						) }
					</Button>
				</div>
			</div>
		);
	}

	const currency = stats?.currency || 'EUR';
	const formatAmount = (
		v,
		currencyCode = currency,
		{ minimumFractionDigits = 2, maximumFractionDigits = 2 } = {}
	) =>
		new Intl.NumberFormat( 'fr-FR', {
			style: 'currency',
			currency: currencyCode,
			minimumFractionDigits,
			maximumFractionDigits,
		} ).format( Number( v ) || 0 );

	return (
		<div className="mdf-page">
			{ /* Hub status notice */ }
			{ hubStatus && (
				<div
					className={ `mdf-hub-status${
						hubStatus.connected
							? ' mdf-hub-status--ok'
							: ' mdf-hub-status--error'
					}` }
				>
					{ hubStatus.connected
						? __(
								'✓ Connected',
								'marques-de-france-connector-for-woocommerce'
						  )
						: __(
								'✗ Invalid token — please re-enter your Secure Token in Settings.',
								'marques-de-france-connector-for-woocommerce'
						  ) }
				</div>
			) }

			{ /* KPI cards */ }
			<div className="mdf-stat-cards">
				<KpiCard
					label={ __( 'Total Sales', 'marques-de-france-connector-for-woocommerce' ) }
					value={ stats?.totalSales ?? '—' }
					subLabel={ __( 'All time', 'marques-de-france-connector-for-woocommerce' ) }
				/>
				<KpiCard
					label={ __( 'Total Revenue', 'marques-de-france-connector-for-woocommerce' ) }
					value={ formatAmount( stats?.totalRevenue ) }
					subLabel={ __( 'Confirmed', 'marques-de-france-connector-for-woocommerce' ) }
				/>
				<KpiCard
					label={ __( 'This Month', 'marques-de-france-connector-for-woocommerce' ) }
					value={ formatAmount( stats?.monthRevenue ) }
					subLabel={ `${ stats?.monthSales ?? 0 } ${ __(
						stats?.monthSales === 1 ? 'sale' : 'sales',
						'marques-de-france-connector-for-woocommerce'
					) }` }
				/>
				{ stats?.unsyncedSales > 0 && (
					<KpiCard
						label={ __( 'Unsynced', 'marques-de-france-connector-for-woocommerce' ) }
						value={ stats.unsyncedSales }
						subLabel={ __( 'pending Hub sync', 'marques-de-france-connector-for-woocommerce' ) }
					/>
				) }
			</div>

			{ /* Chart */ }
			<div className="mdf-chart-card">
				<div className="mdf-chart-controls">
					<strong style={ { fontSize: 14, color: '#051440' } }>
						{ __(
							'Sales over the last 12 months',
							'marques-de-france-connector-for-woocommerce'
						) }
					</strong>
				</div>
				<div className="mdf-chart-container">
					<RevenueChart
						data={ chartData }
						currency={ currency }
						granularity="month"
						loading={ loading }
						revenueLabel={ __( 'Revenue', 'marques-de-france-connector-for-woocommerce' ) }
						salesLabel={ __( 'Sales', 'marques-de-france-connector-for-woocommerce' ) }
					/>
				</div>
			</div>
		</div>
	);
}
