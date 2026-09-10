<?php
/**
 * EmployeeAccess — login padrão do funcionário no portal.
 *
 * Regra da plataforma: todo funcionário cadastrado recebe um usuário GLOBAL
 * com username = CPF (só dígitos), senha inicial = data de nascimento
 * (ddmmaaaa, troca obrigatória no primeiro acesso), e-mail = e-mail do
 * funcionário (ou {cpf}@sem-email.local quando vazio/duplicado) e o preset
 * de micropermissões "funcionario" do manifesto. O vínculo usuário ↔
 * funcionário fica em rh_user_profile.
 *
 * Usado pelo cadastro (EmployeeController), pela ficha (criar acesso /
 * redefinir senha / vincular usuário existente) e pela aba "Acessos dos
 * funcionários" da Administração central.
 */
class EmployeeAccess
{
    public const NO_EMAIL_DOMAIN = '@sem-email.local';

    /** Senha inicial padrão: data de nascimento em ddmmaaaa. */
    public static function defaultPassword(array $employee): string
    {
        $ts = strtotime((string)($employee['birth_date'] ?? ''));
        return $ts ? date('dmY', $ts) : Sanitize::cpf($employee['cpf'] ?? '');
    }

    /** Username padrão: CPF só dígitos. */
    public static function defaultUsername(array $employee): string
    {
        return Sanitize::cpf($employee['cpf'] ?? '');
    }

    /** true quando o e-mail é o placeholder gerado (não deve receber e-mails). */
    public static function isPlaceholderEmail(?string $email): bool
    {
        return $email === null || $email === '' || str_ends_with(mb_strtolower($email), self::NO_EMAIL_DOMAIN);
    }

    /** Usuário vinculado ao funcionário (rh_user_profile.employee_id) ou null. */
    public static function linkedUser(int $employeeId): ?array
    {
        return Core\DB::queryOne(
            'SELECT u.id, u.name, u.username, u.email, u.active, u.is_admin, u.last_login_at, u.force_password_change,
                    p.department_id
             FROM rh_user_profile p
             JOIN users u ON u.id = p.user_id
             WHERE p.employee_id = ?',
            [$employeeId]
        );
    }

    /**
     * Usuários ativos ainda sem vínculo com funcionário (para o select
     * "vincular a usuário existente" da ficha).
     */
    public static function unlinkedUsers(): array
    {
        return Core\DB::query(
            'SELECT u.id, u.name, u.username, u.email
             FROM users u
             LEFT JOIN rh_user_profile p ON p.user_id = u.id
             WHERE u.active = 1 AND (p.user_id IS NULL OR p.employee_id IS NULL)
             ORDER BY u.name'
        );
    }

    /**
     * Garante o acesso padrão do funcionário:
     *  - já vinculado → nada a fazer;
     *  - existe usuário com username = CPF → apenas vincula (senha intacta);
     *  - senão cria o usuário global com a senha padrão.
     *
     * @return array{status: 'exists'|'linked'|'created'|'error', user_id: int, username: string, password: ?string, message: string}
     */
    public static function ensure(array $employee, ?int $by): array
    {
        $employeeId = (int)$employee['id'];
        $cpf        = self::defaultUsername($employee);
        if (!Sanitize::isValidCpf($cpf)) {
            return ['status' => 'error', 'user_id' => 0, 'username' => $cpf, 'password' => null,
                    'message' => 'CPF inválido (' . ($cpf !== '' ? Sanitize::formatCpf($cpf) : 'vazio') . ') — acesso não criado. Corrija o CPF na ficha do funcionário.'];
        }

        $linked = self::linkedUser($employeeId);
        if ($linked) {
            return ['status' => 'exists', 'user_id' => (int)$linked['id'], 'username' => (string)$linked['username'],
                    'password' => null, 'message' => 'O funcionário já possui acesso (login ' . $linked['username'] . ').'];
        }

        $existing = Core\DB::queryOne('SELECT id, username FROM users WHERE username = ? LIMIT 1', [$cpf]);
        if ($existing) {
            $other = Core\DB::queryOne('SELECT employee_id FROM rh_user_profile WHERE user_id = ? AND employee_id IS NOT NULL AND employee_id <> ?',
                [(int)$existing['id'], $employeeId]);
            if ($other) {
                return ['status' => 'error', 'user_id' => (int)$existing['id'], 'username' => $cpf, 'password' => null,
                        'message' => 'Já existe um usuário com o CPF ' . Sanitize::formatCpf($cpf) . ' vinculado a outro funcionário — acesso não criado.'];
            }
            self::link($employeeId, (int)$existing['id'], $employee['department_id'] ?? null, $by);
            return ['status' => 'linked', 'user_id' => (int)$existing['id'], 'username' => $cpf, 'password' => null,
                    'message' => 'Já existia um usuário com o CPF ' . Sanitize::formatCpf($cpf) . ' — vinculado ao funcionário (senha mantida).'];
        }

        $password = self::defaultPassword($employee);
        $email    = self::availableEmail((string)($employee['email'] ?? ''), $cpf);

        Core\DB::execute(
            'INSERT INTO users (name, username, email, password_hash, is_admin, active, force_password_change)
             VALUES (?, ?, ?, ?, 0, 1, 1)',
            [(string)$employee['full_name'], $cpf, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])]
        );
        $userId = Core\DB::lastId();

        self::link($employeeId, $userId, $employee['department_id'] ?? null, $by);
        Core\Audit::log('employee_access.create', 'users', (string)$userId, ['employee_id' => $employeeId], $by, 'rh');

        return ['status' => 'created', 'user_id' => $userId, 'username' => $cpf, 'password' => $password,
                'message' => 'Acesso criado: login ' . Sanitize::formatCpf($cpf) . ' / senha inicial ' . $password . ' (troca obrigatória no primeiro acesso).'];
    }

    /**
     * Redefine a senha do usuário vinculado para a padrão (nascimento
     * ddmmaaaa) e obriga a troca no próximo login.
     */
    public static function resetPassword(array $employee, ?int $by): ?string
    {
        $linked = self::linkedUser((int)$employee['id']);
        if (!$linked) {
            return null;
        }
        $password = self::defaultPassword($employee);
        Core\DB::execute(
            'UPDATE users SET password_hash = ?, force_password_change = 1, active = 1 WHERE id = ?',
            [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $linked['id']]
        );
        Core\Audit::log('employee_access.reset', 'users', (string)$linked['id'], ['employee_id' => (int)$employee['id']], $by, 'rh');
        return $password;
    }

    /**
     * Vincula (ou re-vincula) um usuário ao funcionário e garante as
     * micropermissões do preset "funcionario" (mescladas às existentes).
     */
    public static function link(int $employeeId, int $userId, $departmentId, ?int $by): void
    {
        $departmentId = (int)$departmentId ?: null;
        // Libera o funcionário de qualquer outro usuário (chave única).
        Core\DB::execute('UPDATE rh_user_profile SET employee_id = NULL WHERE employee_id = ? AND user_id <> ?', [$employeeId, $userId]);
        Core\DB::execute(
            'INSERT INTO rh_user_profile (user_id, employee_id, department_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE employee_id = VALUES(employee_id), department_id = VALUES(department_id)',
            [$userId, $employeeId, $departmentId]
        );
        self::grantPreset($userId, $by);
    }

    /**
     * Vincula o funcionário a um usuário global já existente (escolhido na
     * ficha). Recusa usuários já vinculados a outro funcionário.
     *
     * @return array{ok: bool, message: string}
     */
    public static function linkExisting(array $employee, int $userId, ?int $by): array
    {
        $employeeId = (int)$employee['id'];
        if ($userId <= 0) {
            return ['ok' => false, 'message' => 'Selecione o usuário a vincular.'];
        }
        if (self::linkedUser($employeeId)) {
            return ['ok' => false, 'message' => 'O funcionário já possui um usuário vinculado.'];
        }
        $user = Core\DB::queryOne('SELECT id, name, username FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return ['ok' => false, 'message' => 'Usuário não encontrado.'];
        }
        $other = Core\DB::queryOne('SELECT employee_id FROM rh_user_profile WHERE user_id = ? AND employee_id IS NOT NULL', [$userId]);
        if ($other) {
            return ['ok' => false, 'message' => 'Este usuário já está vinculado a outro funcionário.'];
        }
        self::link($employeeId, $userId, $employee['department_id'] ?? null, $by);
        Core\Audit::log('employee_access.link', 'users', (string)$userId, ['employee_id' => $employeeId], $by, 'rh');
        return ['ok' => true, 'message' => 'Usuário ' . $user['name'] . ' (' . $user['username'] . ') vinculado ao funcionário.'];
    }

    /** Mantém rh_user_profile.department_id igual ao departamento do funcionário. */
    public static function syncDepartment(int $employeeId, ?int $departmentId): void
    {
        Core\DB::execute('UPDATE rh_user_profile SET department_id = ? WHERE employee_id = ?', [$departmentId, $employeeId]);
    }

    /**
     * Departamento efetivo do usuário (o do funcionário vinculado; senão o
     * gravado em rh_user_profile). null = sem departamento.
     */
    public static function departmentOf(?int $userId): ?int
    {
        if (!$userId) {
            return null;
        }
        $row = Core\DB::queryOne(
            'SELECT p.department_id, e.department_id AS emp_dept
             FROM rh_user_profile p LEFT JOIN rh_employees e ON e.id = p.employee_id
             WHERE p.user_id = ?',
            [$userId]
        );
        $d = (int)($row['emp_dept'] ?? $row['department_id'] ?? 0);
        return $d ?: null;
    }

    /**
     * Desativa (ou reativa) o usuário vinculado ao funcionário — usado no
     * desligamento e na anonimização. Administradores globais não são
     * desativados automaticamente (evita bloquear a administração).
     * @return string|null mensagem para a flash (null = nada feito)
     */
    public static function setLinkedUserActive(int $employeeId, bool $active, ?int $by): ?string
    {
        $linked = self::linkedUser($employeeId);
        if (!$linked || (int)$linked['active'] === (int)$active) {
            return null;
        }
        if (!empty($linked['is_admin']) && !$active) {
            return 'O usuário vinculado (' . $linked['username'] . ') é administrador global e NÃO foi desativado automaticamente — revise na administração central.';
        }
        Core\DB::execute('UPDATE users SET active = ? WHERE id = ?', [$active ? 1 : 0, (int)$linked['id']]);
        Core\Audit::log($active ? 'employee_access.activate' : 'employee_access.deactivate', 'users', (string)$linked['id'], ['employee_id' => $employeeId], $by, 'rh');
        return $active
            ? 'Acesso ao sistema do usuário ' . $linked['username'] . ' reativado.'
            : 'Acesso ao sistema do usuário ' . $linked['username'] . ' desativado.';
    }

    /**
     * Mantém o login (username = CPF) sincronizado quando o CPF do
     * funcionário muda. Só altera se o username atual era o CPF antigo.
     * @return string|null mensagem para a flash (null = nada feito)
     */
    public static function syncUsername(int $employeeId, string $oldCpf, string $newCpf, ?int $by): ?string
    {
        $oldCpf = Sanitize::cpf($oldCpf);
        $newCpf = Sanitize::cpf($newCpf);
        if ($oldCpf === $newCpf || strlen($newCpf) !== 11) {
            return null;
        }
        $linked = self::linkedUser($employeeId);
        if (!$linked || (string)$linked['username'] !== $oldCpf) {
            return null;
        }
        $dup = Core\DB::queryOne('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1', [$newCpf, (int)$linked['id']]);
        if ($dup) {
            return 'O login do usuário vinculado NÃO foi alterado para o novo CPF: já existe outro usuário com o login ' . Sanitize::formatCpf($newCpf) . '.';
        }
        Core\DB::execute('UPDATE users SET username = ? WHERE id = ?', [$newCpf, (int)$linked['id']]);
        Core\Audit::log('employee_access.rename', 'users', (string)$linked['id'], ['employee_id' => $employeeId, 'from' => $oldCpf, 'to' => $newCpf], $by, 'rh');
        return 'Login do usuário vinculado atualizado para o novo CPF (' . Sanitize::formatCpf($newCpf) . ').';
    }

    /** ID do funcionário vinculado ao usuário logado (0 se não houver). */
    public static function employeeIdOf(?int $userId): int
    {
        if (!$userId) {
            return 0;
        }
        $row = Core\DB::queryOne('SELECT employee_id FROM rh_user_profile WHERE user_id = ?', [$userId]);
        return (int)($row['employee_id'] ?? 0);
    }

    /** Aplica o preset "funcionario" do manifesto (união com o que o usuário já tem). */
    public static function grantPreset(int $userId, ?int $by): void
    {
        $manifest = Core\Modules::manifest('rh') ?? [];
        $keys     = (array)($manifest['presets']['funcionario']['keys'] ?? ['my.view']);
        $preset   = Core\Perms::expand('rh', $keys);

        $row = Core\DB::queryOne('SELECT is_admin FROM users WHERE id = ?', [$userId]);
        if (!empty($row['is_admin'])) {
            return; // administradores globais já têm tudo
        }
        $current = array_keys(Core\Perms::effective($userId, 'rh'));
        Core\Perms::setUserGrants($userId, 'rh', array_values(array_unique(array_merge($current, $preset))), $by);
    }

    /** Lista de funcionários ativos com o usuário vinculado (ou NULL) — 1 query. */
    public static function overview(): array
    {
        return Core\DB::query(
            "SELECT e.id, e.full_name, e.cpf, e.birth_date, e.email, e.department_id, e.status,
                    d.name AS department_name,
                    u.id AS user_id, u.username, u.email AS user_email, u.active AS user_active,
                    u.last_login_at, u.force_password_change
             FROM rh_employees e
             LEFT JOIN rh_departments d ON d.id = e.department_id
             LEFT JOIN rh_user_profile p ON p.employee_id = e.id
             LEFT JOIN users u ON u.id = p.user_id
             WHERE e.status = 'ativo' AND e.anonymized_at IS NULL
             ORDER BY e.full_name"
        );
    }

    /** E-mail válido e livre, senão o placeholder {cpf}@sem-email.local. */
    private static function availableEmail(string $email, string $cpf): string
    {
        $email = mb_strtolower(trim($email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $dup = Core\DB::queryOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
            if (!$dup) {
                return $email;
            }
        }
        return $cpf . self::NO_EMAIL_DOMAIN;
    }
}
