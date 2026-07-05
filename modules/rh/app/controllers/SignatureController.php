<?php
class SignatureController
{
    public function index(): void { header('Location: index.php?page=employees'); exit; }
    public function sign(): void
    {
        Auth::requireLogin(); Csrf::check();
        $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        $docType = Sanitize::post('document_type'); $docId = Sanitize::int($_POST['document_id'] ?? 0);
        $title = Sanitize::post('document_title'); $content = Sanitize::post('content_to_sign');
        if (!$empId || !$docType || !$title || !$content) {
            Session::flash('error', 'Dados insuficientes para assinatura.');
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php?page=dashboard')); exit;
        }
        $sigId = DigitalSignature::sign($empId, $docType, $docId ?: null, $title, $content);
        AuditLog::log('sign', 'digital_signatures', $sigId);
        Session::flash('success', 'Documento assinado digitalmente.');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php?page=employees&action=show&id=' . $empId)); exit;
    }
    public function verify(): void
    {
        Auth::requireLogin();
        $id = Sanitize::int($_GET['id'] ?? 0);
        $sig = DigitalSignature::find($id);
        header('Content-Type: application/json');
        echo json_encode($sig ?: ['error' => 'Assinatura nao encontrada'], JSON_UNESCAPED_UNICODE); exit;
    }
}
