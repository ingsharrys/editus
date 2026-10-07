{{-- Gráficas del análisis avanzado, simulador y diagnóstico IA. Requiere Chart.js cargado, $av, $tablero, $diagnosticos y $ctx (alcance para las peticiones). --}}
<script>
(function () {
  const AV = @json($av);
  const TR = @json($tablero['tendencias']);
  const CTX = @json($ctx);
  const DIAGS = @json($diagnosticos);
  const COLORES_EMO = @json(\App\Services\Inteligencia\InteligenciaAvanzadaService::COLORES_EMOCION);
  const NOMBRES_EMO = @json(\App\Services\Inteligencia\ConsultorService::EMOCIONES);
  const g = (id) => document.getElementById(id);
  const fmt = (n) => n === null || n === undefined ? '—' : new Intl.NumberFormat('es-CO', { maximumFractionDigits: 1 }).format(n);
  const esc = (t) => String(t ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const base = { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { labels: { boxWidth: 10, font: { size: 11 } } } } };
  const sem = AV.semanal || [];

  if (g('avSemanal') && window.Chart) new Chart(g('avSemanal'), { type: 'bar', data: { labels: sem.map(s => s.etiqueta), datasets: [
      { label: 'Alcance de publicaciones', data: sem.map(s => s.alcance), backgroundColor: 'rgba(0,2,79,.78)', borderRadius: 4, yAxisID: 'y' },
      { label: 'Tasa de interacción %', data: sem.map(s => s.tasa), type: 'line', borderColor: '#dc2626', backgroundColor: '#dc2626', tension: .3, spanGaps: true, yAxisID: 'y1' },
      { label: 'Publicaciones', data: sem.map(s => s.publicaciones), type: 'line', borderColor: '#94a3b8', borderDash: [4, 4], pointRadius: 0, tension: .3, yAxisID: 'y2' } ] },
    options: { ...base, scales: { y: { ticks: { callback: fmt } }, y1: { position: 'right', grid: { drawOnChartArea: false }, beginAtZero: true }, y2: { display: false, beginAtZero: true } } } });

  if (g('avEmocionesSemana') && window.Chart) new Chart(g('avEmocionesSemana'), { type: 'bar', data: { labels: sem.map(s => s.etiqueta),
      datasets: Object.keys(NOMBRES_EMO).map(k => ({ label: NOMBRES_EMO[k], data: sem.map(s => s.emociones ? s.emociones[k] : null), backgroundColor: COLORES_EMO[k] })) },
    options: { ...base, scales: { x: { stacked: true }, y: { stacked: true, max: 100, ticks: { callback: v => v + ' %' } } } } });

  const co = AV.comportamiento || {};
  if (g('avMezcla') && window.Chart) new Chart(g('avMezcla'), { type: 'doughnut', data: { labels: (co.mezcla || []).map(m => m.nombre + ' ' + m.pct + ' %'),
      datasets: [{ data: (co.mezcla || []).map(m => m.n), backgroundColor: ['#00024f', '#6366f1', '#10b981', '#f59e0b'] }] },
    options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'right', labels: { boxWidth: 10, font: { size: 11 } } } } } });

  const barrasGrupo = (id, filas) => { if (!g(id) || !window.Chart) return; new Chart(g(id), { type: 'bar', data: { labels: filas.map(f => f.nombre.replace(/ \(.*\)/, '') + ' (' + f.n + ')'), datasets: [
      { label: 'Alcance mediano', data: filas.map(f => f.n ? f.mediana_alcance : null), backgroundColor: 'rgba(0,2,79,.78)', borderRadius: 4, yAxisID: 'y' },
      { label: 'Tasa de interacción %', data: filas.map(f => f.tasa), type: 'line', borderColor: '#dc2626', backgroundColor: '#dc2626', spanGaps: true, yAxisID: 'y1' } ] },
    options: { ...base, scales: { y: { ticks: { callback: fmt } }, y1: { position: 'right', grid: { drawOnChartArea: false }, beginAtZero: true } } } }); };
  barrasGrupo('avFranjas', co.franjas || []);
  barrasGrupo('avDias', co.dias || []);

  // Pronósticos: histórico + esperado (punteado) + banda del 80 %
  document.querySelectorAll('canvas[data-pronostico]').forEach(cv => {
    const p = AV.pronosticos[cv.dataset.pronostico]; if (!p || !p.disponible || !window.Chart) return;
    const labels = p.etiquetas.concat(p.futuro.map(f => f.etiqueta));
    const nH = p.historico.length, ult = (() => { for (let i = nH - 1; i >= 0; i--) if (p.historico[i] !== null) return i; return -1; })();
    const pad = (arr) => Array(nH).fill(null).concat(arr);
    const unir = (campo) => { const a = pad(p.futuro.map(f => f[campo])); if (ult >= 0) a[ult] = p.historico[ult]; return a; };
    new Chart(cv, { type: 'line', data: { labels, datasets: [
        { label: 'Real', data: p.historico.concat(Array(p.futuro.length).fill(null)), borderColor: '#00024f', backgroundColor: '#00024f', tension: .3, spanGaps: true, pointRadius: 2 },
        { label: 'Rango alto', data: unir('alto'), borderColor: 'transparent', backgroundColor: 'rgba(99,102,241,.15)', pointRadius: 0, fill: '+1' },
        { label: 'Rango bajo', data: unir('bajo'), borderColor: 'transparent', pointRadius: 0, fill: false },
        { label: 'Esperado', data: unir('esperado'), borderColor: '#6366f1', borderDash: [5, 4], pointRadius: 2, tension: .2 } ] },
      options: { ...base, plugins: { legend: { display: false } }, scales: { x: { ticks: { font: { size: 9 }, maxRotation: 0, autoSkip: true } }, y: { ticks: { callback: fmt, font: { size: 10 } } } } } });
  });

  if (g('gTendencia') && window.Chart && TR && TR.temas && TR.temas.length) { const labels = Array.from({ length: TR.semanas }, (_, i) => 'S-' + (TR.semanas - i));
    new Chart(g('gTendencia'), { type: 'line', data: { labels, datasets: TR.temas.map(t => ({ label: t.tema, data: t.serie, borderColor: t.color || '#94a3b8', backgroundColor: t.color || '#94a3b8', spanGaps: true, tension: .3 })) },
      options: { ...base, scales: { y: { beginAtZero: true, title: { display: true, text: 'Tasa de interacción %' } } } } }); }

  // ------------------------------------------------------------- simulador
  const sb = g('simBoton');
  if (sb) sb.addEventListener('click', async () => {
    const out = g('simResultado'); out.textContent = 'Calculando…';
    const q = new URLSearchParams();
    Object.entries(CTX).forEach(([k, v]) => { if (Array.isArray(v)) v.forEach(x => q.append(k + '[]', x)); else if (v !== null && v !== undefined && v !== '' && k !== 'desde' && k !== 'hasta') q.append(k, v); });
    [['meta_page_id', 'simPagina'], ['formato', 'simFormato'], ['franja', 'simFranja'], ['dia', 'simDia'], ['tema_id', 'simTema']].forEach(([k, id]) => { const v = g(id).value; if (v !== '') q.append(k, v); });
    try {
      const r = await fetch(@json(route('inteligencia.simular')) + '?' + q, { headers: { 'Accept': 'application/json' } });
      const d = await r.json();
      if (!d.suficiente) { out.innerHTML = '<span class="text-amber-700">Faltan datos: hay ' + (d.n || 0) + ' publicaciones con métricas en los últimos 90 días (se necesitan 5).</span>'; return; }
      out.innerHTML = '<div class="rounded-xl bg-gray-50 p-3"><div class="text-xs text-gray-500">Alcance esperado</div><div class="text-2xl font-bold">' + fmt(d.alcance.esperado) + '</div>'
        + '<div class="text-xs text-gray-500">rango probable ' + fmt(d.alcance.bajo) + ' – ' + fmt(d.alcance.alto) + '</div>'
        + '<div class="text-xs text-gray-500 mt-2">Interacciones esperadas</div><div class="text-xl font-bold">' + fmt(d.interacciones) + ' <span class="text-xs font-normal text-gray-500">(tasa ' + fmt(d.tasa) + ' %)</span></div>'
        + '<div class="mt-3 text-[11px] text-gray-500 space-y-0.5">' + '<div>Base: alcance mediano ' + fmt(d.base) + ' · ' + d.n + ' publicaciones · confianza ' + d.confianza + '</div>'
        + d.factores.map(f => '<div>' + esc(f.nombre) + ': <b class="' + (f.factor > 1.02 ? 'text-emerald-700' : (f.factor < 0.98 ? 'text-rose-600' : 'text-gray-700')) + '">×' + fmt(f.factor) + '</b>' + (f.nota ? ' (' + esc(f.nota) + ')' : (f.n ? ' · ' + f.n + ' publ.' : '')) + '</div>').join('')
        + '<div>Factor total ×' + fmt(d.factor_total) + '</div></div></div>';
    } catch (e) { out.textContent = 'No se pudo calcular.'; }
  });

  // -------------------------------------------------------- diagnóstico IA
  const cont = g('diag-contenido');
  if (!cont) return;
  const chip = (t, cls) => '<span class="inline-flex rounded-md px-1.5 py-0.5 text-[11px] font-semibold ' + cls + '">' + esc(t) + '</span>';
  const prio = { alta: 'bg-rose-50 text-rose-700', media: 'bg-amber-50 text-amber-800', baja: 'bg-gray-100 text-gray-600' };
  const card = (titulo, cuerpo, extra) => '<div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm min-w-0 ' + (extra || '') + '"><h3 class="font-bold text-gray-900 mb-3">' + titulo + '</h3>' + cuerpo + '</div>';
  const fecha = (f) => f ? String(f).split('-').reverse().join('/') : '';
  const lista = (arr) => (arr && arr.length) ? '<ul class="list-disc ml-5 space-y-1 text-sm text-gray-700">' + arr.map(x => '<li>' + esc(x) + '</li>').join('') + '</ul>' : '<p class="text-sm text-gray-400">—</p>';
  const pintar = (d) => {
    const r = d.resultado || {}; const e = r.estado || {}; const em = r.emociones || {}; const pr = r.pronostico || {};
    const est = { bueno: ['bg-emerald-50 border-emerald-200 text-emerald-900', '●'], atencion: ['bg-amber-50 border-amber-200 text-amber-900', '●'], critico: ['bg-rose-50 border-rose-200 text-rose-900', '●'] }[e.nivel] || ['bg-gray-50 border-gray-200 text-gray-800', '●'];
    let h = '<div class="rounded-2xl border ' + est[0] + ' p-5 mb-5"><div class="text-[11px] font-semibold uppercase tracking-[0.14em] opacity-70">' + esc(d.enfoque_nombre) + ' · ' + esc(fecha((d.ambito || {}).desde)) + ' a ' + esc(fecha((d.ambito || {}).hasta)) + ' · ' + esc(d.fecha) + (d.usuario ? ' · ' + esc(d.usuario) : '') + '</div>'
      + '<div class="text-xl font-bold mt-1">' + est[1] + ' ' + esc(e.titulo || '') + '</div><p class="text-sm mt-1">' + esc(e.motivo || '') + '</p>'
      + '<p class="text-[15px] leading-relaxed mt-3 text-gray-800">' + esc(r.resumen_ejecutivo || '') + '</p></div>';
    h += '<div class="grid lg:grid-cols-3 gap-5 mb-5">'
      + card('Emociones del público', '<div class="text-sm text-gray-700">' + esc(em.lectura) + '</div><div class="mt-3 flex flex-wrap gap-2">' + chip('Dominante: ' + (em.emocion_dominante || '—'), 'bg-indigo-50 text-indigo-800') + chip('Riesgo reputacional: ' + (em.riesgo_reputacional || '—'), prio[em.riesgo_reputacional] || prio.baja) + '</div>'
          + '<div class="text-xs font-semibold text-gray-500 mt-4 mb-1">Qué la provoca</div>' + lista(em.que_la_provoca) + '<div class="text-xs font-semibold text-gray-500 mt-3 mb-1">Cómo responder</div>' + lista(em.como_responder))
      + card('Comportamientos detectados', (r.comportamientos || []).map(c => '<div class="py-2 border-t border-gray-100 first:border-0"><div class="text-sm font-semibold text-gray-800">' + esc(c.titulo) + '</div><div class="text-sm text-gray-600">' + esc(c.detalle) + '</div><div class="text-[11px] text-gray-400 mt-0.5">Evidencia: ' + esc(c.evidencia) + '</div></div>').join('') || '<p class="text-sm text-gray-400">—</p>')
      + card('Tendencias', (r.tendencias || []).map(t => '<div class="py-2 border-t border-gray-100 first:border-0"><div class="text-sm font-semibold text-gray-800">' + (t.direccion === 'sube' ? '<span class="text-emerald-600">▲</span> ' : t.direccion === 'baja' ? '<span class="text-rose-600">▼</span> ' : '<span class="text-gray-400">=</span> ') + esc(t.titulo) + '</div><div class="text-sm text-gray-600">' + esc(t.detalle) + '</div></div>').join('') || '<p class="text-sm text-gray-400">—</p>')
      + '</div>';
    h += card('Pronóstico a 4 semanas <span class="text-xs font-normal text-gray-500">· confianza ' + esc(pr.confianza || '—') + '</span>', '<p class="text-sm text-gray-700 mb-4">' + esc(pr.lectura) + '</p><div class="grid md:grid-cols-3 gap-3">'
        + [['Optimista', pr.escenario_optimista, 'border-emerald-200 bg-emerald-50/50'], ['Base', pr.escenario_base, 'border-indigo-200 bg-indigo-50/50'], ['De riesgo', pr.escenario_riesgo, 'border-rose-200 bg-rose-50/50']].map(([n, t, c]) => '<div class="rounded-xl border ' + c + ' p-3"><div class="text-xs font-bold uppercase tracking-wide text-gray-500">Escenario ' + n + '</div><div class="text-sm text-gray-800 mt-1">' + esc(t) + '</div></div>').join('') + '</div>', 'mb-5');
    h += '<div class="grid lg:grid-cols-2 gap-5 mb-5">'
      + card('Oportunidades', (r.oportunidades || []).map(o => '<div class="py-2 border-t border-gray-100 first:border-0"><div class="flex items-center justify-between gap-2"><span class="text-sm font-semibold text-gray-800">' + esc(o.titulo) + '</span>' + chip('impacto ' + o.impacto, prio[o.impacto] || prio.baja) + '</div><div class="text-sm text-gray-600">' + esc(o.detalle) + '</div></div>').join('') || '<p class="text-sm text-gray-400">—</p>')
      + card('Riesgos', (r.riesgos || []).map(o => '<div class="py-2 border-t border-gray-100 first:border-0"><div class="text-sm font-semibold text-gray-800">' + esc(o.titulo) + '</div><div class="text-sm text-gray-600">' + esc(o.detalle) + '</div><div class="text-xs text-indigo-700 mt-0.5">Mitigación: ' + esc(o.mitigacion) + '</div></div>').join('') || '<p class="text-sm text-gray-400">—</p>')
      + '</div>';
    h += card('Plan de acción', '<div class="overflow-x-auto"><table class="w-full text-sm min-w-[760px]"><thead class="text-gray-400 text-[11px] uppercase tracking-wide"><tr><th class="text-left py-1 pr-3">Acción</th><th class="text-left pr-3">Por qué</th><th class="text-left pr-3">Prioridad</th><th class="text-left pr-3">Plazo</th><th class="text-left pr-3">KPI</th><th class="text-left">Meta</th></tr></thead><tbody>'
        + (r.acciones || []).map(a => '<tr class="border-t border-gray-100 align-top"><td class="py-2 pr-3 font-semibold text-gray-800">' + esc(a.accion) + '</td><td class="py-2 pr-3 text-gray-600">' + esc(a.por_que) + '</td><td class="py-2 pr-3">' + chip(a.prioridad, prio[a.prioridad] || prio.baja) + '</td><td class="py-2 pr-3 text-gray-600">' + esc(a.plazo) + '</td><td class="py-2 pr-3 text-gray-600">' + esc(a.kpi) + '</td><td class="py-2 text-gray-800">' + esc(a.meta) + '</td></tr>').join('')
        + '</tbody></table></div>', 'mb-5');
    h += '<h3 class="font-bold text-gray-900 mb-3">Plan de publicaciones de la semana</h3><div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4 mb-5">'
      + (r.plan_semana || []).map((p, i) => '<div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm flex flex-col"><div class="flex flex-wrap gap-1.5 mb-2">' + chip(p.dia + ' · ' + p.hora, 'bg-[#00024f] text-white') + chip(p.red, 'bg-gray-100 text-gray-700') + chip(p.formato, 'bg-indigo-50 text-indigo-800') + chip(p.tema, 'bg-amber-50 text-amber-800') + '</div>'
          + '<div class="font-semibold text-gray-900">' + esc(p.titulo) + '</div><p class="text-sm text-gray-700 mt-1 whitespace-pre-line flex-1" data-texto="' + i + '">' + esc(p.texto) + '</p>'
          + '<div class="text-[11px] text-gray-500 mt-2">Objetivo: ' + esc(p.objetivo) + '</div><button type="button" data-copiar="' + i + '" class="mt-3 self-start rounded-lg border border-gray-200 px-3 py-1 text-xs text-gray-700 hover:bg-gray-50 print:hidden">Copiar texto</button></div>').join('')
      + '</div>';
    if ((r.datos_faltantes || []).length) h += '<div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><b>Para un diagnóstico más preciso falta:</b>' + lista(r.datos_faltantes) + '</div>';
    cont.innerHTML = h;
    cont.querySelectorAll('[data-copiar]').forEach(b => b.addEventListener('click', () => { const t = (r.plan_semana[+b.dataset.copiar] || {}); navigator.clipboard?.writeText((t.titulo ? t.titulo + '\n\n' : '') + (t.texto || '')); b.textContent = 'Copiado ✓'; setTimeout(() => b.textContent = 'Copiar texto', 1800); }));
  };
  const porId = {}; DIAGS.forEach(d => porId[d.id] = d);
  if (DIAGS.length) pintar(DIAGS[0]);
  const hist = g('diag-historial');
  if (hist) hist.addEventListener('change', () => porId[hist.value] && pintar(porId[hist.value]));

  const bd = g('diag-boton');
  if (bd) bd.addEventListener('click', async () => {
    const estado = g('diag-estado'); estado.classList.remove('hidden', 'border-rose-200', 'bg-rose-50', 'text-rose-800');
    bd.disabled = true; let s = 0;
    const reloj = setInterval(() => { s++; estado.textContent = '✦ La IA está analizando los indicadores y redactando el diagnóstico… ' + s + ' s'; }, 1000);
    estado.textContent = '✦ La IA está analizando los indicadores y redactando el diagnóstico…';
    try {
      const r = await fetch(@json(route('inteligencia.diagnostico')), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ ...CTX, enfoque: AV.enfoque }) });
      const d = await r.json().catch(() => ({ success: false, error: 'El servidor tardó demasiado o respondió con un error (' + r.status + ').' }));
      if (!d.success) throw new Error(d.error || (d.message ?? 'Error'));
      porId[d.diagnostico.id] = d.diagnostico; pintar(d.diagnostico);
      if (hist) { const o = document.createElement('option'); o.value = d.diagnostico.id; o.textContent = d.diagnostico.fecha + ' · ' + d.diagnostico.enfoque_nombre + ' (nuevo)'; hist.prepend(o); hist.value = d.diagnostico.id; }
      estado.textContent = '✓ Diagnóstico listo en ' + s + ' s.';
    } catch (e) {
      estado.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-800'); estado.textContent = e.message || 'No se pudo generar el diagnóstico.';
    } finally { clearInterval(reloj); bd.disabled = false; }
  });
})();
</script>
