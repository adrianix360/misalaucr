<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
boot();

$reason = trim($_GET['r'] ?? '');

page_top('Asociación suspendida');
?>
<div class="login-box">
  <div class="brand-big">
    <span class="msu-logo msu-logo--xl msu-logo--vertical" aria-label="MiSalaUCR">
      <span class="msu-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
      <span class="msu-word"><b>MiSala</b><span>UCR</span></span>
    </span>
  </div>
  <div class="card" style="text-align:center">
    <h1 style="margin-top:4px">⏸️ Asociación suspendida</h1>
    <p>El servicio de reservas de tu asociación está <b>temporalmente suspendido</b>,
       por lo que no es posible acceder al sistema ni gestionar reservas.</p>
    <?php if ($reason !== ''): ?>
      <div class="alert bad" style="text-align:left"><b>Motivo:</b> <?= e($reason) ?></div>
    <?php endif; ?>
    <p class="mini">Si tienes dudas, contacta a la junta directiva de tu asociación.</p>
    <a class="btn gris" href="login.php">Volver al inicio</a>
  </div>
</div>
<?php page_bottom();
