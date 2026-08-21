<?php

namespace Jelite;

use function htmlspecialchars as e;

/**
 * Small Markdown → HTML renderer for the admin Docs page (no Composer deps).
 *
 * Supported subset: fenced code blocks, ATX headings (#..####), pipe tables,
 * unordered/ordered lists, paragraphs, bold (**x**), inline code (`x`) and
 * links ([text](url), http(s)/# only). All raw HTML is escaped by default.
 */
class Markdown
{
    public static function toHtml(string $markdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $out = [];
        $state = ['code' => false, 'codeBuf' => [], 'list' => null, 'listBuf' => [], 'para' => [], 'table' => []];

        foreach ($lines as $line) {
            if ($state['code']) {
                if (preg_match('/^```/', $line)) {
                    $out[] = '<pre><code>' . e(implode("\n", $state['codeBuf'])) . '</code></pre>';
                    $state['code'] = false;
                    $state['codeBuf'] = [];
                } else {
                    $state['codeBuf'][] = $line;
                }
                continue;
            }

            if (preg_match('/^```/', $line)) {
                self::flushPara($state, $out);
                self::flushList($state, $out);
                self::flushTable($state, $out);
                $state['code'] = true;
                continue;
            }

            // Table rows.
            if (preg_match('/^\s*\|.*\|\s*$/', $line)) {
                self::flushPara($state, $out);
                self::flushList($state, $out);
                $state['table'][] = trim($line);
                continue;
            }
            self::flushTable($state, $out);

            // Headings.
            if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
                self::flushPara($state, $out);
                self::flushList($state, $out);
                $level = strlen($m[1]);
                $out[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
                continue;
            }

            // Lists.
            if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
                self::flushPara($state, $out);
                self::pushList($state, $out, 'ul', $m[1]);
                continue;
            }
            if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $m)) {
                self::flushPara($state, $out);
                self::pushList($state, $out, 'ol', $m[1]);
                continue;
            }
            self::flushList($state, $out);

            if (trim($line) === '') {
                self::flushPara($state, $out);
                continue;
            }

            $state['para'][] = trim($line);
        }

        // Flush trailing blocks.
        if ($state['code']) {
            $out[] = '<pre><code>' . e(implode("\n", $state['codeBuf'])) . '</code></pre>';
        }
        self::flushTable($state, $out);
        self::flushList($state, $out);
        self::flushPara($state, $out);

        return implode("\n", $out);
    }

    private static function pushList(array &$state, array &$out, string $type, string $item): void
    {
        if ($state['list'] !== null && $state['list'] !== $type) {
            self::flushList($state, $out);
        }
        $state['list'] = $type;
        $state['listBuf'][] = $item;
    }

    private static function flushList(array &$state, array &$out): void
    {
        if ($state['list'] === null || $state['listBuf'] === []) {
            $state['list'] = null;
            return;
        }
        $tag = $state['list'];
        $items = implode('', array_map(
            static fn (string $i): string => '<li>' . self::inline($i) . '</li>',
            $state['listBuf']
        ));
        $out[] = "<{$tag}>{$items}</{$tag}>";
        $state['list'] = null;
        $state['listBuf'] = [];
    }

    private static function flushPara(array &$state, array &$out): void
    {
        if ($state['para'] === []) {
            return;
        }
        $out[] = '<p>' . self::inline(implode(' ', $state['para'])) . '</p>';
        $state['para'] = [];
    }

    private static function flushTable(array &$state, array &$out): void
    {
        if ($state['table'] === []) {
            return;
        }

        $rows = $state['table'];
        array_shift($rows); // header row

        $isSeparator = static fn (string $r): bool => (bool) preg_match('/^\s*\|?[\s:|-]+\|?\s*$/', $r) && str_contains($r, '-');
        if (isset($rows[0]) && $isSeparator($rows[0])) {
            array_shift($rows);
        }

        $renderRow = static function (string $row): string {
            $cells = array_map(
                static fn (string $c): string => '<td>' . Markdown::inline(trim($c, ' ')) . '</td>',
                explode('|', trim($row, '|'))
            );
            return '<tr>' . implode('', $cells) . '</tr>';
        };

        $body = implode('', array_map($renderRow, $rows));
        $out[] = "<table><tbody>{$body}</tbody></table>";
        $state['table'] = [];
    }

    private static function inline(string $text): string
    {
        $text = e($text);

        // Inline code first; placeholders protect it from later replacements.
        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . $m[1] . '</code>';
            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text) ?? $text;

        // Links: [text](url) — http(s) or same-page anchors only.
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES);
            $safe = str_starts_with(strtolower($url), 'http://')
                || str_starts_with(strtolower($url), 'https://')
                || str_starts_with($url, '#');
            return $safe
                ? '<a href="' . $m[2] . '" rel="noopener">' . $m[1] . '</a>'
                : $m[1];
        }, $text) ?? $text;

        // Bold.
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;

        // Restore inline code.
        $text = preg_replace_callback("/\x00(\d+)\x00/", static fn (array $m) => $codes[(int) $m[1]] ?? '', $text) ?? $text;

        return $text;
    }
}
