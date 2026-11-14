{{--
    Logo y membrete livianos antes de subirlos: máximo 400 px de ancho y 100 KB, en WebP (o JPG si el
    navegador o el servidor no tienen WebP). El servidor lo vuelve a revisar (App\Services\ImagenLigera).
    Uso: <input type="file" data-imagen-ligera="logo"> con #logoPrev (img), #logoTxt (texto) y #logoInfo (estado).
    $form: id del formulario (no deja enviarlo mientras se prepara una imagen, ni si pasa del límite del servidor).
--}}
@php
    $limitePost = (function () {
        $v = trim((string) ini_get('post_max_size'));
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    })();
@endphp
<script>
(function () {
    const ANCHO = {{ \App\Services\ImagenLigera::ANCHO }}, MAX = {{ \App\Services\ImagenLigera::MAX_BYTES }}, LIMITE_POST = {{ $limitePost }};
    const TX = {
        preparando: @js(__('Preparando la imagen…')),
        lista: @js(__(':archivo · :kb KB · lista')),
        noLee: @js(__('No se pudo leer esa imagen. Usa un PNG, JPG o WebP.')),
        pesada: @js(__('No se pudo reducir la imagen a 100 KB. Usa una imagen más sencilla.')),
        espera: @js(__('Espera un momento: se está preparando la imagen.')),
        muyGrande: @js(__('Los archivos suman :mb MB y el servidor recibe máximo :max MB. Adjunta el RUT y el certificado bancario más livianos (por ejemplo, en PDF).')),
    };
    const txt = (t, v) => t.replace(/:(\w+)/g, (_, k) => v[k] ?? '');
    // WebP solo si el servidor lo puede leer (para el PDF) y el navegador lo sabe escribir (Safari viejo no)
    const WEBP = @js(\App\Services\ImagenLigera::webpDisponible()) && (() => { try { return document.createElement('canvas').toDataURL('image/webp').startsWith('data:image/webp'); } catch { return false; } })();
    let pendientes = 0;

    const cargar = file => new Promise((ok, mal) => {
        const u = URL.createObjectURL(file), i = new Image();
        i.onload = () => ok(i); i.onerror = () => { URL.revokeObjectURL(u); mal(new Error(TX.noLee)); }; i.src = u;
    });
    async function decodificar(file) {
        if (window.createImageBitmap) { try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (e) { /* se intenta con <img> */ } }
        return cargar(file);
    }
    const aBlob = (c, tipo, q) => new Promise(ok => c.toBlob(ok, tipo, q));

    /** Devuelve un File de máximo ANCHO px y MAX bytes (WebP o JPG). */
    async function aligerar(file) {
        const img = await decodificar(file);
        const w0 = img.naturalWidth || img.width, h0 = img.naturalHeight || img.height;
        if (!w0 || !h0) throw new Error(TX.noLee);
        if (file.type === (WEBP ? 'image/webp' : 'image/jpeg') && w0 <= ANCHO && file.size <= MAX) return file;
        const tipo = WEBP ? 'image/webp' : 'image/jpeg';
        const base = (file.name || 'imagen').replace(/\.[^.]+$/, '').slice(0, 80) || 'imagen';
        let escala = Math.min(1, ANCHO / w0);
        for (let intento = 0; intento < 8; intento++) {
            const w = Math.max(1, Math.round(w0 * escala)), h = Math.max(1, Math.round(h0 * escala));
            const c = document.createElement('canvas'); c.width = w; c.height = h;
            const ctx = c.getContext('2d');
            ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
            if (tipo === 'image/jpeg') { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h); } // JPG: lo transparente queda blanco
            ctx.drawImage(img, 0, 0, w, h);
            for (const q of [0.9, 0.8, 0.7, 0.6, 0.5, 0.4]) {
                const b = await aBlob(c, tipo, q);
                if (b && b.type === tipo && b.size <= MAX) return new File([b], base + (tipo === 'image/webp' ? '.webp' : '.jpg'), { type: tipo, lastModified: Date.now() });
            }
            escala *= 0.8;
        }
        throw new Error(TX.pesada);
    }

    document.querySelectorAll('input[type=file][data-imagen-ligera]').forEach(input => {
        const id = input.dataset.imagenLigera, prev = document.getElementById(id + 'Prev'), vacio = document.getElementById(id + 'Txt'), info = document.getElementById(id + 'Info');
        const estado = (t, error = false) => { if (!info) return; info.textContent = t; info.classList.toggle('hidden', !t); info.classList.toggle('text-rose-600', error); info.classList.toggle('text-gray-500', !error); };
        input.addEventListener('change', async () => {
            const f = input.files && input.files[0];
            if (!f) return;
            pendientes++; estado(TX.preparando);
            try {
                let lista = f;
                try { lista = await aligerar(f); } catch (e) { input.value = ''; estado(e.message || TX.noLee, true); return; }
                if (lista !== f) {
                    // Si el navegador no deja cambiar el archivo, se sube el original y el servidor lo reduce
                    try { const dt = new DataTransfer(); dt.items.add(lista); input.files = dt.files; } catch (e) { lista = f; }
                }
                if (prev) {
                    const r = new FileReader();
                    r.onload = () => { prev.src = r.result; prev.classList.remove('hidden'); if (vacio) vacio.classList.add('hidden'); };
                    r.readAsDataURL(lista);
                }
                estado(txt(TX.lista, { archivo: lista.name, kb: Math.max(1, Math.round(lista.size / 1024)) }));
                const quitar = document.querySelector('[name="quitar_' + id + '"]'); if (quitar) quitar.checked = false;
            } finally { pendientes--; }
        });
    });

    const form = document.getElementById(@js($form));
    if (form) form.addEventListener('submit', e => {
        if (pendientes > 0) { e.preventDefault(); e.stopImmediatePropagation(); alert(TX.espera); return; }
        if (!LIMITE_POST) return;
        let total = 0;
        form.querySelectorAll('input[type=file]').forEach(i => { for (const f of (i.files || [])) total += f.size; });
        if (total > LIMITE_POST * 0.95) {
            e.preventDefault(); e.stopImmediatePropagation();
            alert(txt(TX.muyGrande, { mb: (total / 1048576).toFixed(1), max: Math.floor(LIMITE_POST / 1048576) }));
        }
    }, true); // antes que los demás (la firma): si no se envía, que no se pierda nada
})();
</script>
