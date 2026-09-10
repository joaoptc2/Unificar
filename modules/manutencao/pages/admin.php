<?php
/**
 * ROTA LEGADA ?page=admin — Setores e Categorias de equipamentos
 *
 * A configuração do módulo agora fica na ADMINISTRAÇÃO CENTRAL
 * (index.php?m=admin&a=module&slug=manutencao&tab=sectors|categories —
 * ver admin_panel.php). Esta rota é mantida apenas como:
 *   • GET  → redirecionamento para o painel central;
 *   • POST → processamento dos formulários (setores/categorias) e
 *            redirecionamento de volta ao painel.
 *
 * A antiga aba "Hospital / Dados da unidade" foi descontinuada — o nome da
 * organização é o do núcleo (Core\Settings 'org_name').
 */
requireLogin();

// Aceita também o parâmetro legado ?action=categories (links e favoritos
// antigos), para cair na aba certa do painel central.
$tabParam = (string) ($_GET['tab'] ?? $_POST['tab'] ?? $_GET['action'] ?? '');
$tab = str_contains($tabParam, 'categor') ? 'categories' : 'sectors';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    core_redirect(core_admin_url(MAN_MODULE_SLUG, $tab));
}

if (verifyCsrf()) {
    $tab = manAdminHandlePost($_POST['action'] ?? '') ?? $tab;
} else {
    flash('error', 'Sessão expirada ou token inválido. Tente novamente.');
}
core_redirect(core_admin_url(MAN_MODULE_SLUG, $tab));
