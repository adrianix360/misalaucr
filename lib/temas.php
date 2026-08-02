<?php
/**
 * MiSalaUCR — Temas de temporada.
 *
 * Un solo interruptor por organización: organizations.theme
 *   'none' | 'independencia' | 'halloween' | 'navidad'
 *
 * Este archivo NO toca la lógica de reservas: solo decide clase de <body>,
 * fuentes, textos de temporada y los adornos decorativos.
 */

const TEMAS_DISPONIBLES = [
    'none'          => 'Sin tema (marca normal)',
    'independencia' => 'Independencia (setiembre)',
    'halloween'     => 'Halloween (octubre)',
    'navidad'       => 'Navidad (diciembre)',
];

/** Tema activo de la organización (o 'none'). */
function tema_actual(?array $org): string {
    $t = $org['theme'] ?? 'none';
    return isset(TEMAS_DISPONIBLES[$t]) ? $t : 'none';
}

/** Fuentes extra que pide cada tema (se suman al <link> de Google Fonts). */
function tema_fuentes(string $tema): string {
    switch ($tema) {
        case 'independencia': return '&family=Bitter:wght@600;700';
        case 'halloween':     return '&family=Creepster';
        case 'navidad':       return '&family=Mountains+of+Christmas:wght@700&family=Playfair+Display:wght@700';
    }
    return '';
}

/** Color de la barra del navegador móvil (<meta name="theme-color">). */
function tema_color_barra(string $tema): string {
    return [
        'independencia' => '#0b1c46',
        'halloween'     => '#14101f',
        'navidad'       => '#0e3b2e',
    ][$tema] ?? '#ffffff';
}

/**
 * Texto de temporada. Si el tema no define la clave, devuelve el neutro.
 * Uso:  tema_txt($tema, 'saludo', 'Hola, %s 👋')
 */
function tema_txt(string $tema, string $clave, string $neutro): string {
    static $txt = [
        'independencia' => [
            'login_sub'   => 'Encendé tu farol: tu sala te espera esta semana patria.',
            'login_btn'   => 'Entrar al desfile',
            'saludo'      => '¡Pura vida, %s!',
            'saldo'       => 'te quedan esta semana patria',
            'salas_h2'    => 'Salas de hoy',
            'salas_ayuda' => 'Tocá un bloque <b>libre</b> y encendelo. Solo se reserva para hoy.',
            'perfil_ok'   => 'Perfil actualizado. Ya podés desfilar tranquilo.',
            'confirmar'   => 'Encender %s, hoy a las %d:00',
        ],
        'halloween' => [
            'login_sub'   => 'Reservá tu guarida antes de que alguien más la conjure.',
            'login_btn'   => 'Entrar a la guarida',
            'saludo'      => 'Buuu, %s',
            'saldo'       => 'te quedan antes de la medianoche',
            'salas_h2'    => 'Guaridas de hoy',
            'salas_ayuda' => 'Tocá un bloque <b>libre</b> para apoderarte de él. Solo se reserva para hoy.',
            'perfil_ok'   => 'Perfil actualizado. Ningún fantasma tocó tus datos.',
            'confirmar'   => 'Apoderarte de %s, hoy a las %d:00',
            'blk_ocupado' => 'Ocupada ⏳',
        ],
        'navidad' => [
            'login_sub'   => 'Reservá tu sala antes de que Santa se lleve el último cupo.',
            'login_btn'   => 'Subirme al trineo',
            'saludo'      => '¡Feliz diciembre, %s!',
            'saldo'       => 'te quedan esta semana',
            'salas_h2'    => 'Salas de hoy',
            'salas_ayuda' => 'Tocá un bloque <b>libre</b> y colgalo en tu árbol. Solo se reserva para hoy.',
            'perfil_ok'   => 'Perfil actualizado. Quedaste en la lista de los buenos.',
            'confirmar'   => 'Reservar %s, hoy a las %d:00',
        ],
    ];
    return $txt[$tema][$clave] ?? $neutro;
}

/**
 * Adornos del ingreso (login.php / password.php): se imprimen dentro de
 * <main>, antes de .login-box. Todos son decorativos (aria-hidden).
 */
function tema_deco_ingreso(string $tema): void {
    if ($tema === 'independencia') {
        echo '<div class="deco-faroles" aria-hidden="true">' . str_repeat('<i></i>', 7) . '</div>';
    } elseif ($tema === 'halloween') {
        echo '<div class="deco-calabazas" aria-hidden="true">' . str_repeat('<i></i>', 7) . '</div>';
    } elseif ($tema === 'navidad') {
        echo '<div class="deco-luna" aria-hidden="true"></div>';
        echo '<div class="deco-nieve" aria-hidden="true">' . str_repeat('<i></i>', 22) . '</div>';
        echo '<div class="deco-guirnalda" aria-hidden="true">' . str_repeat('<i></i>', 11) . '</div>';
        // Imagen opcional: si no existe el archivo, el ingreso funciona igual.
        if (is_file(__DIR__ . '/../assets/brand/navidad/trineo.png')) {
            echo '<img class="deco-trineo" src="assets/brand/navidad/trineo.png" alt="" aria-hidden="true">';
        }
    }
}

/** Adorno bajo la barra superior en las pantallas internas. */
function tema_deco_barra(string $tema): void {
    if ($tema === 'independencia') {
        echo '<div class="deco-banderines" aria-hidden="true">' . str_repeat('<i></i>', 24) . '</div>';
    } elseif ($tema === 'navidad') {
        echo '<div class="deco-guirnalda" aria-hidden="true">' . str_repeat('<i></i>', 13) . '</div>';
    }
}

/** Pie decorativo del ingreso (solo Navidad: colinas nevadas). */
function tema_deco_pie(string $tema): void {
    if ($tema === 'navidad') echo '<div class="deco-colinas" aria-hidden="true"></div>';
}

/** ¿El saludo del estudiante va dentro de la cinta roja? (solo Navidad) */
function tema_usa_banner(string $tema): bool {
    return $tema === 'navidad';
}
