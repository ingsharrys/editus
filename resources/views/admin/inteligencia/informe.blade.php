@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-8">
    <div class="mt-10 mb-6 flex items-center justify-between gap-4">
        <div class="text-[#00024f]">
            <a href="{{ route('inteligencia.show', $campana) }}" class="text-sm underline">← {{ $campana->nombre }}</a>
            <h1 class="text-2xl font-bold mt-1">Informe {{ $informe->desde->format('d/m/Y') }} a {{ $informe->hasta->format('d/m/Y') }}</h1>
            <p class="text-xs text-gray-500">Redactado el {{ $informe->created_at->format('d/m/Y H:i') }} a partir de datos agregados.</p>
        </div>
        <button onclick="window.print()" class="rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white">Imprimir / PDF</button>
    </div>
    @if (session('success'))<div class="rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-800 p-3 mb-4">{{ session('success') }}</div>@endif
    <article id="informe" class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm prose prose-sm max-w-none"></article>
</div>
<script src="https://cdn.jsdelivr.net/npm/marked@12/marked.min.js"></script>
<script>
  const md = @json($informe->contenido);
  const html = marked.parse(md, { breaks: true });
  // Sin scripts ni eventos en el HTML generado
  const div = document.createElement('div'); div.innerHTML = html;
  div.querySelectorAll('script, iframe, object, embed').forEach(n => n.remove());
  div.querySelectorAll('*').forEach(n => { for (const a of Array.from(n.attributes)) if (a.name.startsWith('on') || a.value.trim().toLowerCase().startsWith('javascript:')) n.removeAttribute(a.name); });
  document.getElementById('informe').innerHTML = div.innerHTML;
</script>
<style>
  #informe h1 { font-size: 1.4rem; margin-top: 1rem; } #informe h2 { font-size: 1.15rem; margin-top: 1.2rem; } #informe h3 { font-size: 1rem; margin-top: 1rem; }
  #informe ul { list-style: disc; padding-left: 1.3rem; } #informe ol { list-style: decimal; padding-left: 1.3rem; } #informe li { margin: .2rem 0; }
  #informe table { border-collapse: collapse; margin: .6rem 0; } #informe th, #informe td { border: 1px solid #e5e7eb; padding: .3rem .5rem; font-size: .85rem; }
  #informe p { margin: .5rem 0; }
  @media print { nav, aside, header, button { display: none !important; } #informe { border: 0; box-shadow: none; } }
</style>
@endsection
