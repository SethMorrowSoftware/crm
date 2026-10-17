<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Input validation with human-readable messages.
 *
 * Rules are declared as a string per field: 'required|email|max:160'. The
 * validator returns both the errors and a cleaned value set, so a controller
 * writes the validated array rather than reaching back into the request and
 * accidentally saving an unvalidated field.
 *
 * Messages are written for the person filling the form, not the developer —
 * "Enter a valid email address", not "email validation failed".
 */
final class Validator
{
    /** @var array<string,mixed> */
    private array $data;

    /** @var array<string,string> */
    private array $rules;

    /** @var array<string,list<string>> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $validated = [];

    /** @var array<string,string> Field name => display label. */
    private array $labels = [];

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public function __construct(array $data, array $rules, array $labels = [])
    {
        $this->data   = $data;
        $this->rules  = $rules;
        $this->labels = $labels;
    }

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function passes(): bool
    {
        $this->run();

        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        $this->run();

        return $this->errors;
    }

    /** The first error per field, which is what a form usually shows. */
    /** @return array<string,string> */
    public function firstErrors(): array
    {
        $out = [];

        foreach ($this->errors() as $field => $messages) {
            $out[$field] = $messages[0];
        }

        return $out;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors() as $messages) {
            return $messages[0];
        }

        return null;
    }

    /**
     * The validated subset. Only fields that had rules and passed appear, so
     * this is safe to hand straight to a model.
     *
     * @return array<string,mixed>
     */
    public function validated(): array
    {
        $this->run();

        return $this->validated;
    }

    private bool $hasRun = false;

    private function run(): void
    {
        if ($this->hasRun) {
            return;
        }

        $this->hasRun = true;

        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $rules = array_filter(explode('|', $ruleString));

            $isNullable = in_array('nullable', $rules, true);
            $isRequired = in_array('required', $rules, true);

            $isEmpty = $value === null || $value === '' || (is_array($value) && $value === []);

            if ($isEmpty) {
                if ($isRequired) {
                    $this->addError($field, sprintf('%s is required.', $this->label($field)));
                    continue;
                }

                // Nothing to validate. Record null for nullable fields so the
                // caller can distinguish "cleared" from "not submitted".
                if ($isNullable && array_key_exists($field, $this->data)) {
                    $this->validated[$field] = null;
                }

                continue;
            }

            $failed = false;

            foreach ($rules as $rule) {
                if (in_array($rule, ['required', 'nullable'], true)) {
                    continue;
                }

                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

                if (!$this->check($name, $value, $parameter, $field, $rules)) {
                    $failed = true;
                    break;
                }
            }

            if (!$failed) {
                $this->validated[$field] = $this->normalize($value, $rules);
            }
        }
    }

    /**
     * @param list<string> $fieldRules All rules declared for this field, so
     *                                 min/max know whether they are bounding a
     *                                 number or a string length.
     */
    private function check(string $rule, mixed $value, ?string $parameter, string $field, array $fieldRules = []): bool
    {
        $label = $this->label($field);

        /*
         * A VALUE THAT IS NOT A SCALAR IS REFUSED HERE, ONCE.
         *
         * Nearly every rule below does `(string) $value` or `strlen()` on it,
         * and PHP casting an array to a string is a warning — which means two
         * different wrong answers depending on how the install is configured.
         * In development, where notices are promoted to exceptions, the
         * request dies with a 500 before validation has returned anything. In
         * production it is worse than that: the warning is swallowed and the
         * value becomes the literal string `"Array"`, which then passes
         * `max:80` happily and gets stored.
         *
         * And it is trivially reachable — `field[]=x` in a form post, or a
         * JSON array in an API body — so a public enquiry form could be made
         * to answer 500, or to file an enquiry whose name is "Array".
         *
         * Found by review on the API's consent endpoint, where `in:email,sms`
         * on an array answered 500; fixed at the validator because every
         * controller and every public form shares it. `array` and the
         * presence rules are exempt: they are the ones that legitimately
         * expect something that is not a scalar.
         */
        $declaredArray = false;

        foreach ($fieldRules as $declared) {
            if (explode(':', $declared, 2)[0] === 'array') {
                $declaredArray = true;
                break;
            }
        }

        if ($value !== null && !is_scalar($value)
            && !in_array($rule, ['required', 'nullable', 'array'], true)
            // `min`/`max` count ITEMS on a field declared `array` — the same
            // rule meaning two things, decided by the declaration rather than
            // by inspecting the value, exactly as the numeric case above.
            && !($declaredArray && in_array($rule, ['min', 'max'], true))
        ) {
            return $this->addError($field, sprintf('%s must be text.', $label));
        }

        // A ZIP code, a phone number and an order reference are all strings
        // that happen to look numeric. Whether min/max means "value between"
        // or "length between" is therefore decided by the field's DECLARED
        // type, never by inspecting the value — otherwise 'max:20' on a
        // postal_code rejects "10918" for being larger than twenty.
        $isNumericField = false;

        foreach ($fieldRules as $declared) {
            if (in_array(explode(':', $declared, 2)[0], ['int', 'integer', 'numeric'], true)) {
                $isNumericField = true;
                break;
            }
        }

        switch ($rule) {
            case 'string':
                if (!is_string($value)) {
                    return $this->addError($field, sprintf('%s must be text.', $label));
                }

                return true;

            case 'email':
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    return $this->addError($field, sprintf('Enter a valid email address for %s.', lcfirst($label)));
                }

                // An address with no dot in the domain is syntactically legal
                // but never deliverable on the public internet.
                if (!str_contains(explode('@', $value)[1] ?? '', '.')) {
                    return $this->addError($field, sprintf('Enter a valid email address for %s.', lcfirst($label)));
                }

                return true;

            case 'phone':
                $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

                if (strlen($digits) < 10 || strlen($digits) > 15) {
                    return $this->addError($field, sprintf('Enter a valid phone number for %s.', lcfirst($label)));
                }

                return true;

            case 'url':
                if (filter_var((string) $value, FILTER_VALIDATE_URL) === false) {
                    return $this->addError($field, sprintf('%s must be a valid URL.', $label));
                }

                if (!preg_match('#^https?://#i', (string) $value)) {
                    return $this->addError($field, sprintf('%s must start with http:// or https://.', $label));
                }

                return true;

            case 'int':
            case 'integer':
                if (!is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
                    return $this->addError($field, sprintf('%s must be a whole number.', $label));
                }

                return true;

            case 'numeric':
                if (!is_numeric($value)) {
                    return $this->addError($field, sprintf('%s must be a number.', $label));
                }

                return true;

            case 'bool':
            case 'boolean':
                if (!in_array((string) $value, ['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true)) {
                    return $this->addError($field, sprintf('%s must be yes or no.', $label));
                }

                return true;

            case 'min':
                if ($isNumericField) {
                    if ((float) $value < (float) $parameter) {
                        return $this->addError($field, sprintf('%s must be at least %s.', $label, $parameter));
                    }

                    return true;
                }

                if (is_array($value)) {
                    if (count($value) < (int) $parameter) {
                        return $this->addError($field, sprintf('Choose at least %d.', (int) $parameter));
                    }

                    return true;
                }

                if (mb_strlen((string) $value) < (int) $parameter) {
                    return $this->addError(
                        $field,
                        sprintf('%s must be at least %d characters.', $label, (int) $parameter)
                    );
                }

                return true;

            case 'max':
                if ($isNumericField) {
                    if ((float) $value > (float) $parameter) {
                        return $this->addError($field, sprintf('%s must be %s or less.', $label, $parameter));
                    }

                    return true;
                }

                if (is_array($value)) {
                    if (count($value) > (int) $parameter) {
                        return $this->addError($field, sprintf('Choose no more than %d.', (int) $parameter));
                    }

                    return true;
                }

                if (mb_strlen((string) $value) > (int) $parameter) {
                    return $this->addError(
                        $field,
                        sprintf('%s must be %d characters or fewer.', $label, (int) $parameter)
                    );
                }

                return true;

            case 'in':
                $allowed = explode(',', (string) $parameter);

                if (!in_array((string) $value, $allowed, true)) {
                    return $this->addError($field, sprintf('%s is not one of the allowed values.', $label));
                }

                return true;

            case 'date':
                if (!is_string($value) || strtotime($value) === false) {
                    return $this->addError($field, sprintf('%s must be a valid date.', $label));
                }

                return true;

            case 'date_format':
                $parsed = \DateTimeImmutable::createFromFormat((string) $parameter, (string) $value);

                if ($parsed === false || $parsed->format((string) $parameter) !== (string) $value) {
                    return $this->addError($field, sprintf('%s must look like %s.', $label, $parameter));
                }

                return true;

            case 'after_today':
                $timestamp = strtotime((string) $value);

                if ($timestamp === false || $timestamp < strtotime('today')) {
                    return $this->addError($field, sprintf('%s must be today or later.', $label));
                }

                return true;

            case 'time':
                if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $value) !== 1) {
                    return $this->addError($field, sprintf('%s must be a time like 14:30.', $label));
                }

                return true;

            case 'slug':
                if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value) !== 1) {
                    return $this->addError($field, sprintf('%s may use lowercase letters, numbers and hyphens only.', $label));
                }

                return true;

            case 'array':
                if (!is_array($value)) {
                    return $this->addError($field, sprintf('%s must be a list.', $label));
                }

                return true;

            case 'confirmed':
                if (($this->data[$field . '_confirmation'] ?? null) !== $value) {
                    return $this->addError($field, sprintf('%s and its confirmation do not match.', $label));
                }

                return true;

            case 'different':
                if ((string) $value === (string) ($this->data[(string) $parameter] ?? '')) {
                    return $this->addError($field, sprintf('%s must differ from %s.', $label, $this->label((string) $parameter)));
                }

                return true;

            case 'regex':
                if (preg_match('#' . $parameter . '#', (string) $value) !== 1) {
                    return $this->addError($field, sprintf('%s is not in the expected format.', $label));
                }

                return true;

            case 'color':
                if (preg_match('/^#[0-9a-f]{6}$/i', (string) $value) !== 1) {
                    return $this->addError($field, sprintf('%s must be a hex colour like #6b4ea8.', $label));
                }

                return true;

            case 'timezone':
                if (!in_array((string) $value, \DateTimeZone::listIdentifiers(), true)) {
                    return $this->addError($field, sprintf('%s must be a valid timezone.', $label));
                }

                return true;

            case 'unique':
                // unique:table,column[,ignore_id]
                [$table, $column, $ignoreId] = array_pad(explode(',', (string) $parameter), 3, null);

                $sql    = 'SELECT id FROM {' . $table . '} WHERE ' . DB::quoteIdentifier((string) $column) . ' = :value';
                $params = ['value' => $value];

                if ($ignoreId !== null && $ignoreId !== '') {
                    $sql .= ' AND id <> :ignore';
                    $params['ignore'] = (int) $ignoreId;
                }

                if (DB::columnExists((string) $table, 'deleted_at')) {
                    $sql .= ' AND deleted_at IS NULL';
                }

                if (DB::exists($sql, $params)) {
                    return $this->addError($field, sprintf('That %s is already in use.', strtolower($label)));
                }

                return true;

            case 'exists':
                // exists:table[,column]
                [$table, $column] = array_pad(explode(',', (string) $parameter), 2, 'id');

                if (!DB::exists(
                    'SELECT id FROM {' . $table . '} WHERE ' . DB::quoteIdentifier((string) $column) . ' = :value',
                    ['value' => $value]
                )) {
                    return $this->addError($field, sprintf('The selected %s does not exist.', strtolower($label)));
                }

                return true;

            default:
                // An unknown rule is a programming error, and silently passing
                // it would mean a field nobody validated. Say so loudly in the
                // log, and fail the field.
                Log::error('Unknown validation rule', ['rule' => $rule, 'field' => $field]);

                return $this->addError($field, sprintf('%s could not be validated.', $label));
        }
    }

    /** @param list<string> $rules */
    private function normalize(mixed $value, array $rules): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        foreach ($rules as $rule) {
            $name = explode(':', $rule, 2)[0];

            if (in_array($name, ['int', 'integer'], true)) {
                return (int) $value;
            }

            if ($name === 'numeric') {
                return (float) $value;
            }

            if (in_array($name, ['bool', 'boolean'], true)) {
                return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
            }

            if ($name === 'email') {
                return strtolower((string) $value);
            }
        }

        return $value;
    }

    private function addError(string $field, string $message): bool
    {
        $this->errors[$field][] = $message;

        return false;
    }

    private function label(string $field): string
    {
        if (isset($this->labels[$field])) {
            return $this->labels[$field];
        }

        // first_name → First name
        return ucfirst(str_replace('_', ' ', $field));
    }
}
