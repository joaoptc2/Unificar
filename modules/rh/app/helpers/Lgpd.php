<?php
/**
 * Lgpd — utilitários para suporte a LGPD/GDPR.
 *
 * Anonimização preserva o registro (para fins de auditoria/estatística), mas
 * apaga PII: nome, CPF, contato, endereço, foto, observações e arquivos
 * relacionados (documentos, atestados).
 */
class Lgpd
{
    /**
     * Anonimiza um funcionário desligado: substitui PII por placeholders e
     * remove arquivos sensíveis. Marca `anonymized_at`.
     */
    public static function anonymizeEmployee(int $employeeId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM rh_employees WHERE id = ?');
        $stmt->execute([$employeeId]);
        $emp = $stmt->fetch();
        if (!$emp) return false;

        if (!empty($emp['anonymized_at'])) return true;

        $db->beginTransaction();
        try {
            // Substitui PII.
            $placeholderName = 'Funcionário Anonimizado #' . $employeeId;
            $placeholderCpf  = sprintf('00000000%03d', $employeeId % 1000);

            $stmt = $db->prepare(
                'UPDATE rh_employees SET
                    full_name = ?, cpf = ?, email = NULL, phone = NULL,
                    address_street = NULL, address_number = NULL, address_complement = NULL,
                    address_neighborhood = NULL, address_city = NULL, address_state = NULL, address_zip = NULL,
                    regional_council = NULL, council_number = NULL,
                    notes = NULL, photo = NULL,
                    anonymized_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([$placeholderName, $placeholderCpf, $employeeId]);

            // Apaga foto.
            if (!empty($emp['photo'])) Upload::delete($emp['photo']);

            // Remove e apaga documentos.
            $docs = $db->prepare('SELECT id, file_path FROM rh_employee_documents WHERE employee_id = ?');
            $docs->execute([$employeeId]);
            foreach ($docs->fetchAll() as $d) {
                if ($d['file_path']) Upload::delete($d['file_path']);
            }
            $db->prepare('DELETE FROM rh_employee_documents WHERE employee_id = ?')->execute([$employeeId]);

            // Remove e apaga atestados (mantém só agregados estatísticos).
            $certs = $db->prepare('SELECT id, file_path FROM rh_medical_certificates WHERE employee_id = ?');
            $certs->execute([$employeeId]);
            foreach ($certs->fetchAll() as $c) {
                if ($c['file_path']) Upload::delete($c['file_path']);
            }
            // Mantém o registro do atestado mas remove dados sensíveis (CID, médico).
            $db->prepare(
                'UPDATE rh_medical_certificates
                 SET cid = NULL, doctor_name = NULL, doctor_crm = NULL, notes = NULL, file_path = NULL
                 WHERE employee_id = ?'
            )->execute([$employeeId]);

            // Anonimiza histórico (mantém datas e tipos).
            $db->prepare(
                "UPDATE rh_employee_records
                 SET description = CONCAT('[anonimizado] ', record_type),
                     old_value = NULL, new_value = NULL
                 WHERE employee_id = ?"
            )->execute([$employeeId]);

            // Usuário global vinculado (login = CPF, nome, e-mail): desativa e
            // anonimiza — o ex-funcionário não pode continuar entrando no portal
            // (administradores globais só são desativados manualmente).
            $userStmt = $db->prepare('SELECT u.id, u.is_admin FROM rh_user_profile p JOIN users u ON u.id = p.user_id WHERE p.employee_id = ?');
            $userStmt->execute([$employeeId]);
            $user = $userStmt->fetch();
            if ($user && empty($user['is_admin'])) {
                $db->prepare(
                    'UPDATE users SET active = 0, name = ?, username = ?, email = ?, force_password_change = 1 WHERE id = ?'
                )->execute([$placeholderName, 'anon_' . $employeeId . '_' . (int)$user['id'], 'anon_' . $employeeId . '_' . (int)$user['id'] . EmployeeAccess::NO_EMAIL_DOMAIN, (int)$user['id']]);
            }

            $db->commit();
            if ($user && empty($user['is_admin'])) {
                Core\Audit::log('employee_access.anonymize', 'users', (string)$user['id'], ['employee_id' => $employeeId], null, 'rh');
            }
            AuditLog::log('lgpd_anonymize', 'employees', $employeeId, $emp);
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Lgpd::anonymizeEmployee falhou: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove um candidato e seu currículo (direito ao esquecimento).
     */
    public static function deleteCandidate(int $candidateId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, resume_path FROM rh_candidates WHERE id = ?');
        $stmt->execute([$candidateId]);
        $c = $stmt->fetch();
        if (!$c) return false;

        if (!empty($c['resume_path'])) Upload::delete($c['resume_path']);
        $db->prepare('DELETE FROM rh_candidates WHERE id = ?')->execute([$candidateId]);
        AuditLog::log('lgpd_delete_candidate', 'candidates', $candidateId);
        return true;
    }
}
