<?php

declare(strict_types=1);

/**
 * Global helpers.
 *
 * Kept small and deliberately boring. The important one is e() — every piece of
 * data echoed into a template goes through it, and that single habit is what
 * keeps the templates free of XSS.
 */

use App\Core\Acl;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Core\Settings;

if (!function_exists('e')) {
    /**
     * Escape for HTML output. The default for everything.
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            // Printing an array is almost always a bug; make it visible in
            // development rather than emitting "Array".
            return htmlspecialchars('[' . implode(', ', array_map('strval', $value)) . ']', ENT_QUOTES, 'UTF-8');
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('e_attr')) {
    /** Escape for an HTML attribute value. */
    function e_attr(mixed $value): string
    {
        return e($value);
    }
}

if (!function_exists('e_js')) {
    /**
     * Escape a value for embedding in a <script> block as JSON.
     *
     * HEX_TAG / HEX_AMP / HEX_APOS / HEX_QUOT stop a value containing
     * "</script>" from breaking out of the block.
     */
    function e_js(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );

        return $json === false ? 'null' : $json;
    }
}

if (!function_exists('clean_html')) {
    /**
     * Allow a narrow set of formatting tags through, for template bodies and
     * notes that staff write. Everything else is escaped.
     *
     * This is an allow-list, not a filter: it strips all tags, then restores
     * only the handful we permit. That ordering matters — a deny-list of
     * dangerous tags is always one obscure vector behind.
     */
    function clean_html(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><h4><blockquote><a><hr>';

        // Remove these elements INCLUDING their contents first. strip_tags()
        // only removes the tags, so "<script>alert(1)</script>" would survive
        // as the visible text "alert(1)" — inert, but nonsense to show a
        // guest, and a confusing thing to find in a saved template.
        $html = (string) preg_replace(
            '#<(script|style|iframe|object|embed|noscript|template)\b[^>]*>.*?</\1\s*>#is',
            '',
            $html
        );

        // An unclosed one would otherwise leave its content behind.
        $html = (string) preg_replace(
            '#<(script|style|iframe|object|embed|noscript|template)\b[^>]*>.*$#is',
            '',
            $html
        );

        $stripped = strip_tags($html, $allowed);

        // Remove every attribute except href on an anchor, and force that href
        // to be a safe scheme.
        $stripped = (string) preg_replace_callback(
            '/<a\b([^>]*)>/i',
            static function (array $m): string {
                if (preg_match('/href\s*=\s*(["\'])(.*?)\1/i', $m[1], $href) !== 1) {
                    return '<a>';
                }

                $url = trim($href[2]);

                if (preg_match('#^(https?://|mailto:|tel:|/)#i', $url) !== 1) {
                    return '<a>';
                }

                return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                    . '" rel="noopener noreferrer" target="_blank">';
            },
            $stripped
        );

        // Strip attributes from every other permitted tag, which is where
        // onclick / style payloads would otherwise live.
        return (string) preg_replace('/<(?!a\b)([a-z0-9]+)\b[^>]*>/i', '<$1>', $stripped);
    }
}

if (!function_exists('url')) {
    /**
     * An absolute-path URL, honouring a subdirectory install.
     */
    function url(string $path = '/'): string
    {
        static $base = null;

        if ($base === null) {
            $configured = Config::get('app_url');

            if (is_string($configured) && $configured !== '') {
                $base = rtrim($configured, '/');
            } else {
                // Derived from the front controller's location, so the same
                // code works at a domain root and under /crm/.
                $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
                $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
                $base   = ($dir === '/' || $dir === '.') ? '' : $dir;
            }
        }

        if ($path === '' || $path === '/') {
            return $base === '' ? '/' : $base . '/';
        }

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * An asset URL with a cache-busting version, so a browser picks up CSS
     * changes after an update without a manual hard refresh.
     */
    function asset(string $path): string
    {
        static $version = null;

        if ($version === null) {
            $file = CC_ROOT . '/VERSION';
            $version = is_file($file) ? trim((string) file_get_contents($file)) : '1';
        }

        return url('assets/' . ltrim($path, '/')) . '?v=' . rawurlencode($version);
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('method_field')) {
    /** Spoof PUT/PATCH/DELETE from an HTML form. */
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        return Acl::can($permission);
    }
}

if (!function_exists('can_any')) {
    /** @param list<string> $permissions */
    function can_any(array $permissions): bool
    {
        return Acl::canAny($permissions);
    }
}

if (!function_exists('auth_user')) {
    /** @return array<string,mixed>|null */
    function auth_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return Settings::get($key, $default);
    }
}

if (!function_exists('old')) {
    /**
     * Repopulate a form after a validation failure.
     */
    function old(string $field, mixed $default = ''): mixed
    {
        $old = Session::get('_old_input', []);

        return is_array($old) ? ($old[$field] ?? $default) : $default;
    }
}

if (!function_exists('error_for')) {
    /** The validation message for a field, if any. */
    function error_for(string $field): ?string
    {
        $errors = Session::get('_errors', []);

        if (!is_array($errors) || !isset($errors[$field])) {
            return null;
        }

        return is_array($errors[$field]) ? (string) $errors[$field][0] : (string) $errors[$field];
    }
}

if (!function_exists('has_error')) {
    function has_error(string $field): bool
    {
        return error_for($field) !== null;
    }
}

if (!function_exists('flash')) {
    /**
     * Queue a one-shot message for the next page.
     *
     * @param 'success'|'error'|'warning'|'info' $type
     */
    function flash(string $type, string $message): void
    {
        $messages = Session::get('_flash', []);
        $messages = is_array($messages) ? $messages : [];
        $messages[] = ['type' => $type, 'message' => $message];
        Session::put('_flash', $messages);
    }
}

if (!function_exists('money')) {
    /**
     * Format cents as currency.
     *
     * Money is stored as integer cents throughout — floats cannot represent
     * 0.1 exactly, and a cent of drift per booking becomes a real
     * reconciliation problem against the POS.
     */
    function money(?int $cents, bool $symbol = true): string
    {
        $cents ??= 0;
        $negative = $cents < 0;
        $formatted = number_format(abs($cents) / 100, 2);

        return ($negative ? '-' : '') . ($symbol ? '$' : '') . $formatted;
    }
}

if (!function_exists('money_short')) {
    /** Compact money for dashboard tiles: $1.2k, $48.5k. */
    function money_short(?int $cents): string
    {
        $dollars = (int) round(($cents ?? 0) / 100);

        if (abs($dollars) >= 1000000) {
            return '$' . rtrim(rtrim(number_format($dollars / 1000000, 1), '0'), '.') . 'M';
        }

        if (abs($dollars) >= 1000) {
            return '$' . rtrim(rtrim(number_format($dollars / 1000, 1), '0'), '.') . 'k';
        }

        return '$' . number_format($dollars);
    }
}

if (!function_exists('venue_tz')) {
    function venue_tz(): DateTimeZone
    {
        static $tz = null;

        return $tz ??= new DateTimeZone(Settings::timezone());
    }
}

if (!function_exists('local_time')) {
    /**
     * Render a UTC database timestamp in the venue's timezone.
     *
     * Everything is stored UTC and displayed local. Mixing the two is the
     * classic source of "the reminder went out at 3am" bugs.
     */
    function local_time(?string $utc, string $format = 'M j, Y g:i A'): string
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
            return '—';
        }

        // A bare date is a day on the calendar, not an instant, so it has no
        // timezone to convert out of. Read as midnight UTC it became the
        // evening BEFORE in New York, and every birthday and party date on
        // the page showed a day early. calendar_date() is the right call for
        // a DATE column; this keeps a stray one from going wrong.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $utc) === 1) {
            return calendar_date($utc, $format);
        }

        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(venue_tz())
                ->format($format);
        } catch (Exception) {
            return '—';
        }
    }
}

if (!function_exists('calendar_date')) {
    /**
     * A calendar day, as stored: a DATE column, or the date part of a
     * DATETIME that only ever holds midnight (a visit has no clock time).
     *
     * NEVER converted between timezones, because there is nothing to convert:
     * a child born on 21 October was born on 21 October in Chester. local_date()
     * is for INSTANTS stored in UTC, and given a day it moved it to the evening
     * before — which on a birthday-party venue's guest page is the one date
     * that must not be wrong.
     */
    function calendar_date(?string $value, string $format = 'M j, Y'): string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return '—';
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $day === false ? '—' : $day->format($format);
    }
}

if (!function_exists('birthday_next')) {
    /**
     * The next birthday on or after today, and the age it brings.
     *
     * Today is the VENUE'S day unless one is given. A 29 February birthday
     * falls on the 28th in a year without one, which is when a family would
     * expect the card.
     *
     * @return array{date:string,turns:int,days:int}|null
     */
    function birthday_next(?string $birthDate, ?string $today = null): ?array
    {
        $born = $birthDate === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', substr($birthDate, 0, 10));
        $now  = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $today ?? (new DateTimeImmutable('now', venue_tz()))->format('Y-m-d')
        );

        if ($born === false || $now === false || $born > $now) {
            return null;
        }

        $month = (int) $born->format('n');
        $day   = (int) $born->format('j');

        $on = static function (int $year) use ($month, $day): DateTimeImmutable {
            $useDay = ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) ? 28 : $day;

            return (new DateTimeImmutable())->setDate($year, $month, $useDay)->setTime(0, 0);
        };

        $next = $on((int) $now->format('Y'));

        if ($next < $now) {
            $next = $on((int) $now->format('Y') + 1);
        }

        return [
            'date'  => $next->format('Y-m-d'),
            'turns' => (int) $next->format('Y') - (int) $born->format('Y'),
            'days'  => (int) $now->diff($next)->days,
        ];
    }
}

if (!function_exists('local_date')) {
    function local_date(?string $utc, string $format = 'M j, Y'): string
    {
        return local_time($utc, $format);
    }
}

if (!function_exists('time_ago')) {
    /** "3 minutes ago", "in 2 days" — for activity feeds and SLA columns. */
    function time_ago(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }

        $timestamp = strtotime($utc . ' UTC');

        if ($timestamp === false) {
            return '—';
        }

        $diff   = time() - $timestamp;
        $future = $diff < 0;
        $diff   = abs($diff);

        $units = [
            [31536000, 'year'],
            [2592000,  'month'],
            [604800,   'week'],
            [86400,    'day'],
            [3600,     'hour'],
            [60,       'minute'],
        ];

        foreach ($units as [$seconds, $name]) {
            if ($diff >= $seconds) {
                $count = (int) floor($diff / $seconds);
                $label = $count . ' ' . $name . ($count === 1 ? '' : 's');

                return $future ? 'in ' . $label : $label . ' ago';
            }
        }

        return $future ? 'in a moment' : 'just now';
    }
}

if (!function_exists('now_utc')) {
    function now_utc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('normalize_phone')) {
    /**
     * Normalise a phone number to E.164 for storage and matching.
     *
     * SMS delivery, Text Club dedup, and inbound-message routing all depend on
     * two renderings of the same number comparing equal, so this runs on every
     * write path rather than at display time.
     */
    function normalize_phone(?string $phone, string $defaultCountry = '1'): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        // 10 digits is a bare North American number.
        if (strlen($digits) === 10) {
            return '+' . $defaultCountry . $digits;
        }

        // 11 digits starting with 1 is already country-coded.
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        if (strlen($digits) >= 11 && strlen($digits) <= 15) {
            return '+' . $digits;
        }

        // Too short or too long to be a real number; keep nothing rather than
        // storing something that will never match or deliver.
        return null;
    }
}

if (!function_exists('format_phone')) {
    /** Display an E.164 number in a readable North American form. */
    function format_phone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '—';
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
        }

        return $phone;
    }
}

if (!function_exists('slugify')) {
    function slugify(string $text, string $separator = '-'): string
    {
        $text = strtolower(trim($text));
        $text = (string) preg_replace('/[^a-z0-9]+/', $separator, $text);

        return trim($text, $separator);
    }
}

if (!function_exists('initials')) {
    /** Up to two initials, for avatar placeholders. */
    function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return '?';
        }

        if (count($parts) === 1) {
            return strtoupper(mb_substr($parts[0], 0, 1));
        }

        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    }
}

if (!function_exists('str_truncate')) {
    function str_truncate(?string $text, int $length = 80, string $suffix = '…'): string
    {
        $text = trim((string) $text);

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $length)) . $suffix;
    }
}

if (!function_exists('percent')) {
    /** A safe percentage that does not divide by zero. */
    function percent(int|float $part, int|float $whole, int $decimals = 0): string
    {
        if ($whole == 0) {
            return '—';
        }

        return number_format(($part / $whole) * 100, $decimals) . '%';
    }
}

if (!function_exists('array_get')) {
    /** Dotted-path read from a nested array. */
    function array_get(array $array, string $key, mixed $default = null): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }

            $array = $array[$segment];
        }

        return $array;
    }
}

if (!function_exists('request')) {
    function request(): Request
    {
        $request = $GLOBALS['cc_request'] ?? null;

        // Cron and CLI contexts have no HTTP request, but shared view partials
        // still ask for one. Synthesising an empty one keeps those paths from
        // having to special-case it.
        if (!$request instanceof Request) {
            $request = Request::capture();
            $GLOBALS['cc_request'] = $request;
        }

        return $request;
    }
}

if (!function_exists('bytes_human')) {
    /**
     * A byte count somebody can read.
     *
     * HERE RATHER THAN ON A CONTROLLER, because the second thing that needed
     * it would otherwise copy it — `BackupController` had its own private
     * copy, and the import screens need the same thing to report an upload
     * limit. One definition is also what stops two pages describing the same
     * file as 12.3 MB and 12 MB.
     */
    function bytes_human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit  = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $value < 10 ? 1 : 0) . ' ' . $units[$unit];
    }
}

if (!function_exists('bytes_from_ini')) {
    /**
     * Read a PHP ini size — `8M`, `512K`, `1G`, or a bare byte count.
     *
     * Needed because `upload_max_filesize` is a STRING in that shorthand, and
     * comparing it numerically reads `8M` as 8. A venue whose host allows 8MB
     * and whose file is 12MB gets "no file was uploaded" from PHP, with no
     * number anywhere, so the import page has to work the real limit out and
     * say it.
     *
     * 0 when it cannot be read, which callers treat as "no limit known"
     * rather than "no bytes allowed".
     */
    function bytes_from_ini(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (float) $value;
        $suffix = strtolower(substr($value, -1));

        return (int) match ($suffix) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}

// The icon set defines icon(); loaded here so every view and controller has it.
require_once __DIR__ . '/Views/partials/icons.php';
