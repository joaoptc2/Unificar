<?php

declare(strict_types=1);

namespace Core;

/**
 * Falha de SMTP com código próprio, tempo de cada etapa e a conversa com o
 * servidor até o ponto do erro. É o que a tela Administração > E-mail mostra
 * ao administrador em vez de "falha no envio".
 *
 * O campo se chama errorCode (e não code) porque Exception::$code já existe e
 * é int; aqui o código é textual (Mailer::ERR_*).
 */
final class MailerException extends \RuntimeException
{
    /**
     * @param array<string,float> $steps      etapa => milissegundos
     * @param array<int,string>   $transcript linhas C:/S: já sem segredos
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $steps = [],
        public readonly array $transcript = [],
    ) {
        parent::__construct($message);
    }
}
