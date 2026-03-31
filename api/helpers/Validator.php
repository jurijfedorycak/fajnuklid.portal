<?php

declare(strict_types=1);

namespace App\Helpers;

class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];
    private array $validated = [];

    private array $messages = [
        'required' => 'Pole :field je povinné.',
        'email' => 'Pole :field musí být platná e-mailová adresa.',
        'min' => 'Pole :field musí mít alespoň :param znaků.',
        'max' => 'Pole :field nesmí být delší než :param znaků.',
        'numeric' => 'Pole :field musí být číslo.',
        'integer' => 'Pole :field musí být celé číslo.',
        'string' => 'Pole :field musí být textový řetězec.',
        'boolean' => 'Pole :field musí být pravda nebo nepravda.',
        'array' => 'Pole :field musí být seznam.',
        'in' => 'Pole :field obsahuje neplatnou hodnotu.',
        'confirmed' => 'Potvrzení pole :field se neshoduje.',
        'regex' => 'Pole :field má nesprávný formát.',
        'ico' => 'Pole :field musí být platné IČO (8 číslic).',
    ];

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    public function validate(): bool
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $this->data[$field] ?? null;

            foreach ($rules as $rule) {
                $this->applyRule($field, $value, $rule);
            }

            // Add to validated data if no errors for this field
            if (!isset($this->errors[$field]) && array_key_exists($field, $this->data)) {
                $this->validated[$field] = $value;
            }
        }

        return empty($this->errors);
    }

    private function applyRule(string $field, mixed $value, string $rule): void
    {
        $parts = explode(':', $rule);
        $ruleName = $parts[0];
        $param = $parts[1] ?? null;

        // Skip validation for nullable fields with null/empty value (except required)
        if ($ruleName !== 'required' && ($value === null || $value === '')) {
            return;
        }

        $method = 'validate' . ucfirst($ruleName);

        if (method_exists($this, $method)) {
            if (!$this->$method($value, $param)) {
                $this->addError($field, $ruleName, $param);
            }
        }
    }

    private function addError(string $field, string $rule, ?string $param = null): void
    {
        $message = $this->messages[$rule] ?? "Pole :field je neplatné.";
        $message = str_replace(':field', $field, $message);

        if ($param !== null) {
            $message = str_replace(':param', $param, $message);
        }

        $this->errors[$field][] = $message;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getValidated(): array
    {
        return $this->validated;
    }

    // Validation methods
    private function validateRequired(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_array($value) && empty($value)) {
            return false;
        }

        return true;
    }

    private function validateEmail(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validateMin(mixed $value, string $param): bool
    {
        $min = (int) $param;

        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }

        if (is_numeric($value)) {
            return $value >= $min;
        }

        if (is_array($value)) {
            return count($value) >= $min;
        }

        return false;
    }

    private function validateMax(mixed $value, string $param): bool
    {
        $max = (int) $param;

        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }

        if (is_numeric($value)) {
            return $value <= $max;
        }

        if (is_array($value)) {
            return count($value) <= $max;
        }

        return false;
    }

    private function validateNumeric(mixed $value): bool
    {
        return is_numeric($value);
    }

    private function validateInteger(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    private function validateString(mixed $value): bool
    {
        return is_string($value);
    }

    private function validateBoolean(mixed $value): bool
    {
        return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true);
    }

    private function validateArray(mixed $value): bool
    {
        return is_array($value);
    }

    private function validateIn(mixed $value, string $param): bool
    {
        $allowed = explode(',', $param);
        return in_array($value, $allowed, true);
    }

    private function validateConfirmed(mixed $value, ?string $param = null): bool
    {
        // For fields like 'password', check 'password_confirmation'
        $confirmField = $param ?? 'confirmation';

        return isset($this->data[$confirmField]) && $value === $this->data[$confirmField];
    }

    private function validateRegex(mixed $value, string $param): bool
    {
        return preg_match($param, $value) === 1;
    }

    private function validateIco(mixed $value): bool
    {
        // Czech IČO validation - 8 digits
        if (!preg_match('/^\d{8}$/', $value)) {
            return false;
        }

        // Checksum validation
        $weights = [8, 7, 6, 5, 4, 3, 2];
        $sum = 0;

        for ($i = 0; $i < 7; $i++) {
            $sum += (int) $value[$i] * $weights[$i];
        }

        $remainder = $sum % 11;
        $checkDigit = (11 - $remainder) % 10;

        return (int) $value[7] === $checkDigit;
    }
}
