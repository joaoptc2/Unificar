<?php
/**
 * ComplaintController — canal de denúncia anônimo.
 *
 *   page=complaints                         lista (complaints.view — comissão)
 *   page=complaints&action=show&id=N        apuração: detalhes, mensagens, status
 *   page=complaints&action=update           POST status / notas internas / conclusão (complaints.respond)
 *   page=complaints&action=message          POST mensagem da comissão ao denunciante (complaints.respond)
 *   page=complaints&action=store            POST do portal (my.view + complaints.create) — ANÔNIMO
 *   page=complaints&action=receipt          protocolo + chave, mostrados UMA vez
 *   page=complaint_track                    PÚBLICA: acompanhar pelo protocolo + chave (sem login)
 *
 * REGRA DE OURO: nenhuma ação do denunciante chama AuditLog (gravaria
 * user_id e IP) nem grava Session::userId() em lugar nenhum.
 */
class ComplaintController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function index(): void
    {
        core_require('complaints.view');
        $status   = Sanitize::get('status');
        $category = Sanitize::get('category');
        View::render('complaints/index', [
            'pageTitle' => 'Canal de denúncias',
            'page'      => 'complaints',
            'items'     => Complaint::listing($status, $category),
            'status'    => $status,
            'category'  => $category,
        ]);
    }

    public function show(): void
    {
        core_require('complaints.view');
        $id = Sanitize::int($_GET['id'] ?? 0);
        $c  = Complaint::find($id);
        if (!$c) {
            Session::flash('error', 'Denúncia não encontrada.');
            header('Location: index.php?m=rh&page=complaints'); exit;
        }
        $handler = null;
        if (!empty($c['handled_by'])) {
            $st = $this->db->prepare('SELECT name FROM users WHERE id = ?');
            $st->execute([(int) $c['handled_by']]);
            $handler = $st->fetchColumn() ?: null;
        }
        View::render('complaints/show', [
            'pageTitle' => 'Denúncia ' . $c['protocol'],
            'page'      => 'complaints',
            'c'         => $c,
            'handler'   => $handler,
            'messages'  => Complaint::messages($id),
        ]);
    }

    public function update(): void
    {
        core_require('complaints.respond'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $c  = Complaint::find($id);
        if (!$c) {
            Session::flash('error', 'Denúncia não encontrada.');
            header('Location: index.php?m=rh&page=complaints'); exit;
        }
        $status = Sanitize::post('status');
        if (!isset(Complaint::STATUS_LABELS[$status])) { $status = $c['status']; }
        $notes    = mb_substr(trim((string) ($_POST['internal_notes'] ?? '')), 0, 10000);
        $response = mb_substr(trim((string) ($_POST['response'] ?? '')), 0, 5000);
        $fechou   = in_array($status, ['concluida', 'arquivada'], true);

        $this->db->prepare(
            'UPDATE rh_complaints SET status = ?, internal_notes = ?, response = ?, handled_by = ?,
                    responded_at = CASE WHEN ? <> COALESCE(response, \'\') THEN NOW() ELSE responded_at END,
                    closed_at = ? WHERE id = ?'
        )->execute([
            $status, $notes ?: null, $response ?: null, Session::userId(),
            $response, $fechou ? date('Y-m-d H:i:s') : null, $id,
        ]);
        // Auditoria SÓ do lado da comissão (quem apurou, o quê) — nada do denunciante.
        AuditLog::log('update', 'complaints', $id, ['status' => $c['status']], ['status' => $status]);
        Session::flash('success', 'Denúncia atualizada: ' . Complaint::STATUS_LABELS[$status] . '.');
        header('Location: index.php?m=rh&page=complaints&action=show&id=' . $id); exit;
    }

    public function message(): void
    {
        core_require('complaints.respond'); Csrf::check();
        $id   = Sanitize::int($_POST['id'] ?? 0);
        $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 5000);
        $c = Complaint::find($id);
        if (!$c || $body === '') {
            Session::flash('error', 'Escreva a mensagem.');
            header('Location: index.php?m=rh&page=complaints&action=show&id=' . $id); exit;
        }
        if (in_array($c['status'], ['concluida', 'arquivada'], true)) {
            Session::flash('error', 'Denúncia encerrada: reabra (status "em apuração") para conversar com o denunciante.');
            header('Location: index.php?m=rh&page=complaints&action=show&id=' . $id); exit;
        }
        Complaint::addMessage($id, 'comissao', $body, Session::userId());
        // Denúncia nova que recebe mensagem passa a "em apuração".
        $this->db->prepare("UPDATE rh_complaints SET status = 'em_apuracao', handled_by = COALESCE(handled_by, ?) WHERE id = ? AND status = 'nova'")
                 ->execute([Session::userId(), $id]);
        Session::flash('success', 'Mensagem enviada. O denunciante a verá ao consultar o protocolo.');
        header('Location: index.php?m=rh&page=complaints&action=show&id=' . $id); exit;
    }

    /**
     * Abertura pelo portal. Exige estar logado como funcionário (evita spam
     * externo), mas NÃO grava quem abriu: nada de user_id, employee_id, IP
     * ou AuditLog. Os únicos rastros são o protocolo e o hash da chave.
     */
    public function store(): void
    {
        core_require('my.view'); core_require('complaints.create'); Csrf::check();

        $category = Sanitize::post('category');
        if (!isset(Complaint::CATEGORIES[$category])) { $category = 'outro'; }
        $subject = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, 200);
        $body    = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 20000);
        if ($subject === '' || mb_strlen($body) < 20) {
            Session::flash('error', 'Descreva o ocorrido (assunto e um relato com pelo menos 20 caracteres).');
            header('Location: index.php?m=rh&page=my&tab=denuncias'); exit;
        }
        if (empty($_POST['consent'])) {
            Session::flash('error', 'Confirme que leu como o canal funciona antes de enviar.');
            header('Location: index.php?m=rh&page=my&tab=denuncias'); exit;
        }
        $attachmentPath = null; $attachmentName = null;
        if (!empty($_FILES['attachment']['name'])) {
            $up = Upload::handle('attachment', 'complaints');
            if (!$up['success']) {
                Session::flash('error', 'Anexo recusado: ' . $up['error']);
                header('Location: index.php?m=rh&page=my&tab=denuncias'); exit;
            }
            // O nome original pode identificar o autor ("relatorio-da-maria.pdf"):
            // guarda só a extensão com um nome neutro. E o nome físico que o
            // Upload gera leva time() (segundo exato da abertura): renomeia
            // para um nome só aleatório e alinha o mtime à hora truncada.
            $ext = strtolower(pathinfo((string) $up['original_name'], PATHINFO_EXTENSION));
            $attachmentName = 'anexo' . ($ext ? '.' . $ext : '');
            $attachmentPath = $up['path'];
            $abs = Upload::resolvePath($up['path']);
            if ($abs && is_file($abs)) {
                $novo = dirname($abs) . '/' . bin2hex(random_bytes(20)) . ($ext ? '.' . $ext : '');
                if (@rename($abs, $novo)) {
                    $attachmentPath = dirname($up['path']) . '/' . basename($novo);
                    @touch($novo, (int) (floor(time() / 3600) * 3600));
                }
            }
        }

        [$id, $protocol, $key] = Complaint::open([
            'category'        => $category,
            'subject'         => $subject,
            'body'            => $body,
            'involved'        => mb_substr(trim((string) ($_POST['involved'] ?? '')), 0, 300),
            'occurred_at'     => Sanitize::date($_POST['occurred_at'] ?? null),
            'location'        => mb_substr(trim((string) ($_POST['location'] ?? '')), 0, 200),
            'contact'         => mb_substr(trim((string) ($_POST['contact'] ?? '')), 0, 200),
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
        ]);
        Complaint::notifyCommittee($id, $protocol, $category);

        // Protocolo + chave ficam na sessão só até a tela de recibo ler.
        Session::set('_complaint_receipt', ['protocol' => $protocol, 'key' => $key]);
        header('Location: index.php?m=rh&page=complaints&action=receipt'); exit;
    }

    /** Recibo: mostra protocolo e chave UMA vez e apaga da sessão. */
    public function receipt(): void
    {
        core_require('my.view');
        $r = Session::get('_complaint_receipt');
        Session::remove('_complaint_receipt');
        if (!$r) {
            header('Location: index.php?m=rh&page=my&tab=denuncias'); exit;
        }
        $employeeId = EmployeeAccess::employeeIdOf(Session::userId());
        $employee = $employeeId ? Employee::findWithRelations($employeeId) : null;
        View::renderRaw('my/complaint_receipt', [
            'hospitalName' => Core\Branding::name(),
            'flashSuccess' => null, 'flashError' => null,
            'unread'       => Core\Notifications::unreadCount((int) Session::userId()),
            'employee'     => $employee ?: ['full_name' => Session::userName() ?? '', 'admission_date' => null],
            'compact'      => true,
            'portalTitle'  => 'Denúncia registrada',
            'protocol'     => $r['protocol'],
            'key'          => $r['key'],
        ]);
    }

    /**
     * Acompanhamento PÚBLICO (sem login) pelo protocolo + chave: o
     * denunciante vê status, conclusão e mensagens, e pode responder.
     * Falhas são lentas e limitadas por sessão para desestimular tentativa.
     */
    public function track(): void
    {
        $protocol = trim((string) ($_REQUEST['protocol'] ?? ''));
        $key      = trim((string) ($_REQUEST['key'] ?? ''));
        $c = null; $erro = null; $ok = null;

        if ($protocol !== '' || $key !== '') {
            // Dois freios contra adivinhação da chave: por sessão e por IP.
            // O de IP grava SÓ as falhas (identifier 'denuncia@<ip>' na
            // tabela de tentativas de login, que o núcleo limpa em 2 dias):
            // quem acerta não deixa rastro nenhum — é o denunciante.
            $falhas = (int) Session::get('_complaint_track_fail', 0);
            $ipKey  = 'denuncia@' . Core\Audit::ip();
            $porIp  = (int) ($this->db->query(
                "SELECT COUNT(*) FROM login_attempts WHERE identifier = " . $this->db->quote($ipKey)
                . " AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            )->fetchColumn() ?: 0);
            // Num hospital todo mundo sai pelo mesmo IP (NAT): o freio não
            // pode negar quem ACERTA protocolo+chave — só torna as falhas
            // lentas (atraso crescente) e, acima do limite, as recusa.
            $bloqueado = $falhas >= 15 || $porIp >= 30;
            $c = Complaint::findByCredentials($protocol, $key);
            if (!$c) {
                usleep(min(3000000, 400000 * (1 + min($falhas, 6))));
                Session::set('_complaint_track_fail', $falhas + 1);
                $this->db->prepare('INSERT INTO login_attempts (identifier, success) VALUES (?, 0)')->execute([$ipKey]);
                $erro = $bloqueado ? 'Muitas tentativas. Aguarde alguns minutos e tente de novo.' : 'Protocolo ou chave não conferem.';
            } else {
                Session::set('_complaint_track_fail', 0);
            }
        }

        if ($c && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply'])) {
            Csrf::check();
            $body = mb_substr(trim((string) ($_POST['reply'] ?? '')), 0, 5000);
            if ($body !== '' && !in_array($c['status'], ['concluida', 'arquivada'], true)) {
                Complaint::addMessage((int) $c['id'], 'denunciante', $body);
                foreach (Core\Perms::usersWith('rh', 'complaints.view') as $uid) {
                    Core\Notifications::add((int) $uid, 'Nova mensagem na denúncia ' . $c['protocol'], 'O denunciante respondeu.',
                        'index.php?m=rh&page=complaints&action=show&id=' . (int) $c['id'], 'info', 'rh');
                }
                $ok = 'Mensagem enviada à comissão.';
            } elseif ($body !== '') {
                $erro = 'Esta denúncia já foi encerrada; não aceita novas mensagens.';
            }
        }

        View::renderRaw('complaints/track', [
            'hospitalName' => Core\Branding::name(),
            'c'            => $c,
            'messages'     => $c ? Complaint::messages((int) $c['id']) : [],
            'protocol'     => $protocol,
            'key'          => $c ? $key : '',
            'erro'         => $erro,
            'ok'           => $ok,
        ]);
    }
}
