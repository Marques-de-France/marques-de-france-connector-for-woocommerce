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
import { __ } from '@wordpress/i18n';
import LoadingState from './LoadingState';

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

function formatAxisLabel(dateKey, granularity = 'day') {
	const [year, month, day] = dateKey.split('-').map(Number);
	const date = new Date(year, month - 1, day || 1);

	return new Intl.DateTimeFormat('fr-FR', {
		day: granularity === 'day' ? 'numeric' : undefined,
		month: 'long',
		year: 'numeric',
	}).format(date);
}

export default function RevenueChart({
	data,
	currency = 'EUR',
	granularity = 'day',
	loading = false,
	revenueLabel = __('Revenue', 'marques-de-france-connector-for-woocommerce'),
	salesLabel = __('Sales', 'marques-de-france-connector-for-woocommerce'),
	fallbackMessage = __('No data for the selected period.', 'marques-de-france-connector-for-woocommerce'),
}) {
	const chartItems = data?.data || [];
	const labels = chartItems.map((item) => formatAxisLabel(item.date, granularity));
	const formatAmount = (
		value,
		currencyCode = currency,
		{ minimumFractionDigits = 2, maximumFractionDigits = 2 } = {}
	) =>
		new Intl.NumberFormat('fr-FR', {
			style: 'currency',
			currency: currencyCode,
			minimumFractionDigits,
			maximumFractionDigits,
		}).format(Number(value) || 0);

	const mixedData = {
		labels,
		datasets: [
			{
				type: 'line',
				label: revenueLabel,
				data: chartItems.map((item) =>
					parseFloat(Number(item.revenue || 0).toFixed(2))
				),
				borderColor: '#051440',
				backgroundColor: 'rgba(5,20,64,0.08)',
				yAxisID: 'y',
				tension: 0.3,
				fill: false,
				pointRadius: 4,
				pointBackgroundColor: '#051440',
			},
			{
				type: 'bar',
				label: salesLabel,
				data: chartItems.map((item) => item.conversions || 0),
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
					callback: (value) =>
						formatAmount(value, currency, {
							minimumFractionDigits: 0,
							maximumFractionDigits: 0,
						}),
				},
				title: {
					display: true,
					text: revenueLabel,
					color: '#051440',
				},
			},
			y1: {
				type: 'linear',
				position: 'right',
				grid: { drawOnChartArea: false },
				ticks: {
					color: '#ed2e38',
					callback: (value) => `${value}`,
				},
				title: {
					display: true,
					text: salesLabel,
					color: '#ed2e38',
				},
			},
		},
		plugins: {
			legend: { position: 'bottom' },
		},
	};

	if (loading) {
		return <LoadingState style={{ minHeight: 140 }} />;
	}

	if (!labels.length) {
		return <div className="mdf-loading">{fallbackMessage}</div>;
	}

	return <Chart type="bar" data={mixedData} options={chartOptions} />;
}
