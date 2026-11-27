<?php
/**
 * MiSalaUCR — Paneles compartidos de coordinación (horas extra y enlaces de registro).
 * Los usan admin.php (cuenta de la asociación) y student.php (estudiantes Coordinador).
 * La lógica vive en lib/rules.php; aquí solo se dibuja la interfaz.
 */

require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/layout.php';

/**
 * Panel "Horas extra esta semana": buscar estudiante, dar horas, ver y quitar concesiones.
 * $page = archivo que recibe los formularios; $keep = parámetros GET a conservar (ej. ['tab' => 'estudiantes']).
 */
function render_horas_extra_panel(array $org, string $page, array $keep = []): void {
    $xq = trim($_GET['xq'] ?? '');
    [$ini, $fin] = week_bounds($org, today());
    $resultados = [];
    if ($xq !== '') {
        $st = db()->prepare("SELECT id, name, carne FROM users
                             WHERE org_id = ? AND role = 'student' AND active = 1 AND (name LIKE ? OR carne LIKE ?)
                             ORDER BY name LIMIT 10");
        $st->execute([(int)$org['id'], "%$xq%", "%$xq%"]);
        $resultados = $st->fetchAll();
    }
    $grants = horas_extra_semana($org);
    ?>
<div class="card" id="horas-extra">
  <h2 style="margin-top:0">Horas extra esta semana</h2>
  <p class="mini">Suma horas al límite semanal de un estudiante solo para la semana actual
    (<?= e(date('d/m', strtotime($ini))) ?> al <?= e(date('d/m', strtotime($fin))) ?>). La semana siguiente vuelve al límite normal
    de <?= (int)$org['max_hours_week'] ?>h.</p>
  <form method="get" action="<?= e($page) ?>#horas-extra" style="display:flex; gap:8px; align-items:end; margin-bottom:10px">
    <?php foreach ($keep as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <div style="flex:1"><label>Buscar estudiante</label><input name="xq" value="<?= e($xq) ?>" placeholder="nombre o carné"></div>
    <button class="btn gris">Buscar</button>
  </form>
  <?php if ($xq !== '' && !$resultados): ?><p class="mini">Sin resultados para «<?= e($xq) ?>».</p><?php endif; ?>
  <?php foreach ($resultados as $r): ?>
  <form method="post" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap; margin-bottom:8px">
    <?= csrf_field() ?><input type="hidden" name="a" value="extra_dar"><input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
    <div style="min-width:160px; flex:1"><b><?= e($r['name']) ?></b><br><span class="mini"><?= e($r['carne']) ?></span></div>
    <div><label>Horas</label><input type="number" name="horas" min="1" max="20" value="1" style="width:70px" required></div>
    <div style="flex:2; min-width:140px"><label>Motivo (opcional)</label><input name="motivo" maxlength="255" placeholder="Ej: trabajo en grupo"></div>
    <button class="btn chico">Dar horas extra</button>
  </form>
  <?php endforeach; ?>

  <h3 style="margin-bottom:6px">Concedidas esta semana</h3>
  <?php if (!$grants): ?><p class="mini">Aún no hay horas extra concedidas esta semana.</p><?php else: ?>
  <div class="tabla-scroll"><table class="tabla">
    <tr><th>Estudiante</th><th>Horas</th><th>Motivo</th><th>Dadas por</th><th></th></tr>
    <?php foreach ($grants as $g): ?>
    <tr>
      <td><?= e($g['uname']) ?> <span class="mini"><?= e($g['ucarne']) ?></span></td>
      <td>+<?= (int)$g['hours'] ?>h</td>
      <td><?= e($g['reason'] ?? '') ?></td>
      <td><?= e($g['gname'] ?? '—') ?></td>
      <td>
        <form class="inline" method="post" onsubmit="return confirm('¿Quitar estas horas extra?')">
          <?= csrf_field() ?><input type="hidden" name="a" value="extra_quitar"><input type="hidden" name="grant_id" value="<?= (int)$g['id'] ?>">
          <button class="btn gris chico">Quitar</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<?php
}

/** Panel "Enlace de registro": generar, copiar, ver QR, listar pendientes y revocar. */
function render_invites_panel(array $org): void {
    $pend = invites_pendientes($org);
    ?>
<div class="card" id="enlaces-registro">
  <h2 style="margin-top:0">Enlace de registro para estudiantes</h2>
  <p class="mini">Genera un enlace (y su código QR) para que una persona cree su propia cuenta en
    <b><?= e($org['name']) ?></b>. Cada enlace sirve <b>una sola vez</b> y vence a las 48 horas.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="a" value="invite_crear">
    <button class="btn">Generar enlace nuevo</button>
  </form>
  <?php if ($pend): ?>
  <h3 style="margin-bottom:6px">Enlaces pendientes</h3>
  <?php foreach ($pend as $i): $url = invite_url($i['token']); ?>
  <div style="display:flex; gap:14px; align-items:flex-start; flex-wrap:wrap; border-top:1px solid #e6e1d4; padding:10px 0">
    <div class="qr-box" data-qr="<?= e($url) ?>" style="width:120px; height:120px; flex:none"></div>
    <div style="flex:1; min-width:220px">
      <input readonly value="<?= e($url) ?>" onclick="this.select()" style="width:100%">
      <p class="mini" style="margin:6px 0">Creado <?= e(date('d/m H:i', strtotime($i['created_at']))) ?>
        por <?= e($i['creador'] ?? '—') ?> · vence <?= e(date('d/m H:i', strtotime($i['expires_at']))) ?></p>
      <div style="display:flex; gap:6px">
        <button type="button" class="btn gris chico" data-copiar="<?= e($url) ?>">Copiar enlace</button>
        <form class="inline" method="post" onsubmit="return confirm('¿Revocar este enlace? Dejará de funcionar.')">
          <?= csrf_field() ?><input type="hidden" name="a" value="invite_revocar"><input type="hidden" name="invite_id" value="<?= (int)$i['id'] ?>">
          <button class="btn rojo chico">Revocar</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php else: ?><p class="mini" style="margin-top:10px">No hay enlaces pendientes.</p><?php endif; ?>
</div>
<?php if ($pend): ?>
<style>.qr-box svg { width: 100%; height: auto; display: block; }</style>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
(function () {
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    try {
      var qr = qrcode(0, 'M'); qr.addData(el.getAttribute('data-qr')); qr.make();
      el.innerHTML = qr.createSvgTag(4, 2);
    } catch (e) { el.textContent = 'QR no disponible'; el.className += ' mini'; }
  });
  document.querySelectorAll('[data-copiar]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-copiar');
      function ok() { var o = b.textContent; b.textContent = '¡Copiado!'; setTimeout(function () { b.textContent = o; }, 1500); }
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(ok, function () { window.prompt('Copia el enlace:', t); });
      else window.prompt('Copia el enlace:', t);
    });
  });
})();
</script>
<?php endif;
}
