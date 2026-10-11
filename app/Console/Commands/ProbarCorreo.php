<?php

namespace App\Console\Commands;

use App\Services\NotificadorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Envía un correo de prueba con el correo corporativo configurado en el .env (MAIL_*). */
class ProbarCorreo extends Command
{
    protected $signature = 'editus:probar-correo {correo : a quién enviarlo}';
    protected $description = 'Envía un correo de prueba para comprobar el correo corporativo (MAIL_* en el .env)';

    public function handle(): int
    {
        if (!NotificadorService::correoConfigurado()) {
            $this->error('El correo no está configurado: MAIL_MAILER es "' . config('mail.default') . '". Pon MAIL_MAILER=smtp y los datos MAIL_* en el .env, y php artisan config:clear.');
            return self::FAILURE;
        }
        $correo = (string) $this->argument('correo');
        $this->line('Enviando desde ' . config('mail.from.address') . ' (' . config('mail.mailers.smtp.host') . ':' . config('mail.mailers.smtp.port') . ") a {$correo}…");
        try {
            Mail::send('emails.aviso', ['titulo' => 'Correo de prueba de editus', 'cuerpo' => 'Si recibes este correo, los avisos de en vivos y pregrabados programados te llegarán así.',
                'accion' => ['texto' => 'Abrir editus', 'url' => url('/en-vivo')], 'lineas' => [], 'nombre' => $correo], fn($m) => $m->to($correo)->subject('Correo de prueba de editus'));
        } catch (\Throwable $e) {
            $this->error('No se pudo enviar: ' . $e->getMessage());
            return self::FAILURE;
        }
        $this->info('Enviado. Revisa la bandeja de entrada (y la de spam).');
        return self::SUCCESS;
    }
}
