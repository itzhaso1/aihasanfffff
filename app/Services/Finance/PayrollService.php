<?php

namespace App\Services\Finance;

class PayrollService
{
    /**
     * @param  array<string,float|int>  $components
     * @return array{gross:float,deductions:float,net:float}
     */
    public function calculate(array $components): array
    {
        $basic = (float) ($components['basic_salary'] ?? 0);
        $housing = (float) ($components['housing_allowance'] ?? 0);
        $transport = (float) ($components['transport_allowance'] ?? 0);
        $otherAllowances = (float) ($components['other_allowances'] ?? 0);
        $overtime = (float) ($components['overtime'] ?? 0);
        $bonuses = (float) ($components['bonuses'] ?? 0);

        $deductions = (float) ($components['deductions'] ?? 0);
        $advances = (float) ($components['advances'] ?? 0);
        $absenceDeduction = (float) ($components['absence_deduction'] ?? 0);

        $gross = round($basic + $housing + $transport + $otherAllowances + $overtime + $bonuses, 2);
        $totalDeductions = round($deductions + $advances + $absenceDeduction, 2);
        $net = round($gross - $totalDeductions, 2);

        return [
            'gross' => $gross,
            'deductions' => $totalDeductions,
            'net' => $net,
        ];
    }
}
