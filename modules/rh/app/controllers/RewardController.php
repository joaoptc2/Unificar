<?php
/**
 * RewardController — brindes (troca de pontos).
 *
 *   page=rewards                          catálogo + resgates (rewards.view)
 *   action=create|store|edit|update       cadastro (rewards.create / rewards.edit)
 *   action=delete                         excluir (rewards.delete) — POST
 *   action=respond                        aprovar / entregar / rejeitar / cancelar resgate (rewards.respond) — POST
 *   action=redeem                         POST do portal: funcionário troca pontos (rewards.view + vínculo)
 *
 * Saldo = SUM(rh_employee_scores.points). Ao aprovar, lança pontos negativos
 * (category 'brinde', reason "Resgate: <brinde>"), vincula score_id e
 * decrementa o estoque; rejeitar/cancelar após aprovação devolve ambos.
 */
class RewardController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function index(): void
    {
        core_require('rewards.view');
        // Quem não responde resgates (funcionário) usa o portal.
        if (!core_can_any(['rewards.respond', 'rewards.create', 'rewards.edit'])) {
            header('Location: index.php?m=rh&page=my#brindes'); exit;
        }
        $status = Sanitize::get('status');
        View::render('rewards/index', [
            'pageTitle' => 'Brindes', 'page' => 'rewards',
            'rewards' => Reward::listAll(),
            'redemptions' => RewardRedemption::listAll($status ?: null),
            'status' => $status,
        ]);
    }

    public function create(): void
    {
        core_require('rewards.create');
        View::render('rewards/form', ['pageTitle' => 'Novo Brinde', 'page' => 'rewards', 'item' => null]);
    }

    public function edit(): void
    {
        core_require('rewards.edit');
        $item = Reward::find(Sanitize::int($_GET['id'] ?? 0));
        if (!$item) { Session::flash('error', 'Brinde não encontrado.'); header('Location: index.php?m=rh&page=rewards'); exit; }
        View::render('rewards/form', ['pageTitle' => 'Editar Brinde', 'page' => 'rewards', 'item' => $item]);
    }

    public function store(): void
    {
        core_require('rewards.create'); Csrf::check();
        $data = $this->formData(null);
        if ($data === null) { header('Location: index.php?m=rh&page=rewards&action=create'); exit; }
        $data['created_by'] = Session::userId();
        $id = Reward::insert($data);
        AuditLog::log('create', 'rewards', $id);
        Session::flash('success', 'Brinde cadastrado.'); header('Location: index.php?m=rh&page=rewards'); exit;
    }

    public function update(): void
    {
        core_require('rewards.edit'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $old = Reward::find($id);
        if (!$old) { Session::flash('error', 'Brinde não encontrado.'); header('Location: index.php?m=rh&page=rewards'); exit; }
        $data = $this->formData($old);
        if ($data === null) { header('Location: index.php?m=rh&page=rewards&action=edit&id=' . $id); exit; }
        Reward::update($id, $data);
        AuditLog::log('update', 'rewards', $id);
        Session::flash('success', 'Brinde atualizado.'); header('Location: index.php?m=rh&page=rewards'); exit;
    }

    public function delete(): void
    {
        core_require('rewards.delete'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $item = Reward::find($id);
        if ($item) {
            $st = $this->db->prepare('SELECT COUNT(*) FROM rh_reward_redemptions WHERE reward_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                Reward::update($id, ['active' => 0]);
                Session::flash('warning', 'O brinde possui resgates e foi apenas desativado.');
            } else {
                if (!empty($item['image_path'])) { Upload::delete($item['image_path']); }
                Reward::delete($id);
                Session::flash('success', 'Brinde excluído.');
            }
            AuditLog::log('delete', 'rewards', $id);
        }
        header('Location: index.php?m=rh&page=rewards'); exit;
    }

    private function formData(?array $old): ?array
    {
        $name = mb_substr(Sanitize::post('name'), 0, 150);
        $cost = Sanitize::int($_POST['points_cost'] ?? 0);
        if ($name === '' || $cost <= 0) { Session::flash('error', 'Informe o nome e o custo em pontos (maior que zero).'); return null; }
        $stockRaw = trim((string)($_POST['stock'] ?? ''));
        $data = [
            'name' => $name, 'description' => Sanitize::post('description') ?: null,
            'points_cost' => $cost, 'stock' => $stockRaw === '' ? null : max(0, Sanitize::int($stockRaw)),
            'active' => !empty($_POST['active']) ? 1 : 0,
        ];
        if (!empty($_FILES['image']['name'])) {
            $up = Upload::handle('image', 'rewards', ['jpg', 'jpeg', 'png']);
            if (!$up['success']) { Session::flash('error', 'Imagem: ' . Sanitize::e($up['error'])); return null; }
            if (!empty($old['image_path'])) { Upload::delete($old['image_path']); }
            $data['image_path'] = $up['path'];
        } elseif (!empty($_POST['remove_image']) && !empty($old['image_path'])) {
            Upload::delete($old['image_path']); $data['image_path'] = null;
        }
        return $data;
    }

    /** Portal: funcionário troca pontos por um brinde (POST). */
    public function redeem(): void
    {
        core_require('rewards.view'); Csrf::check();
        $userId = (int)Session::userId();
        $empId  = EmployeeAccess::employeeIdOf($userId);
        $back   = 'index.php?m=rh&page=my#brindes';
        if (!$empId) { Session::flash('error', 'Seu usuário não está vinculado a um funcionário.'); header('Location: index.php?m=rh&page=my'); exit; }

        $reward = Reward::find(Sanitize::int($_POST['reward_id'] ?? 0));
        if (!$reward || !(int)$reward['active']) { Session::flash('error', 'Brinde indisponível.'); header("Location: $back"); exit; }
        // Estoque disponível desconta os resgates pendentes (já reservam uma unidade).
        $availableStock = Reward::availableStock((int)$reward['id']);
        if ($availableStock !== null && $availableStock <= 0) {
            Session::flash('error', (int)$reward['stock'] > 0 ? 'Brinde esgotado: as unidades restantes já estão reservadas por resgates pendentes.' : 'Brinde esgotado.');
            header("Location: $back"); exit;
        }
        $cost = (int)$reward['points_cost'];
        $available = RewardRedemption::availableFor($empId);
        if ($available < $cost) {
            Session::flash('error', "Saldo insuficiente: você tem {$available} ponto(s) disponíveis e o brinde custa {$cost}.");
            header("Location: $back"); exit;
        }
        $id = RewardRedemption::insert([
            'reward_id' => (int)$reward['id'], 'employee_id' => $empId, 'points_spent' => $cost,
            'status' => 'pendente', 'notes' => mb_substr(Sanitize::post('notes'), 0, 500) ?: null,
        ]);
        AuditLog::log('redeem', 'reward_redemptions', $id, null, ['reward_id' => $reward['id'], 'points' => $cost]);

        $emp = Employee::find($empId);
        foreach (Core\Perms::usersWith('rh', 'rewards.respond') as $uid) {
            Core\Notifications::add((int)$uid, 'Resgate de brinde: ' . $reward['name'],
                ($emp['full_name'] ?? 'Funcionário') . " solicitou o brinde \"{$reward['name']}\" por {$cost} pontos.",
                'index.php?m=rh&page=rewards&status=pendente', 'info', 'rh');
        }
        Session::flash('success', "Resgate solicitado! O RH vai avaliar e você será avisado. ({$cost} pontos reservados)");
        header("Location: $back"); exit;
    }

    /** RH: aprovar / entregar / rejeitar / cancelar um resgate (POST). */
    public function respond(): void
    {
        core_require('rewards.respond'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $decision = Sanitize::post('decision');
        $response = mb_substr(Sanitize::post('response'), 0, 1000) ?: null;
        $red = RewardRedemption::find($id);
        if (!$red) { Session::flash('error', 'Resgate não encontrado.'); header('Location: index.php?m=rh&page=rewards'); exit; }
        $reward = Reward::find((int)$red['reward_id']);
        $userId = (int)Session::userId();

        $transitions = [
            'pendente' => ['aprovar' => 'aprovada', 'rejeitar' => 'rejeitada', 'cancelar' => 'cancelada'],
            'aprovada' => ['entregar' => 'entregue', 'rejeitar' => 'rejeitada', 'cancelar' => 'cancelada'],
        ];
        $new = $transitions[$red['status']][$decision] ?? null;
        if ($new === null) { Session::flash('error', 'Ação inválida para o status atual.'); header('Location: index.php?m=rh&page=rewards'); exit; }

        $this->db->beginTransaction();
        try {
            $scoreId = $red['score_id'] ? (int)$red['score_id'] : null;
            if ($new === 'aprovada') {
                if ($reward['stock'] !== null && (int)$reward['stock'] <= 0) {
                    throw new RuntimeException('Brinde sem estoque — não é possível aprovar.');
                }
                $scoreId = EmployeeScore::insert([
                    'employee_id' => (int)$red['employee_id'], 'points' => -1 * (int)$red['points_spent'],
                    'reason' => 'Resgate: ' . $reward['name'], 'category' => 'brinde', 'created_by' => $userId,
                ]);
                if ($reward['stock'] !== null) {
                    $this->db->prepare('UPDATE rh_rewards SET stock = stock - 1 WHERE id = ? AND stock > 0')->execute([$reward['id']]);
                }
            } elseif (in_array($new, ['rejeitada', 'cancelada'], true) && $red['status'] === 'aprovada') {
                // Devolve os pontos e o estoque já lançados.
                if ($scoreId) { EmployeeScore::delete($scoreId); $scoreId = null; }
                if ($reward['stock'] !== null) {
                    $this->db->prepare('UPDATE rh_rewards SET stock = stock + 1 WHERE id = ?')->execute([$reward['id']]);
                }
            }
            $this->db->prepare('UPDATE rh_reward_redemptions SET status = ?, response = ?, score_id = ?, responded_by = ?, responded_at = NOW() WHERE id = ?')
                     ->execute([$new, $response, $scoreId, $userId, $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('RH rewards: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível concluir a operação. Tente novamente.');
            header('Location: index.php?m=rh&page=rewards'); exit;
        }
        AuditLog::log('respond', 'reward_redemptions', $id, ['status' => $red['status']], ['status' => $new]);

        // Notifica o funcionário.
        $st = $this->db->prepare('SELECT user_id FROM rh_user_profile WHERE employee_id = ?');
        $st->execute([(int)$red['employee_id']]);
        $empUser = (int)($st->fetchColumn() ?: 0);
        if ($empUser) {
            $labels = ['aprovada' => 'aprovado', 'entregue' => 'entregue', 'rejeitada' => 'recusado', 'cancelada' => 'cancelado'];
            Core\Notifications::add($empUser, 'Brinde ' . $labels[$new] . ': ' . $reward['name'],
                $response ?: 'Seu resgate foi ' . $labels[$new] . '.', 'index.php?m=rh&page=my#brindes',
                in_array($new, ['aprovada', 'entregue'], true) ? 'success' : 'warning', 'rh');
        }
        Session::flash('success', 'Resgate ' . $new . '.');
        header('Location: index.php?m=rh&page=rewards'); exit;
    }
}
