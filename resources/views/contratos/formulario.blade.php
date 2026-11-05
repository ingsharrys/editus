@extends('layouts.app')

@section('content')
@php
    $lbl = 'block text-[11px] font-semibold uppercase tracking-wide text-gray-400 mb-1';
    $inp = 'w-full h-10 rounded-lg border-gray-200 text-sm shadow-sm';
    $paso = fn($n) => '<span class="h-6 w-6 rounded-full bg-[#00024f] text-white text-xs font-bold flex items-center justify-center shrink-0">' . $n . '</span>';
    $sitioEtiqueta = match ($medio?->clase) { 'emisora' => __('Emisora (nombre y frecuencia)'), 'web', 'digital' => __('Página web'), 'redes' => __('Cuenta o página en redes'), default => __('Página web o emisora') };
@endphp
<div class="max-w-3xl mx-auto px-2 sm:px-4 py-6 lg:py-8">
    <div class="mt-8 lg:mt-10 mb-6">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">{{ __('Campaña') }} · {{ $c->name }}</p>
        <h1 class="text-2xl sm:text-3xl font-bold text-[#00024f] tracking-tight mt-1">{{ __('Contrato de tu medio') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('Primero firma el contrato; después vinculas tu medio (páginas y certificado de publicación). Al firmar te enviamos una copia en PDF a tu correo.') }}</p>
    </div>

    @if (session('aviso'))<div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">{{ session('aviso') }}</div>@endif
    @if (session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 mb-5 text-sm">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="rounded-xl border border-amber-200 bg-amber-50 text-amber-900 px-4 py-3 mb-5 text-sm">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="rounded-xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 mb-5 text-sm">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    @if ($contrato)
        {{-- Ya firmado --}}
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5">
            <p class="text-sm font-semibold text-emerald-900">✅ {{ __('Contrato No. :n firmado el :f', ['n' => $contrato->codigo, 'f' => $contrato->firmado_at->format('d/m/Y H:i')]) }}</p>
            <p class="text-sm text-emerald-900/80 mt-1">{{ $contrato->dato('medio_nombre') }} · {{ $contrato->dato('tipo_nombre') }}
                · {{ $contrato->enviado_at ? __('copia enviada a :c', ['c' => $contrato->dato('correo')]) : __('la copia por correo aún no se envió') }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('contratos.pdf', $contrato) }}" class="h-10 inline-flex items-center rounded-xl bg-[#00024f] text-white px-4 text-sm font-semibold">{{ __('Descargar PDF') }}</a>
                <form method="POST" action="{{ route('contratos.reenviar', $contrato) }}">@csrf<button class="h-10 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700">{{ __('Enviarme otra copia') }}</button></form>
                <a href="{{ route('certificados.formulario', $c) }}" class="h-10 inline-flex items-center rounded-xl bg-emerald-600 text-white px-4 text-sm font-semibold">{{ __('Continuar: vincular mi medio') }} →</a>
            </div>
        </div>
    @elseif (!$medio)
        {{-- 1. ¿Qué medio eres? --}}
        <form method="POST" action="{{ route('contratos.elegir', $c) }}" class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-3">
            @csrf
            <div class="flex items-center gap-2">{!! $paso(1) !!}<h2 class="text-base font-bold text-gray-900">{{ __('¿Qué medio representas?') }}</h2></div>
            @if ($libres->isEmpty())
                <p class="text-sm text-amber-800 bg-amber-50 rounded-lg px-3 py-2">{{ __('No hay medios disponibles en el listado de esta campaña. Escribe al administrador para que agregue tu medio.') }}</p>
            @else
                <select name="medio_id" required class="{{ $inp }}">
                    <option value="">{{ __('— Elige tu medio —') }}</option>
                    @foreach ($libres as $m)<option value="{{ $m->id }}" @selected((int) old('medio_id') === $m->id)>{{ $m->nombre }} · {{ $m->claseTexto() }}</option>@endforeach
                </select>
                <p class="text-xs text-gray-500">{{ __('Cada medio tiene su tipo de contrato. Si tu medio no aparece, escribe al administrador de la campaña.') }}</p>
                <div class="text-right"><button class="h-10 rounded-xl bg-[#00024f] text-white px-5 text-sm font-semibold">{{ __('Continuar') }}</button></div>
            @endif
        </form>
    @else
        <div class="rounded-2xl border border-indigo-100 bg-indigo-50/50 p-4 mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm"><span class="font-semibold text-gray-900">{{ $medio->nombre }}</span> · {{ $medio->claseTexto() }}
                <span class="block text-xs text-gray-600">{{ __('Contrato: :t · número :n (se asigna al firmar)', ['t' => $medio->tipo?->nombre ?? '—', 'n' => $siguiente]) }}</span></div>
            @if ($libres->count() > 1)
                <form method="POST" action="{{ route('contratos.elegir', $c) }}" class="flex gap-2">
                    @csrf
                    <select name="medio_id" class="h-9 rounded-lg border-gray-200 text-xs">@foreach ($libres as $m)<option value="{{ $m->id }}" @selected($m->id === $medio->id)>{{ $m->nombre }}</option>@endforeach</select>
                    <button class="h-9 rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700">{{ __('Cambiar') }}</button>
                </form>
            @endif
        </div>

        <form method="POST" action="{{ route('contratos.firmar', $c) }}" enctype="multipart/form-data" id="contratoForm" class="space-y-5">
            @csrf
            {{-- 1. Encabezado --}}
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-4">
                <div class="flex items-center gap-2">{!! $paso(1) !!}<h2 class="text-base font-bold text-gray-900">{{ __('Encabezado del contrato') }}</h2></div>
                <div class="grid sm:grid-cols-2 gap-4">
                    @foreach ([['logo', __('Logo del medio') . ' *', true], ['membrete', __('Membrete (hoja membreteada, opcional)'), false]] as [$campo, $titulo, $req])
                        <div>
                            <label class="{{ $lbl }}">{{ $titulo }}</label>
                            <label class="flex flex-col items-center justify-center gap-1 h-28 rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 cursor-pointer hover:border-indigo-300 overflow-hidden">
                                <img id="{{ $campo }}Prev" alt="" class="hidden max-h-20 max-w-[90%] object-contain">
                                <span id="{{ $campo }}Txt" class="text-xs text-gray-500 text-center px-2">{{ __('Toca para subir (PNG o JPG)') }}</span>
                                <input type="file" name="{{ $campo }}" accept="image/png,image/jpeg,image/webp" class="hidden" @required($req)
                                       onchange="const f=this.files[0]; if(!f) return; const i=document.getElementById('{{ $campo }}Prev'); i.src=URL.createObjectURL(f); i.classList.remove('hidden'); document.getElementById('{{ $campo }}Txt').classList.add('hidden');">
                            </label>
                        </div>
                    @endforeach
                </div>
                <div class="grid sm:grid-cols-2 gap-3">
                    <div class="sm:col-span-2"><label class="{{ $lbl }}">{{ __('Nombre del medio') }} * <span class="normal-case font-normal">({{ mb_strtolower($medio->claseTexto()) }})</span></label><input name="medio_nombre" data-var-input="medio" required maxlength="190" value="{{ $datos['medio_nombre'] ?? '' }}" class="{{ $inp }}"></div>
                    <div><label class="{{ $lbl }}">{{ $sitioEtiqueta }}</label><input name="sitio" data-var-input="sitio" maxlength="190" value="{{ $datos['sitio'] ?? '' }}" class="{{ $inp }}"></div>
                    <div><label class="{{ $lbl }}">{{ __('NIT del medio') }}</label><input name="nit" data-var-input="nit" maxlength="40" value="{{ $datos['nit'] ?? '' }}" class="{{ $inp }}"></div>
                    <div class="sm:col-span-2"><label class="{{ $lbl }}">{{ __('Representante legal') }} *</label><input name="representante" data-var-input="representante" required maxlength="190" value="{{ $datos['representante'] ?? '' }}" class="{{ $inp }}"></div>
                    <div class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-2 sm:col-span-2">
                        <div><label class="{{ $lbl }}">{{ __('Documento') }} *</label><select name="documento_tipo" data-var-input="documento_tipo" class="{{ $inp }}">@foreach ($documentos as $t)<option @selected(($datos['documento_tipo'] ?? 'C.C.') === $t)>{{ $t }}</option>@endforeach</select></div>
                        <div><label class="{{ $lbl }}">{{ __('Número de identificación') }} *</label><input name="documento" data-var-input="documento" required maxlength="40" value="{{ $datos['documento'] ?? '' }}" class="{{ $inp }}"></div>
                    </div>
                    <div><label class="{{ $lbl }}">{{ __('Correo (aquí llega la copia del contrato)') }} *</label><input type="email" name="correo" data-var-input="correo" required maxlength="190" value="{{ $datos['correo'] ?? '' }}" class="{{ $inp }}"></div>
                    <div><label class="{{ $lbl }}">{{ __('Teléfono') }}</label><input name="telefono" data-var-input="telefono" maxlength="40" value="{{ $datos['telefono'] ?? '' }}" class="{{ $inp }}"></div>
                    <div><label class="{{ $lbl }}">{{ __('Dirección') }}</label><input name="direccion" data-var-input="direccion" maxlength="190" value="{{ $datos['direccion'] ?? '' }}" class="{{ $inp }}"></div>
                    <div><label class="{{ $lbl }}">{{ __('Ciudad') }} *</label><input name="ciudad" data-var-input="ciudad" required maxlength="120" value="{{ $datos['ciudad'] ?? '' }}" class="{{ $inp }}"></div>
                </div>
            </div>

            {{-- 2. Documentos --}}
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-3">
                <div class="flex items-center gap-2">{!! $paso(2) !!}<h2 class="text-base font-bold text-gray-900">{{ __('Documentos') }}</h2></div>
                <div class="grid sm:grid-cols-2 gap-3">
                    <label class="block rounded-xl border border-gray-200 p-3"><span class="{{ $lbl }}">{{ __('RUT') }} *</span>
                        <input type="file" name="rut" required accept="application/pdf,image/png,image/jpeg,image/webp" class="block w-full text-sm text-gray-600 file:mr-3 file:h-9 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:text-sm file:font-semibold">
                        <span class="block text-[11px] text-gray-400 mt-1">{{ __('PDF o imagen, máx. 8 MB') }}</span></label>
                    <label class="block rounded-xl border border-gray-200 p-3"><span class="{{ $lbl }}">{{ __('Certificado bancario') }} *</span>
                        <input type="file" name="certificado_bancario" required accept="application/pdf,image/png,image/jpeg,image/webp" class="block w-full text-sm text-gray-600 file:mr-3 file:h-9 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:text-sm file:font-semibold">
                        <span class="block text-[11px] text-gray-400 mt-1">{{ __('PDF o imagen, máx. 8 MB') }}</span></label>
                </div>
            </div>

            {{-- 3. Contrato --}}
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5 space-y-3">
                <div class="flex items-center gap-2">{!! $paso(3) !!}<h2 class="text-base font-bold text-gray-900">{{ __('Lee el contrato') }}</h2></div>
                @if (trim(strip_tags($texto)) === '')
                    <p class="text-sm text-amber-800 bg-amber-50 rounded-lg px-3 py-2">{{ __('El texto de este tipo de contrato todavía no está cargado. Escribe al administrador de la campaña.') }}</p>
                @else
                    <div class="contrato-texto max-h-[28rem] overflow-y-auto rounded-xl border border-gray-200 bg-gray-50 p-5 text-sm leading-relaxed text-gray-800">{!! $texto !!}</div>
                @endif
                <label class="flex items-start gap-2 text-sm text-gray-700"><input type="checkbox" name="acepto" value="1" required class="mt-0.5 h-4 w-4 rounded border-gray-300 text-indigo-600" @checked(old('acepto'))>
                    <span>{{ __('Leí el contrato y acepto sus términos en nombre del medio.') }}</span></label>
            </div>

            {{-- 4. Firma --}}
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm p-5">
                <div class="flex items-center gap-2 mb-3">{!! $paso(4) !!}<h2 class="text-base font-bold text-gray-900">{{ __('Firma del representante legal') }}</h2></div>
                @include('contratos._firma', ['form' => 'contratoForm'])
            </div>

            <div class="flex justify-end">
                <button @disabled(trim(strip_tags($texto)) === '') class="h-11 rounded-xl bg-[#00024f] text-white px-6 text-sm font-semibold shadow-sm hover:opacity-90 disabled:opacity-40">{{ __('Firmar contrato') }}</button>
            </div>
        </form>
    @endif
</div>

<style>
    .contrato-texto p { margin: 0 0 .75em; text-align: justify; }
    .contrato-texto h2, .contrato-texto h3, .contrato-texto h4 { font-weight: 700; margin: 1em 0 .5em; color: #111827; }
    .contrato-texto ul { list-style: disc; padding-left: 1.4em; margin: 0 0 .75em; }
    .contrato-texto ol { list-style: decimal; padding-left: 1.4em; margin: 0 0 .75em; }
    .contrato-texto table { width: 100%; border-collapse: collapse; margin: .5em 0 1em; }
    .contrato-texto td, .contrato-texto th { border: 1px solid #d1d5db; padding: .3em .5em; }
    .contrato-texto .var { background: #eef2ff; border-radius: .25rem; padding: 0 .2em; font-weight: 600; }
</style>
<script>
// El contrato se va llenando mientras el medio escribe sus datos
document.querySelectorAll('[data-var-input]').forEach(el => {
    const pintar = () => document.querySelectorAll('[data-var="' + el.dataset.varInput + '"]').forEach(s => { s.textContent = el.value.trim() || '________'; });
    el.addEventListener('input', pintar); el.addEventListener('change', pintar);
});
</script>
@endsection
