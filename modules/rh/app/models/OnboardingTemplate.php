<?php
class OnboardingTemplate extends Model
{
    protected static string $table = 'rh_onboarding_templates';
    protected static array $fillable = ['name','type','department_id','active'];

    public static function withItems(int $id): ?array
    {
        $tpl = self::find($id);
        if (!$tpl) return null;
        $stmt = self::db()->prepare('SELECT * FROM rh_onboarding_template_items WHERE template_id = ? ORDER BY sort_order');
        $stmt->execute([$id]);
        $tpl['items'] = $stmt->fetchAll();

        // Quantos funcionários já receberam este checklist. A exclusão do
        // template leva o progresso junto (ON DELETE CASCADE nas três FKs de
        // rh_onboarding_progress), então a tela precisa dizer o tamanho do
        // estrago antes de perguntar.
        $stmt = self::db()->prepare('SELECT COUNT(DISTINCT employee_id) FROM rh_onboarding_progress WHERE template_id = ?');
        $stmt->execute([$id]);
        $tpl['atribuidos'] = (int) $stmt->fetchColumn();
        return $tpl;
    }

    public static function allActive(string $type = 'onboarding'): array
    {
        return self::all(['where' => 'active = 1 AND type = ?', 'params' => [$type], 'order' => 'name']);
    }
}
