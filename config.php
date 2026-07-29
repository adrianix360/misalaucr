<?php
/**
 * MiSalaUCR — Configuración general
 *
 * Este archivo NO contiene secretos y sí se versiona. Los datos sensibles
 * (API key de Resend, contraseñas iniciales y credenciales de MySQL) van en
 * `config.local.php`, que está en .gitignore y no se sube al repositorio.
 *
 *   1. Copie `config.example.php` a `config.local.php`.
 *   2. Complete ahí sus valores reales.
 *
 * LOCAL:     deje driver = 'sqlite' (no requiere instalar nada más).
 * HOSTINGER: ponga driver = 'mysql' y complete 'mysql' en config.local.php.
 */

$defaults = [
    'db' => [
        'driver'      => 'sqlite',              // 'sqlite' o 'mysql'
        'sqlite_path' => __DIR__ . '/data/misalaucr.sqlite',
        'mysql' => [
            'host'   => 'localhost',
            'dbname' => '',
            'user'   => '',
            'pass'   => '',
        ],
    ],

    'timezone' => 'America/Costa_Rica',

    // URL pública de la app (para los enlaces en correos). Ej: https://misala.ucr.cr
    'base_url' => '',

    /**
     * Correos (Resend). Mientras el API key esté vacío, los correos
     * quedan registrados como "pendientes" en la bitácora (Admin → Correos)
     * y la app funciona con normalidad. Al pegar el key (en config.local.php),
     * se envían de verdad.
     */
    'resend_api_key' => '',
    'mail_from'      => 'MiSalaUCR <onboarding@resend.dev>',

    // Minutos antes del inicio de la reserva para enviar el recordatorio
    'reminder_minutes' => 30,

    /**
     * Credenciales iniciales: SOLO se usan al crear la base de datos por
     * primera vez (seed). Defínalas en config.local.php. Si quedan vacías,
     * se genera una contraseña aleatoria segura y se guarda una única vez en
     * `data/credenciales_iniciales.txt`.
     */
    'seed' => [
        'super_email' => 'castroramirez702@gmail.com',
        'super_pass'  => '',
        'admin_email' => 'admin.civil@misalaucr.test',
        'admin_pass'  => '',
    ],
];

$localFile = __DIR__ . '/config.local.php';
$local = is_file($localFile) ? require $localFile : [];

return array_replace_recursive($defaults, is_array($local) ? $local : []);
