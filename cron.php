<?php
/**
 * MiSalaUCR — Tareas automáticas (no-shows, completadas, recordatorios).
 *
 * Los procesos también corren solos cada vez que alguien usa la app,
 * pero para máxima puntualidad configure en Hostinger (hPanel → Avanzado →
 * Cron Jobs) una tarea cada 5 minutos:
 *
 *   php /home/USUARIO/public_html/cron.php
 */
require_once __DIR__ . '/lib/rules.php';
process_automatic();
echo "OK " . date('Y-m-d H:i:s') . "\n";
