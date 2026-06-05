import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, DatePicker, Popover } from '@wordpress/components';
import LoadingState from '../components/LoadingState';
import KpiCard from '../components/KpiCard';
import RevenueChart from '../components/RevenueChart';

/** Compute dateFrom/dateTo from a preset string. */
function getRangeDates(preset) {
	const now = new Date();
	const pad = (n) => String(n).padStart(2, '0');
	const fmt = (d) =>
		`${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
	const today = fmt(now);
	let from;

	switch (preset) {
		case '7d': {
			const d = new Date(now);
			d.setDate(d.getDate() - 6);
			from = fmt(d);
			break;
		}
		case '90d': {
			const d = new Date(now);
			d.setDate(d.getDate() - 89);
			from = fmt(d);
			break;
		}
		case '12m': {
			const d = new Date(now);
			d.setMonth(d.getMonth() - 11);
			d.setDate(1);
			from = fmt(d);
			break;
		}
		case 'year': {
			const d = new Date(now);
			d.setFullYear(d.getFullYear() - 1);
			from = fmt(d);
			break;
		}
		case 'this_year': {
			from = `${now.getFullYear()}-01-01`;
			break;
		}
		default: {
			// 28d
			const d = new Date(now);
			d.setDate(d.getDate() - 27);
			from = fmt(d);
		}
	}

	return { dateFrom: from, dateTo: today };
}

const RANGE_OPTIONS = [
	{
		value: '7d',
		label: __('Last 7 days', 'marques-de-france-connector-for-woocommerce'),
	},
	{
		value: '28d',
		label: __(
			'Last 28 days',
			'marques-de-france-connector-for-woocommerce'
		),
	},
	{
		value: '90d',
		label: __(
			'Last 90 days',
			'marques-de-france-connector-for-woocommerce'
		),
	},
	{
		value: '12m',
		label: __(
			'Last 12 months',
			'marques-de-france-connector-for-woocommerce'
		),
	},
	{
		value: 'year',
		label: __('Last year', 'marques-de-france-connector-for-woocommerce'),
	},
	{
		value: 'this_year',
		label: __('This year', 'marques-de-france-connector-for-woocommerce'),
	},
];

const STATUS_COLORS = {
	confirmed: { background: '#00a32a', color: '#fff' },
	cancelled: { background: '#d63638', color: '#fff' },
	refunded: { background: '#9ea3a8', color: '#fff' },
	pending: { background: '#f0c33c', color: '#2c3338' },
};

function formatDateValue(date) {
	if (!date) {
		return '';
	}

	const dateValue = date instanceof Date ? date : new Date(date);

	if (Number.isNaN(dateValue.getTime())) {
		return '';
	}

	const year = dateValue.getFullYear();
	const month = String(dateValue.getMonth() + 1).padStart(2, '0');
	const day = String(dateValue.getDate()).padStart(2, '0');

	return `${year}-${month}-${day}`;
}



export default function Sales() {
	// Analytics state
	const [analyticsRange, setAnalyticsRange] = useState('28d');
	const [granularity, setGranularity] = useState('day');
	const [analytics, setAnalytics] = useState(null);
	const [analyticsLoading, setAnalyticsLoading] = useState(true);
	const [analyticsError, setAnalyticsError] = useState(null);

	// Sales table state
	const [search, setSearch] = useState('');
	const [status, setStatus] = useState('');
	const [dateFrom, setDateFrom] = useState('');
	const [dateTo, setDateTo] = useState('');
	const [sortField, setSortField] = useState('created_at');
	const [sortDir, setSortDir] = useState('desc');
	const [page, setPage] = useState(1);
	const [sales, setSales] = useState(null);
	const [salesLoading, setSalesLoading] = useState(true);
	const [salesError, setSalesError] = useState(null);
	const [isFromPickerOpen, setIsFromPickerOpen] = useState(false);
	const [isToPickerOpen, setIsToPickerOpen] = useState(false);
	const fromButtonRef = useRef(null);
	const toButtonRef = useRef(null);

	// Fetch analytics chart data
	useEffect(() => {
		const { dateFrom: from, dateTo: to } = getRangeDates(analyticsRange);
		setAnalyticsLoading(true);
		setAnalyticsError(null);
		apiFetch({
			path: `/mdfcforwc/v1/admin/analytics?dateFrom=${from}&dateTo=${to}&granularity=${granularity}`,
		})
			.then(setAnalytics)
			.catch(() => setAnalyticsError(
				__( 'Failed to load chart data.', 'marques-de-france-connector-for-woocommerce' )
			))
			.finally(() => setAnalyticsLoading(false));
	}, [analyticsRange, granularity]);

	// Fetch sales table
	useEffect(() => {
		setSalesLoading(true);
		setSalesError(null);
		const params = new URLSearchParams({
			page: String(page),
			per_page: '25',
			sortField,
			sortDir,
		});
		if (search) params.set('search', search);
		if (status) params.set('status', status);
		if (dateFrom) params.set('dateFrom', dateFrom);
		if (dateTo) params.set('dateTo', dateTo);

		apiFetch({ path: `/mdfcforwc/v1/admin/sales?${params}` })
			.then(setSales)
			.catch(() =>
				setSalesError(
					__(
						'Failed to load sales.',
						'marques-de-france-connector-for-woocommerce'
					)
				)
			)
			.finally(() => setSalesLoading(false));
	}, [search, status, dateFrom, dateTo, sortField, sortDir, page]);

	const handleSort = (field) => {
		if (field === sortField) {
			setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
		} else {
			setSortField(field);
			setSortDir('desc');
		}
		setPage(1);
	};

	const handleReset = () => {
		setSearch('');
		setStatus('');
		setDateFrom('');
		setDateTo('');
		setPage(1);
	};

	const sortIndicator = (field) => {
		if (field !== sortField) return ' ↕';
		return sortDir === 'asc' ? ' ↑' : ' ↓';
	};

	const currency = analytics?.currency || sales?.currency || 'EUR';
	const totalRevenue = (analytics?.data || []).reduce(
		(sum, item) => sum + Number(item.revenue || 0),
		0
	);
	const totalSales = (analytics?.data || []).reduce(
		(sum, item) => sum + Number(item.conversions || 0),
		0
	);

	const formatAmount = (
		v,
		currencyCode = currency,
		{ minimumFractionDigits = 2, maximumFractionDigits = 2 } = {}
	) =>
		new Intl.NumberFormat('fr-FR', {
			style: 'currency',
			currency: currencyCode,
			minimumFractionDigits,
			maximumFractionDigits,
		}).format(Number(v) || 0);

	const totalPages = Math.ceil((sales?.total || 0) / 25);

	const handleGranularityChange = (value) => {
		setGranularity(value);

		// When the chart switches to monthly aggregation, use the
		// last 12 months as the default range for the analytics query.
		if (value === 'month') {
			setAnalyticsRange('12m');
		}
	};

	return (
		<div className="mdf-page mdf-sales">
			{ /* Analytics chart */}
			<div className="mdf-chart-card">
				<div className="mdf-chart-controls">
					<strong style={{ fontSize: 14, color: '#051440' }}>
						{__(
							'Revenue over time',
							'marques-de-france-connector-for-woocommerce'
						)}
					</strong>
					<div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
						<select
							className="mdf-select"
							value={analyticsRange}
							onChange={(e) => {
								setAnalyticsRange(e.target.value);
							}}
						>
							{RANGE_OPTIONS.map((o) => (
								<option key={o.value} value={o.value}>
									{o.label}
								</option>
							))}
						</select>
						<select
							className="mdf-select"
							value={granularity}
							onChange={(e) => handleGranularityChange(e.target.value)}
						>
							<option value="day">
								{__(
									'Daily',
									'marques-de-france-connector-for-woocommerce'
								)}
							</option>
							<option value="month">
								{__(
									'Monthly',
									'marques-de-france-connector-for-woocommerce'
								)}
							</option>
						</select>
					</div>
				</div>
				<div className="mdf-chart-container">
					{ analyticsError ? (
						<div className="mdf-error">{ analyticsError }</div>
					) : (
						<RevenueChart
							data={analytics}
							currency={currency}
							granularity={granularity}
							loading={analyticsLoading}
							revenueLabel={__('Revenue', 'marques-de-france-connector-for-woocommerce')}
							salesLabel={__('Sales', 'marques-de-france-connector-for-woocommerce')}
						/>
					) }
				</div>
			</div>

			<div className="mdf-stat-cards">
				<KpiCard
					label={__('Revenue', 'marques-de-france-connector-for-woocommerce')}
					value={formatAmount(totalRevenue, currency)}
					subLabel={__('On selected period', 'marques-de-france-connector-for-woocommerce')}
					loading={analyticsLoading}
				/>
				<KpiCard
					label={__('Sales', 'marques-de-france-connector-for-woocommerce')}
					value={String(totalSales)}
					subLabel={__('On selected period', 'marques-de-france-connector-for-woocommerce')}
					loading={analyticsLoading}
				/>
			</div>

			<div className="mdf-chart-card">
				{ /* Filters */}
				<div className="mdf-filters">
				<input
					type="search"
					className="mdf-input"
					style={{ flex: 1, minWidth: 180 }}
					placeholder={__(
						'Search order ID…',
						'marques-de-france-connector-for-woocommerce'
					)}
					value={search}
					onChange={(e) => {
						setSearch(e.target.value);
						setPage(1);
					}}
				/>
				<select
					className="mdf-select"
					value={status}
					onChange={(e) => {
						setStatus(e.target.value);
						setPage(1);
					}}
				>
					<option value="">
						{__(
							'All statuses',
							'marques-de-france-connector-for-woocommerce'
						)}
					</option>
					<option value="confirmed">
						{__(
							'Confirmed',
							'marques-de-france-connector-for-woocommerce'
						)}
					</option>
					<option value="cancelled">
						{__(
							'Cancelled',
							'marques-de-france-connector-for-woocommerce'
						)}
					</option>
					<option value="refunded">
						{__(
							'Refunded',
							'marques-de-france-connector-for-woocommerce'
						)}
					</option>
					<option value="pending">
						{__(
							'Pending',
							'marques-de-france-connector-for-woocommerce'
						)}
					</option>
				</select>
				<div className="mdf-filter-calendar" style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
					<Button
						ref={fromButtonRef}
						variant="secondary"
						style={{ backgroundColor: '#fff', minHeight: 40, height: 40 }}
						onClick={() => setIsFromPickerOpen((open) => !open)}
					>
						{dateFrom
							? new Intl.DateTimeFormat('fr-FR', {
								day: '2-digit',
								month: '2-digit',
								year: 'numeric',
							}).format(new Date(dateFrom))
							: __('From', 'marques-de-france-connector-for-woocommerce')}
					</Button>
					{isFromPickerOpen && (
						<Popover
							anchorRef={fromButtonRef}
							className="mdf-date-picker-popover"
							onClose={() => setIsFromPickerOpen(false)}
						>
							<DatePicker
								currentDate={dateFrom ? new Date(dateFrom) : null}
								onChange={(value) => {
									setDateFrom(formatDateValue(value));
									setPage(1);
									setIsFromPickerOpen(false);
								}}
							/>
						</Popover>
					)}
					<Button
						ref={toButtonRef}
						variant="secondary"
						style={{ backgroundColor: '#fff', minHeight: 40, height: 40 }}
						onClick={() => setIsToPickerOpen((open) => !open)}
					>
						{dateTo
							? new Intl.DateTimeFormat('fr-FR', {
								day: '2-digit',
								month: '2-digit',
								year: 'numeric',
							}).format(new Date(dateTo))
							: __('To', 'marques-de-france-connector-for-woocommerce')}
					</Button>
					{isToPickerOpen && (
						<Popover
							anchorRef={toButtonRef}
							className="mdf-date-picker-popover"
							onClose={() => setIsToPickerOpen(false)}
						>
							<DatePicker
								currentDate={dateTo ? new Date(dateTo) : null}
								onChange={(value) => {
									setDateTo(formatDateValue(value));
									setPage(1);
									setIsToPickerOpen(false);
								}}
							/>
						</Popover>
					)}
				</div>
					<Button
						variant="secondary"
						style={{ backgroundColor: '#fff', minHeight: 40, height: 40 }}
						onClick={handleReset}
					>
						{__(
							'Reset',
							'marques-de-france-connector-for-woocommerce'
						)}
					</Button>
				</div>

				{ /* Sales count */}
				{sales !== null && (
					<p className="mdf-sales__summary">
					{sales.total}{' '}
					{__(
						'sales',
						'marques-de-france-connector-for-woocommerce'
					)}
				</p>
				)}

				{ /* Table */}
				<div className="mdf-table-wrap">
				<table className="mdf-table">
					<thead>
						<tr>
							<th>
								<button
									type="button"
									className={`mdf-sort-btn${sortField === 'order_id'
											? ' mdf-sort-btn--active'
											: ''
										}`}
									onClick={() => handleSort('order_id')}
								>
									{__(
										'Order',
										'marques-de-france-connector-for-woocommerce'
									)}
									{sortIndicator('order_id')}
								</button>
							</th>
							<th>
								{__(
									'Attribution',
									'marques-de-france-connector-for-woocommerce'
								)}
							</th>
							<th>
								<button
									type="button"
									className={`mdf-sort-btn${sortField === 'amount'
											? ' mdf-sort-btn--active'
											: ''
										}`}
									onClick={() => handleSort('amount')}
								>
									{__(
										'Amount',
										'marques-de-france-connector-for-woocommerce'
									)}
									{sortIndicator('amount')}
								</button>
							</th>
							<th>
								<button
									type="button"
									className={`mdf-sort-btn${sortField === 'status'
											? ' mdf-sort-btn--active'
											: ''
										}`}
									onClick={() => handleSort('status')}
								>
									{__(
										'Status',
										'marques-de-france-connector-for-woocommerce'
									)}
									{sortIndicator('status')}
								</button>
							</th>
							<th>
								<button
									type="button"
									className={`mdf-sort-btn${sortField === 'created_at'
											? ' mdf-sort-btn--active'
											: ''
										}`}
									onClick={() =>
										handleSort('created_at')
									}
								>
									{__(
										'Date',
										'marques-de-france-connector-for-woocommerce'
									)}
									{sortIndicator('created_at')}
								</button>
							</th>
						</tr>
					</thead>
					<tbody>
						{salesLoading && (
							<tr>
								<td
									colSpan={5}
									className="mdf-table__loading"
									style={{ textAlign: 'center' }}
								>
									<LoadingState
										style={{ margin: '0 auto' }}
									/>
								</td>
							</tr>
						)}
						{!salesLoading && salesError && (
							<tr>
								<td colSpan={5}>
									<div className="mdf-error">
										{salesError}
									</div>
								</td>
							</tr>
						)}
						{!salesLoading &&
							!salesError &&
							sales?.sales?.length === 0 && (
								<tr>
									<td
										colSpan={5}
										className="mdf-table__empty"
									>
										{__(
											'No sales found.',
											'marques-de-france-connector-for-woocommerce'
										)}
									</td>
								</tr>
							)}
						{!salesLoading &&
							sales?.sales?.map((row) => {
								const orderUrl = `${window.location.origin}/wp-admin/post.php?post=${row.order_id}&action=edit`;
								const colors =
									STATUS_COLORS[row.status] || {
										background: '#e0e0e0',
										color: '#333',
									};
								return (
									<tr key={row.id}>
										<td>
											<a href={orderUrl}>
												#
												{row.order_number ||
													row.order_id}
											</a>
										</td>
										<td>
											{row.attribution_source || '—'}
										</td>
										<td>
											{formatAmount(row.amount, row.currency)}
										</td>
										<td>
											<span
												style={{
													background:
														colors.background,
													borderRadius: 3,
													color: colors.color,
													fontSize: 11,
													fontWeight: 600,
													padding: '2px 8px',
													textTransform: 'capitalize',
												}}
											>
												{row.status}
											</span>
										</td>
										<td>
											{row.created_at
												? new Intl.DateTimeFormat('fr-FR', {
													day: '2-digit',
													month: '2-digit',
													year: 'numeric',
												}).format(new Date(row.created_at))
												: '—'}
										</td>
									</tr>
								);
							})}
					</tbody>
				</table>
			</div>

				{ /* Pagination */}
				{totalPages > 1 && (
					<div className="mdf-pagination">
					<span className="mdf-pagination__info">
						{__(
							'Page',
							'marques-de-france-connector-for-woocommerce'
						)}{' '}
						{page} / {totalPages}
					</span>
					<Button
						variant="secondary"
						style={{ backgroundColor: '#fff' }}
						disabled={page <= 1}
						onClick={() => setPage(page - 1)}
					>
						{__(
							'Previous',
							'marques-de-france-connector-for-woocommerce'
						)}
					</Button>
					<Button
						variant="secondary"
						style={{ backgroundColor: '#fff' }}
						disabled={page >= totalPages}
						onClick={() => setPage(page + 1)}
					>
						{__(
							'Next',
							'marques-de-france-connector-for-woocommerce'
						)}
					</Button>
					</div>
				)}
			</div>
		</div>
	);
}
