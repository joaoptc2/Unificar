<?php
/**
 * Database — adaptador da conexão PDO única da plataforma (Core\DB).
 * Todos os módulos usam o mesmo banco; as tabelas do RH são prefixadas
 * com `rh_`.
 */
class Database
{
    public static function getInstance(): PDO
    {
        return Core\DB::pdo();
    }

    // Impedir instanciação
    private function __construct() {}
    private function __clone() {}
}
