<?php
/**
 * ============================================================
 * ONDE MORA O CÓDIGO
 *
 * Este arquivo vive na área pública, ao lado do index.php, e é a ÚNICA
 * coisa que os três pontos de entrada públicos (index.php, cron.php e
 * install.php) precisam saber de antemão. Ele responde uma pergunta só:
 * de onde carregar core/bootstrap.php.
 *
 * Por que existe: a instalação pode ter o código junto (a arrumação de
 * sempre) ou numa pasta irmã, fora do public_html. Sem isto, cada ponto de
 * entrada teria a sua própria ideia de onde procurar — e foi exatamente
 * assim que o install.php e o bootstrap acabaram com duas cascatas
 * diferentes para achar o config, que só coincidiam por sorte.
 *
 * A ORDEM DOS DEGRAUS É DELIBERADA, e o degrau 3 vem por último de
 * propósito. Se __DIR__ viesse antes da pasta irmã, uma migração pela
 * metade — código já copiado para fora, cópia velha ainda no public_html —
 * continuaria rodando o código VELHO. O administrador conferiria o portal,
 * veria tudo funcionando, apagaria as pastas antigas e só então descobriria
 * se a cópia nova prestava. Com a irmã na frente, subir a pasta JÁ troca o
 * código em uso, dá para conferir de verdade, e desfazer é apagar a irmã.
 * ============================================================
 */

declare(strict_types=1);

if (!function_exists('unificar_localizar_app')) {
    /**
     * @param string $publico A raiz servida (o diretório do front controller).
     * @return string         A pasta onde está core/bootstrap.php.
     */
    function unificar_localizar_app(string $publico): string
    {
        $candidatos = [];

        // 1. Dito explicitamente — SetEnv no Apache, env do PHP-FPM, systemd.
        //    É a saída para quem tem um arranjo que a convenção não cobre.
        $env = getenv('UNIFICAR_APP');
        if ($env !== false && $env !== '') {
            $candidatos[] = rtrim($env, '/');
        }

        // 2. Pasta irmã, com o nome DERIVADO da pasta pública — a mesma
        //    convenção que o config já usa ("<nome>-config"). Não é
        //    "../codigo": em hospedagem com addon domains o pai é o home da
        //    conta, compartilhado por vários sites, e uma pasta chamada só
        //    "codigo" colidiria entre duas instalações, em silêncio.
        $candidatos[] = dirname($publico) . '/' . basename($publico) . '-codigo';

        // 3. A arrumação de sempre: o código está aqui mesmo.
        $candidatos[] = $publico;

        // O que qualifica um candidato NÃO é só ter o bootstrap: é ter o
        // suficiente para o núcleo subir. Uma pasta irmã com a cópia ainda em
        // andamento tem o bootstrap (ele vem cedo, em ordem alfabética) e não
        // tem o resto — e nesse intervalo o site já prefere a irmã e cai com
        // página em branco. Medido numa subida em ordem alfabética: a janela
        // ia de "core/bootstrap.php chegou" até o fim da cópia.
        //
        // Estes três são os que o bootstrap carrega ANTES de existir qualquer
        // tratador de erro — sem eles não há nem mensagem, só página vazia.
        // Isto ENCURTA a janela; não a fecha. Fechar é atribuição do
        // procedimento, e o README manda subir com outro nome e renomear no
        // fim, porque renomear é instantâneo.
        $exigidos = ['/core/bootstrap.php', '/core/helpers.php', '/core/src/Config.php'];
        foreach ($candidatos as $c) {
            if ($c === '') {
                continue;
            }
            $completo = true;
            foreach ($exigidos as $arq) {
                if (!@is_file($c . $arq)) {
                    $completo = false;
                    break;
                }
            }
            if ($completo) {
                return $c;
            }
        }

        // Nada serviu. Morrer aqui, com a causa, é melhor que um require
        // fatal três linhas adiante: a mensagem do PHP diria só "failed to
        // open stream" com um caminho que ninguém sabe de onde saiu.
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit(
            "Não encontrei o código do portal (core/bootstrap.php).\n\n"
            . "Procurei em:\n  - " . implode("\n  - ", $candidatos) . "\n\n"
            . "Se o código foi movido para outro lugar, aponte-o com a variável\n"
            . "de ambiente UNIFICAR_APP, ou renomeie a pasta para\n"
            . basename($publico) . "-codigo ao lado de " . basename($publico) . ".\n"
        );
    }
}

/**
 * Os três pontos de entrada públicos fazem a mesma coisa: definem BASE_PATH
 * como o próprio diretório (é a definição de "raiz servida") e descobrem
 * APP_PATH. Definir BASE_PATH ANTES do bootstrap é o que impede que mover
 * core/ mude, sozinho, a pasta do config, a dos backups e a dos uploads.
 */
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}
define('UNIFICAR_APP_DIR', unificar_localizar_app(BASE_PATH));
