<?php
/**
 * MiSalaUCR — Plantilla de configuración local.
 *
 * Copie este archivo a `config.local.php` y complete sus valores reales.
 * `config.local.php` NO se sube al repositorio (está en .gitignore).
 * Solo necesita definir las claves que quiera cambiar respecto a config.php.
 */
return [
    // API key de Resend (https://resend.com). Vacío = correos quedan en bitácora.
    'resend_api_key' => '',
    // 'mail_from'   => 'MiSalaUCR <no-reply@tudominio.com>',

    // Base de datos MySQL en producción (Hostinger):
    // 'db' => [
    //     'driver' => 'mysql',
    //     'mysql'  => ['host' => 'localhost', 'dbname' => '', 'user' => '', 'pass' => ''],
    // ],
    // 'base_url' => 'https://misala.tudominio.com',

    // Contraseñas iniciales (solo al crear la BD por primera vez).
    // Si las deja vacías, se generan al azar y se guardan en
    // data/credenciales_iniciales.txt.
    'seed' => [
        'super_pass' => '',
        'admin_pass' => '',
    ],
];
