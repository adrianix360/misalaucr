<?php
/**
 * MiSalaUCR — Podio mensual de la asociación (vista del estudiante).
 * El cálculo vive en lib/ranking.php; aquí solo se dibuja.
 */
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/ranking.php';

$u   = require_role(['student']);
$org = org_of($u);
if (!$org || !$org['active']) { logout(); header('Location: login.php'); exit; }

[$desde, $hasta, $etiqueta] = ranking_periodo();
$activo = ranking_activo($org);

$filas    = $activo ? ranking_org((int)$org['id'], $desde, $hasta) : [];
$visibles = ranking_visibles($filas);
$yo       = ranking_mi_fila((int)$u['id'], $filas);

// Mi fila dentro del podio público (null si me oculté o si el admin me excluyó).
$miVisible = null;
foreach ($visibles as $v) if ((int)$v['user_id'] === (int)$u['id']) { $miVisible = $v; break; }

$podio    = array_slice($visibles, 0, 3);
$resto    = array_slice($visibles, 3, 7);   // puestos 4 al 10
$hayPodio = count($visibles) >= 3;          // con 1 o 2 personas un podio se ve triste

page_top('Podio', $u, 'ranking');
?>
<h1>Podio de <?= e($etiqueta) ?></h1>
<p class="sub"><?= e($org['name']) ?></p>

<?php if (!$activo): ?>
  <div class="card">
    <p>Tu asociación todavía no tiene activado el podio de horas.</p>
    <p class="mini">Cuando lo active, acá vas a ver quiénes más aprovecharon las salas durante el mes.</p>
  </div>
<?php elseif (!$filas): ?>
  <div class="card">
    <p>Todavía nadie ha reservado en <?= e($etiqueta) ?>.</p>
    <p class="mini">Reservá tu primera hora y estrenás el podio.</p>
  </div>
<?php else: ?>

<?php if ($hayPodio): ?>
<div class="podio">
  <?php foreach ($podio as $i => $p):
      $lugar   = $i + 1;
      $medalla = ['🥇', '🥈', '🥉'][$i];
      $esYo    = (int)$p['user_id'] === (int)$u['id'];
  ?>
  <div class="puesto p<?= $lugar ?><?= $esYo ? ' yo' : '' ?>">
    <span class="medalla" aria-hidden="true"><?= $medalla ?></span>
    <?= ranking_avatar($p, $lugar === 1 ? 76 : 60) ?>
    <b class="nom"><?= e($p['nombre_corto']) ?><?= $esYo ? ' <span class="tag-yo">vos</span>' : '' ?></b>
    <span class="hrs"><?= (int)$p['horas'] ?>h</span>
    <?php if ($p['fav_room']): ?><span class="fav">Sala favorita: <?= e($p['fav_room']) ?></span><?php endif; ?>
    <div class="base"><span><?= $lugar ?></span></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php /* Con menos de 3 visibles el podio no se dibuja: se listan todos aquí. */
      $lista = $hayPodio ? $resto : $visibles;
      if ($lista): ?>
<div class="card">
  <h2 style="margin-top:0"><?= $hayPodio ? 'Del 4 en adelante' : 'Ranking del mes' ?></h2>
  <ul class="rank-lista">
    <?php foreach ($lista as $r):
        $esYo = (int)$r['user_id'] === (int)$u['id']; ?>
    <li<?= $esYo ? ' class="yo"' : '' ?>>
      <span class="pos"><?= (int)$r['posicion_publica'] ?></span>
      <?= ranking_avatar($r, 34) ?>
      <span class="nom"><?= e($r['nombre_corto']) ?><?= $esYo ? ' <span class="tag-yo">vos</span>' : '' ?></span>
      <?php if ($r['fav_room']): ?><span class="fav mini"><?= e($r['fav_room']) ?></span><?php endif; ?>
      <span class="hrs"><?= (int)$r['horas'] ?>h</span>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<h2>Tu posición</h2>
<div class="card rank-yo-card">
<?php if (!$yo): ?>
  <p>Todavía no aparecés en el ranking de <?= e($etiqueta) ?>.</p>
  <p class="mini">Reservá tu primera hora del mes y entrás automáticamente.</p>
  <p><a class="btn chico" href="student.php">Reservar una sala</a></p>
<?php else:
    $faltan = ranking_faltan_para_subir($filas, (int)$u['id']);
    $pos    = $miVisible ? (int)$miVisible['posicion_publica'] : (int)$yo['posicion'];
    $total  = $miVisible ? count($visibles) : count($filas);
?>
  <div class="rank-yo-linea">
    <?= ranking_avatar($yo, 48) ?>
    <div>
      <b>Vas #<?= $pos ?> de <?= $total ?></b>
      <div class="mini">
        <?= (int)$yo['horas'] ?>h reservadas en <?= e($etiqueta) ?>
        <?php if ($yo['fav_room']): ?> · sala favorita: <?= e($yo['fav_room']) ?><?php endif; ?>
        <?php if ($yo['asistencia'] !== null): ?> · <?= (int)$yo['asistencia'] ?>% de asistencia<?php endif; ?>
      </div>
    </div>
  </div>
  <?php if ($faltan): ?>
    <p class="rank-gancho"><?= (int)$faltan['horas'] ?>h más y alcanzás el #<?= (int)$faltan['posicion'] ?>.</p>
  <?php else: ?>
    <p class="rank-gancho">Vas de primero en <?= e($etiqueta) ?>. 👑</p>
  <?php endif; ?>
  <?php if (!$miVisible): ?>
    <p class="mini">Estás <b>oculto del podio</b>, así que nadie más ve tu nombre acá. Esta es tu posición privada.</p>
  <?php endif; ?>
<?php endif; ?>
</div>

<p class="mini">
  El podio cuenta las <b>horas reservadas</b> durante <?= e($etiqueta) ?>, incluidas las reservas
  activas y las que no se asistieron. Se reinicia al empezar cada mes.
</p>

<?php endif; ?>
<?php page_bottom();
