<?php

namespace Tests\Unit;

use App\Services\Audit\ColumnMapper;
use App\Services\Audit\PayrollAuditEngine;
use PHPUnit\Framework\TestCase;

class PayrollAuditEngineTest extends TestCase
{
    public function test_detects_duplicates_missing_and_differences(): void
    {
        $main = [
            ['employeeId' => '100', 'employee' => 'Ana', 'concept' => 'Sueldo', 'value' => 2000000, 'quantity' => 30],
            ['employeeId' => '100', 'employee' => 'Ana', 'concept' => 'Sueldo', 'value' => 2000000, 'quantity' => 30], // duplicado
            ['employeeId' => '200', 'employee' => 'Luis', 'concept' => 'Sueldo', 'value' => 1500000, 'quantity' => 30],
            ['employeeId' => '300', 'employee' => 'Eva', 'concept' => 'Horas extra', 'value' => 80000, 'quantity' => 4],
            ['employeeId' => '', 'employee' => 'Sin id', 'concept' => 'X', 'value' => 1],
        ];
        $compare = [
            ['employeeId' => '100', 'concept' => 'SUELDO', 'value' => '$ 2.000.000', 'quantity' => '30'], // igual
            ['employeeId' => '200', 'concept' => 'Sueldo', 'value' => '1.450.000', 'quantity' => '30'],   // diferencia
            ['employeeId' => '300', 'concept' => 'Horas Extra', 'value' => '80000', 'quantity' => '5'],  // cantidad
            ['employeeId' => '400', 'concept' => 'Bono', 'value' => '50000'],                            // no existe en principal
        ];

        $findings = (new PayrollAuditEngine)->run($main, 'principal.xlsx', [['name' => 'banco.csv', 'rows' => $compare]]);
        $types = array_column($findings, 'type');

        $this->assertSame(['duplicate', 'difference', 'quantity', 'missing_main'], $types);
        $this->assertSame('HALL-0001', $findings[0]['code']);
        $this->assertEquals(-50000, $findings[1]['difference']);
        $this->assertStringContainsString('$ 50.000', $findings[1]['description']);

        $summary = PayrollAuditEngine::summarize($findings);
        $this->assertSame(['total' => 4, 'differences' => 1, 'missing' => 1, 'duplicates' => 1], $summary);
    }

    public function test_reports_main_records_missing_from_comparison(): void
    {
        $main = [['employeeId' => '1', 'concept' => 'Sueldo', 'value' => 10]];
        $findings = (new PayrollAuditEngine)->run($main, 'p.xlsx', [['name' => 'c.xlsx', 'rows' => []]]);

        $this->assertCount(1, $findings);
        $this->assertSame('missing_compare', $findings[0]['type']);
        $this->assertSame('No existe', $findings[0]['compared_value']);
    }

    public function test_number_parsing_and_header_guessing(): void
    {
        $this->assertSame(1250000.0, ColumnMapper::toNumber('$ 1.250.000'));
        $this->assertSame(1250000.5, ColumnMapper::toNumber('1.250.000,50'));
        $this->assertSame(1250000.5, ColumnMapper::toNumber('1,250,000.50'));
        $this->assertSame(-3000.0, ColumnMapper::toNumber('(3.000)'));
        $this->assertSame(12.5, ColumnMapper::toNumber('12,5'));

        $this->assertSame('employeeId', ColumnMapper::guess('Cédula'));
        $this->assertSame('employee', ColumnMapper::guess(' NOMBRE '));
        $this->assertSame('value', ColumnMapper::guess('Valor liquidado'));
        $this->assertSame('', ColumnMapper::guess('Centro de costo'));
    }
}
