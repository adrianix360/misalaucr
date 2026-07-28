<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rules.php';
require_once __DIR__ . '/lib/layout.php';

$u = require_role(['student']);
$org = org_of($u);
if (!$org || !$org['active']) { logout(); header('Location: login.php'); exit; }
process_automatic();

/* --- acciones --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = $_POST['a'] ?? '';
    if ($a === 'reservar') {
        [$ok, $msg] = try_reserve($u, $org, (int)$_POST['room_id'], (int)$_POST['hora'], (int)$_POST['bloques']);
    } elseif ($a === 'cancelar') {
        [$ok, $msg] = cancel_reservation($u, (int)$_POST['res_id']);
    } elseif ($a === 'checkin') {
        [$ok, $msg] = do_checkin($u, (int)$_POST['res_id']);
    } else { $ok = false; $msg = 'Acción desconocida.'; }
    flash_set($ok, $msg);
    header('Location: student.php'); exit;
}

/* --- datos de la vista --- */
$hoy    = today();
$ahora  = time();
$horaAct = (int)date('G');
$abierto = is_open_day($org, $hoy);
$horas   = block_hours($org);
$rooms   = db()->prepare("SELECT * FROM rooms WHERE org_id = ? ORDER BY name");
$rooms->execute([$org['id']]);
$rooms = $rooms->fetchAll();
$occ = day_occupancy((int)$org['id'], $hoy);

$usadas = weekly_used_hours((int)$u['id'], $org, $hoy);
$rest   = max(0, (int)$org['max_hours_week'] - $usadas);
$bloqueo = student_block_reason($u);

// Mis reservas de hoy y futuras (activas) + historial corto
$st = db()->prepare(
    "SELECT r.*, rm.name AS room_name FROM reservations r JOIN rooms rm ON rm.id = r.room_id
     WHERE r.user_id = ? ORDER BY r.rdate DESC, r.start_hour DESC LIMIT 12");
$st->execute([$u['id']]);
$mias = $st->fetchAll();
$misBloquesHoy = [];
foreach ($mias as $m) if ($m['rdate'] === $hoy && $m['status'] === 'activa')
    foreach (range($m['start_hour'], $m['end_hour'] - 1) as $h) $misBloquesHoy[$m['room_id']][$h] = true;

// selección pendiente (tocó un bloque libre)
$sel = null;
if (isset($_GET['sel']) && preg_match('/^(\d+),(\d+)$/', $_GET['sel'], $m2)) {
    $sel = ['room_id' => (int)$m2[1], 'hora' => (int)$m2[2]];
}

page_top('Reservar', $u, 'student');
?>
<h1>Hola, <?= e(explode(' ', trim($u['name']))[0]) ?> 👋</h1>
<p class="sub"><?= e($org['name']) ?> · <?= e(strftime_es($hoy)) ?></p>
<?php show_flash(); ?>

<?php if ($bloqueo): ?><div class="alert bad"><?= e($bloqueo) ?></div><?php endif; ?>

<div class="saldo">
  <b><?= $rest ?>h</b>
  <div>te quedan disponibles esta semana <span class="mini">(máx. <?= (int)$org['max_hours_week'] ?>h por semana, <?= (int)$org['max_blocks_session'] ?>h por sesión)</span></div>
</div>

<?php
/* --- mis reservas activas de hoy: check-in / cancelar --- */
$activas = array_filter($mias, fn($m) => $m['status'] === 'activa');
if ($activas): ?>
<h2>Tus reservas activas</h2>
<?php foreach ($activas as $m):
    $ini = strtotime(dt($m['rdate'], (int)$m['start_hour']));
    $winStart = max($ini, strtotime($m['created_at']));
    $winEnd = $winStart + 60 * (int)$org['checkin_minutes'];
    $enVentana = !$m['checked_in_at'] && $ahora >= $winStart && $ahora <= $winEnd && $ahora >= min($ini, $winStart);
?>
<div class="res">
  <div>
    <div class="qué"><?= e($m['room_name']) ?> · <?= (int)$m['start_hour'] ?>:00–<?= (int)$m['end_hour'] ?>:00</div>
    <div class="cuando"><?= e(date('d/m/Y', strtotime($m['rdate']))) ?>
      <?php if ($m['checked_in_at']): ?> · ✅ llegada confirmada<?php elseif ($enVentana): ?> · ⏱️ confirma tu llegada (quedan <?= max(0, (int)ceil(($winEnd - $ahora) / 60)) ?> min)<?php endif; ?>
    </div>
  </div>
  <div style="display:flex; gap:6px">
    <?php if ($enVentana): ?>
    <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="a" value="checkin"><input type="hidden" name="res_id" value="<?= (int)$m['id'] ?>">
      <button class="btn verde chico">✔ Confirmar que llegué</button></form>
    <?php endif; ?>
    <?php if (!$m['checked_in_at']): ?>
    <form class="inline" method="post" onsubmit="return confirm('¿Cancelar esta reserva?')"><?= csrf_field() ?><input type="hidden" name="a" value="cancelar"><input type="hidden" name="res_id" value="<?= (int)$m['id'] ?>">
      <button class="btn gris chico">Cancelar</button></form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; endif; ?>

<h2>Salas de hoy</h2>
<?php if (!$abierto): ?>
  <div class="card">Hoy las salas no están en operación. Horario: lunes a viernes, <?= (int)$org['open_hour'] ?>:00–<?= (int)$org['close_hour'] ?>:00. Las reservas del día se abren a las <?= (int)$org['open_hour'] ?>:00 a.m.</div>
<?php elseif (($preApertura = $ahora < strtotime(dt($hoy, (int)$org['open_hour']))) && true): ?>
  <div class="card">Las reservas de hoy se abren a las <b><?= (int)$org['open_hour'] ?>:00 a.m.</b> A esa hora se habilitan todos los bloques del día, por orden de llegada.</div>
<?php else: ?>
<p class="sub">Toca un bloque <b style="color:#10613f">libre</b> para reservarlo. Solo se reserva para hoy.</p>
<div class="card">
  <div class="grid-salas">
    <div class="hd"></div>
    <?php foreach ($rooms as $r): ?>
      <div class="hd"><?= e($r['name']) ?><br><span class="mini"><?= (int)$r['capacity'] ?> pers.</span></div>
    <?php endforeach; ?>
    <?php foreach ($horas as $h): ?>
      <div class="hora"><?= $h ?>:00</div>
      <?php foreach ($rooms as $r):
        $rid = (int)$r['id'];
        $vencido = $ahora >= strtotime(dt($hoy, $h)) + 60 * (int)$org['checkin_minutes'];
        if ($r['status'] !== 'disponible') {
            echo '<span class="blk cerrado">Mant.</span>';
        } elseif (!empty($misBloquesHoy[$rid][$h])) {
            echo '<span class="blk mio">Tuya</span>';
        } elseif (isset($occ[$rid][$h])) {
            echo '<span class="blk ocupado">Ocupado</span>';
        } elseif ($vencido) {
            echo '<span class="blk pasado">—</span>';
        } elseif ($bloqueo || $rest <= 0) {
            echo '<span class="blk ocupado">Libre</span>';
        } else {
            $on = $sel && $sel['room_id'] === $rid && $sel['hora'] === $h;
            echo '<a class="blk libre" style="text-decoration:none' . ($on ? ';outline:2px solid var(--verde)' : '') . '" href="student.php?sel=' . $rid . ',' . $h . '#confirmar">Libre</a>';
        }
      endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($sel && !$bloqueo && $rest > 0):
    $roomSel = null; foreach ($rooms as $r) if ((int)$r['id'] === $sel['room_id']) $roomSel = $r;
    $h = $sel['hora'];
    $puede2 = $rest >= 2 && (int)$org['max_blocks_session'] >= 2
              && ($h + 2) <= (int)$org['close_hour']
              && empty($occ[$sel['room_id']][$h + 1])
              && empty($misBloquesHoy[$sel['room_id']][$h + 1]);
    if ($roomSel && $roomSel['status'] === 'disponible' && empty($occ[$sel['room_id']][$h])): ?>
<div class="confirmar" id="confirmar">
  <b>Reservar <?= e($roomSel['name']) ?>, hoy a las <?= $h ?>:00</b>
  <form method="post" style="margin-top:8px">
    <?= csrf_field() ?>
    <input type="hidden" name="a" value="reservar">
    <input type="hidden" name="room_id" value="<?= $sel['room_id'] ?>">
    <input type="hidden" name="hora" value="<?= $h ?>">
    <label>Duración</label>
    <select name="bloques">
      <option value="1"><?= $h ?>:00 – <?= $h + 1 ?>:00 (1 hora)</option>
      <?php if ($puede2): ?><option value="2"><?= $h ?>:00 – <?= $h + 2 ?>:00 (2 horas)</option><?php endif; ?>
    </select>
    <div style="display:flex; gap:8px; margin-top:10px">
      <button class="btn" type="submit">Confirmar reserva</button>
      <a class="btn gris" href="student.php">Cancelar</a>
    </div>
  </form>
</div>
<?php endif; endif; ?>
<?php endif; ?>

<?php $hist = array_filter($mias, fn($m) => $m['status'] !== 'activa'); if ($hist): ?>
<h2>Historial reciente</h2>
<?php foreach (array_slice($hist, 0, 6) as $m):
    $nombres = ['completada' => 'Completada', 'cancelada' => 'Cancelada', 'no_show' => 'No-show']; ?>
<div class="res">
  <div>
    <div class="qué"><?= e($m['room_name']) ?> · <?= (int)$m['start_hour'] ?>:00–<?= (int)$m['end_hour'] ?>:00</div>
    <div class="cuando"><?= e(date('d/m/Y', strtotime($m['rdate']))) ?></div>
  </div>
  <span class="pill <?= e($m['status']) ?>"><?= e($nombres[$m['status']] ?? $m['status']) ?></span>
</div>
<?php endforeach; endif; ?>

<script>setTimeout(() => location.replace('student.php'), 60000);</script>
<?php page_bottom();

function strftime_es(string $ymd): string {
    $dias = ['Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado','Sunday'=>'Domingo'];
    $meses = [1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','setiembre','octubre','noviembre','diciembre'];
    $t = strtotime($ymd);
    return $dias[date('l', $t)] . ' ' . date('j', $t) . ' de ' . $meses[(int)date('n', $t)];
}
