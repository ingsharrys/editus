{{--
  Consultor de IA: pregunta libre (política o comercial) sobre los datos del alcance actual.
  Variables: $consultas (historial), $ejemplosConsulta, $iaLista, $contextoConsulta (campana_id | medio, paginas, desde, hasta), $tituloAlcance
--}}
<div class="grid lg:grid-cols-[minmax(0,1fr)_20rem] gap-5 items-start" id="consultor">
    <div class="space-y-5 min-w-0">
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
            <div class="flex items-start justify-between gap-3 mb-3">
                <div>
                    <h3 class="text-base font-bold text-gray-900">Consultor de IA</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Pregunta lo que quieras saber del público de <strong>{{ $tituloAlcance }}</strong> en el periodo elegido: temas políticos, comerciales, emociones, qué publicar. La IA responde solo con los datos recolectados y nunca sobre personas individuales.</p>
                </div>
                <span class="shrink-0 rounded-full bg-indigo-50 text-indigo-700 px-2.5 py-1 text-[11px] font-semibold">Claude</span>
            </div>
            @unless ($iaLista)
                <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-3 text-sm">La IA no está configurada: falta <code>ANTHROPIC_API_KEY</code> en el <code>.env</code> de editus.</div>
            @endunless
            <textarea id="con-pregunta" rows="3" maxlength="1500" class="w-full rounded-xl border-gray-200 text-sm shadow-sm focus:border-indigo-300 focus:ring-indigo-200" placeholder="Ej: ¿Qué emociones predominan frente a la seguridad y qué publicaciones recomiendas para esta semana?"></textarea>
            <div class="flex flex-wrap items-center justify-between gap-2 mt-3">
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($ejemplosConsulta as $grupo => $lista)
                        <details class="relative">
                            <summary class="list-none cursor-pointer select-none rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ $grupo }} ▾</summary>
                            <div class="absolute z-30 mt-1 w-80 rounded-xl border border-gray-200 bg-white shadow-xl p-1.5 text-xs">
                                @foreach ($lista as $ej)
                                    <button type="button" class="con-ejemplo block w-full text-left rounded-lg px-2.5 py-2 hover:bg-gray-50 text-gray-700">{{ $ej }}</button>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </div>
                <button type="button" id="con-enviar" class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold shadow-sm hover:opacity-90 disabled:opacity-50" @disabled(!$iaLista)>✦ Consultar</button>
            </div>
            <p id="con-estado" class="hidden text-xs text-gray-500 mt-2"></p>
        </div>

        <div id="con-respuesta" class="hidden space-y-5"></div>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 min-w-0">
        <h3 class="text-sm font-bold text-gray-900 mb-2">Consultas anteriores</h3>
        <div id="con-historial" class="divide-y divide-gray-100 text-sm">
            @forelse ($consultas as $c)
                <button type="button" class="con-item block w-full text-left py-2.5 hover:bg-gray-50 rounded-lg px-1" data-consulta="{{ json_encode($c, JSON_UNESCAPED_UNICODE) }}">
                    <div class="text-gray-800 line-clamp-2">{{ $c['pregunta'] }}</div>
                    <div class="text-[11px] text-gray-400 mt-0.5">{{ $c['fecha'] }}{{ $c['usuario'] ? ' · ' . $c['usuario'] : '' }}{{ !empty($c['ambito']['titulo']) ? ' · ' . $c['ambito']['titulo'] : '' }}</div>
                </button>
            @empty
                <p class="text-xs text-gray-500 py-2" id="con-vacio">Todavía no has hecho consultas.</p>
            @endforelse
        </div>
    </div>
</div>

<script>
(function () {
  const g = (id) => document.getElementById(id);
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const contexto = @json($contextoConsulta);
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const tonoClase = { favorable: 'bg-emerald-50 text-emerald-700', dividido: 'bg-amber-50 text-amber-700', desfavorable: 'bg-rose-50 text-rose-700' };
  const prioClase = { alta: 'bg-rose-50 text-rose-700', media: 'bg-amber-50 text-amber-700', baja: 'bg-gray-100 text-gray-600' };
  const tarjeta = (titulo, cuerpo, extra) => '<div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 min-w-0"><div class="flex items-center justify-between gap-2 mb-2"><h3 class="text-sm font-bold text-gray-900">' + titulo + '</h3>' + (extra || '') + '</div>' + cuerpo + '</div>';
  const lista = (arr, clase) => arr && arr.length ? '<ul class="space-y-1.5 text-sm text-gray-700">' + arr.map(x => '<li class="flex gap-2"><span class="' + (clase || 'text-indigo-500') + '">•</span><span>' + esc(x) + '</span></li>').join('') + '</ul>' : '<p class="text-sm text-gray-400">Nada que señalar.</p>';

  function pintar(c) {
    const r = c.respuesta || {}; const d = r.diagnostico_emocional || {};
    let html = '<div class="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-5"><div class="text-[11px] font-semibold uppercase tracking-wide text-indigo-600 mb-1">Pregunta · ' + esc(c.fecha || '') + (c.ambito && c.ambito.titulo ? ' · ' + esc(c.ambito.titulo) : '') + '</div><div class="text-sm font-semibold text-gray-900 mb-3">' + esc(c.pregunta) + '</div><div class="text-sm text-gray-800 whitespace-pre-line leading-relaxed">' + esc(r.respuesta || '') + '</div></div>';
    const tono = d.tono || 'sin datos';
    const emos = (d.emociones || []).map(e => '<div class="py-1.5"><div class="flex justify-between text-sm"><span class="font-medium text-gray-800">' + esc(e.emocion) + '</span><span class="text-gray-500">' + e.peso + '%</span></div><div class="h-1.5 rounded bg-gray-100"><div class="h-1.5 rounded bg-[#00024f]" style="width:' + e.peso + '%"></div></div><div class="text-[11px] text-gray-500 mt-0.5">' + esc(e.evidencia) + '</div></div>').join('');
    html += '<div class="grid lg:grid-cols-2 gap-5">'
      + tarjeta('Diagnóstico emocional del público', '<p class="text-sm text-gray-700 mb-3">' + esc(d.resumen || '') + '</p>' + (emos || '<p class="text-sm text-gray-400">Sin lectura de emociones: analiza comentarios primero.</p>'), '<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold ' + (tonoClase[tono] || 'bg-gray-100 text-gray-600') + '">' + esc(tono) + '</span>')
      + tarjeta('Hallazgos', lista(r.hallazgos))
      + '</div>';
    const recs = (r.recomendaciones || []).map(x => '<div class="py-2.5 border-b border-gray-100 last:border-0"><div class="flex items-start justify-between gap-2"><div class="text-sm font-semibold text-gray-900">' + esc(x.accion) + '</div><span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ' + (prioClase[x.prioridad] || prioClase.media) + '">' + esc(x.prioridad) + '</span></div><div class="text-xs text-gray-600 mt-0.5">' + esc(x.por_que) + '</div>' + (x.plazo ? '<div class="text-[11px] text-gray-400 mt-0.5">Plazo: ' + esc(x.plazo) + '</div>' : '') + '</div>').join('');
    html += tarjeta('Cómo actuar', recs || '<p class="text-sm text-gray-400">Sin recomendaciones.</p>');
    const pubs = (r.publicaciones_sugeridas || []).map((x, i) => '<div class="rounded-xl border border-gray-200 p-4"><div class="flex flex-wrap items-center gap-1.5 mb-1.5"><span class="rounded-md bg-gray-100 text-gray-600 px-1.5 py-0.5 text-[11px] font-semibold">' + (i + 1) + '</span><span class="rounded-md bg-indigo-50 text-indigo-700 px-1.5 py-0.5 text-[11px] font-semibold">' + esc(x.formato) + '</span><span class="rounded-md bg-sky-50 text-sky-700 px-1.5 py-0.5 text-[11px] font-semibold">' + esc(x.red) + '</span>' + (x.tema ? '<span class="rounded-md bg-gray-100 text-gray-600 px-1.5 py-0.5 text-[11px]">' + esc(x.tema) + '</span>' : '') + '</div><div class="text-sm font-bold text-gray-900">' + esc(x.titulo) + '</div><p class="text-sm text-gray-700 mt-1 whitespace-pre-line">' + esc(x.texto) + '</p><div class="text-[11px] text-gray-500 mt-2">🕒 ' + esc(x.mejor_momento) + ' · 🎯 ' + esc(x.resultado_esperado) + '</div><button type="button" class="con-copiar mt-2 text-xs text-indigo-700 hover:underline" data-texto="' + esc(x.texto) + '">Copiar texto</button></div>').join('');
    html += tarjeta('Publicaciones sugeridas para la próxima semana', pubs ? '<div class="grid md:grid-cols-2 gap-3">' + pubs + '</div>' : '<p class="text-sm text-gray-400">Sin propuestas.</p>');
    if ((r.riesgos || []).length || (r.datos_faltantes || []).length) html += '<div class="grid lg:grid-cols-2 gap-5">' + tarjeta('Riesgos', lista(r.riesgos, 'text-rose-500')) + tarjeta('Datos que faltan para afinar', lista(r.datos_faltantes, 'text-amber-500')) + '</div>';
    const cont = g('con-respuesta'); cont.innerHTML = html; cont.classList.remove('hidden');
    cont.querySelectorAll('.con-copiar').forEach(b => b.addEventListener('click', () => { navigator.clipboard.writeText(b.dataset.texto).then(() => { b.textContent = 'Copiado ✓'; }); }));
    cont.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function agregarHistorial(c) {
    const h = g('con-historial'); const vacio = g('con-vacio'); if (vacio) vacio.remove();
    const b = document.createElement('button'); b.type = 'button'; b.className = 'con-item block w-full text-left py-2.5 hover:bg-gray-50 rounded-lg px-1'; b.dataset.consulta = JSON.stringify(c);
    b.innerHTML = '<div class="text-gray-800 line-clamp-2">' + esc(c.pregunta) + '</div><div class="text-[11px] text-gray-400 mt-0.5">' + esc(c.fecha) + (c.usuario ? ' · ' + esc(c.usuario) : '') + '</div>';
    b.addEventListener('click', () => pintar(JSON.parse(b.dataset.consulta)));
    h.prepend(b);
  }

  document.querySelectorAll('.con-item').forEach(b => b.addEventListener('click', () => pintar(JSON.parse(b.dataset.consulta))));
  document.querySelectorAll('.con-ejemplo').forEach(b => b.addEventListener('click', () => { g('con-pregunta').value = b.textContent.trim(); b.closest('details').open = false; g('con-pregunta').focus(); }));
  document.addEventListener('click', e => { document.querySelectorAll('#consultor details[open]').forEach(d => { if (!d.contains(e.target)) d.open = false; }); });

  const enviar = g('con-enviar');
  if (enviar) enviar.addEventListener('click', async () => {
    const pregunta = g('con-pregunta').value.trim();
    if (pregunta.length < 5) { g('con-pregunta').focus(); return; }
    enviar.disabled = true; enviar.textContent = 'Analizando…'; const est = g('con-estado'); est.classList.remove('hidden'); est.textContent = 'La IA está leyendo los datos del periodo. Suele tardar entre 20 y 60 segundos.';
    try {
      const r = await fetch(@json(route('inteligencia.consultar')), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(Object.assign({ pregunta }, contexto)) });
      const d = await r.json();
      if (!d.success) { est.textContent = d.error || (d.message ? d.message : 'No se pudo consultar.'); est.classList.add('text-rose-600'); }
      else { est.classList.add('hidden'); est.classList.remove('text-rose-600'); pintar(d.consulta); agregarHistorial(d.consulta); }
    } catch (e) { est.textContent = 'Se perdió la conexión con el servidor. Inténtalo de nuevo.'; est.classList.add('text-rose-600'); }
    enviar.disabled = false; enviar.textContent = '✦ Consultar';
  });
})();
</script>
