<?php
/**
 * MiSalaUCR — Versión actual de los assets estáticos.
 * Endpoint público y liviano: sin sesión, sin BD. Lo consulta el JS de
 * lib/layout.php para avisar a pestañas ya abiertas cuando hay un despliegue
 * nuevo (ver page_bottom()).
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo (int)(@filemtime(__DIR__ . '/assets/style.css') ?: 1);
