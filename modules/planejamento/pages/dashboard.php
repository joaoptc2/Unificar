<?php
/** PLANEJAMENTO — painel (esqueleto). */

declare(strict_types=1);

core_require('dashboard.view');

ob_start();
?>
<h1 class="h4 mb-3"><i class="bi bi-kanban me-2"></i>Planejamento</h1>
<p class="text-muted">Módulo em implantação: planos de trabalho, quadros, fluxogramas e modelos.</p>
<?php
Core\Layout::render(['title' => 'Painel', 'content' => (string) ob_get_clean(), 'active' => 'dashboard']);
