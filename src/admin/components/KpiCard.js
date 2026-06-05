import { __ } from '@wordpress/i18n';
import LoadingState from './LoadingState';

export default function KpiCard({
	label,
	value,
	subLabel,
	className = '',
	loading = false,
}) {
	return (
		<div className={`mdf-stat-card ${className}`.trim()}>
			<div className="mdf-stat-card__label">{label}</div>
			{loading ? (
				<LoadingState
					message={__('Loading…', 'marques-de-france-connector-for-woocommerce')}
					className="mdf-loading mdf-loading--inline"
					style={{ minHeight: 64, justifyContent: 'center' }}
				/>
			) : (
				<>
					<div className="mdf-stat-card__value">{value}</div>
					{subLabel ? <div className="mdf-stat-card__sub">{subLabel}</div> : null}
				</>
			)}
		</div>
	);
}
