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

/**
 * Segunda conferência: ESTE script está ao lado do MESMO código que a web roda?
 *
 * Os scripts daqui carregam o núcleo com dirname(__DIR__) — eles fixam o
 * código que está ao lado deles. Já index.php, cron.php e install.php
 * perguntam ao localizar.php, que PREFERE a pasta irmã. Numa mudança pela
 * metade (código já na irmã, cópia velha ainda no public_html) as duas coisas
 * apontam para lugares diferentes: a tela roda uma cópia e a linha de comando
 * roda outra.
 *
 * O estrago não é abstrato. Rodando o migrate.php da cópia VELHA, ele lê o
 * sql/ velho, conclui "nada pendente" e vai embora — enquanto a migração nova,
 * que está na irmã, nunca é aplicada. Ninguém vê erro: vê um "nada pendente"
 * que é verdade sobre a pasta errada.
 *
 * Aqui não se conserta nada sozinho: rodar a outra cópia por conta própria
 * seria trocar uma surpresa por outra. Recusa-se, dizendo quais são as duas.
 */
$meuApp = dirname(__DIR__);

$publico = getenv('UNIFICAR_PUBLIC') ?: null;
if ($publico === null) {
    if (str_ends_with($meuApp, '-codigo') && @is_file(substr($meuApp, 0, -7) . '/index.php')) {
        $publico = substr($meuApp, 0, -7);
    } elseif (@is_file($meuApp . '/index.php')) {
        $publico = $meuApp;
    }
}

if ($publico !== null) {
    // A MESMA ordem do localizar.php, e ela tem de continuar a mesma.
    $daWeb = null;
    foreach ([getenv('UNIFICAR_APP') ?: null,
              dirname($publico) . '/' . basename($publico) . '-codigo',
              $publico] as $cand) {
        if ($cand !== null && $cand !== '' && @is_file($cand . '/core/bootstrap.php')) {
            $daWeb = $cand;
            break;
        }
    }
    if ($daWeb !== null && realpath($daWeb) !== realpath($meuApp)) {
        fwrite(STDERR,
            "Recusado: este script rodaria um código DIFERENTE do que o portal roda.\n\n"
            . "  este script está em .. {$meuApp}\n"
            . "  o portal (web) roda ... {$daWeb}\n\n"
            . "Isso acontece quando a mudança de pastas ficou pela metade. Rode o script\n"
            . "de dentro de {$daWeb}/scripts/, ou termine a mudança apagando a cópia que\n"
            . "sobrou. O checkup, em Administração, lista o que falta.\n");
        exit(1);
    }
}
