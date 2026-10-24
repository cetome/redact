<?php

namespace App\Reports;

use RuntimeException;
use ZipArchive;

/**
 * What PhpWord cannot write, applied to the saved file so the documents are
 * CRAted's to the attribute:
 *  - word/settings.xml replaced by CRAted's SETTINGS_XML (print layout, 100 %, Word 2013+ mode);
 *  - the cover's disclaimer paragraph made a frame pinned to the bottom margin, boxed and shaded;
 *  - tab and line-break markers turned into <w:tab/> and <w:br/> inside their run;
 *  - borderless layout tables (header, cover) written with "nil" borders;
 *  - the thin rules of the header and footer given their 4 pt spacing.
 */
final class DocxFinisher
{
    /** Written in a text where a tab must go (a right tab stop of the footer, for example). */
    public const TAB = "\u{E000}";

    /** Written in a text where a line break must go, inside the same paragraph. */
    public const BREAK = "\u{E001}";

    /** The border colour of a table that must have no border at all. */
    public const NO_BORDER = 'FFFFFE';

    /** The colour of a header or footer rule (#E2E8F0 once finished, with its spacing). */
    public const RULE = 'E2E8F1';

    /** The paragraph style that marks the cover's disclaimer. */
    public const DISCLAIMER_STYLE = 'Disclaimer';

    public const SETTINGS_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:view w:val="print"/>
  <w:zoom w:percent="100"/>
  <w:displayBackgroundShape/>
  <w:defaultTabStop w:val="720"/>
  <w:characterSpacingControl w:val="doNotCompress"/>
  <w:compat>
    <w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/>
  </w:compat>
</w:settings>
XML;

    /** The disclaimer frame: as wide as the text less 360 twips, centred, at the bottom margin. */
    private static function disclaimerProperties(int $width): string
    {
        $border = fn (string $side) => '<w:'.$side.' w:val="single" w:sz="8" w:space="8" w:color="000000"/>';

        return '<w:pPr><w:pStyle w:val="'.self::DISCLAIMER_STYLE.'"/>'
            .'<w:framePr w:w="'.$width.'" w:h="400" w:hRule="atLeast" w:wrap="none" w:hAnchor="margin" w:vAnchor="margin" w:xAlign="center" w:yAlign="bottom"/>'
            .'<w:pBdr>'.$border('top').$border('left').$border('bottom').$border('right').'</w:pBdr>'
            .'<w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/>'
            .'<w:spacing w:before="0" w:after="0"/>'
            .'</w:pPr>';
    }

    public static function finish(string $path, int $textWidth): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open the Word document to finish it.');
        }

        $zip->deleteName('word/settings.xml');
        $zip->addFromString('word/settings.xml', self::SETTINGS_XML);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name)) {
                continue;
            }
            $xml = (string) $zip->getFromIndex($i);
            $finished = self::transform($xml, $textWidth);
            if ($finished !== $xml) {
                $zip->deleteName($name);
                $zip->addFromString($name, $finished);
            }
        }

        $zip->close();
    }

    public static function transform(string $xml, int $textWidth): string
    {
        $xml = str_replace(
            '<w:pPr><w:pStyle w:val="'.self::DISCLAIMER_STYLE.'"/></w:pPr>',
            self::disclaimerProperties($textWidth - 360),
            $xml,
        );
        $xml = str_replace(self::TAB, '</w:t><w:tab/><w:t xml:space="preserve">', $xml);
        $xml = str_replace(self::BREAK, '</w:t><w:br/><w:t xml:space="preserve">', $xml);
        $xml = (string) preg_replace(
            '#<w:(top|left|right|bottom|insideH|insideV) w:val="single" w:sz="\d+" w:color="'.self::NO_BORDER.'"/>#',
            '<w:$1 w:val="nil"/>',
            $xml,
        );

        return str_replace('w:color="'.self::RULE.'"', 'w:space="4" w:color="E2E8F0"', $xml);
    }
}
