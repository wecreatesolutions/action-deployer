<?php

declare(strict_types=1);

namespace Utils;

/**
 * Parses the app version and the changelog of a project, same format as wecreatesolutions/devops and wcs-cli
 *
 * @phpstan-type ChangelogItem array{number: string, title: string}|array{text: string}
 */
final class ProjectUtils
{
    public const string APP_TYPE_SF4 = 'SF4';

    public const string APP_TYPE_SEF = 'SEF';

    /**
     * Parse the changelog: `# vX.Y.Z` headings with card lines `* \#123 title`, QA card lines `* \#QA-123 title` /
     * `* \QA-#123 title`, and any other line as text
     *
     * @return array<string, list<ChangelogItem>>
     */
    public function parseChangelog(string $changelog): array
    {
        $retValue = [];
        $key      = '';

        foreach (preg_split('/\r\n|\n|\r/', $changelog) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^#\s?v([0-9]+\.[0-9]+\.[0-9]+)$/', $line, $matches) === 1) {
                $key            = 'v' . $matches[1];
                $retValue[$key] = [];
            } elseif (preg_match('/^\* \\\\#(QA-)?([0-9]+)\s(.*)$/', $line, $matches) === 1) {
                $retValue[$key][] = ['number' => $matches[1] . $matches[2], 'title' => $matches[3]];
            } elseif (preg_match('/^\* \\\\QA-#([0-9]+)\s(.*)$/', $line, $matches) === 1) {
                $retValue[$key][] = ['number' => 'QA-' . $matches[1], 'title' => $matches[2]];
            } elseif ($line !== '') {
                $retValue[$key][] = ['text' => $line];
            }
        }

        return $retValue;
    }

    /**
     * Parse the app version from the config of the app, always with a `v` prefix: SEF the DEPLOYMENT_VERSION in
     * application/config/application/Config.php, Symfony parameters.app.release in config/packages/app.yaml
     */
    public function parseAppVersion(string $appType, string $configContent): ?string
    {
        $pattern = match ($appType) {
            self::APP_TYPE_SEF => '/^\s*self::DEPLOYMENT_VERSION\s*=>\s*\'(.*)\',\s*$/m',
            self::APP_TYPE_SF4 => '/^\s+app\.release:\s*[\'"]?([^\'"\s#]+)[\'"]?/m',
            default            => null,
        };

        if ($pattern === null || preg_match($pattern, $configContent, $matches) !== 1) {
            return null;
        }

        return 'v' . ltrim($matches[1], 'vV');
    }

    /**
     * Changelog items of one version as release notes
     *
     * @param list<ChangelogItem> $items
     */
    public function changeLogVersionToString(array $items): string
    {
        $contentLines = [];
        foreach ($items as $item) {
            $contentLines[] = isset($item['text']) ? $item['text'] : sprintf('* \#%1$s %2$s', $item['number'], $item['title']);
        }

        return trim(implode(PHP_EOL, $contentLines));
    }
}
