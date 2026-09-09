<?php
/**
 * Controller de Relatórios — DESCONTINUADO.
 * O relatório de conformidade passou a ser a seção "Conformidade" do
 * Dashboard (dashboard#conformidade). Mantido apenas o redirecionamento.
 */

function reports_index($param = null) {
    core_require('dashboard.view');
    core_redirect(url('dashboard') . '#conformidade');
}
