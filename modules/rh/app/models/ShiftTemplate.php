<?php
class ShiftTemplate extends Model
{
    protected static string $table = 'shift_templates';
    protected static array $fillable = ['name','work_hours','rest_hours','color','active'];

    public static function allActive(): array
    {
        return self::all(['where' => 'active = 1', 'order' => 'name']);
    }
}
