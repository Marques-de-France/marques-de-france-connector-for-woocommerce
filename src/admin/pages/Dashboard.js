import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';
import {
	Chart as ChartJS,
	CategoryScale,
	LinearScale,
	PointElement,
	LineElement,
	BarElement,
	BarController,
	LineController,
	Title,
	Tooltip,
	Legend,
} from 'chart.js';
import { Chart } from 'react-chartjs-2';

ChartJS.register(
	CategoryScale,
	LinearScale,
	PointElement,
	LineElement,
	BarElement,
	BarController,
	LineController,
	Title,
	Tooltip,
	Legend
);

const { configured, settingsUrl } = window.mdfcforwcAdmin || {};

function formatMonthLabel( dateKey ) {
	const [ year, month ] = dateKey.split( '-' ).map( Number );
	return new Intl.DateTimeFormat( 'fr-FR', {
		month: 'long',
		year: 'numeric',
	} ).format( new Date( year, month - 1, 1 ) );
}

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
			<div className="mdf-loading">
				{ __( 'Loading…', 'marques-de-france-connector-for-woocommerce' ) }
			</div>
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

	const labels =
		chartData?.data?.map( ( d ) => formatMonthLabel( d.date ) ) || [];
	const revenueValues =
		chartData?.data?.map( ( d ) =>
			parseFloat( Number( d.revenue || 0 ).toFixed( 2 ) )
		) || [];
	const salesValues = chartData?.data?.map( ( d ) => d.conversions ) || [];

	const mixedData = {
		labels,
		datasets: [
			{
				type: 'line',
				label: __(
					'Revenue',
					'marques-de-france-connector-for-woocommerce'
				),
				data: revenueValues,
				borderColor: '#051440',
				backgroundColor: 'rgba(5,20,64,0.08)',
				yAxisID: 'y',
				tension: 0.3,
				fill: false,
				pointRadius: 3,
				pointBackgroundColor: '#051440',
			},
			{
				type: 'bar',
				label: __(
					'Sales',
					'marques-de-france-connector-for-woocommerce'
				),
				data: salesValues,
				backgroundColor: 'rgba(255,102,84,0.65)',
				borderColor: 'rgba(255,102,84,0)',
				yAxisID: 'y1',
			},
		],
	};

	const chartOptions = {
		responsive: true,
		maintainAspectRatio: false,
		interaction: { mode: 'index', intersect: false },
		scales: {
			y: {
				type: 'linear',
				position: 'left',
				ticks: {
					color: '#051440',
					callback: ( value ) =>
						formatAmount( value, currency, {
							minimumFractionDigits: 0,
							maximumFractionDigits: 0,
						} ),
				},
			},
			y1: {
				type: 'linear',
				position: 'right',
				grid: { drawOnChartArea: false },
				ticks: {
					color: '#ed2e38',
					callback: ( value ) => `${ value }`,
				},
			},
		},
		plugins: {
			legend: { position: 'bottom' },
		},
	};

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
				<div className="mdf-stat-card">
					<div className="mdf-stat-card__label">
						{ __(
							'Total Sales',
							'marques-de-france-connector-for-woocommerce'
						) }
					</div>
					<div className="mdf-stat-card__value">
						{ stats?.totalSales ?? '—' }
					</div>
					<div className="mdf-stat-card__sub">
						{ __(
							'All time',
							'marques-de-france-connector-for-woocommerce'
						) }
					</div>
				</div>

				<div className="mdf-stat-card">
					<div className="mdf-stat-card__label">
						{ __(
							'Total Revenue',
							'marques-de-france-connector-for-woocommerce'
						) }
					</div>
					<div className="mdf-stat-card__value">
						{ formatAmount( stats?.totalRevenue ) }
					</div>
					<div className="mdf-stat-card__sub">
						{ __(
							'Confirmed only',
							'marques-de-france-connector-for-woocommerce'
						) }
					</div>
				</div>

				<div className="mdf-stat-card">
					<div className="mdf-stat-card__label">
						{ __(
							'This Month',
							'marques-de-france-connector-for-woocommerce'
						) }
					</div>
					<div className="mdf-stat-card__value">
						{ formatAmount( stats?.monthRevenue ) }
					</div>
					<div className="mdf-stat-card__sub">
						{ `${ stats?.monthSales ?? 0 } ${ __(
							stats?.monthSales === 1 ? 'sale' : 'sales',
							'marques-de-france-connector-for-woocommerce'
						) }` }
					</div>
				</div>

				{ stats?.unsyncedSales > 0 && (
					<div className="mdf-stat-card">
						<div className="mdf-stat-card__label">
							{ __(
								'Unsynced',
								'marques-de-france-connector-for-woocommerce'
							) }
						</div>
						<div className="mdf-stat-card__value">
							{ stats.unsyncedSales }
						</div>
						<div className="mdf-stat-card__sub">
							{ __(
								'pending Hub sync',
								'marques-de-france-connector-for-woocommerce'
							) }
						</div>
					</div>
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
					{ labels.length > 0 ? (
						<Chart type="bar" data={ mixedData } options={ chartOptions } />
					) : (
						<div className="mdf-loading">
							{ __(
								'No data for the selected period.',
								'marques-de-france-connector-for-woocommerce'
							) }
						</div>
					) }
				</div>
			</div>
		</div>
	);
}
