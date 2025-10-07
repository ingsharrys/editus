<?php

namespace App\Console\Commands;

use App\Models\MetaPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Jobs\PublishPhotosToFacebook;

class RetryFailedMetaPosts extends Command
{
    protected $signature = 'meta:retry-failed
        {--type=photo : photo|video|text (por ahora usamos photo)}
        {--batch= : filtra por batch_uuid}
        {--limit=50 : máximo a reintentar}
        {--since= : ISO datetime para filtrar por created_at >=}
    ';

    protected $description = 'Reintenta publicaciones fallidas (photos) usando tokens del pivot.';

    public function handle(): int
    {
        $type  = $this->option('type') ?: 'photo';
        $batch = $this->option('batch');
        $limit = (int) $this->option('limit');
        $since = $this->option('since');

        $q = MetaPost::query()
            ->where('type', $type)
            ->where('status', 'fail')
            ->whereNull('fb_post_id');

        if ($batch) {
            $q->where('batch_uuid', $batch);
        }
        if ($since) {
            $q->where('created_at', '>=', $since);
        }

        // Evita reintentar errores permanentes recientes de auth/permiso: opcional
        $q->where(function($w){
            $w->whereNull('error')
              ->orWhere(function($e){
                  $e->where('error', 'not like', '%The user must be an administrator%')
                    ->where('error', 'not like', '%session has been invalidated%')
                    ->where('error', 'not like', '%Error validating access token%');
              });
        });

        $posts = $q->with(['page:id,page_id,name', 'user:id'])
                   ->orderBy('id')
                   ->limit($limit)
                   ->get();

        if ($posts->isEmpty()) {
            $this->info('No hay fallidos para reintentar.');
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($posts as $post) {
            // Obtener page_id
            $pageId = $post->page?->page_id ?? $post->page_id;
            if (!$pageId) continue;

            // Extraer photo_urls desde local_media o desde campo dedicado
            $urls = [];
            $data = [];
            if ($post->local_media) {
                $data = json_decode($post->local_media, true) ?: [];
                $urls = (array) ($data['photo_urls'] ?? []);
            } elseif ($post->photo_urls) {
                $data = json_decode($post->photo_urls, true) ?: [];
                $urls = (array) ($data['photo_urls'] ?? $data);
            }

            $urls = array_values(array_filter($urls));
            if (empty($urls)) {
                // marca como no-reintantable
                $post->update(['error' => 'retry: sin photo_urls válidas']);
                continue;
            }

            // (opcional) HEAD a la primera imagen para no encolar basura
            try {
                $head = Http::timeout(10)->head($urls[0]);
                if (!$head->ok() || stripos($head->header('Content-Type') ?? '', 'image/') !== 0) {
                    $post->update(['error' => 'retry: image no accesible o no image/*']);
                    continue;
                }
            } catch (\Throwable $e) {
                // sigue, a veces HEAD está bloqueado por hosting
            }

            // Marcar en queued y limpiar error
            $post->update(['status' => 'queued', 'error' => null]);

            // Encolar (el Job resolverá el token via pivot/SystemUser)
            PublishPhotosToFacebook::dispatch([
                'meta_post_id' => $post->id,
                'page_id'      => $pageId,
                'photo_urls'   => $urls,
                'caption'      => $post->message,
                'cleanup_rel'  => [],
                'cleanup_abs'  => [],
            ])->onQueue('default')->delay(now()->addSeconds($count * 3));

            $count++;
        }

        $this->info("Reintentos encolados: {$count}");
        return self::SUCCESS;
    }
}
