<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Plain PHP templates.
 *
 * No template language: a view is a .php file that echoes. The rule that keeps
 * this safe is that every variable goes through e() — see helpers.php — and the
 * only exception is content explicitly passed through clean_html().
 */
final class View
{
    private static string $path = '';

    /** @var array<string,mixed> Data shared with every view. */
    private static array $shared = [];

    /** @var array<string,string> Captured named sections. */
    private static array $sections = [];

    /** @var list<string> Open section stack, for nesting detection. */
    private static array $sectionStack = [];

    public static function init(?string $viewPath = null): void
    {
        self::$path = $viewPath ?? CC_ROOT . '/app/Views';
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Render a view inside a layout.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $view, array $data = [], string $layout = 'layouts/app'): string
    {
        $content = self::renderPartial($view, $data);

        if ($layout === '') {
            return $content;
        }

        return self::renderPartial($layout, array_merge($data, [
            'content'  => $content,
            'sections' => self::$sections,
        ]));
    }

    /**
     * Render a view with no layout — for partials and AJAX fragments.
     *
     * @param array<string,mixed> $data
     */
    public static function renderPartial(string $view, array $data = []): string
    {
        $file = self::resolve($view);

        $scope = array_merge(self::$shared, $data);

        // Keep output buffering balanced even if the template throws, or a
        // partial failure would corrupt the whole response.
        $level = ob_get_level();
        ob_start();

        try {
            (static function (array $__data, string $__file): void {
                // Templates get their data as local variables; the two
                // underscore-prefixed names are the only ones they must avoid.
                extract($__data, EXTR_SKIP);
                require $__file;
            })($scope, $file);

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }
    }

    public static function exists(string $view): bool
    {
        return is_file(self::pathFor($view));
    }

    private static function resolve(string $view): string
    {
        $file = self::pathFor($view);

        if (!is_file($file)) {
            throw new RuntimeException("View '{$view}' not found at {$file}.");
        }

        return $file;
    }

    private static function pathFor(string $view): string
    {
        // Reject traversal: a view name is always a plain dotted or slashed path.
        $clean = str_replace(['..', "\0"], '', $view);
        $clean = str_replace('.', '/', $clean);
        $clean = trim((string) preg_replace('#/+#', '/', $clean), '/');

        if (self::$path === '') {
            self::init();
        }

        return self::$path . '/' . $clean . '.php';
    }

    // -----------------------------------------------------------------
    // Sections — a view declares content a layout then places
    // -----------------------------------------------------------------

    public static function startSection(string $name): void
    {
        self::$sectionStack[] = $name;
        ob_start();
    }

    public static function endSection(): void
    {
        if (self::$sectionStack === []) {
            throw new RuntimeException('endSection() called without a matching startSection().');
        }

        $name = array_pop(self::$sectionStack);
        self::$sections[$name] = (string) ob_get_clean();
    }

    public static function section(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }

    public static function hasSection(string $name): bool
    {
        return isset(self::$sections[$name]) && trim(self::$sections[$name]) !== '';
    }

    /** Reset between renders — important in cron, where many run in one process. */
    public static function reset(): void
    {
        self::$sections = [];
        self::$sectionStack = [];
    }

    // -----------------------------------------------------------------
    // Error pages
    // -----------------------------------------------------------------

    /**
     * Render an error page, falling back to inline HTML if the error view
     * itself is missing or broken — an error handler that can throw is useless.
     *
     * @param array<string,mixed> $data
     */
    public static function renderError(int $status, string $title, array $data = []): string
    {
        $data = array_merge(['status' => $status, 'title' => $title], $data);

        try {
            if (self::exists('errors/' . $status)) {
                return self::render('errors/' . $status, $data, 'layouts/bare');
            }

            if (self::exists('errors/generic')) {
                return self::render('errors/generic', $data, 'layouts/bare');
            }
        } catch (Throwable $e) {
            Log::error('Error view failed to render', [
                'status' => $status,
                'error'  => $e->getMessage(),
            ]);
        }

        return self::fallbackError($status, $title, (string) ($data['message'] ?? ''));
    }

    private static function fallbackError(int $status, string $title, string $message): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $esc($title) . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
            . 'background:#f6f7f9;color:#1b1f26;display:grid;place-items:center;min-height:100vh;margin:0}'
            . 'main{background:#fff;padding:2.5rem;border-radius:12px;max-width:30rem;'
            . 'box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center}'
            . 'h1{font-size:3rem;margin:0;color:#6b4ea8}p{color:#5b6472;line-height:1.6}'
            . 'a{color:#6b4ea8}</style></head><body><main>'
            . '<h1>' . $status . '</h1><h2>' . $esc($title) . '</h2>'
            . ($message !== '' ? '<p>' . $esc($message) . '</p>' : '')
            . '<p><a href="' . $esc(url('/')) . '">Back to Castle CRM</a></p>'
            . '</main></body></html>';
    }
}
