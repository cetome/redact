<?php

namespace App\PublicPage;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The QR code of a public page address: SVG (sharp at any print size, no PHP extension needed),
 * dark modules on white with the standard four-module quiet zone, error correction M (15 %: a
 * label may be scratched).
 */
final class QrCode
{
    public static function svg(string $url, int $size = 512): string
    {
        $style = new RendererStyle($size, 4, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(15, 23, 42)));
        $writer = new Writer(new ImageRenderer($style, new SvgImageBackEnd));

        return $writer->writeString($url, 'UTF-8', ErrorCorrectionLevel::M());
    }

    /** The SVG to put inside a page (no XML prolog), sized by CSS. */
    public static function inline(string $url): string
    {
        $svg = (string) preg_replace('/^<\?xml[^>]*>\s*/', '', self::svg($url, 256));

        return (string) preg_replace('/<svg /', '<svg aria-hidden="true" focusable="false" ', $svg, 1);
    }

    /** PNG for tools that take no SVG (word processors, label printers); null without the GD extension. */
    public static function png(string $url, int $size = 1024): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }
        $writer = new Writer(new GDLibRenderer($size, 4, 'png', 9, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(15, 23, 42))));

        return $writer->writeString($url, 'UTF-8', ErrorCorrectionLevel::M());
    }
}
