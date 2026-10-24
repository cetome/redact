<?php

namespace App\Declaration;

use RuntimeException;
use ZipArchive;

/**
 * A text watermark across every page of a saved Word document ("DRAFT — not valid"), the way
 * Word writes its own (Design › Watermark): a VML WordArt shape in the default header, light
 * grey, diagonal, behind the text. PhpWord only knows picture watermarks.
 */
final class Watermark
{
    /** Word's WordArt shape type (plain text), needed once per part that uses it. */
    private const SHAPETYPE = '<v:shapetype id="_x0000_t136" coordsize="21600,21600" o:spt="136" adj="10800" path="m@7,l@8,m@5,21600l@6,21600e">'
        .'<v:formulas><v:f eqn="sum #0 0 10800"/><v:f eqn="prod #0 2 1"/><v:f eqn="sum 21600 0 @1"/><v:f eqn="sum 0 0 @2"/>'
        .'<v:f eqn="sum 21600 0 @3"/><v:f eqn="if @0 @3 0"/><v:f eqn="if @0 21600 @1"/><v:f eqn="if @0 0 @2"/><v:f eqn="if @0 @4 21600"/>'
        .'<v:f eqn="mid @5 @6"/><v:f eqn="mid @8 @5"/><v:f eqn="mid @7 @8"/><v:f eqn="mid @6 @7"/><v:f eqn="sum @6 0 @5"/></v:formulas>'
        .'<v:path textpathok="t" o:connecttype="custom" o:connectlocs="@9,0;@10,10800;@11,21600;@12,10800" o:connectangles="270,180,90,0"/>'
        .'<v:textpath on="t" fitshape="t"/><v:handles><v:h position="#0,bottomRight" xrange="6629,14971"/></v:handles>'
        .'<o:lock v:ext="edit" text="t" shapetype="t"/></v:shapetype>';

    public static function paragraph(string $text): string
    {
        $text = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<w:p><w:pPr><w:spacing w:before="0" w:after="0"/></w:pPr><w:r><w:rPr><w:noProof/></w:rPr><w:pict>'.self::SHAPETYPE
            .'<v:shape id="RedactDraftWatermark" o:spid="_x0000_s2049" type="#_x0000_t136" '
            .'style="position:absolute;margin-left:0;margin-top:0;width:470pt;height:110pt;rotation:315;z-index:-251657216;'
            .'mso-position-horizontal:center;mso-position-horizontal-relative:margin;mso-position-vertical:center;mso-position-vertical-relative:margin" '
            .'o:allowincell="f" fillcolor="#c8ced6" stroked="f"><v:fill opacity=".45"/>'
            .'<v:textpath style="font-family:&quot;Gill Sans MT&quot;;font-size:1pt" string="'.$text.'"/></v:shape></w:pict></w:r></w:p>';
    }

    public static function apply(string $path, string $text): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open the Word document to mark it.');
        }
        // The names first: replacing a part appends it, the loop would meet it again.
        $headers = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^word/header\d*\.xml$#', $name)) {
                $headers[] = $name;
            }
        }
        $done = false;
        foreach ($headers as $name) {
            $xml = (string) $zip->getFromName($name);
            $xml = self::namespaces($xml);
            $xml = preg_replace('#(<w:hdr\b[^>]*>)#', '$1'.self::paragraph($text), $xml, 1) ?? $xml;
            $zip->deleteName($name);
            $zip->addFromString($name, $xml);
            $done = true;
        }
        $zip->close();
        if (! $done) {
            throw new RuntimeException('The Word document has no header to mark.');
        }
    }

    /** The VML and Office namespaces on the header's root, when PhpWord did not declare them. */
    private static function namespaces(string $xml): string
    {
        $add = [];
        if (! str_contains($xml, 'xmlns:v=')) {
            $add[] = 'xmlns:v="urn:schemas-microsoft-com:vml"';
        }
        if (! str_contains($xml, 'xmlns:o=')) {
            $add[] = 'xmlns:o="urn:schemas-microsoft-com:office:office"';
        }

        return $add === [] ? $xml : (preg_replace('#<w:hdr\b#', '<w:hdr '.implode(' ', $add), $xml, 1) ?? $xml);
    }
}
