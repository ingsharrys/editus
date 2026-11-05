{{-- Recuadro para firmar con el dedo o el mouse. $form: id del formulario; la firma va en el campo «firma» (data URL PNG). --}}
<div class="relative rounded-xl border-2 border-dashed border-gray-300 bg-white">
    <canvas id="firmaCanvas" class="block w-full h-48 touch-none cursor-crosshair rounded-xl"></canvas>
    <span id="firmaGuia" class="pointer-events-none absolute inset-x-0 bottom-10 mx-8 border-b border-gray-300 text-center text-[11px] text-gray-400 pb-1">{{ __('Firma aquí con el dedo o el mouse') }}</span>
</div>
<div class="mt-2 text-right"><button type="button" id="firmaBorrar" class="text-xs font-semibold text-gray-600 hover:text-rose-600">{{ __('Borrar y volver a firmar') }}</button></div>
<input type="hidden" name="firma" id="firmaInput">
<script>
(function () {
    const canvas = document.getElementById('firmaCanvas'), input = document.getElementById('firmaInput'), guia = document.getElementById('firmaGuia');
    const ctx = canvas.getContext('2d');
    let dibujando = false, hayTrazo = false, ultimo = null;
    const ajustar = () => {
        const r = canvas.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(r.width * dpr); canvas.height = Math.round(r.height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0b1f5c';
        hayTrazo = false; guia.classList.remove('hidden');
    };
    const punto = e => { const r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };
    canvas.addEventListener('pointerdown', e => { dibujando = true; ultimo = punto(e); canvas.setPointerCapture(e.pointerId); e.preventDefault(); });
    canvas.addEventListener('pointermove', e => {
        if (!dibujando) return;
        const p = punto(e);
        ctx.beginPath(); ctx.moveTo(ultimo.x, ultimo.y); ctx.lineTo(p.x, p.y); ctx.stroke();
        ultimo = p; hayTrazo = true; guia.classList.add('hidden');
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(t => canvas.addEventListener(t, () => { dibujando = false; }));
    document.getElementById('firmaBorrar').addEventListener('click', () => { ctx.clearRect(0, 0, canvas.width, canvas.height); hayTrazo = false; guia.classList.remove('hidden'); });
    window.addEventListener('resize', () => { if (!hayTrazo) ajustar(); });
    ajustar();
    document.getElementById(@js($form)).addEventListener('submit', e => {
        if (hayTrazo) { input.value = canvas.toDataURL('image/png'); return; }
        e.preventDefault(); alert(@js(__('Dibuja tu firma en el recuadro.'))); canvas.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
})();
</script>
