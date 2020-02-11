<?php
declare(strict_types=1);

namespace Utils;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ProjectUtils
 */
class ProjectUtils
{
    const APP_TYPE_SF4 = 'SF4';
    const APP_TYPE_SEF = 'SEF';

    /**
     * Parse change log
     *
     * @param string $changelog
     * @return array
     */
    public function parseChangelog(string $changelog): array
    {
        $retValue = [];
        $key      = null;

        foreach (preg_split("/\r\n|\n|\r/", $changelog) as $line) {
            $line = trim($line);
            if (preg_match('/^#\s?v([0-9]*\.[0-9]*\.[0-9]*)$/', $line, $matches)) {
                $key            = 'v' . $matches[1];
                $retValue[$key] = [];
            } elseif (preg_match('/^\* \\\\#([0-9]*)\s(.*)$/', $line, $matches)) {
                $retValue[$key][] = [
                    'number' => $matches[1],
                    'title'  => $matches[2],
                ];
            } elseif (mb_strlen($line) > 0) {
                $retValue[$key][] = ['text' => $line];
            }
        }

        return $retValue;
    }

    /**
     * Parse app version from config
     *
     * @param string $appType
     * @param string $configContent
     * @return string|null
     */
    public function parseAppVersion(string $appType, string $configContent): ?string
    {
        $retValue = null;

        switch ($appType) {
            case self::APP_TYPE_SF4:
                try {
                    $appConfig  = Yaml::parse($configContent);
                    $parameters = $appConfig['parameters'] ?? [];
                    $retValue   = $parameters['app.release'] ?? null;
                } catch (ParseException $exception) {
                }
                break;
            case self::APP_TYPE_SEF:
                if (preg_match('/^\s*self::DEPLOYMENT_VERSION\s*=>\s*\'(.*)\',\s*$/m', $configContent, $matches)) {
                    $retValue = $matches[1];
                }
                break;
        }

        if ($retValue !== null && strtolower(substr($retValue, 0, 1)) != 'v') {
            $retValue = 'v' . $retValue;
        }

        return $retValue;
    }

    /**
     * Change log version to string
     *
     * @param array $items
     * @return string
     */
    public function changeLogVersionToString(array $items): string
    {
        $contentLines = [];
        foreach ($items as $item) {
            if (array_key_exists('text', $item)) {
                $contentLines[] = $item['text'];
            } else {
                $contentLines[] = sprintf('* \#%1$d %2$s', $item['number'], $item['title']);
            }
        }
        $contentLines[] = '';

        return trim(implode(PHP_EOL, $contentLines));
    }

}
