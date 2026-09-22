-- ============================================================================
--  019 — Invalidação de sessão ao trocar a senha
--
--  Antes, trocar ou redefinir a senha NÃO derrubava as sessões já abertas:
--  só a inatividade de 8h ou a desativação do usuário faziam isso. Um cookie
--  furtado, um dispositivo compartilhado ou a sessão de um invasor seguiam
--  valendo mesmo depois de a vítima trocar a senha — que é justamente o que
--  se faz para revogar o acesso.
--
--  password_changed_at guarda a época da última troca. O login grava essa
--  época na sessão (Auth::establish); Auth::user() confere a cada requisição
--  e destrói a sessão cuja época seja anterior à última troca. A comparação
--  é feita com UNIX_TIMESTAMP (relógio do banco), sem depender do fuso do PHP.
--
--  Idempotente E portável (MySQL 8 e MariaDB): NÃO usa "ADD COLUMN IF NOT
--  EXISTS", que é sintaxe exclusiva do MariaDB e o MySQL 8 rejeita com erro de
--  sintaxe — o que fazia a migração falhar e a coluna nunca ser criada,
--  derrubando o login inteiro. Numa reaplicação, o ADD COLUMN retorna o erro
--  1060 (coluna duplicada), que o runner de migração tolera e pula.
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN password_changed_at DATETIME NULL
        COMMENT 'Época da última troca de senha; invalida sessões antigas'
        AFTER force_password_change;

-- Base para os usuários já existentes: sem isto, password_changed_at fica
-- NULL (época 0) e a primeira troca de senha de cada um invalidaria sessões
-- que nasceram legitimamente. Ancora a época na hora da migração.
UPDATE users
   SET password_changed_at = COALESCE(password_changed_at, last_login_at, created_at, NOW())
 WHERE password_changed_at IS NULL;
