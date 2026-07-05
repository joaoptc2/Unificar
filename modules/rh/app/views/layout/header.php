<?php
/**
 * Header de COMPATIBILIDADE — o layout legado (html/head/navbar/sidebar)
 * foi substituído pelo layout unificado da plataforma (Core\Layout).
 *
 * Este arquivo apenas abre o buffer de captura do conteúdo e emite as
 * flash messages; o footer.php fecha o buffer e chama Core\Layout::render().
 * Assim os controllers legados (require header + view + footer) continuam
 * funcionando sem reescrita.
 *
 * Variáveis esperadas no escopo do controller: $pageTitle, $page
 * (opcionais: $extraCss, $extraJs — repassadas ao footer).
 */
ob_start();

$flashError   = Session::flash('error');
$flashSuccess = Session::flash('success');
?>
<?php if ($flashError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $flashError ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($flashSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $flashSuccess ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
