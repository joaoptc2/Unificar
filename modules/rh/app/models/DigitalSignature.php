<?php
class DigitalSignature extends Model
{
    protected static string $table = 'rh_digital_signatures';
    protected static array $fillable = ['employee_id','document_type','document_id','document_title','content_hash','signer_ip','signer_user_agent'];

    public static function sign(int $employeeId, string $docType, ?int $docId, string $title, string $contentToHash): int
    {
        return self::insert([
            'employee_id' => $employeeId,
            'document_type' => $docType,
            'document_id' => $docId,
            'document_title' => $title,
            'content_hash' => hash('sha256', $contentToHash),
            'signer_ip' => RateLimit::clientIp(),
            'signer_user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    }

    public static function forEmployee(int $empId): array
    {
        $stmt = self::db()->prepare('SELECT * FROM rh_digital_signatures WHERE employee_id = ? ORDER BY signed_at DESC');
        $stmt->execute([$empId]);
        return $stmt->fetchAll();
    }
}
