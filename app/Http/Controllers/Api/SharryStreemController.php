<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetaPage;
use App\Models\Suscripcion;
use App\Services\MetaPageTokenResolver;
use App\Services\PlanService;
use App\Services\SocialPhotoPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * API del plugin de WordPress SharryStreem (autopost a Facebook e Instagram).
 *
 * Cada petición va firmada; la llave completa NUNCA viaja por la red:
 *   X-SharryStreem-Key:       ss_<prefijo>            (identifica la licencia)
 *   X-SharryStreem-Timestamp: segundos UNIX            (±5 minutos)
 *   X-SharryStreem-Nonce:     aleatorio de 16 a 64 caracteres, distinto en cada petición
 *   X-SharryStreem-Site:      URL del sitio WordPress
 *   X-SharryStreem-Signature: HMAC-SHA256( timestamp \n nonce \n MÉTODO \n ruta \n sitio \n sha256(cuerpo) , sha256(llave) )
 * editus guarda solo sha256(llave), así que puede verificar la firma sin conocer la llave.
 * Una firma no se acepta dos veces (anti-repetición). La licencia vale mientras el plan esté activo.
 */
class SharryStreemController extends Controller
{
    private const VENTANA = 300;

    public function __construct(private PlanService $planes)
    {
    }

    public function activar(Request $request): JsonResponse
    {
        [$s, $sitio, $error] = $this->autenticar($request, false);
        if ($error) return $error;
        $sitios = (array) ($s->licencia_sitios ?? []);
        if (!collect($sitios)->contains(fn($x) => ($x['url'] ?? '') === $sitio)) {
            $max = (int) ($s->config()['sitios'] ?? 1);
            if (count($sitios) >= $max) {
                return $this->fallo("Tu plan {$s->nombrePlan()} permite {$max} sitio(s). Desvincula uno en editus → Mi suscripción.", 409);
            }
            $sitios[] = ['url' => $sitio, 'activado_en' => now()->toIso8601String()];
            $s->fill(['licencia_sitios' => $sitios])->save();
        }
        return response()->json(['success' => true] + $this->estadoDe($s));
    }

    public function estado(Request $request): JsonResponse
    {
        [$s, , $error] = $this->autenticar($request);
        if ($error) return $error;
        return response()->json(['success' => true] + $this->estadoDe($s));
    }

    public function desactivar(Request $request): JsonResponse
    {
        [$s, $sitio, $error] = $this->autenticar($request);
        if ($error) return $error;
        $s->fill(['licencia_sitios' => array_values(array_filter((array) $s->licencia_sitios, fn($x) => ($x['url'] ?? '') !== $sitio))])->save();
        return response()->json(['success' => true]);
    }

    /** Publica una entrada de WordPress en las páginas elegidas (solo las del plan). */
    public function publicar(Request $request, SocialPhotoPublisher $publisher): JsonResponse
    {
        [$s, $sitio, $error] = $this->autenticar($request);
        if ($error) return $error;
        $v = Validator::make($request->json()->all(), [
            'titulo' => ['required', 'string', 'max:300'],
            'mensaje' => ['nullable', 'string', 'max:5000'],
            'enlace' => ['required', 'url', 'max:2000'],
            'imagen_url' => ['nullable', 'url', 'max:2000'],
            'paginas' => ['required', 'array', 'min:1', 'max:20'],
            'paginas.*' => ['integer'],
            'facebook' => ['nullable', 'boolean'],
            'instagram' => ['nullable', 'boolean'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ]);
        if ($v->fails()) return $this->fallo($v->errors()->first(), 422);
        $d = $v->validated();
        if (!$this->mismoSitio($d['enlace'], $sitio)) return $this->fallo('El enlace debe ser de tu sitio vinculado.', 422);
        $user = $s->user;
        if ($motivo = $this->planes->validarPaginas($user, $d['paginas'])) return $this->fallo($motivo, 403);

        $fb = (bool) ($d['facebook'] ?? true);
        $ig = (bool) ($d['instagram'] ?? false);
        $texto = trim((string) ($d['mensaje'] ?? '')) ?: $d['titulo'];
        auth()->setUser($user);
        MetaPageTokenResolver::preferirUsuarioApp(null);
        MetaPageTokenResolver::preferirUsuarioWeb((int) $user->id);
        $batch = (string) Str::uuid();
        $resultados = [];
        foreach (MetaPage::whereIn('id', $d['paginas'])->get() as $page) {
            $r = ['id' => $page->id, 'pagina' => (string) $page->name, 'facebook' => null, 'instagram' => null];
            if (!empty($d['imagen_url'])) {
                $out = $publisher->publicar($page, $d['imagen_url'], $texto . "\n\n" . $d['enlace'], $fb, $ig, $d['enlace'], $batch, $texto);
                $r['facebook'] = $out['facebook'];
                $r['instagram'] = $out['instagram'];
            } else {
                if ($fb) $r['facebook'] = $this->publicarEnlace($page, $texto, $d['enlace']);
                if ($ig) $r['instagram'] = ['ok' => false, 'error' => 'Instagram necesita una imagen: agrega una imagen destacada a la entrada.'];
            }
            $resultados[] = $r;
        }
        return response()->json(['success' => true, 'resultados' => $resultados]);
    }

    // ------------------------------------------------------------------

    /** @return array{0: ?Suscripcion, 1: ?string, 2: ?JsonResponse} */
    private function autenticar(Request $request, bool $exigirSitio = true): array
    {
        $clave = (string) $request->header('X-SharryStreem-Key', '');
        $ts = (string) $request->header('X-SharryStreem-Timestamp', '');
        $firma = strtolower((string) $request->header('X-SharryStreem-Signature', ''));
        $nonce = (string) $request->header('X-SharryStreem-Nonce', '');
        $sitio = self::normalizarSitio((string) $request->header('X-SharryStreem-Site', ''));
        if (!preg_match('/^ss_([a-z0-9]{12})$/', $clave, $m) || !ctype_digit($ts) || !preg_match('/^[a-f0-9]{64}$/', $firma) || !preg_match('/^[A-Za-z0-9]{16,64}$/', $nonce) || !$sitio) {
            return [null, null, $this->fallo('Licencia o firma inválida.', 401)];
        }
        if (abs(time() - (int) $ts) > self::VENTANA) return [null, null, $this->fallo('La hora del servidor de WordPress no está sincronizada (firma vencida).', 401)];
        $s = Suscripcion::with('user')->where('licencia_prefijo', $m[1])->first();
        $esperada = $s && $s->licencia_hash
            ? hash_hmac('sha256', implode("\n", [$ts, $nonce, strtoupper($request->method()), '/' . ltrim($request->path(), '/'), $sitio, hash('sha256', $request->getContent())]), $s->licencia_hash)
            : str_repeat('0', 64);
        if (!$s || !hash_equals($esperada, $firma)) return [null, null, $this->fallo('Licencia o firma inválida.', 401)];
        if (!Cache::add('sharrystreem:nonce:' . $s->id . ':' . $nonce, 1, self::VENTANA * 2)) return [null, null, $this->fallo('Petición repetida.', 409)];
        if (!$s->activa()) return [$s, $sitio, $this->fallo('Tu suscripción venció el ' . $s->vence_en?->format('d/m/Y') . '. Renuévala en editus → Mi suscripción para seguir publicando.', 402)];
        if ($exigirSitio && !collect((array) $s->licencia_sitios)->contains(fn($x) => ($x['url'] ?? '') === $sitio)) {
            return [$s, $sitio, $this->fallo('Este sitio no está vinculado a la licencia. Pulsa «Activar» en el plugin.', 403)];
        }
        return [$s, $sitio, null];
    }

    private function estadoDe(Suscripcion $s): array
    {
        $permitidas = $this->planes->paginasPermitidas($s->user) ?? collect();
        return [
            'plan' => $s->plan, 'plan_nombre' => $s->nombrePlan(), 'vence_en' => $s->vence_en?->toIso8601String(), 'dias_restantes' => $s->diasRestantes(),
            'limites' => ['paginas' => (int) ($s->config()['paginas'] ?? 0), 'sitios' => (int) ($s->config()['sitios'] ?? 1)],
            'paginas' => $permitidas->map(fn(MetaPage $p) => ['id' => $p->id, 'nombre' => (string) $p->name, 'instagram' => (bool) $p->instagram_business_account_id])->values(),
        ];
    }

    /** Publicación de enlace en Facebook (entradas sin imagen destacada). */
    private function publicarEnlace(MetaPage $page, string $mensaje, string $enlace): array
    {
        $token = app(MetaPageTokenResolver::class)->forPage($page->page_id);
        if (!$token) return ['ok' => false, 'error' => 'La página no tiene un token activo en editus: vuelve a conectarla.'];
        $r = Http::timeout(30)->asForm()->post(SocialPhotoPublisher::graph("{$page->page_id}/feed"), ['message' => $mensaje, 'link' => $enlace, 'access_token' => $token]);
        $id = data_get($r->json(), 'id');
        return $r->ok() && $id ? ['ok' => true, 'post_id' => (string) $id, 'permalink' => 'https://www.facebook.com/' . $id, 'error' => null]
            : ['ok' => false, 'error' => Str::limit((string) (data_get($r->json(), 'error.message') ?: 'Facebook no aceptó la publicación'), 200)];
    }

    /** https://Mi-Sitio.com/ → https://mi-sitio.com (sin barra final). Solo https (http solo en localhost). */
    public static function normalizarSitio(string $url): ?string
    {
        $p = parse_url(trim($url));
        $esquema = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '' || !in_array($esquema, ['https', 'http'], true)) return null;
        if ($esquema === 'http' && !in_array($host, ['localhost', '127.0.0.1'], true)) return null;
        $ruta = rtrim((string) ($p['path'] ?? ''), '/');
        return $esquema . '://' . $host . (isset($p['port']) ? ':' . (int) $p['port'] : '') . $ruta;
    }

    private function mismoSitio(string $enlace, string $sitio): bool
    {
        $a = strtolower((string) parse_url($enlace, PHP_URL_HOST));
        $b = strtolower((string) parse_url($sitio, PHP_URL_HOST));
        return $a !== '' && ($a === $b || preg_replace('/^www\./', '', $a) === preg_replace('/^www\./', '', $b));
    }

    private function fallo(string $mensaje, int $codigo): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $mensaje], $codigo);
    }
}
