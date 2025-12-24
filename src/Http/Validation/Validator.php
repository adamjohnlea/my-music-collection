<?php
declare(strict_types=1);

namespace App\Http\Validation;

class Validator
{
    private array $errors = [];

    public function validate(array $data, array $rules): bool
    {
        $this->errors = [];
        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $ruleList = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            
            foreach ($ruleList as $rule) {
                if ($rule === 'required') {
                    if ($value === null || $value === '') {
                        $this->errors[$field][] = "The $field field is required.";
                    }
                } elseif ($rule === 'email') {
                    if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $this->errors[$field][] = "The $field must be a valid email address.";
                    }
                } elseif (str_starts_with($rule, 'min:')) {
                    $min = (int)substr($rule, 4);
                    if ($value !== null && strlen((string)$value) < $min) {
                        $this->errors[$field][] = "The $field must be at least $min characters.";
                    }
                } elseif (str_starts_with($rule, 'max:')) {
                    $max = (int)substr($rule, 4);
                    if ($value !== null && strlen((string)$value) > $max) {
                        $this->errors[$field][] = "The $field must not exceed $max characters.";
                    }
                } elseif ($rule === 'numeric') {
                    if ($value !== null && $value !== '' && !is_numeric($value)) {
                        $this->errors[$field][] = "The $field must be numeric.";
                    }
                }
            }
        }
        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstErrors(): array
    {
        $first = [];
        foreach ($this->errors as $field => $messages) {
            $first[] = $messages[0];
        }
        return $first;
    }
}
