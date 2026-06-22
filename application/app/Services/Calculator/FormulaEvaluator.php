<?php

namespace App\Services\Calculator;

use InvalidArgumentException;

class FormulaEvaluator
{
    /**
     * @return int|float
     */
    public function evaluate(string $expression, int $precision = 2): int|float
    {
        $tokens = $this->tokenize($expression);
        $result = $this->evaluateReversePolish($this->toReversePolish($tokens));

        return $this->normalizeNumber(round($result, max(0, min(6, $precision))));
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    private function tokenize(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        $i = 0;

        while ($i < $length) {
            $char = $expression[$i];

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            if (ctype_digit($char) || $char === '.') {
                $number = '';

                while ($i < $length && (ctype_digit($expression[$i]) || $expression[$i] === '.')) {
                    $number .= $expression[$i];
                    $i++;
                }

                if (!is_numeric($number)) {
                    throw new InvalidArgumentException('Некорректное число в формуле: ' . $number);
                }

                $tokens[] = ['type' => 'number', 'value' => $number];
                continue;
            }

            if (ctype_alpha($char)) {
                $name = '';

                while ($i < $length && (ctype_alpha($expression[$i]) || ctype_digit($expression[$i]) || $expression[$i] === '_')) {
                    $name .= $expression[$i];
                    $i++;
                }

                $tokens[] = ['type' => 'function', 'value' => mb_strtolower($name)];
                continue;
            }

            if (in_array($char, ['+', '-', '*', '/', '%', '^', '(', ')', ','], true)) {
                $tokens[] = ['type' => $char === ',' ? 'separator' : 'operator', 'value' => $char];
                $i++;
                continue;
            }

            throw new InvalidArgumentException('Недопустимый символ в формуле: ' . $char);
        }

        return $this->markUnaryMinus($tokens);
    }

    /**
     * @param array<int, array{type: string, value: string}> $tokens
     * @return array<int, array{type: string, value: string}>
     */
    private function markUnaryMinus(array $tokens): array
    {
        $previous = null;

        foreach ($tokens as $index => $token) {
            if (
                $token['type'] === 'operator'
                && $token['value'] === '-'
                && (
                    $previous === null
                    || $previous['type'] === 'separator'
                    || ($previous['type'] === 'operator' && $previous['value'] !== ')')
                    || ($previous['type'] === 'function')
                )
            ) {
                $tokens[$index]['value'] = 'u-';
            }

            $previous = $tokens[$index];
        }

        return $tokens;
    }

    /**
     * @param array<int, array{type: string, value: string}> $tokens
     * @return array<int, array{type: string, value: string}>
     */
    private function toReversePolish(array $tokens): array
    {
        $output = [];
        $stack = [];

        foreach ($tokens as $token) {
            if ($token['type'] === 'number') {
                $output[] = $token;
                continue;
            }

            if ($token['type'] === 'function') {
                $stack[] = $token;
                continue;
            }

            if ($token['type'] === 'separator') {
                while ($stack !== [] && end($stack)['value'] !== '(') {
                    $output[] = array_pop($stack);
                }

                continue;
            }

            if ($token['value'] === '(') {
                $stack[] = $token;
                continue;
            }

            if ($token['value'] === ')') {
                while ($stack !== [] && end($stack)['value'] !== '(') {
                    $output[] = array_pop($stack);
                }

                if ($stack === []) {
                    throw new InvalidArgumentException('Несогласованные скобки в формуле.');
                }

                array_pop($stack);

                if ($stack !== [] && end($stack)['type'] === 'function') {
                    $output[] = array_pop($stack);
                }

                continue;
            }

            while (
                $stack !== []
                && end($stack)['value'] !== '('
                && (
                    $this->precedence(end($stack)['value']) > $this->precedence($token['value'])
                    || (
                        $this->precedence(end($stack)['value']) === $this->precedence($token['value'])
                        && !$this->isRightAssociative($token['value'])
                    )
                )
            ) {
                $output[] = array_pop($stack);
            }

            $stack[] = $token;
        }

        while ($stack !== []) {
            $operator = array_pop($stack);

            if (in_array($operator['value'], ['(', ')'], true)) {
                throw new InvalidArgumentException('Несогласованные скобки в формуле.');
            }

            $output[] = $operator;
        }

        return $output;
    }

    /**
     * @param array<int, array{type: string, value: string}> $tokens
     */
    private function evaluateReversePolish(array $tokens): float
    {
        $stack = [];

        foreach ($tokens as $token) {
            if ($token['type'] === 'number') {
                $stack[] = (float)$token['value'];
                continue;
            }

            if ($token['type'] === 'function') {
                $stack = $this->applyFunction($stack, $token['value']);
                continue;
            }

            if ($token['value'] === 'u-') {
                $stack[] = -1 * $this->popNumber($stack);
                continue;
            }

            $right = $this->popNumber($stack);
            $left = $this->popNumber($stack);

            $stack[] = match ($token['value']) {
                '+' => $left + $right,
                '-' => $left - $right,
                '*' => $left * $right,
                '/' => $right == 0.0 ? throw new InvalidArgumentException('Деление на ноль в формуле.') : $left / $right,
                '%' => $right == 0.0 ? throw new InvalidArgumentException('Деление на ноль в формуле.') : fmod($left, $right),
                '^' => $left ** $right,
                default => throw new InvalidArgumentException('Неизвестный оператор: ' . $token['value']),
            };
        }

        if (count($stack) !== 1) {
            throw new InvalidArgumentException('Некорректная формула.');
        }

        return (float)array_pop($stack);
    }

    /**
     * @param array<int, float> $stack
     * @return array<int, float>
     */
    private function applyFunction(array $stack, string $function): array
    {
        if (!in_array($function, ['round', 'ceil', 'floor', 'abs', 'min', 'max'], true)) {
            throw new InvalidArgumentException('Функция не поддерживается: ' . $function);
        }

        if (in_array($function, ['min', 'max'], true)) {
            $right = $this->popNumber($stack);
            $left = $this->popNumber($stack);
            $stack[] = $function === 'min' ? min($left, $right) : max($left, $right);

            return $stack;
        }

        $value = $this->popNumber($stack);

        $stack[] = match ($function) {
            'round' => round($value),
            'ceil' => ceil($value),
            'floor' => floor($value),
            'abs' => abs($value),
        };

        return $stack;
    }

    /**
     * @param array<int, float> $stack
     */
    private function popNumber(array &$stack): float
    {
        if ($stack === []) {
            throw new InvalidArgumentException('Некорректная формула.');
        }

        return (float)array_pop($stack);
    }

    private function precedence(string $operator): int
    {
        return match ($operator) {
            'u-' => 4,
            '^' => 3,
            '*', '/', '%' => 2,
            '+', '-' => 1,
            default => 0,
        };
    }

    private function isRightAssociative(string $operator): bool
    {
        return in_array($operator, ['^', 'u-'], true);
    }

    private function normalizeNumber(float $value): int|float
    {
        return floor($value) == $value ? (int)$value : $value;
    }
}
