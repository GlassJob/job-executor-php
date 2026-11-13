<?php
declare(strict_types=1);

namespace JobCenter;

/** Load KEY=VALUE pairs from a .env file. Existing environment variables take precedence. */
final class EnvLoad
{
    public static function loadDotEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('cannot open ' . $path);
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_starts_with($line, 'export ')) {
                    $line = trim(substr($line, 7));
                }
                $eq = strpos($line, '=');
                if ($eq === false) {
                    continue;
                }
                $k = trim(substr($line, 0, $eq));
                $v = trim(substr($line, $eq + 1));
                if (strlen($v) >= 2) {
                    $q = $v[0];
                    if (($q === '"' || $q === "'") && $v[strlen($v) - 1] === $q) {
                        $v = substr($v, 1, -1);
                    }
                }
                if ($k === '' || getenv($k) !== false) {
                    continue;
                }
                putenv($k . '=' . $v);
                $_ENV[$k] = $v;
            }
        } finally {
            fclose($fh);
        }
    }

    /** @param string ...$paths */
    public static function loadFirst(string ...$paths): void
    {
        foreach ($paths as $p) {
            if ($p !== '' && is_file($p)) {
                self::loadDotEnv($p);
                return;
            }
        }
    }

    /** @return list<string> */
    public static function candidatePaths(): array
    {
        $cwd = getcwd() ?: '';
        $out = [];
        if ($cwd !== '') {
            $out[] = $cwd . DIRECTORY_SEPARATOR . '.env';
            $out[] = $cwd . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.env';
            $out[] = $cwd . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.env';
        }
        return $out;
    }
}
