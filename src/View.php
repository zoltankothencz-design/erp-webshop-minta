<?php
declare(strict_types=1);

namespace App;

final class View
{
    /** Egy sablont (templates/<név>.php) renderel. */
    public static function render(string $template, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        include APP_ROOT . '/templates/' . $template . '.php';
        return (string) ob_get_clean();
    }

    /** Sablon + közös keret (layout). A $layout 'layout' vagy 'admin/layout'. */
    public static function page(string $template, array $vars, string $title, string $layout = 'layout'): string
    {
        $content = self::render($template, $vars);
        return self::render($layout, ['content' => $content, 'title' => $title]);
    }
}
