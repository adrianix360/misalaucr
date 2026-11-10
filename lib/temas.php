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
        case 'independencia': return '';
        case 'halloween':     return '&family=Creepster';
        case 'navidad':       return '&family=Lilita+One';
    }
    return '';
}

/** Color de la barra del navegador móvil (<meta name="theme-color">). */
function tema_color_barra(string $tema): string {
    return [
        'independencia' => '#ffffff',
        'halloween'     => '#14101f',
        'navidad'       => '#d62839',
    ][$tema] ?? '#ffffff';
}

/**
 * Texto de temporada. Si el tema no define la clave, devuelve el neutro.
 * Uso:  tema_txt($tema, 'saludo', 'Hola, %s 👋')
 */
function tema_txt(string $tema, string $clave, string $neutro): string {
    static $txt = [
        'independencia' => [
            'login_sub'   => 'Setiembre patrio: tu sala de estudio, en dos toques.',
            'login_btn'   => 'Entrar',
            'saludo'      => 'Hola, %s',
            'saldo'       => 'te quedan esta semana',
            'salas_h2'    => 'Salas de hoy',
            'salas_ayuda' => 'Toca un bloque <b>libre</b> para reservarlo. Solo se reserva para hoy.',
            'perfil_ok'   => 'Perfil actualizado.',
            'confirmar'   => 'Reservar %s, %s a las %d:00',
        ],
        'halloween' => [
            'login_sub'   => 'Reservá tu guarida antes de que alguien más la conjure.',
            'login_btn'   => 'Entrar a la guarida',
            'saludo'      => 'Buuu, %s',
            'saldo'       => 'te quedan antes de la medianoche',
            'salas_h2'    => 'Guaridas de hoy',
            'salas_ayuda' => 'Tocá un bloque <b>libre</b> para apoderarte de él. Solo se reserva para hoy.',
            'perfil_ok'   => 'Perfil actualizado. Ningún fantasma tocó tus datos.',
            'confirmar'   => 'Apoderarte de %s, %s a las %d:00',
            'blk_ocupado' => 'Ocupada ⏳',
        ],
        'navidad' => [
            'login_sub'   => 'El taller ya abrió: los duendes envolvieron las horas. Apartá tu sala antes de que se las lleven todas.',
            'login_btn'   => 'Subirme al trineo',
            'saludo'      => '¡Hola, %s! El taller está abierto',
            'saldo'       => 'te quedan esta semana',
            'salas_h2'    => 'Salas de hoy',
            'salas_ayuda' => 'Cada cajita es una hora de sala. Tocá una <b>libre</b> y la envolvemos a tu nombre. Solo se reserva para hoy.',
            'perfil_ok'   => 'Perfil actualizado. Quedaste en la lista de los buenos.',
            'confirmar'   => 'Envolver %s, %s a las %d:00',
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
        echo '<div class="deco-franja" aria-hidden="true"></div>';
    } elseif ($tema === 'halloween') {
        echo '<div class="deco-calabazas" aria-hidden="true">' . str_repeat('<i></i>', 7) . '</div>';
    } elseif ($tema === 'navidad') {
        // Equipos modestos / ahorro de datos: menos confeti (clase msu-lite).
        echo '<script>(function(){var c=navigator.connection;if((navigator.hardwareConcurrency||8)<=4||(c&&c.saveData))document.documentElement.classList.add("msu-lite");})();</script>';
        echo '<div class="deco-banderines" aria-hidden="true">' . str_repeat('<i></i>', 16) . '</div>';
        // Confeti: 18 piezas, posiciones fijas (sin azar) para que no cambie entre cargas.
        $conf = '';
        for ($i = 0; $i < 18; $i++) {
            $x = 3 + (($i * 37) % 94);          // 3%–96%
            $d = -(($i * 53) % 100) / 10;       // retraso negativo: ya cayendo al cargar
            $t = 8 + (($i * 29) % 60) / 10;     // 8s–13.9s
            $conf .= '<i style="--x:' . $x . '%;--d:' . $d . 's;--t:' . $t . 's"></i>';
        }
        echo '<div class="deco-confeti" aria-hidden="true">' . $conf . '</div>';
    }
}

/** Adorno bajo la barra superior en las pantallas internas. */
function tema_deco_barra(string $tema): void {
    // 'independencia' no imprime nada: la banda tricolor vive en .topbar::after.
    if ($tema === 'navidad') {
        echo '<div class="deco-baston" aria-hidden="true"><i></i></div>';
    }
}

/** Rótulo del mes con la bandera que ondea (encima del <h1> del saludo). */
function tema_deco_saludo(string $tema): void {
    if ($tema !== 'independencia') return;
    echo '<div class="deco-mes">'
       . '<span class="deco-bandera" aria-hidden="true"><i></i></span>'
       . 'Setiembre · mes de la patria</div>';
}

/** Pie decorativo del ingreso (solo Navidad: pila de regalos con un duende que asoma). */
function tema_deco_pie(string $tema): void {
    if ($tema !== 'navidad') return;
    echo '<div class="deco-regalos" aria-hidden="true">' . tema_svg_regalos() . '</div>';
}

/** Regalos + duende dentro de la cinta del saludo en Reservar (solo Navidad). */
function tema_deco_banner(string $tema): void {
    if ($tema !== 'navidad') return;
    echo '<div class="deco-regalos deco-regalos--banner" aria-hidden="true">' . tema_svg_regalos() . '</div>';
}

/**
 * Medidor de bastón de caramelo dentro de .saldo (solo Navidad).
 * $rest = horas que le quedan; $max = máximo semanal de la asociación.
 */
function tema_deco_saldo(string $tema, int $rest, int $max): void {
    if ($tema !== 'navidad' || $max <= 0) return;
    $rest = max(0, min($rest, $max));
    $pct  = round($rest / $max * 100, 1);
    $marcas = array_unique([0, (int)round($max / 3), (int)round($max * 2 / 3), $max]);
    echo '<div class="deco-saldo">';
    echo '<div class="deco-saldo-barra" role="img" aria-label="' . $rest . ' de ' . $max . ' horas disponibles esta semana">'
       . '<span style="width:' . $pct . '%"><i></i></span></div>';
    echo '<div class="deco-saldo-escala" aria-hidden="true">';
    foreach ($marcas as $k => $m) echo '<span>' . $m . ($k === count($marcas) - 1 ? ' h' : '') . '</span>';
    echo '</div>';
    echo '<p class="deco-saldo-nota">El bastón de caramelo se va gastando con cada reserva. Se rellena el lunes.</p>';
    echo '</div>';
}

/** SVG de la pila de regalos con duende (compartido por login y Reservar). */
function tema_svg_regalos(): string {
    return <<<'SVG'
<svg viewBox="0 0 460 300" focusable="false">
<ellipse cx="230" cy="290" rx="224" ry="9" fill="#ebd5ae"/>
<g stroke="#123a5e" stroke-width="3" stroke-linejoin="round">
<rect x="340" y="250" width="110" height="38" rx="5" fill="#2ec5d8"/><rect x="350" y="216" width="92" height="34" rx="5" fill="#ffc93c"/><rect x="344" y="186" width="100" height="30" rx="5" fill="#123a5e"/><rect x="388" y="186" width="13" height="102" fill="#d62839"/><path d="M394 186c-16-20-34-6-20 3zM394 186c16-20 34-6 20 3z" fill="#d62839"/>
<g class="duende"><circle cx="280" cy="198" r="25" fill="#f2c9a0"/><path d="M250 184L282 98l30 86z" fill="#17a56b"/><rect x="244" y="176" width="74" height="15" rx="7.5" fill="#d62839"/><circle cx="282" cy="96" r="8" fill="#ffc93c"/><circle cx="271" cy="202" r="2.6" fill="#123a5e" stroke="none"/><circle cx="289" cy="202" r="2.6" fill="#123a5e" stroke="none"/></g>
<rect x="222" y="206" width="118" height="84" fill="#0b7a53"/><rect x="273" y="206" width="16" height="84" fill="#ffc93c"/><path d="M222 206l-16-20h34l12 20zM340 206l16-20h-34l-12 20z" fill="#0a6645"/>
<rect x="40" y="162" width="150" height="128" fill="#d62839"/><rect x="104" y="162" width="22" height="128" fill="#ffc93c"/>
<g class="tapa"><rect x="30" y="136" width="170" height="30" rx="6" fill="#b81f2f"/><rect x="104" y="136" width="22" height="30" fill="#ffc93c"/><path d="M115 136c-24-32-54-10-32 5zM115 136c24-32 54-10 32 5z" fill="#ffc93c"/><circle cx="115" cy="134" r="7" fill="#f2a900"/></g>
</g>
<g fill="#ffc93c"><path class="chis" d="M214 104l3 9 9 3-9 3-3 9-3-9-9-3 9-3z"/><path class="chis" d="M28 96l3 9 9 3-9 3-3 9-3-9-9-3 9-3z"/><path class="chis" d="M430 140l3 9 9 3-9 3-3 9-3-9-9-3 9-3z"/></g>
</svg>
SVG;
}

/** ¿El saludo del estudiante va dentro de la cinta roja? (solo Navidad) */
function tema_usa_banner(string $tema): bool {
    return $tema === 'navidad';
}
