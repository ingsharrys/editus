{{-- Pestañas del módulo En vivo: estudio (con cámaras) y pregrabados programados --}}
<div class="max-w-full overflow-x-auto">
    <div class="inline-flex rounded-xl bg-gray-200/70 p-1 gap-1">
        @foreach (['en-vivo.web' => [__('● Estudio en vivo'), __('● Estudio')], 'pregrabados.index' => [__('⏱ Pregrabados programados'), __('⏱ Pregrabados')]] as $ruta => [$largo, $corto])
            <a href="{{ route($ruta) }}" class="rounded-lg px-3.5 py-2 text-sm font-semibold whitespace-nowrap transition {{ request()->routeIs($ruta) ? 'bg-white text-[#00024f] shadow-sm' : 'text-gray-600 hover:text-gray-900' }}"><span class="hidden sm:inline">{{ $largo }}</span><span class="sm:hidden">{{ $corto }}</span></a>
        @endforeach
    </div>
</div>
