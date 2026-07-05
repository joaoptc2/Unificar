<?php
class EmployeeDependent extends Model
{
    protected static string $table = 'rh_employee_dependents';
    protected static array $fillable = ['employee_id','full_name','cpf','birth_date','relationship','for_health_plan','for_ir','notes'];

    public static function forEmployee(int $empId): array
    {
        return self::all(['where' => 'employee_id = ?', 'params' => [$empId], 'order' => 'full_name']);
    }
}
