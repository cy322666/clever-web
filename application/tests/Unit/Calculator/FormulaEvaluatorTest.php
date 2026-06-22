<?php

namespace Tests\Unit\Calculator;

use App\Services\Calculator\FormulaEvaluator;
use InvalidArgumentException;
use Tests\TestCase;

class FormulaEvaluatorTest extends TestCase
{
    public function test_it_evaluates_arithmetic_with_precedence(): void
    {
        $result = app(FormulaEvaluator::class)->evaluate('2 + 3 * (10 - 4) / 2', 2);

        $this->assertSame(11, $result);
    }

    public function test_it_supports_unary_minus_and_functions(): void
    {
        $result = app(FormulaEvaluator::class)->evaluate('max(abs(-7), floor(6.8)) + ceil(1.1)', 2);

        $this->assertSame(9, $result);
    }

    public function test_it_throws_on_division_by_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(FormulaEvaluator::class)->evaluate('10 / (5 - 5)');
    }
}
