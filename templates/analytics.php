<div class="page-header"><h1>Analytics</h1><a class="btn secondary" href="<?= e(url('/')) ?>">&larr; Dashboard</a></div>

<div class="kpis">
  <div class="kpi"><div class="v"><?= e($kpis['requirements']) ?></div><div class="l">Requirements received</div></div>
  <div class="kpi"><div class="v"><?= e($kpis['boms']) ?></div><div class="l">Project BOMs</div></div>
  <div class="kpi"><div class="v"><?= e($kpis['library']) ?></div><div class="l">Uploaded BOMs</div></div>
  <div class="kpi"><div class="v"><?= e($kpis['quotes']) ?></div><div class="l">Quotations</div></div>
  <div class="kpi"><div class="v"><?= e($kpis['pending']) ?><?php if ($kpis['overdue']): ?> <small style="color:#d03b3b;font-size:0.9rem;">&#9888; <?= $kpis['overdue'] ?> overdue</small><?php endif; ?></div><div class="l">Pending work</div></div>
  <div class="kpi"><div class="v">&#8377;<?= inr($kpis['quote_value']) ?></div><div class="l">Total quoted value</div></div>
  <div class="kpi"><div class="v">&#8377;<?= inr($kpis['avg_quote']) ?></div><div class="l">Average quote value</div></div>
  <div class="kpi"><div class="v"><?= e($kpis['conversion']) ?>%</div><div class="l">Requirements &rarr; quote sent</div></div>
  <div class="kpi"><div class="v"><?= e($kpis['customers']) ?></div><div class="l">Customers</div></div>
</div>

<div class="charts">
  <div class="chart-card wide"><h3>Activity per month</h3><div class="cbox"><canvas id="c-activity"></canvas></div></div>
  <div class="chart-card"><h3>Pipeline: requirement &rarr; quotation sent</h3><div class="cbox"><canvas id="c-funnel"></canvas></div></div>
  <div class="chart-card"><h3>Quoted value per month (&#8377;)</h3><div class="cbox"><canvas id="c-value"></canvas></div></div>
  <div class="chart-card"><h3>Pending work by person</h3><div class="cbox"><canvas id="c-person"></canvas></div></div>
  <div class="chart-card"><h3>Quotations by person</h3><div class="cbox"><canvas id="c-done"></canvas></div></div>
  <div class="chart-card"><h3>Project BOM status (latest versions)</h3><div class="cbox"><canvas id="c-status"></canvas></div></div>
  <div class="chart-card"><h3>Pending by stage owner</h3><div class="cbox"><canvas id="c-owner"></canvas></div></div>
  <div class="chart-card"><h3>Quoted value by customer (&#8377;)</h3><div class="cbox"><canvas id="c-cust"></canvas></div></div>
  <div class="chart-card"><h3>Cost / margin / GST in recent quotations (&#8377;)</h3><div class="cbox"><canvas id="c-split"></canvas></div></div>
  <div class="chart-card"><h3>BOM value by component category (&#8377;)</h3><div class="cbox"><canvas id="c-cat"></canvas></div></div>
  <div class="chart-card"><h3>Uploaded BOMs per customer</h3><div class="cbox"><canvas id="c-lib"></canvas></div></div>
  <div class="chart-card"><h3>Requirements by sales person</h3><div class="cbox"><canvas id="c-sales"></canvas></div></div>
</div>

<script src="<?= e(asset('chart.umd.min.js')) ?>"></script>
<script>
const D = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
// categorical slots in fixed order; status colour only for "overdue"
const S = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
const CRITICAL = '#d03b3b', INK = '#52514e', GRID = '#ececf2';

function inr(n){ n=Math.round(n); var s=String(Math.abs(n)),t=s.slice(-3),h=s.slice(0,-3); if(h) t=h.replace(/\B(?=(\d{2})+(?!\d))/g,',')+','+t; return (n<0?'-':'')+t; }
function short(n){ if(n>=1e7) return (n/1e7).toFixed(1).replace(/\.0$/,'')+' Cr'; if(n>=1e5) return (n/1e5).toFixed(1).replace(/\.0$/,'')+' L'; if(n>=1e3) return Math.round(n/1e3)+'k'; return String(n); }
function noData(id){ var el=document.getElementById(id); el.parentNode.innerHTML='<div class="empty-state" style="padding:40px 10px;">No data yet</div>'; }
function has(arr){ return arr.some(v => v > 0); }

Chart.defaults.font.family = '"Segoe UI", system-ui, Arial, sans-serif';
Chart.defaults.font.size = 12;
Chart.defaults.color = INK;
Chart.defaults.maintainAspectRatio = false;
Chart.defaults.plugins.legend.labels.boxWidth = 12;
Chart.defaults.plugins.legend.labels.boxHeight = 12;
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.interaction = { mode: 'index', intersect: false };

function axes(money, horizontal, stacked) {
  const val = { beginAtZero: true, stacked: !!stacked, grid: { color: GRID }, border: { display: false },
                ticks: { precision: 0, callback: v => money ? short(v) : v } };
  const cat = { stacked: !!stacked, grid: { display: false }, border: { color: '#cfd1dc' },
                ticks: { autoSkip: false, callback: function(v){ const l = this.getLabelForValue(v); return l.length > 22 ? l.slice(0, 21) + '…' : l; } } };
  return horizontal ? { x: val, y: cat } : { x: cat, y: val };
}
function tip(money) { return { callbacks: { label: c => (c.dataset.label ? c.dataset.label + ': ' : '') + (money ? '₹' + inr(c.raw) : c.raw) } }; }
const BAR = { borderRadius: 4, borderSkipped: 'start', maxBarThickness: 34 };

// single-series bar (no legend - the title names it)
function bar(id, rows, opt) {
  opt = opt || {};
  if (!rows.length || !has(rows.map(r => r[1]))) return noData(id);
  new Chart(document.getElementById(id), {
    type: 'bar',
    data: { labels: rows.map(r => r[0]), datasets: [{ data: rows.map(r => r[1]), backgroundColor: opt.color || S[0], ...BAR }] },
    options: { indexAxis: opt.horizontal ? 'y' : 'x', scales: axes(opt.money, opt.horizontal),
               plugins: { legend: { display: false }, tooltip: tip(opt.money) } }
  });
}

// activity: three series, one axis
(function(){
  const sets = [['Requirements', D.req_m], ['BOMs', D.bom_m], ['Quotations', D.quote_m]];
  if (!has(sets.flatMap(s => s[1]))) return noData('c-activity');
  new Chart(document.getElementById('c-activity'), {
    type: 'line',
    data: { labels: D.months, datasets: sets.map((s, i) => ({ label: s[0], data: s[1], borderColor: S[i], backgroundColor: S[i],
            borderWidth: 2, pointRadius: 4, pointHoverRadius: 6, pointBorderColor: '#fff', pointBorderWidth: 2, tension: 0 })) },
    options: { scales: axes(false), plugins: { legend: { position: 'top', align: 'end' } } }
  });
})();

bar('c-funnel', D.funnel, { horizontal: true });
bar('c-value', D.months.map((m, i) => [m, D.quote_value_m[i]]), { money: true });

// pending per person: on time + overdue, stacked
(function(){
  const rows = D.by_person;
  if (!rows.length || !has(rows.map(r => r[1] + r[2]))) return noData('c-person');
  new Chart(document.getElementById('c-person'), {
    type: 'bar',
    data: { labels: rows.map(r => r[0]), datasets: [
      { label: 'On time', data: rows.map(r => r[1]), backgroundColor: S[0], ...BAR, borderRadius: 0, borderWidth: { right: 2 }, borderColor: '#fff' },
      { label: '⚠ Overdue', data: rows.map(r => r[2]), backgroundColor: CRITICAL, ...BAR } ] },
    options: { indexAxis: 'y', scales: axes(false, true, true), plugins: { legend: { position: 'top', align: 'end' } } }
  });
})();

bar('c-done', D.done_by, { horizontal: true });

// BOM status: a few parts of one whole
(function(){
  const rows = D.bom_status;
  if (!rows.length) return noData('c-status');
  new Chart(document.getElementById('c-status'), {
    type: 'doughnut',
    data: { labels: rows.map(r => r[0] + ' · ' + r[1]), datasets: [{ data: rows.map(r => r[1]), backgroundColor: S.slice(0, rows.length), borderColor: '#fff', borderWidth: 2 }] },
    options: { cutout: '62%', interaction: { mode: 'nearest' }, plugins: { legend: { position: 'right' } } }
  });
})();

bar('c-owner', D.pending_owner, { horizontal: true });
bar('c-cust', D.by_customer, { horizontal: true, money: true });

(function(){
  const rows = D.quote_split;
  if (!rows.length) return noData('c-split');
  const names = ['Component cost', 'Margin', 'GST'];
  new Chart(document.getElementById('c-split'), {
    type: 'bar',
    data: { labels: rows.map(r => r[0]), datasets: names.map((n, i) => ({ label: n, data: rows.map(r => r[i + 1]), backgroundColor: S[i],
            ...BAR, borderRadius: i === 2 ? 4 : 0, borderWidth: { top: 2 }, borderColor: '#fff' })) },
    options: { scales: axes(true, false, true), plugins: { legend: { position: 'top', align: 'end' }, tooltip: tip(true) } }
  });
})();

bar('c-cat', D.by_category, { horizontal: true, money: true });
bar('c-lib', D.library_customers, { horizontal: true });
bar('c-sales', D.by_sales, { horizontal: true });
</script>
