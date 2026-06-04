import { __ } from '@wordpress/i18n';

export default function KpiCard({
	label,
	value,
	subLabel,
	className = '',
}) {
	return (
		<div className={`mdf-stat-card ${className}`.trim()}>
			<div className="mdf-stat-card__label">{label}</div>
			<div className="mdf-stat-card__value">{value}</div>
			{subLabel ? <div className="mdf-stat-card__sub">{subLabel}</div> : null}
		</div>
	);
}
