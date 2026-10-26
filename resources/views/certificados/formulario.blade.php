@extends('layouts.app')

@section('content')
@php
    $elegidas = array_map('intval', old('paginas', (array) ($p->meta_page_ids ?? [])));
    $lbl = 'block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1';
    $inp = 'w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm';
    $paso = fn($n) => '<span class="h-6 w-6 rounded-full bg-[#00024f] text-white text-xs font-bold flex items-center justify-center shrink-0">' . $n . '</span>';
@endphp
<div class="max-w-3xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">{{ __('Campaña') }} · {{ $c->name }}</p>
        <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight mt-1">{{ __('Certificado de publicación de tu medio') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('Llena los datos de tu medio, acepta los términos y firma. Con esto se genera el certificado; la tabla de piezas se llena sola con cada publicación de la campaña en tu medio.') }}</p>
    </div>

    @if (session('aviso'))<div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">{{ session('aviso') }}</div>@endif
    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <form method="POST" action="{{ route('certificados.guardar', $c) }}" enctype="multipart/form-data" id="certForm" class="space-y-5">
        @csrf

        {{-- 1. Medio --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
            <div class="flex items-center gap-2">{!! $paso(1) !!}<h2 class="text-base font-bold text-gray-900">{{ __('Datos del medio') }}</h2></div>
            <div class="grid sm:grid-cols-[minmax(0,1fr)_12rem] gap-4">
                <div class="space-y-3">
                    <div><label class="{{ $lbl }}">{{ __('Nombre del medio') }} *</label><input name="medio_nombre" required maxlength="190" value="{{ old('medio_nombre', $p->medio_nombre) }}" placeholder="{{ __('Ej: Canal 6 de Alfasurt Pitalito') }}" class="{{ $inp }}"></div>
                    <div><label class="{{ $lbl }}">{{ __('Ciudad donde se expide') }} *</label><input name="ciudad" required maxlength="120" value="{{ old('ciudad', $p->ciudad) }}" placeholder="{{ __('Ej: Pitalito (Huila)') }}" class="{{ $inp }}"></div>
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('Logo del medio') }} {{ $logo ? '' : '*' }}</label>
                    <label class="flex flex-col items-center justify-center gap-1 h-32 rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 cursor-pointer hover:border-indigo-300 overflow-hidden">
                        <img id="logoPrev" src="{{ $logo }}" alt="" class="{{ $logo ? '' : 'hidden' }} max-h-24 max-w-[90%] object-contain">
                        <span id="logoTxt" class="{{ $logo ? 'hidden' : '' }} text-xs text-gray-500 text-center px-2">{{ __('Toca para subir (PNG o JPG, máx. 3 MB)') }}</span>
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="hidden" {{ $logo ? '' : 'required' }}
                               onchange="const f=this.files[0]; if(!f) return; const i=document.getElementById('logoPrev'); i.src=URL.createObjectURL(f); i.classList.remove('hidden'); document.getElementById('logoTxt').classList.add('hidden');">
                    </label>
                </div>
            </div>
        </div>

        {{-- 2. Representante --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-3">
            <div class="flex items-center gap-2">{!! $paso(2) !!}<h2 class="text-base font-bold text-gray-900">{{ __('Quién firma') }}</h2></div>
            <div><label class="{{ $lbl }}">{{ __('Nombre completo') }} *</label><input name="representante" required maxlength="190" value="{{ old('representante', $p->representante) }}" class="{{ $inp }}"></div>
            <div class="grid sm:grid-cols-[8rem_minmax(0,1fr)_minmax(0,1fr)] gap-3">
                <div><label class="{{ $lbl }}">{{ __('Documento') }} *</label>
                    <select name="documento_tipo" class="{{ $inp }}">@foreach ($documentos as $d)<option value="{{ $d }}" @selected(old('documento_tipo', $p->documento_tipo ?: 'C.C.') === $d)>{{ $d }}</option>@endforeach</select></div>
                <div><label class="{{ $lbl }}">{{ __('Número') }} *</label><input name="documento" required maxlength="40" inputmode="numeric" value="{{ old('documento', $p->documento) }}" class="{{ $inp }}"></div>
                <div><label class="{{ $lbl }}">{{ __('Cargo') }} *</label><input name="cargo" required maxlength="120" value="{{ old('cargo', $p->cargo) }}" placeholder="{{ __('Ej: Gerente') }}" class="{{ $inp }}"></div>
            </div>
        </div>

        {{-- 3. Páginas del medio --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
            <div class="flex items-center gap-2 mb-1">{!! $paso(3) !!}<h2 class="text-base font-bold text-gray-900">{{ __('Páginas de tu medio') }}</h2></div>
            <p class="text-xs text-gray-500 mb-3 ml-8">{{ __('Las publicaciones de la campaña en estas páginas llenan la tabla de tu certificado. Si no eliges ninguna, se usan todas las que tengas conectadas.') }}</p>
            @if ($paginas->isEmpty())
                <div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 text-sm">
                    {{ __('Aún no tienes páginas de Facebook conectadas a editus.') }}
                    @if (Route::has('facebook.connect') && auth()->user()->tienePermiso('paginas'))
                        <a href="{{ route('facebook.connect') }}" class="font-semibold underline">{{ __('Conectar mis páginas') }}</a>
                    @endif
                    <span class="block text-xs mt-1">{{ __('Puedes firmar ahora y conectarlas después.') }}</span>
                </div>
            @else
                <div class="grid sm:grid-cols-2 gap-2">
                    @foreach ($paginas as $pg)
                        <label class="flex items-center gap-2 rounded-xl border border-gray-200 px-3 py-2 text-sm cursor-pointer hover:border-gray-300 has-[:checked]:border-indigo-300 has-[:checked]:bg-indigo-50/40">
                            <input type="checkbox" name="paginas[]" value="{{ $pg->id }}" class="h-4 w-4 rounded border-gray-300 text-indigo-600" @checked(in_array((int) $pg->id, $elegidas, true))>
                            @if ($pg->picture_url)<img src="{{ $pg->picture_url }}" alt="" class="h-6 w-6 rounded-full">@endif
                            <span class="truncate">{{ $pg->name }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- 4. Términos --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
            <div class="flex items-center gap-2 mb-3">{!! $paso(4) !!}<h2 class="text-base font-bold text-gray-900">{{ __('Términos y condiciones') }}</h2></div>
            <div class="max-h-56 overflow-y-auto rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 whitespace-pre-line">{{ $terminos }}</div>
            <p class="text-xs text-gray-500 mt-2">{!! __('Lee también los <a href=":t" target="_blank" class="underline">términos de uso</a> y la <a href=":p" target="_blank" class="underline">política de privacidad</a> de editus.', ['t' => route('terms'), 'p' => route('privacy')]) !!}</p>
            <label class="mt-3 flex items-start gap-2 text-sm font-medium text-gray-900">
                <input type="checkbox" name="acepto_terminos" value="1" required class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600" @checked(old('acepto_terminos'))>
                <span>{{ __('He leído y acepto los términos y condiciones.') }}</span>
            </label>
        </div>

        {{-- 5. Firma --}}
        <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
            <div class="flex items-center justify-between gap-2 mb-3">
                <div class="flex items-center gap-2">{!! $paso(5) !!}<h2 class="text-base font-bold text-gray-900">{{ __('Firma digital') }}</h2></div>
                <button type="button" id="firmaBorrar" class="text-xs font-semibold text-gray-600 hover:text-rose-600">{{ __('Borrar y volver a firmar') }}</button>
            </div>
            @if ($datos['firma'])
                <p class="text-xs text-gray-500 mb-2">{{ __('Ya firmaste. Si no dibujas una firma nueva, se conserva la que tienes:') }}</p>
                <img src="{{ $datos['firma'] }}" alt="" class="h-16 mb-3">
            @endif
            <div class="relative rounded-xl border-2 border-dashed border-gray-300 bg-white">
                <canvas id="firmaCanvas" class="block w-full h-48 touch-none cursor-crosshair rounded-xl"></canvas>
                <span id="firmaGuia" class="pointer-events-none absolute inset-x-0 bottom-10 mx-8 border-b border-gray-300 text-center text-[11px] text-gray-400 pb-1">{{ __('Firma aquí con el dedo o el mouse') }}</span>
            </div>
            <input type="hidden" name="firma" id="firmaInput">
        </div>

        <div class="flex items-center justify-end gap-2">
            @if ($p->firmado())
                <a href="{{ route('certificados.ver', $p) }}" class="h-11 inline-flex items-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Ver mi certificado') }}</a>
            @endif
            <button class="h-11 rounded-xl bg-[#00024f] text-white px-6 text-sm font-semibold shadow-sm hover:opacity-90">{{ __('Firmar y generar certificado') }}</button>
        </div>
    </form>
</div>

<script>
(function () {
    const canvas = document.getElementById('firmaCanvas');
    const input = document.getElementById('firmaInput');
    const guia = document.getElementById('firmaGuia');
    const yaFirmado = @js((bool) $datos['firma']);
    const ctx = canvas.getContext('2d');
    let dibujando = false, hayTrazo = false, ultimo = null;

    const ajustar = () => {
        const r = canvas.getBoundingClientRect(), dpr = window.devicePixelRatio || 1;
        canvas.width = Math.round(r.width * dpr);
        canvas.height = Math.round(r.height * dpr);
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

    document.getElementById('certForm').addEventListener('submit', e => {
        if (hayTrazo) { input.value = canvas.toDataURL('image/png'); return; }
        input.value = '';
        if (!yaFirmado) { e.preventDefault(); alert(@js(__('Dibuja tu firma en el recuadro.'))); canvas.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });
})();
</script>
@endsection
