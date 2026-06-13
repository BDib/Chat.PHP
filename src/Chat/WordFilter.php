<?php

namespace App\Chat;

use App\Config;

class WordFilter
{
    private static ?array $allowedWords = null;
    private static ?array $badWords = null;

    public static function isPronounceable(string $name): bool
    {
        $len = strlen($name);
        if ($len === 0) return false;

        // Count vowels (y counts as vowel except at word start)
        $vc = preg_match_all('/[aeiouy]/', $name);
        if ($name[0] === 'y') $vc = max(0, $vc - 1);
        if ($vc === 0 || $vc / $len >= 0.8) return false;

        // For structural analysis, treat non-initial y as vowel
        $w = $name[0] . str_replace('y', 'a', substr($name, 1));
        if (preg_match('/[aeiou]{3}/', $w)) return false;
        if (preg_match('/(.)\1{2}/', $name)) return false;

        // Whitelist of allowed consonant pairs and triples
        $ok2 = array_flip([
            'bl', 'br', 'ch', 'cl', 'cr', 'dr', 'fl', 'fr', 'gl', 'gr', 'kn', 'ph', 'pl', 'pr',
            'sc', 'sh', 'sk', 'sl', 'sm', 'sn', 'sp', 'st', 'sw', 'th', 'tr', 'tw', 'wh', 'wr',
            'ck', 'ct', 'ft', 'ld', 'lf', 'lk', 'll', 'lm', 'ln', 'lp', 'ls', 'lt', 'lv',
            'mb', 'mp', 'nc', 'nd', 'ng', 'nk', 'nn', 'ns', 'nt', 'nz',
            'rb', 'rc', 'rd', 'rf', 'rg', 'rk', 'rl', 'rm', 'rn', 'rp', 'rs', 'rt', 'rv',
            'ff', 'ss', 'tt', 'dd', 'bb', 'gg', 'mm', 'pp', 'rr', 'zz',
            'dg', 'dm', 'gn', 'gm', 'mn', 'ks', 'ms', 'ts', 'ds', 'gs', 'bs', 'ws',
            'hn', 'hr', 'ht', 'kr', 'lz', 'nf', 'rz', 'xt', 'pt', 'ps',
        ]);
        $ok3 = array_flip([
            'ndr', 'ntr', 'ngl', 'nch', 'nst', 'ngs', 'nks', 'nts',
            'str', 'sch', 'scr', 'shr', 'spl', 'spr',
            'chr', 'thr', 'ght', 'mph', 'mpl',
            'rch', 'rst', 'rth', 'rld', 'rds', 'rks', 'rms', 'rns', 'rts',
            'lth', 'lds', 'lts',
        ]);

        preg_match_all('/[bcdfghjklmnpqrstvwxyz]+/', $w, $clusters);
        foreach ($clusters[0] as $cl) {
            $clen = strlen($cl);
            if ($clen > 3) return false;
            if ($clen === 3 && !isset($ok3[$cl])) return false;
            if ($clen === 2 && !isset($ok2[$cl])) return false;
        }
        return true;
    }

    public static function loadAllowedWords(): array
    {
        if (self::$allowedWords === null) {
            self::$allowedWords = [];
            if (file_exists(Config::WORDS_PATH)) {
                $lines = file(Config::WORDS_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '' && $line[0] !== '#') {
                        self::$allowedWords[$line] = true;
                    }
                }
            }
        }
        return self::$allowedWords;
    }

    public static function isAllowedWord(string $word): bool
    {
        $allowedWords = self::loadAllowedWords();
        if (strlen($word) <= 1) return $word === 'i' || $word === 'a';
        if (isset($allowedWords[$word])) return true;

        // Try removing common suffixes and checking the stem
        $suffixes = ['ness', 'ment', 'able', 'ible', 'less', 'ful', 'ing', 'est', 'er', 'ed', 'ly', 'es', 's'];
        foreach ($suffixes as $suffix) {
            $len = strlen($suffix);
            if (strlen($word) > $len + 2 && str_ends_with($word, $suffix)) {
                $stem = substr($word, 0, -$len);
                if (isset($allowedWords[$stem])) return true;
                if (isset($allowedWords[$stem . 'e'])) return true;
                if (strlen($stem) >= 2 && $stem[-1] === $stem[-2]) {
                    if (isset($allowedWords[substr($stem, 0, -1)])) return true;
                }
            }
        }

        // Handle -ied -> -y, -ies -> -y
        if ((str_ends_with($word, 'ied') || str_ends_with($word, 'ies')) && strlen($word) > 4) {
            $stem = substr($word, 0, -3) . 'y';
            if (isset($allowedWords[$stem])) return true;
        }

        return false;
    }

    public static function loadBadWords(): array
    {
        if (self::$badWords === null) {
            self::$badWords = [];
            if (file_exists(Config::BAD_WORDS_PATH)) {
                $lines = file(Config::BAD_WORDS_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line !== '' && $line[0] !== '#') {
                        self::$badWords[$line] = true;
                    }
                }
            }
        }
        return self::$badWords;
    }

    public static function isBadWord(string $word): bool
    {
        $badWords = self::loadBadWords();
        return isset($badWords[$word]);
    }
}
