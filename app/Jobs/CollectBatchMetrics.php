<?php

namespace App\Jobs;

use App\Models\MetaPost;
use App\Services\MetaInsightsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectBatchMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 3;
    public $timeout = 1800;

    protected string $batch;
    protected ?int $onlyUserId;   // null = admin (todos)
    protected bool $onlyForUser;

    public function __construct(string $batch, ?int $onlyUserId, bool $onlyForUser)
    {
        $this->batch       = $batch;
        $this->onlyUserId  = $onlyUserId;
        $this->onlyForUser = $onlyForUser;
        $this->onQueue('default');
    }

    private function key(): string
    {
        return "metrics:batch:{$this->batch}:progress";
    }

    public function handle(): void
    {
        $svc = app(MetaInsightsService::class);

        $q = MetaPost::query()
            ->where('batch_uuid', $this->batch)
            ->where('status', 'success')
            ->whereNotNull('fb_post_id')
            ->orderBy('id');

        if ($this->onlyForUser && $this->onlyUserId) {
            $q->where('user_id', $this->onlyUserId);
        }

        $total = (clone $q)->count();

        $state = [
            'total'       => $total,
            'done'        => 0,
            'ok'          => 0,
            'empty'       => 0,
            'errors'      => 0,
            'started_at'  => now()->toIso8601String(),
            'finished'    => false,
            'finished_at' => null,
        ];
        Cache::put($this->key(), $state, now()->addHours(2));

        try {
            if ($total === 0) {
                $state['finished']    = true;
                $state['finished_at'] = now()->toIso8601String();
                Cache::put($this->key(), $state, now()->addHours(2));
                return;
            }

            $q->chunkById(100, function ($chunk) use (&$state, $svc) {
                foreach ($chunk as $post) {
                    try {
                        $updated = $svc->updatePostMetrics($post); // tu servicio
                        $state['done']++;
                        if ($updated) {
                            $state['ok']++;
                        } else {
                            $state['empty']++;
                        }
                    } catch (Throwable $e) {
                        $state['done']++;
                        $state['errors']++;
                        Log::warning('[batch-metrics] error', [
                            'meta_post_id' => $post->id,
                            'err' => $e->getMessage(),
                        ]);
                    }

                    // Persistir cada ~10 items para no saturar cache
                    if ($state['done'] % 10 === 0) {
                        Cache::put($this->key(), $state, now()->addHours(2));
                    }
                }
                Cache::put($this->key(), $state, now()->addHours(2));
            });
        } finally {
            $state['finished']    = true;
            $state['finished_at'] = now()->toIso8601String();
            Cache::put($this->key(), $state, now()->addHours(2));
        }
    }
}
