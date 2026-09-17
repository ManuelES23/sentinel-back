<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;

/**
 * Aplica la configuración SMTP de /admin/settings sobre config('mail').
 * Si está desactivada, el sistema sigue usando lo que diga .env.
 */
class MailSettings
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function apply(): void
    {
        $s = $this->settings->all();

        if (! $s['mail.enabled']) {
            return;
        }

        $cifrado = $s['mail.encryption'];

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $cifrado === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.host' => $s['mail.host'],
            'mail.mailers.smtp.port' => $s['mail.port'],
            'mail.mailers.smtp.username' => $s['mail.username'] !== '' ? $s['mail.username'] : null,
            'mail.mailers.smtp.password' => $s['mail.password'] !== '' ? $s['mail.password'] : null,
            'mail.mailers.smtp.timeout' => 10,
            'mail.mailers.smtp.auto_tls' => $cifrado !== 'none',
            'mail.mailers.smtp.require_tls' => $cifrado === 'tls',
            'mail.from.address' => $s['mail.from_address'],
            'mail.from.name' => $s['mail.from_name'],
        ]);

        // Descarta el mailer ya resuelto para que use los valores nuevos.
        Mail::purge('smtp');
    }
}
