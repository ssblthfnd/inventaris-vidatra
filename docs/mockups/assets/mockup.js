/*
 * Mockup script — Dashboard & Laporan.
 * Data di sini HANYA ilustrasi (angka "Saat ini" diambil dari dashboard produksi
 * Oktober 2026; angka "contoh" dan ruangan SD/SMP/SMA adalah karangan).
 * Implementasi sebenarnya mengambil data dari API yang sudah ada.
 */
(function () {
  'use strict';

  // ---------- data ----------
  const SOURCES = [
    ['BOSDA', '--src-bosda'], ['BOSP', '--src-bosp'], ['BOSTK', '--src-bostk'],
    ['Yayasan', '--src-yayasan'], ['Lainnya', '--src-lainnya'], ['Belum diberi keterangan', '--src-belum'],
  ];
  const CONDITIONS = [
    ['Baik', '--st-baik'], ['Kurang Baik', '--st-kurang'], ['Rusak Berat', '--st-rusak'], ['Tidak diketahui', '--st-unknown'],
  ];
  const DATA = {
    current: {
      kpis: { total: 394, writtenOff: 26, lokasi: 4, kategori: 6 },
      sumber: [0, 0, 0, 394, 0, 0],
      kondisi: [378, 0, 11, 5],
      lokasi: [['YAYASAN', 394], ['SD', 0], ['SMP', 0], ['SMA', 0]],
      kategori: [['TANAH DAN BANGUNAN', 0], ['MEUBELAIR', 186], ['ELEKTRONIK', 192], ['MEKANIK', 0], ['ALAT RUMAH TANGGA', 0], ['ALAT KEBERSIHAN', 16]],
    },
    sample: {
      kpis: { total: 817, writtenOff: 41, lokasi: 4, kategori: 6 },
      sumber: [120, 210, 45, 394, 18, 30],
      kondisi: [742, 38, 29, 8],
      lokasi: [['YAYASAN', 394], ['SD', 160], ['SMP', 140], ['SMA', 123]],
      kategori: [['TANAH DAN BANGUNAN', 12], ['MEUBELAIR', 380], ['ELEKTRONIK', 290], ['MEKANIK', 25], ['ALAT RUMAH TANGGA', 60], ['ALAT KEBERSIHAN', 50]],
    },
  };
  const ROOMS = [
    ['CS', 'YAYASAN', 1], ['Gudang', 'YAYASAN', 2], ['Musholla', 'YAYASAN', 2], ['Ruangan Admin Personalia / SARPRAS', 'YAYASAN', 9],
    ['Ruangan Cikini', 'YAYASAN', 4], ['Ruangan Humas IT', 'YAYASAN', 6], ['Ruangan Ketua Harian', 'YAYASAN', 41], ['Ruangan Keuangan', 'YAYASAN', 53],
    ['Kelas 1A', 'SD', 32], ['Kelas 1B', 'SD', 30], ['Laboratorium Komputer', 'SD', 25], ['Perpustakaan', 'SD', 18],
    ['Kelas 7A', 'SMP', 34], ['Laboratorium IPA', 'SMP', 20], ['Ruang Guru', 'SMP', 15],
    ['Kelas 10A', 'SMA', 36], ['Laboratorium Kimia', 'SMA', 22], ['Aula', 'SMA', 14],
  ];
  const YEARS = [[2015, 120], [2016, 64], [2017, 22], [2018, 15], [2019, 30], [2020, 41], [2021, 18], [2022, 26], [2023, 33], [2024, 12], [2025, 8], [2026, 5]];

  // ---------- helpers ----------
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const pct = (v, t) => (t ? (v / t * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 }) : '0') + '%';

  /** 100% stacked bar + legend with count and percentage (identity never by color alone). */
  function stackCard(title, items, extraClass) {
    const total = items.reduce((a, it) => a + it.value, 0);
    const segs = items.filter((it) => it.value > 0)
      .map((it) => `<div title="${esc(`${it.label}: ${it.value} (${pct(it.value, total)})`)}" style="width:${it.value / total * 100}%;background:var(${it.color})"></div>`).join('');
    const legend = items.map((it) => `<li><span class="sw" style="background:var(${it.color})"></span><span class="lbl">${esc(it.label)}</span><span class="num">${it.value}</span><span class="pct num">${pct(it.value, total)}</span></li>`).join('');
    const aria = `${title}: ` + items.map((it) => `${it.label} ${it.value}`).join(', ');
    return `<section class="card ${extraClass}"><h2>${esc(title)}</h2><div class="stack" role="img" aria-label="${esc(aria)}">${segs}</div><ul class="legend">${legend}</ul></section>`;
  }

  /** Horizontal bars sorted descending; value printed beside every bar. */
  function barsHtml(rows, twoCol) {
    const sorted = [...rows].sort((a, b) => b[1] - a[1]);
    const max = Math.max(1, ...sorted.map((r) => r[1]));
    return `<div class="bars${twoCol ? ' two-col' : ''}">` + sorted.map(([label, v]) =>
      `<div class="bar" title="${esc(`${label}: ${v} aset`)}"><div class="top"><span>${esc(label)}</span><span class="num">${v}</span></div><div class="track"><div class="fill" style="width:${v / max * 100}%"></div></div></div>`).join('') + '</div>';
  }

  const kpiHtml = (list) => '<div class="kpis">' + list.map(([l, v]) => `<div class="kpi"><span>${esc(l)}</span><span class="num">${v}</span></div>`).join('') + '</div>';
  const sourceItems = (values) => SOURCES.map(([label, color], i) => ({ label, color, value: values[i] }));
  const conditionItems = (values) => CONDITIONS.map(([label, color], i) => ({ label, color, value: values[i] }));

  // ---------- theme toggle (shared by every page) ----------
  function mountThemeToggle() {
    const root = document.documentElement;
    let saved = null;
    try { saved = localStorage.getItem('mockup-theme'); } catch (e) { /* storage unavailable */ }
    root.dataset.theme = saved === 'dark' || saved === 'light'
      ? saved
      : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

    const wrap = document.createElement('div');
    wrap.className = 'theme-toggle';
    wrap.innerHTML = '<span class="label"></span><button type="button"></button>';
    document.body.appendChild(wrap);
    const label = wrap.querySelector('.label');
    const btn = wrap.querySelector('button');
    const SUN = '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
    const MOON = '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
    const sync = () => {
      const dark = root.dataset.theme === 'dark';
      const text = dark ? 'Ganti ke mode terang' : 'Ganti ke mode gelap';
      label.textContent = text;
      btn.setAttribute('aria-label', text);
      btn.innerHTML = dark ? SUN : MOON;
    };
    btn.addEventListener('click', () => {
      root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('mockup-theme', root.dataset.theme); } catch (e) { /* ignore */ }
      sync();
    });
    sync();
  }

  // ---------- Aset per Ruangan: search, location filter, sort, pagination ----------
  function mountRooms(container, opts) {
    const state = { q: '', lokasi: '', sort: 'jumlah', perPage: 10, page: 1 };
    container.innerHTML = `
      <section class="card flush">
        <div class="card-head">
          <h2>Aset per Ruangan</h2>
          ${opts.withFilters ? `
          <div class="filters">
            <label class="field wide"><span>Cari ruangan</span><input type="search" data-k="q" placeholder="Nama ruangan…"></label>
            <label class="field"><span>Lokasi</span><select data-k="lokasi"><option value="">Semua lokasi</option><option>YAYASAN</option><option>SD</option><option>SMP</option><option>SMA</option></select></label>
            <label class="field"><span>Urutkan</span><select data-k="sort"><option value="jumlah">Jumlah terbanyak</option><option value="nama">Nama ruangan (A–Z)</option></select></label>
            <label class="field narrow"><span>Per halaman</span><select data-k="perPage"><option>10</option><option>25</option></select></label>
          </div>` : ''}
        </div>
        <table><thead><tr><th>RUANGAN</th><th class="r" style="width:96px">JUMLAH</th></tr></thead><tbody></tbody></table>
        <div class="pager"><span data-summary></span><div><button type="button" class="btn" data-prev>‹ Sebelumnya</button><span class="num" data-page></span><button type="button" class="btn" data-next>Selanjutnya ›</button></div></div>
      </section>`;
    const tbody = container.querySelector('tbody');
    const render = () => {
      const needle = state.q.trim().toLowerCase();
      const rows = ROOMS.filter(([n, l]) => (!state.lokasi || l === state.lokasi) && (!needle || n.toLowerCase().includes(needle)));
      rows.sort(state.sort === 'nama' ? (a, b) => a[0].localeCompare(b[0], 'id') : (a, b) => b[2] - a[2] || a[0].localeCompare(b[0], 'id'));
      const pages = Math.max(1, Math.ceil(rows.length / state.perPage));
      state.page = Math.min(state.page, pages);
      const start = (state.page - 1) * state.perPage;
      const pageRows = rows.slice(start, start + state.perPage);
      tbody.innerHTML = pageRows.length
        ? pageRows.map(([n, l, j]) => `<tr><td>${esc(n)}<span class="sub">${esc(l)}</span></td><td class="r num">${j}</td></tr>`).join('')
        : '<tr><td colspan="2" class="muted" style="text-align:center;padding:28px">Tidak ada ruangan yang cocok dengan filter.</td></tr>';
      container.querySelector('[data-summary]').textContent = rows.length ? `Menampilkan ${start + 1}–${start + pageRows.length} dari ${rows.length} ruangan` : 'Menampilkan 0 ruangan';
      container.querySelector('[data-page]').textContent = `${state.page} / ${pages}`;
      container.querySelector('[data-prev]').disabled = state.page <= 1;
      container.querySelector('[data-next]').disabled = state.page >= pages;
    };
    container.querySelectorAll('[data-k]').forEach((el) => {
      el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', () => {
        state[el.dataset.k] = el.dataset.k === 'perPage' ? Number(el.value) : el.value;
        state.page = 1;
        render();
      });
    });
    container.querySelector('[data-prev]').addEventListener('click', () => { state.page -= 1; render(); });
    container.querySelector('[data-next]').addEventListener('click', () => { state.page += 1; render(); });
    render();
  }

  // ---------- pages ----------
  function renderDashboard(root) {
    const params = new URLSearchParams(location.search);
    const d = params.get('data') === 'contoh' ? DATA.sample : DATA.current;
    root.innerHTML = `
      <h1>Dashboard</h1>
      ${kpiHtml([['Total Aset', d.kpis.total], ['Written-off', d.kpis.writtenOff], ['Lokasi Terdaftar', d.kpis.lokasi], ['Kategori Terdaftar', d.kpis.kategori]])}
      <div class="row">
        ${stackCard('Aset per Sumber', sourceItems(d.sumber), 'third')}
        <section class="card third"><h2>Aset per Lokasi</h2>${barsHtml(d.lokasi)}</section>
        ${stackCard('Kondisi Aset', conditionItems(d.kondisi), 'third')}
      </div>
      <section class="card"><h2>Aset per Kategori</h2>${barsHtml(d.kategori, true)}</section>
      <div data-rooms></div>`;
    mountRooms(root.querySelector('[data-rooms]'), { withFilters: true });
  }

  function renderLaporan(root) {
    const FILTERS = [
      ['lokasi', 'Lokasi', ['YAYASAN', 'SD', 'SMP', 'SMA'], 'Semua lokasi'],
      ['kategori', 'Kategori', ['MEUBELAIR', 'ELEKTRONIK', 'ALAT KEBERSIHAN'], 'Semua kategori'],
      ['kondisi', 'Kondisi', ['Baik', 'Kurang Baik', 'Rusak Berat'], 'Semua kondisi'],
      ['status', 'Status', ['Aktif', 'Written-off'], 'Semua status'],
      ['tahun', 'Tahun', ['2015', '2016', '2020', '2024'], 'Semua tahun'],
      ['sumber', 'Sumber', ['BOSDA', 'BOSP', 'BOSTK', 'Yayasan', 'Lainnya'], 'Semua sumber'],
    ];
    const state = { lokasi: 'YAYASAN', status: 'Aktif' };
    const d = DATA.current;
    const ymax = Math.max(...YEARS.map((y) => y[1]));
    root.innerHTML = `
      <div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:16px">
        <div style="display:flex;flex-direction:column;gap:6px;flex:1 1 320px;min-width:0">
          <h1>Laporan Inventaris</h1>
          <p class="muted" style="margin:0;font-size:14px">Rekap dan gambaran umum kondisi inventaris Yayasan Vidatra.</p>
        </div>
        <a class="btn primary" data-pdf href="laporan-pdf.html"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M4 21h16"/></svg>Unduh PDF</a>
      </div>
      <section class="card" style="padding:16px 20px;gap:14px">
        <div class="filters">
          ${FILTERS.map(([k, label, opts, all]) => `<label class="field"><span>${label}</span><select data-f="${k}"><option value="">${all}</option>${opts.map((o) => `<option>${o}</option>`).join('')}</select></label>`).join('')}
          <button type="button" class="btn" data-reset>Reset semua</button>
        </div>
        <div class="chips" data-chips></div>
      </section>
      ${kpiHtml([['Total Aset', 394], ['Aset Aktif', 368], ['Aset Ditulis Off', 26], ['Lokasi Terdaftar', 4], ['Kategori Terdaftar', 6]])}
      <div class="row">
        ${stackCard('Aset per Sumber', sourceItems(d.sumber), 'third')}
        ${stackCard('Kondisi Aset', conditionItems(d.kondisi), 'third')}
        <section class="card third"><h2>Aset per Lokasi</h2>${barsHtml(d.lokasi)}</section>
      </div>
      <div class="row">
        <section class="card half"><h2>Aset per Kategori</h2>${barsHtml(d.kategori)}</section>
        <section class="card half"><h2>Aset per Tahun Perolehan</h2>
          <div class="cols" role="img" aria-label="${esc('Aset per tahun perolehan: ' + YEARS.map(([y, v]) => `${y} ${v}`).join(', '))}">
            ${YEARS.map(([y, v]) => `<div class="c" title="${y}: ${v} aset"><span class="num">${v}</span><div style="height:${v / ymax * 150}px"></div></div>`).join('')}
          </div>
          <div class="col-labels">${YEARS.map(([y]) => `<span>'${String(y).slice(2)}</span>`).join('')}</div>
        </section>
      </div>
      <div data-rooms></div>`;

    const chips = root.querySelector('[data-chips]');
    const selects = root.querySelectorAll('[data-f]');
    const sync = () => {
      const active = FILTERS.filter(([k]) => state[k]).map(([k, label]) => `${label}: ${state[k]}`);
      chips.innerHTML = '<span class="muted">Filter aktif:</span>' + (active.length
        ? active.map((c) => `<span class="chip">${esc(c)}</span>`).join('')
        : '<span class="muted">Tidak ada — menampilkan semua data</span>');
      selects.forEach((s) => { s.value = state[s.dataset.f] || ''; });
      // The PDF receives the same filters (shown there as a caption, never as controls).
      const qs = new URLSearchParams(Object.entries(state).filter(([, v]) => v));
      root.querySelector('[data-pdf]').href = 'laporan-pdf.html' + (qs.toString() ? `?${qs}` : '');
    };
    selects.forEach((s) => s.addEventListener('change', () => { state[s.dataset.f] = s.value; sync(); }));
    root.querySelector('[data-reset]').addEventListener('click', () => { Object.keys(state).forEach((k) => delete state[k]); sync(); });
    sync();
    mountRooms(root.querySelector('[data-rooms]'), { withFilters: false });
  }

  window.InventarisMockup = { renderDashboard, renderLaporan, mountThemeToggle, sourceItems, conditionItems, stackCard, barsHtml, esc };
})();
