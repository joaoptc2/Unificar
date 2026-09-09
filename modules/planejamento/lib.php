<?php
/**
 * MÓDULO PLANEJAMENTO — funções auxiliares (prefixo plan_).
 * (esqueleto — implementação completa nas páginas do módulo)
 */

declare(strict_types=1);

use Core\DB;

/** URL de uma página do módulo. */
function plan_url(string $page, array $params = []): string
{
    return core_module_url('planejamento', array_merge(['page' => $page], $params));
}
