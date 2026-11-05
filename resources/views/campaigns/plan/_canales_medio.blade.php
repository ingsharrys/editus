{{-- Canales de un medio (varios a la vez) y, en cada red social, si publica con pauta. $sel: canales ya marcados --}}
@php $sel = \App\Models\MedioCampana::ordenar((array) ($sel ?? [])); @endphp
<div class="flex flex-wrap gap-1.5">
    @foreach (\App\Models\MedioCampana::CANALES as $k => $info)
        <div class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 whitespace-nowrap" data-canal>
            <label class="flex items-center gap-1.5 text-xs font-semibold text-gray-800 cursor-pointer">
                <input type="checkbox" name="canales[]" value="{{ $k }}" @checked(isset($sel[$k])) class="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600"
                       onchange="if (!this.checked) { const p = this.closest('[data-canal]').querySelector('input[name^=pauta]'); if (p) p.checked = false; }">
                {{ $info['nombre'] }}</label>
            @if ($info['pauta'])
                <label class="mt-0.5 flex items-center gap-1.5 text-[11px] text-gray-500 cursor-pointer">
                    <input type="checkbox" name="pauta[]" value="{{ $k }}" @checked($sel[$k]['pauta'] ?? false) class="h-3 w-3 rounded border-gray-300 text-indigo-600"
                           onchange="if (this.checked) this.closest('[data-canal]').querySelector('input[name^=canales]').checked = true;">
                    {{ __('con pauta') }}</label>
            @endif
        </div>
    @endforeach
</div>
