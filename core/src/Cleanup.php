<?php

declare(strict_types=1);

namespace Core;

/**
 * Limpeza periódica do sistema: apaga o que já não serve e ocupa espaço.
 *
 * Um portal hospitalar acumula rápido — log de auditoria, fila de e-mail,
 * notificações lidas, tentativas de login, backups antigos, arquivos
 * temporários. Sem limpeza, a primeira vez que isso incomoda é quando o
 * disco enche e o backup falha no meio, que é o pior momento possível.
 *
 * Os prazos ficam em Administração > Configurações, cada um em dias. Zero
 * significa "não limpar", para que ninguém perca dado por um padrão que não
 * escolheu.
 *
 * Cuidados deliberados:
 *   • prazos com PISO onde apagar cedo demais atrapalha auditoria;
 *   • apagar em lotes, para não travar tabela grande numa transação só;
 *   • modo simulação (dry run), para conferir o volume antes de apagar;
 *   • o que é referência viva nunca entra: notificação NÃO lida fica, fila
 *     pendente fica, backup mais recente fica.
 */
final class Cleanup
{
    /**
     * Cada alvo: prazo padrão em dias, piso permitido e rótulo.
     * O piso existe porque alguns registros têm valor legal/auditoria.
     */
    public const ALVOS = [
        'audit' => [
            'label'   => 'Registros de auditoria',
            'padrao'  => 365,
            'minimo'  => 90,
            'detalhe' => 'Quem fez o quê no portal. A acreditação costuma pedir pelo menos um ano.',
        ],
        'notifications' => [
            'label'   => 'Notificações já lidas',
            'padrao'  => 90,
            'minimo'  => 7,
            'detalhe' => 'As NÃO lidas nunca são apagadas, por mais antigas que sejam.',
        ],
        'mail_queue' => [
            'label'   => 'Fila de e-mail (enviados e descartados)',
            'padrao'  => 60,
            'minimo'  => 7,
            'detalhe' => 'Mensagens pendentes ou retidas ficam até serem resolvidas.',
        ],
        'mail_tests' => [
            'label'   => 'Histórico de testes de e-mail',
            'padrao'  => 30,
            'minimo'  => 1,
            'detalhe' => 'Inclui a conversa com o servidor, que ocupa espaço.',
        ],
        'login_attempts' => [
            'label'   => 'Tentativas de login',
            'padrao'  => 30,
            'minimo'  => 7,
            'detalhe' => 'Usadas para bloquear força bruta; passado o prazo não têm mais uso.',
        ],
        'password_resets' => [
            'label'   => 'Pedidos de redefinição de senha',
            'padrao'  => 7,
            'minimo'  => 1,
            'detalhe' => 'Os links valem por 30 minutos; o registro só serve para conferência.',
        ],
        'doc_history' => [
            'label'   => 'Histórico de modificações de documentos',
            'padrao'  => 0,
            'minimo'  => 365,
            'detalhe' => 'Padrão: nunca apagar. É a prova de quem alterou o documento e quando.',
        ],
        'logs' => [
            'label'   => 'Arquivos de log',
            'padrao'  => 60,
            'minimo'  => 7,
            'detalhe' => 'Arquivos .log antigos da pasta logs/.',
        ],
        'backup_tmp' => [
            'label'   => 'Arquivos temporários de backup',
            'padrao'  => 2,
            'minimo'  => 1,
            'detalhe' => 'Restos de backups interrompidos. Só pacotes incompletos.',
        ],
    ];

    /** Quantas linhas por vez: tabela grande não pode travar numa tacada. */
    private const LOTE = 2000;

    public static function dias(string $alvo): int
    {
        $def = self::ALVOS[$alvo] ?? null;
        if ($def === null) {
            return 0;
        }
        $v = (int) Settings::get('cleanup.' . $alvo . '_days', (string) $def['padrao']);
        if ($v <= 0) {
            return 0;                      // 0 = não limpar
        }
        return max($def['minimo'], $v);    // o piso protege de um valor curto demais
    }

    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::ALVOS) as $k) {
            $out[$k] = self::dias($k);
        }
        return $out;
    }

    public static function save(array $valores): void
    {
        foreach (self::ALVOS as $k => $def) {
            if (!array_key_exists($k, $valores)) {
                continue;
            }
            $v = (int) $valores[$k];
            // Guarda 0 (nunca limpar) ou um valor já dentro do piso.
            $v = $v <= 0 ? 0 : max($def['minimo'], min(3650, $v));
            Settings::set('cleanup.' . $k . '_days', (string) $v);
        }
        Settings::flush();
    }

    public static function habilitado(): bool
    {
        return (string) Settings::get('cleanup.enabled', '1') === '1';
    }

    public static function ultimaExecucao(): string
    {
        return (string) Settings::get('cleanup.last_run_at', '');
    }

    /**
     * Executa (ou simula) a limpeza.
     *
     * @param bool $simular true = só conta, não apaga
     * @return array{total:int, itens:array<string,array{label:string,dias:int,qtd:int,bytes:int,erro:string}>,
     *                simulado:bool, ms:float}
     */
    public static function run(bool $simular = false): array
    {
        $t0    = hrtime(true);
        $itens = [];
        $total = 0;

        foreach (self::ALVOS as $alvo => $def) {
            $dias = self::dias($alvo);
            $r = ['label' => $def['label'], 'dias' => $dias, 'qtd' => 0, 'bytes' => 0, 'erro' => ''];

            if ($dias <= 0) {
                $r['erro'] = '';
                $itens[$alvo] = $r + ['pulado' => true];
                continue;
            }

            try {
                $res = self::limpar($alvo, $dias, $simular);
                $r['qtd']   = $res['qtd'];
                $r['bytes'] = $res['bytes'];
                $total     += $res['qtd'];
            } catch (\Throwable $e) {
                // Um alvo que falha não pode impedir a limpeza dos outros.
                $r['erro'] = $e->getMessage();
                error_log('Cleanup[' . $alvo . ']: ' . $e->getMessage());
            }
            $itens[$alvo] = $r;
        }

        if (!$simular) {
            Settings::set('cleanup.last_run_at', date('Y-m-d H:i:s'));
            Settings::set('cleanup.last_run_count', (string) $total);
            if ($total > 0) {
                Audit::log('cleanup.run', 'settings', null,
                    ['registros' => $total], null, 'core');
            }
        }

        return [
            'total'    => $total,
            'itens'    => $itens,
            'simulado' => $simular,
            'ms'       => round((hrtime(true) - $t0) / 1e6, 1),
        ];
    }

    /** @return array{qtd:int, bytes:int} */
    private static function limpar(string $alvo, int $dias, bool $simular): array
    {
        return match ($alvo) {
            'audit'           => self::porData('audit_log', 'created_at', $dias, $simular),
            'notifications'   => self::porData('notifications', 'created_at', $dias, $simular,
                                               'read_at IS NOT NULL'),
            'mail_queue'      => self::porData('mail_queue', 'created_at', $dias, $simular,
                                               "status IN ('sent','failed','cancelled')"),
            'mail_tests'      => self::porData('mail_tests', 'created_at', $dias, $simular),
            'login_attempts'  => self::porData('login_attempts', 'attempted_at', $dias, $simular),
            'password_resets' => self::porData('password_resets', 'created_at', $dias, $simular),
            'doc_history'     => self::porData('doc_document_history', 'created_at', $dias, $simular),
            'logs'            => self::logs($dias, $simular),
            'backup_tmp'      => self::backupTmp($dias, $simular),
            default           => ['qtd' => 0, 'bytes' => 0],
        };
    }

    /**
     * Apaga linhas mais velhas que N dias, em lotes.
     * $extra é uma condição adicional (ex.: só notificações já lidas).
     */
    private static function porData(string $tabela, string $coluna, int $dias, bool $simular, string $extra = ''): array
    {
        if (!self::temTabela($tabela)) {
            return ['qtd' => 0, 'bytes' => 0];
        }
        $onde = "`{$coluna}` < (NOW() - INTERVAL ? DAY)" . ($extra !== '' ? " AND ({$extra})" : '');

        $qtd = (int) (DB::queryOne("SELECT COUNT(*) AS n FROM `{$tabela}` WHERE {$onde}", [$dias])['n'] ?? 0);
        if ($simular || $qtd === 0) {
            return ['qtd' => $qtd, 'bytes' => 0];
        }

        // Em lotes: um DELETE de milhões de linhas segura a tabela inteira e
        // derruba o portal justamente durante a rotina noturna.
        $apagadas = 0;
        do {
            $n = DB::execute("DELETE FROM `{$tabela}` WHERE {$onde} LIMIT " . self::LOTE, [$dias]);
            $apagadas += (int) $n;
        } while ($n >= self::LOTE && $apagadas < 500000);

        return ['qtd' => $apagadas, 'bytes' => 0];
    }

    private static function temTabela(string $t): bool
    {
        try {
            $r = DB::queryOne(
                "SELECT COUNT(*) AS n FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?",
                [$t]
            );
            return (int) ($r['n'] ?? 0) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Arquivos .log antigos da pasta logs/ (e das pastas logs dos módulos). */
    private static function logs(int $dias, bool $simular): array
    {
        $limite = time() - $dias * 86400;
        $qtd = 0; $bytes = 0;
        $raiz = dirname(CORE_PATH);

        $pastas = array_merge([$raiz . '/logs'], glob($raiz . '/modules/*/logs') ?: []);
        foreach ($pastas as $pasta) {
            if (!is_dir($pasta)) {
                continue;
            }
            foreach (glob($pasta . '/*.log*') ?: [] as $arq) {
                if (!is_file($arq) || filemtime($arq) >= $limite) {
                    continue;
                }
                $tam = (int) filesize($arq);
                // O log em uso não é apagado, só esvaziado: apagar o arquivo
                // aberto deixa o PHP escrevendo num descritor órfão e o
                // registro some sem ninguém perceber.
                $emUso = basename($arq) === 'app_errors.log';
                if (!$simular) {
                    if ($emUso) {
                        @file_put_contents($arq, '');
                    } elseif (!@unlink($arq)) {
                        continue;
                    }
                }
                $qtd++;
                $bytes += $tam;
            }
        }
        return ['qtd' => $qtd, 'bytes' => $bytes];
    }

    /** Restos de backups interrompidos (pasta tmp do backup). */
    private static function backupTmp(int $dias, bool $simular): array
    {
        try {
            if ($simular) {
                $tmp = Backup::tmpDir();
                if (!is_dir($tmp)) {
                    return ['qtd' => 0, 'bytes' => 0];
                }
                $limite = time() - $dias * 86400;
                $qtd = 0; $bytes = 0;
                foreach (glob($tmp . '/*') ?: [] as $f) {
                    if (is_file($f) && filemtime($f) < $limite) {
                        $qtd++;
                        $bytes += (int) filesize($f);
                    }
                }
                return ['qtd' => $qtd, 'bytes' => $bytes];
            }
            // O Backup já sabe limpar o próprio tmp com segurança (respeita a
            // trava de um backup em andamento).
            $r = Backup::limpaTmp($dias * 86400);
            return ['qtd' => (int) ($r['diretorios'] ?? 0), 'bytes' => (int) ($r['bytes'] ?? 0)];
        } catch (\Throwable $e) {
            return ['qtd' => 0, 'bytes' => 0];
        }
    }

    /**
     * Retenção dos pacotes de backup — delega ao Backup, que tem a regra de
     * diários/semanais/mensais e nunca apaga o mais recente.
     */
    public static function backups(): array
    {
        try {
            $r = Backup::retention();
            return ['qtd' => count($r['apagados'] ?? []), 'bytes' => (int) ($r['bytes'] ?? 0)];
        } catch (\Throwable $e) {
            error_log('Cleanup[backups]: ' . $e->getMessage());
            return ['qtd' => 0, 'bytes' => 0];
        }
    }
}
