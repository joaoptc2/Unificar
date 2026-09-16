<?php
/**
 * Guarda de linha de comando — o PRIMEIRO require de todo script desta pasta.
 *
 * Por que existe, em um parágrafo: um GET em `/scripts/migrate.php` aplicava
 * migração de esquema no banco do hospital, sem login e sem token. Reproduzido:
 * arquivo pendente em sql/migrations, uma chamada de URL anônima, tabela criada.
 * Não era teoria — `migrate.php`, `migrate_role_grants.php` e `test_contraste.php`
 * simplesmente não tinham guarda, e o .htaccess da raiz bloqueava
 * (core|config|sql|storage|docs) sem nunca listar `scripts`.
 *
 * A guarda mora AQUI, no PHP, e não no .htaccess, porque .htaccess falha em
 * silêncio: o Nginx o ignora inteiro, o Apache com AllowOverride None também, e
 * uma cópia por FTP que perde arquivos ocultos o deixa para trás sem avisar
 * ninguém. Um script que só é seguro quando o servidor colabora não é seguro.
 *
 * E ela vem ANTES do bootstrap: carregar o núcleo primeiro abriria sessão,
 * mandaria cabeçalho e poderia redirecionar para https antes de a recusa sair —
 * trabalho e rastro para uma requisição que não deveria ter existido.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    // A mensagem não diz o que o script faz: quem chegou aqui pela web não
    // precisa saber se acertou o nome de um utilitário de banco.
    exit("Este script é só para a linha de comando.\n");
}
