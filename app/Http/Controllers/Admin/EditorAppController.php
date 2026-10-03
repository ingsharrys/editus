<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetaPage;
use App\Models\PlantillaEditor;
use App\Models\RecursoEnVivo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Configuración de la app móvil del editor (sección Redes):
 * qué páginas de Facebook / Instagram se ofrecen en la app y las
 * plantillas de imagen con las que se componen las piezas.
 */
class EditorAppController extends Controller
{
    public function index(Request $request): View
    {
        $paginas = MetaPage::query()
            ->with(['users' => fn($q) => $q->where('meta_page_user.is_active', 1)->whereNotNull('meta_page_user.page_access_token')])
            ->orderBy('name')
            ->get();

        $plantillas = PlantillaEditor::query()->orderBy('orden')->orderBy('id')->get();

        $editar = null;
        if ($request->filled('editar')) {
            $editar = PlantillaEditor::find((int) $request->query('editar'));
        }

        $medios = array_keys((array) config('services.editus.medios', []));
        $recursos = Schema::hasTable('recursos_en_vivo') ? RecursoEnVivo::query()->orderBy('orden')->orderBy('id')->get() : collect();
        $canalesYoutube = Schema::hasTable('youtube_canales') ? \App\Models\YoutubeCanal::orderBy('titulo')->get() : collect();
        $googleListo = (string) config('services.google.client_id') !== '';

        return view('admin.editor-app.index', compact('paginas', 'plantillas', 'editar', 'medios', 'recursos', 'canalesYoutube', 'googleListo'));
    }

    /** Guarda qué páginas se ven en la app y a qué medio pertenece cada una. */
    public function paginas(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'visible' => ['nullable', 'array'],
            'visible.*' => ['integer'],
            'medio' => ['nullable', 'array'],
            'medio.*' => ['nullable', 'string', 'max:100'],
            'usuarios' => ['nullable', 'array'],
            'usuarios.*' => ['nullable', 'string', 'max:500'],
        ]);

        $visibles = array_map('intval', $datos['visible'] ?? []);
        $medios = $datos['medio'] ?? [];
        $usuarios = $datos['usuarios'] ?? [];
        $conUsuarios = Schema::hasColumn('meta_pages', 'app_usuarios');

        foreach (MetaPage::all() as $p) {
            $slug = strtolower(trim((string) ($medios[$p->id] ?? '')));
            $slug = preg_replace('/[^a-z0-9_-]/', '', $slug) ?: null;
            $p->visible_en_editor = in_array($p->id, $visibles, true);
            $p->medio_slug = $slug;
            if ($conUsuarios) $p->app_usuarios = MetaPage::usuariosApp($usuarios[$p->id] ?? null);
            $p->save();
        }

        return redirect()->route('editor-app.index')->with('success', 'Páginas de la app actualizadas.');
    }

    public function plantillaStore(Request $request): RedirectResponse
    {
        $plantilla = new PlantillaEditor();
        $this->guardarPlantilla($request, $plantilla);

        return redirect()->route('editor-app.index')->with('success', "Plantilla «{$plantilla->nombre}» creada.");
    }

    public function plantillaUpdate(Request $request, PlantillaEditor $plantilla): RedirectResponse
    {
        $this->guardarPlantilla($request, $plantilla);

        return redirect()->route('editor-app.index')->with('success', "Plantilla «{$plantilla->nombre}» guardada.");
    }

    public function plantillaDestroy(PlantillaEditor $plantilla): RedirectResponse
    {
        if ($plantilla->logo_path) {
            Storage::disk('public')->delete($plantilla->logo_path);
        }
        $nombre = $plantilla->nombre;
        $plantilla->delete();

        return redirect()->route('editor-app.index')->with('success', "Plantilla «{$nombre}» eliminada.");
    }

    // ---------------------------------------------- Recursos para las transmisiones en vivo

    /** Sube una cortinilla o comercial (video MP4/WebM) o una imagen a pantalla completa. */
    public function recursoStore(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'archivo' => ['required', 'file', 'mimes:mp4,webm,png,jpg,jpeg,webp', 'max:204800'],
            'uso' => ['nullable', 'string', 'in:auto,intro,plantilla,publicidad'],
            'duracion' => ['nullable', 'integer', 'min:1', 'max:600'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [
            'archivo.mimes' => 'Sube un video MP4 o WebM, o una imagen PNG, JPG o WEBP.',
            'archivo.max' => 'El archivo no puede superar 200 MB.',
        ]);
        $ext = strtolower($request->file('archivo')->getClientOriginalExtension());
        $tipo = in_array($ext, ['mp4', 'webm'], true) ? 'video' : 'imagen';
        $uso = $datos['uso'] ?? 'auto';
        if ($uso === 'auto') $uso = 'publicidad';
        if ($uso === 'plantilla' && $ext !== 'png') return back()->withErrors(['archivo' => 'La plantilla de video debe ser un PNG con transparencia (1920×1080).'])->withInput();
        if ($uso === 'intro' && $tipo !== 'video') return back()->withErrors(['archivo' => 'La intro debe ser un video.'])->withInput();
        $ruta = $request->file('archivo')->store('en-vivo/recursos', 'public');
        RecursoEnVivo::create([
            'tipo' => $tipo, 'uso' => $uso, 'nombre' => $datos['nombre'], 'archivo' => $ruta,
            'duracion' => $datos['duracion'] ?? null, 'orden' => $datos['orden'] ?? 0, 'activo' => true,
        ]);
        return redirect()->route('editor-app.index')->with('success', "Recurso «{$datos['nombre']}» subido.")->withFragment('recursos');
    }

    public function recursoDestroy(RecursoEnVivo $recurso): RedirectResponse
    {
        Storage::disk('public')->delete($recurso->archivo);
        $nombre = $recurso->nombre;
        $recurso->delete();
        return redirect()->route('editor-app.index')->with('success', "Recurso «{$nombre}» eliminado.")->withFragment('recursos');
    }

    private function guardarPlantilla(Request $request, PlantillaEditor $plantilla): void
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'logo' => ['nullable', 'file', 'mimes:png,webp', 'max:2048'],
            'quitar_logo' => ['nullable', 'boolean'],
            'logo_texto' => ['nullable', 'string', 'max:60'],
            'logo_tintar' => ['nullable', 'boolean'],
            'etiqueta' => ['nullable', 'string', 'max:40'],
            'pie' => ['nullable', 'string', 'max:80'],
            'hashtag' => ['nullable', 'string', 'max:60'],
            'color_titulo' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_logo' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_etiqueta' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'predeterminada' => ['nullable', 'boolean'],
            'activa' => ['nullable', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [
            'logo.mimes' => 'El logo debe ser PNG (con fondo transparente) o WEBP.',
            'color_titulo.regex' => 'El color del título debe ser HEX (#RRGGBB).',
            'color_logo.regex' => 'El color del logo debe ser HEX (#RRGGBB).',
            'color_etiqueta.regex' => 'El color de la etiqueta debe ser HEX (#RRGGBB).',
        ]);

        $plantilla->fill([
            'nombre' => trim($datos['nombre']),
            'logo_texto' => trim((string) ($datos['logo_texto'] ?? '')) ?: null,
            'logo_tintar' => (bool) ($datos['logo_tintar'] ?? false),
            'etiqueta' => trim((string) ($datos['etiqueta'] ?? '')) ?: null,
            'pie' => trim((string) ($datos['pie'] ?? '')) ?: null,
            'hashtag' => trim((string) ($datos['hashtag'] ?? '')) ?: null,
            'color_titulo' => strtoupper($datos['color_titulo']),
            'color_logo' => strtoupper($datos['color_logo']),
            'color_etiqueta' => strtoupper($datos['color_etiqueta']),
            'predeterminada' => (bool) ($datos['predeterminada'] ?? false),
            'activa' => (bool) ($datos['activa'] ?? false),
            'orden' => (int) ($datos['orden'] ?? 0),
        ]);

        if (!empty($datos['quitar_logo']) && $plantilla->logo_path) {
            Storage::disk('public')->delete($plantilla->logo_path);
            $plantilla->logo_path = null;
        }
        if ($request->hasFile('logo')) {
            if ($plantilla->logo_path) {
                Storage::disk('public')->delete($plantilla->logo_path);
            }
            $plantilla->logo_path = $request->file('logo')->store('plantillas-editor', 'public');
        }

        $plantilla->save();

        // Solo una plantilla puede ser la predeterminada
        if ($plantilla->predeterminada) {
            PlantillaEditor::where('id', '!=', $plantilla->id)->update(['predeterminada' => false]);
        }
    }
}
