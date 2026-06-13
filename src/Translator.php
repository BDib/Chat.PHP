<?php

namespace App;

class Translator
{
    private static array $messages = [];
    private static string $currentLang = 'en';
    private static array $rtlLangs = ['ar', 'he', 'fa'];

    public static function load(?string $lang = null): void
    {
        $lang = $lang ?: Config::get('APP_LANG', 'en');
        self::$currentLang = $lang;
        $path = __DIR__ . "/../lang/{$lang}.json";

        if (file_exists($path)) {
            self::$messages = json_decode(file_get_contents($path), true) ?: [];
        }
    }

    public static function trans(string $key, array $replace = []): string
    {
        if (empty(self::$messages)) {
            self::load();
        }

        $message = self::$messages[$key] ?? $key;

        foreach ($replace as $k => $v) {
            $message = str_replace(":{$k}", $v, $message);
        }

        return $message;
    }

    public static function getLang(): string
    {
        return self::$currentLang;
    }

    public static function isRtl(): bool
    {
        return in_array(self::$currentLang, self::$rtlLangs, true);
    }
}
