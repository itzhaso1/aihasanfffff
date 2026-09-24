<?php

namespace App\Support\Email;

class EmailTemplateLibrary
{
    /**
     * @return array<string, array{name:string,name_en:string,description:string}>
     */
    public static function categories(): array
    {
        $categories = config('email_templates.library.categories', []);

        return is_array($categories) ? $categories : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(?string $category = null, ?string $status = null): array
    {
        $templates = config('email_templates.library.templates', []);
        if (! is_array($templates)) {
            return [];
        }

        return collect($templates)
            ->filter(function (mixed $template, mixed $key) use ($category, $status): bool {
                if (! is_string($key) || ! is_array($template)) {
                    return false;
                }

                if ($category !== null && ($template['category'] ?? null) !== $category) {
                    return false;
                }

                if ($status !== null && ($template['status'] ?? null) !== $status) {
                    return false;
                }

                return isset($template['view']);
            })
            ->map(fn (array $template, string $key): array => ['key' => $key] + $template)
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        $template = config('email_templates.library.templates.'.$key);

        return is_array($template) && isset($template['view'])
            ? ['key' => $key] + $template
            : null;
    }

    public static function view(string $key): ?string
    {
        $view = self::get($key)['view'] ?? null;

        return is_string($view) && $view !== '' ? $view : null;
    }

    /**
     * @return list<string>
     */
    public static function variables(string $key): array
    {
        $variables = self::get($key)['variables'] ?? [];

        return is_array($variables)
            ? array_values(array_filter($variables, fn (mixed $name): bool => is_string($name) && $name !== ''))
            : [];
    }

    public static function exists(string $key): bool
    {
        return self::get($key) !== null;
    }
}
