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
        return $tpl;
    }

    public static function allActive(string $type = 'onboarding'): array
    {
        return self::all(['where' => 'active = 1 AND type = ?', 'params' => [$type], 'order' => 'name']);
    }
}
