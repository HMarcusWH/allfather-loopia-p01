<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http;

/**
 * Read the staged Operator Console asset manifest without trusting it.
 *
 * `frontend/console/dist/` is build output, not runtime input. PHP reads only the separately staged
 * public asset set, validates every manifest path, and fails closed if the stage is absent,
 * malformed, unhashed, symlinked or incomplete. There is deliberately no glob fallback such as
 * "first console*.js" — guessing an asset is equivalent to accepting an unreviewed build product.
 */
final class ConsoleAssetManifest
{
    public const VERSION = 1;

    public function __construct(
        private readonly string $stageDir,
        private readonly string $publicPrefix = '/assets/operator-console',
    ) {
    }

    /**
     * @return array{script: string, style: string, assets: list<string>}
     */
    public function load(): array
    {
        $stage = rtrim($this->stageDir, DIRECTORY_SEPARATOR);
        if ($stage === '' || !is_dir($stage) || is_link($stage)) {
            throw new \RuntimeException('operator console asset stage is unavailable');
        }
        if (!str_starts_with($this->publicPrefix, '/') || str_contains($this->publicPrefix, '://')) {
            throw new \RuntimeException('operator console public prefix is invalid');
        }

        $manifestPath = $stage . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            throw new \RuntimeException('operator console manifest is unavailable');
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('operator console manifest is invalid JSON');
        }
        if (!is_array($manifest)) {
            throw new \RuntimeException('operator console manifest must be an object');
        }
        $this->requireExactKeys($manifest, ['version', 'entry', 'assets'], 'manifest');
        if (($manifest['version'] ?? null) !== self::VERSION) {
            throw new \RuntimeException('operator console manifest version is unsupported');
        }
        if (!is_array($manifest['entry'] ?? null)) {
            throw new \RuntimeException('operator console manifest entry is invalid');
        }
        $this->requireExactKeys($manifest['entry'], ['script', 'style'], 'manifest.entry');

        $script = $this->validatedPath($stage, $manifest['entry']['script'] ?? null, 'script');
        $style = $this->validatedPath($stage, $manifest['entry']['style'] ?? null, 'style');

        $assetsRaw = $manifest['assets'] ?? null;
        if (!is_array($assetsRaw) || !array_is_list($assetsRaw)) {
            throw new \RuntimeException('operator console manifest assets must be a list');
        }

        $assets = [];
        foreach ($assetsRaw as $index => $asset) {
            $assets[] = $this->validatedPath($stage, $asset, 'asset', $index);
        }
        if (count(array_unique($assets)) !== count($assets)) {
            throw new \RuntimeException('operator console manifest contains duplicate assets');
        }

        return [
            'script' => $this->publicUrl($script),
            'style' => $this->publicUrl($style),
            'assets' => array_map(fn (string $asset): string => $this->publicUrl($asset), $assets),
        ];
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function requireExactKeys(array $value, array $expected, string $where): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        $wanted = $expected;
        sort($wanted, SORT_STRING);
        if ($actual !== $wanted) {
            throw new \RuntimeException("operator console {$where} has unexpected keys");
        }
    }

    private function validatedPath(string $stage, mixed $candidate, string $kind, ?int $index = null): string
    {
        $label = $index === null ? $kind : "{$kind}[{$index}]";
        if (!is_string($candidate) || $candidate === '') {
            throw new \RuntimeException("operator console {$label} path is missing");
        }
        if (
            str_contains($candidate, "\0")
            || str_contains($candidate, '\\')
            || str_starts_with($candidate, '/')
            || str_contains($candidate, ':')
        ) {
            throw new \RuntimeException("operator console {$label} path is not local");
        }

        $segments = explode('/', $candidate);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException("operator console {$label} path contains an unsafe segment");
            }
        }

        $matches = match ($kind) {
            'script' => preg_match('/^console\.[A-Z0-9]+\.js$/D', $candidate) === 1,
            'style' => preg_match('/^console\.[A-Z0-9]+\.css$/D', $candidate) === 1,
            'asset' => preg_match('/^assets\/[A-Za-z0-9_-]+\.[A-Z0-9]+\.svg$/D', $candidate) === 1,
            default => false,
        };
        if (!$matches) {
            throw new \RuntimeException("operator console {$label} path is unsupported or unhashed");
        }

        $cursor = $stage;
        foreach ($segments as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                throw new \RuntimeException("operator console {$label} path crosses a symlink");
            }
        }
        if (!is_file($cursor)) {
            throw new \RuntimeException("operator console {$label} file is missing");
        }

        $stageReal = realpath($stage);
        $fileReal = realpath($cursor);
        if ($stageReal === false || $fileReal === false || !str_starts_with($fileReal, $stageReal . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("operator console {$label} path escapes the stage");
        }

        return $candidate;
    }

    private function publicUrl(string $relative): string
    {
        return rtrim($this->publicPrefix, '/') . '/' . $relative;
    }
}
