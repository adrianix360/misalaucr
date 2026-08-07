<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/schedule.php';

$u = require_role(['student']);
$org = org_of($u);
if (!$org || !$org['active']) { logout(); header('Location: login.php'); exit; }

$horario = get_org_schedule((int)$org['id']);
$abierto = is_org_open_now($org, $horario);
$dias    = horario_dias();

/** Fecha 'YYYY-MM-DD' en texto legible en español (sin depender de la configuración regional). */
function horario_fecha_es(string $ymd): string {
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
              'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];
    $t = strtotime($ymd);
    if ($t === false) return $ymd;
    return date('j', $t) . ' de ' . ($meses[(int)date('n', $t)] ?? '') . ' de ' . date('Y', $t);
}

$titulo = ($horario !== null && $horario['title'] !== '') ? $horario['title'] : 'Horario de atención';
$slots  = ($horario !== null && !empty($horario['slots'])) ? $horario['slots'] : [];

// Solo se muestran las columnas de días que aparecen en al menos una franja.
$diasUsados = [];
foreach ($slots as $s) {
    $sd = (isset($s['days']) && is_array($s['days'])) ? $s['days'] : [];
    foreach ($sd as $d) {
        $d = (int)$d;
        if ($d >= 1 && $d <= 7) $diasUsados[$d] = true;
    }
}
ksort($diasUsados);
$diasUsados = array_keys($diasUsados);

$colorFondo = $horario !== null ? (string)$horario['primary_color'] : '#F4C430';
$colorTexto = $horario !== null ? (string)$horario['text_color']    : '#1A1A1A';

$excepciones = schedule_upcoming_exceptions($horario);
$hoy = date('Y-m-d');

page_top('Horario de atención', $u, 'horario');
?>
<h1><?= e($titulo) ?></h1>
<p class="sub"><?= e($org['name']) ?></p>

<p>
  <?php if ($abierto): ?>
    <span class="badge-estado abierto">Abierto ahora</span>
  <?php else: ?>
    <span class="badge-estado cerrado">Cerrado ahora</span>
  <?php endif; ?>
</p>

<?php if (!$slots): ?>
  <div class="card">
    <p>Tu asociación aún no ha publicado su horario de atención.</p>
    <?php
      $diasLegado = [];
      foreach (explode(',', (string)($org['days_open'] ?? '')) as $d) {
          $d = (int)trim($d);
          if (isset($dias[$d])) $diasLegado[] = $dias[$d];
      }
    ?>
    <p class="mini">
      Mientras tanto, el horario operativo de las salas es de
      <b><?= e(sprintf('%02d:00', (int)($org['open_hour'] ?? 0))) ?></b> a
      <b><?= e(sprintf('%02d:00', (int)($org['close_hour'] ?? 0))) ?></b><?php
        if ($diasLegado): ?>, de <?= e(implode(', ', $diasLegado)) ?><?php endif; ?>.
    </p>
  </div>
<?php else: ?>
  <div class="card tabla-scroll">
    <table class="tabla horario-grid">
      <tr>
        <th>Franja</th>
        <?php foreach ($diasUsados as $d): ?><th><?= e($dias[$d]) ?></th><?php endforeach; ?>
      </tr>
      <?php foreach ($slots as $s): ?>
        <?php $sd = array_map('intval', (isset($s['days']) && is_array($s['days'])) ? $s['days'] : []); ?>
        <tr>
          <td class="franja"><?= e((string)($s['start'] ?? '')) ?> – <?= e((string)($s['end'] ?? '')) ?></td>
          <?php foreach ($diasUsados as $d): ?>
            <?php if (in_array($d, $sd, true)): ?>
              <td style="background:<?= e($colorFondo) ?>;color:<?= e($colorTexto) ?>" title="Atención">✓</td>
            <?php else: ?>
              <td></td>
            <?php endif; ?>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<?php if ($excepciones): ?>
  <div class="card">
    <h2 style="margin-top:0">Excepciones</h2>
    <p class="mini">Días sin atención programados.</p>
    <ul class="lista-excepciones">
      <?php foreach ($excepciones as $x): ?>
        <?php $fx = (string)($x['date'] ?? ''); ?>
        <li<?= $fx === $hoy ? ' class="hoy"' : '' ?>>
          <b><?= e(horario_fecha_es($fx)) ?></b> — <?= e((string)($x['label'] ?? '')) ?>
          <?= $fx === $hoy ? '<span class="badge-estado cerrado">Hoy</span>' : '' ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php $tsAct = $horario !== null ? strtotime($horario['updated_at']) : false; ?>
<?php if ($tsAct !== false): ?>
  <p class="mini">Actualizado: <?= e(date('d/m/Y H:i', $tsAct)) ?></p>
<?php endif; ?>

<?php /* El horario cambia poco: basta refrescar cada 5 minutos para que el
         indicador de «Abierto ahora» no quede desactualizado. */ ?>
<script>setTimeout(function () { location.replace('horario.php'); }, 300000);</script>
<?php page_bottom();
