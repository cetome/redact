// cetome console charts (Chart.js 4). CSP-safe: the data comes from a
// data-chart attribute (JSON), no inline script. Chart.js is loaded on demand,
// in its own chunk, only on pages that hold a chart.
//
// <div x-data="adminChart" data-chart='{"labels":[...],"values":[...],"label":"...","type":"bar|line"}'>
//     <canvas role="img" aria-label="..."></canvas>
// </div>

// One series per chart, in the cetome blue validated for each theme
// (contrast >= 3:1 against the chart surface).
const THEMES = {
    light: { series: '#1565d8', fill: 'rgba(21, 101, 216, 0.12)', grid: '#e2e8f0', ticks: '#64748b', surface: '#ffffff' },
    dark: { series: '#217aff', fill: 'rgba(33, 122, 255, 0.18)', grid: '#1e293b', ticks: '#94a3b8', surface: '#0f172a' },
};

let chartModule = null;

// Chart instances live outside Alpine's reactive state (proxying a Chart breaks it).
const charts = new WeakMap();

async function loadChart() {
    if (!chartModule) {
        chartModule = import('chart.js').then((mod) => {
            mod.Chart.register(
                mod.BarController,
                mod.BarElement,
                mod.LineController,
                mod.LineElement,
                mod.PointElement,
                mod.LinearScale,
                mod.CategoryScale,
                mod.Filler,
                mod.Tooltip,
            );
            return mod.Chart;
        });
    }
    return chartModule;
}

function currentTheme() {
    return document.documentElement.classList.contains('dark') ? THEMES.dark : THEMES.light;
}

function dayFormatter(long) {
    const lang = document.documentElement.lang || 'en';
    return new Intl.DateTimeFormat(lang, long
        ? { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }
        : { day: 'numeric', month: 'short', timeZone: 'UTC' });
}

function formatDay(iso, long) {
    const date = new Date(`${iso}T00:00:00Z`);
    return Number.isNaN(date.getTime()) ? iso : dayFormatter(long).format(date);
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('adminChart', () => ({
        observer: null,

        async init() {
            let payload;
            try {
                payload = JSON.parse(this.$el.dataset.chart || '{}');
            } catch {
                return;
            }
            if (!Array.isArray(payload.labels) || !Array.isArray(payload.values)) {
                return;
            }

            const canvas = this.$el.querySelector('canvas');
            if (!canvas) {
                return;
            }

            const Chart = await loadChart();
            charts.set(this.$el, new Chart(canvas, this.config(payload)));

            // Follow the light/dark switch.
            this.observer = new MutationObserver(() => this.repaint());
            this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        },

        config(payload) {
            const theme = currentTheme();
            const isLine = payload.type === 'line';
            const labels = payload.labels;

            return {
                type: isLine ? 'line' : 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: payload.label || '',
                        data: payload.values,
                        borderColor: theme.series,
                        backgroundColor: isLine ? theme.fill : theme.series,
                        borderWidth: isLine ? 2 : 0,
                        borderRadius: isLine ? 0 : { topLeft: 4, topRight: 4 },
                        borderSkipped: 'bottom',
                        fill: isLine,
                        tension: 0.25,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointHoverBorderWidth: 2,
                        pointHoverBorderColor: theme.surface,
                        pointHoverBackgroundColor: theme.series,
                        maxBarThickness: 18,
                        categoryPercentage: 0.9,
                        barPercentage: 0.9,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 300 },
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            displayColors: false,
                            callbacks: {
                                title: (items) => (items.length ? formatDay(labels[items[0].dataIndex], true) : ''),
                                label: (item) => `${payload.label}: ${Number(item.parsed.y).toLocaleString(document.documentElement.lang || 'en')}`,
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            border: { color: theme.grid },
                            ticks: {
                                color: theme.ticks,
                                autoSkip: true,
                                maxTicksLimit: 8,
                                maxRotation: 0,
                                callback: (value, index) => formatDay(labels[index], false),
                            },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: theme.grid },
                            border: { display: false },
                            ticks: { color: theme.ticks, precision: 0, maxTicksLimit: 5 },
                        },
                    },
                },
            };
        },

        repaint() {
            const chart = charts.get(this.$el);
            if (!chart) {
                return;
            }
            const theme = currentTheme();
            const dataset = chart.data.datasets[0];
            const isLine = chart.config.type === 'line';
            dataset.borderColor = theme.series;
            dataset.backgroundColor = isLine ? theme.fill : theme.series;
            dataset.pointHoverBackgroundColor = theme.series;
            dataset.pointHoverBorderColor = theme.surface;
            const { x, y } = chart.options.scales;
            x.border.color = theme.grid;
            x.ticks.color = theme.ticks;
            y.grid.color = theme.grid;
            y.ticks.color = theme.ticks;
            chart.update('none');
        },

        destroy() {
            this.observer?.disconnect();
            charts.get(this.$el)?.destroy();
            charts.delete(this.$el);
        },
    }));
});
