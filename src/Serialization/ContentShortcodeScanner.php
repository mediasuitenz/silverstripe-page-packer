<?php

namespace MadeCurious\PagePacker\Serialization;

/**
 * Finds and rewrites File/Image references embedded as shortcodes inside HTML content field
 * values since these are just text patterns inside a string, not a real has_one/has_many
 * relation, and so are invisible to the rest of the object-graph walk entirely.
 *
 * The two regexes mirror `FileLinkTrackingParser`'s own detection patterns
 */
class ContentShortcodeScanner
{
    private const IMAGE_SHORTCODE = '/\[image\b[^\]]*\]/i';

    private const FILE_LINK_SHORTCODE = '/\[file_link\b[^\]]*\]/i';

    private const ID_ATTRIBUTE = '/\bid=(["\']?)(\d+)\1/i';

    public static function isHtmlFieldSpec(string $spec): bool
    {
        return stripos($spec, 'HTMLText') === 0
            || stripos($spec, 'HTMLVarchar') === 0
            || stripos($spec, 'HTMLFragment') === 0;
    }

    /**
     * @return array<int, array{type: string, id: int}> Every shortcode found, in order
     *     (duplicates included — callers dedupe if needed via the 'id').
     */
    public function extractReferences(string $content): array
    {
        $references = [];

        foreach ([self::IMAGE_SHORTCODE => 'image', self::FILE_LINK_SHORTCODE => 'file_link'] as $pattern => $type) {
            if (!preg_match_all($pattern, $content, $matches)) {
                continue;
            }

            foreach ($matches[0] as $shortcode) {
                if (preg_match(self::ID_ATTRIBUTE, $shortcode, $idMatch)) {
                    $references[] = ['type' => $type, 'id' => (int) $idMatch[2]];
                }
            }
        }

        return $references;
    }

    /**
     * @param array<int, int> $idMap oldFileID => newFileID. An old ID with no entry (couldn't be
     *     materialized on import) is left as-is rather than rewritten to something wrong.
     */
    public function rewriteReferences(string $content, array $idMap): string
    {
        $rewriteShortcode = function (array $match) use ($idMap): string {
            return preg_replace_callback(self::ID_ATTRIBUTE, function (array $idMatch) use ($idMap): string {
                $oldId = (int) $idMatch[2];
                $newId = $idMap[$oldId] ?? $oldId;

                return 'id="' . $newId . '"';
            }, $match[0]);
        };

        $content = preg_replace_callback(self::IMAGE_SHORTCODE, $rewriteShortcode, $content);
        $content = preg_replace_callback(self::FILE_LINK_SHORTCODE, $rewriteShortcode, $content);

        return $content;
    }
}
