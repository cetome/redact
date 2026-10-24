<?php

namespace Tests\Feature\Sbom;

use App\Sbom\Component;
use App\Sbom\Formats\Spreadsheet;
use App\Sbom\SbomException;
use App\Sbom\SbomReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/**
 * Every format read by content, and the defences against hostile files.
 */
class SbomFormatsTest extends SbomTestCase
{
    /** @return array{format: string, kind: string, components: list<Component>, warnings: list<string>} */
    private function read(string ...$names): array
    {
        return (new SbomReader)->read(array_map(fn ($n) => ['name' => basename($n), 'path' => base_path('tests/Fixtures/sbom/'.$n)], $names));
    }

    /** @param list<Component> $components */
    private function byName(array $components, string $name): Component
    {
        foreach ($components as $c) {
            if ($c->name === $name) {
                return $c;
            }
        }
        $this->fail("No component named {$name}");
    }

    public function test_cyclonedx_json_with_nested_components_hardware_and_the_product_itself(): void
    {
        $result = $this->read('cyclonedx.json');

        $this->assertSame('cyclonedx-json', $result['format']);
        $this->assertSame('mixed', $result['kind']);
        $this->assertCount(6, $result['components'], 'Root, lodash (twice → once), openssl, libcrypto (nested), RTOS, SoC.');
        $root = $this->byName($result['components'], 'Sample lock firmware');
        $this->assertTrue($root->root);
        $lodash = $this->byName($result['components'], 'lodash');
        $this->assertSame(['4.17.20', 'pkg:npm/lodash@4.17.20', 'MIT', 'OpenJS Foundation'], [$lodash->version, $lodash->purl, $lodash->licence, $lodash->supplier]);
        $openssl = $this->byName($result['components'], 'openssl');
        $this->assertSame('cpe:2.3:a:openssl:openssl:3.0.7:*:*:*:*:*:*:*', $openssl->cpe);
        $this->assertSame('OpenSSL', $openssl->supplier, 'publisher when there is no supplier');
        $this->assertSame('libcrypto', $this->byName($result['components'], 'libcrypto')->name);
        $soc = $this->byName($result['components'], 'Example radio SoC');
        $this->assertSame(['hardware', 'Example Semiconductors'], [$soc->type, $soc->supplier]);
        $this->assertNotEmpty($result['warnings'], 'The duplicate is reported.');
    }

    public function test_cyclonedx_xml_hbom_and_a_cpe_22_uri(): void
    {
        $result = $this->read('cyclonedx.xml');

        $this->assertSame(['cyclonedx-xml', 'mixed'], [$result['format'], $result['kind']]);
        $this->assertCount(4, $result['components']);
        $mcu = $this->byName($result['components'], 'Example MCU');
        $this->assertSame(['hardware', 'A1', 'Example Semiconductors', 'Main microcontroller'], [$mcu->type, $mcu->version, $mcu->supplier, $mcu->description]);
        $this->assertTrue($this->byName($result['components'], 'Sample lock main board')->root);
        $ble = $this->byName($result['components'], 'BLE stack');
        $this->assertSame('cpe:2.3:a:example:ble_stack:5.1.0:*:*:*:*:*:*:*', $ble->cpe, 'CPE 2.2 URI converted to 2.3');
        $this->assertSame('LicenseRef-Proprietary', $ble->licence);
    }

    public function test_spdx_json_tag_value_and_spdx3(): void
    {
        $json = $this->read('spdx.json');
        $this->assertSame('spdx-json', $json['format']);
        $this->assertCount(4, $json['components']);
        $this->assertTrue($this->byName($json['components'], 'Sample lock firmware')->root);
        $this->assertSame('Emotec Ltd', $this->byName($json['components'], 'Sample lock firmware')->supplier, 'e-mail stripped');
        $zlib = $this->byName($json['components'], 'zlib');
        $this->assertSame(['pkg:generic/zlib@1.2.13', 'cpe:2.3:a:zlib:zlib:1.2.13:*:*:*:*:*:*:*', 'Zlib'], [$zlib->purl, $zlib->cpe, $zlib->licence]);
        $this->assertSame('Kenneth Reitz', $this->byName($json['components'], 'requests')->supplier);
        $this->assertSame('hardware', $this->byName($json['components'], 'Example radio module')->type);

        $tag = $this->read('sample.spdx');
        $this->assertSame('spdx-tag', $tag['format']);
        $this->assertSame(['Sample gateway', 'busybox', 'curl'], array_map(fn ($c) => $c->name, $tag['components']));
        $this->assertTrue($tag['components'][0]->root);
        $this->assertSame('cpe:2.3:a:busybox:busybox:1.36.1:*:*:*:*:*:*:*', $tag['components'][1]->cpe);
        $this->assertSame('pkg:deb/debian/curl@8.4.0?arch=arm64', $tag['components'][2]->purl);
        $this->assertNull($tag['components'][2]->supplier, 'NOASSERTION is no supplier');

        $three = $this->read('spdx3.jsonld');
        $this->assertSame('spdx3-jsonld', $three['format']);
        $this->assertCount(3, $three['components']);
        $app = $this->byName($three['components'], 'Sample companion app');
        $this->assertSame(['3.2.0', 'ACME Components', true], [$app->version, $app->supplier, $app->root]);
        $jinja = $this->byName($three['components'], 'jinja2');
        $this->assertSame(['pkg:pypi/jinja2@3.1.2', 'BSD-3-Clause'], [$jinja->purl, $jinja->licence]);
        $this->assertSame('hardware', $this->byName($three['components'], 'Example motion sensor')->type);
    }

    public function test_swid_tags_as_files_and_as_a_zip_without_patch_tags(): void
    {
        $files = $this->read('swid/busybox.swidtag', 'swid/dropbear.swidtag', 'swid/dropbear-patch.swidtag');
        $this->assertSame('swid', $files['format']);
        $this->assertSame(['BusyBox', 'Dropbear SSH'], array_map(fn ($c) => $c->name, $files['components']));
        $this->assertSame('BusyBox project', $files['components'][0]->supplier, 'softwareCreator');
        $this->assertSame('Emotec Ltd', $files['components'][1]->supplier, 'tagCreator / licensor when no creator');

        $zip = sys_get_temp_dir().'/'.uniqid('swid').'.zip';
        $archive = new ZipArchive;
        $archive->open($zip, ZipArchive::CREATE);
        $archive->addFile(base_path('tests/Fixtures/sbom/swid/busybox.swidtag'), 'tags/busybox.swidtag');
        $archive->addFile(base_path('tests/Fixtures/sbom/swid/dropbear.swidtag'), 'tags/dropbear.swidtag');
        $archive->addFromString('__MACOSX/._busybox.swidtag', 'junk');
        $archive->close();
        $zipped = (new SbomReader)->read([['name' => 'tags.zip', 'path' => $zip]]);
        $this->assertSame(['BusyBox', 'Dropbear SSH'], array_map(fn ($c) => $c->name, $zipped['components']));
    }

    public function test_csv_with_a_title_row_french_headers_and_semicolons(): void
    {
        $result = $this->read('components.csv');

        $this->assertSame('csv', $result['format']);
        $this->assertCount(3, $result['components']);
        $this->assertSame(['Main firmware', '2.4.1', 'Emotec Ltd', 'software', 'Proprietary', 'Bootloader and application'], [
            $result['components'][0]->name, $result['components'][0]->version, $result['components'][0]->supplier,
            $result['components'][0]->type, $result['components'][0]->licence, $result['components'][0]->description,
        ]);
        $this->assertSame('hardware', $result['components'][2]->type, '"matériel" is hardware');
    }

    public function test_the_excel_template_reads_back_and_other_column_names_are_mapped(): void
    {
        $template = sys_get_temp_dir().'/'.uniqid('tpl').'.xlsx';
        file_put_contents($template, Spreadsheet::template());
        $result = (new SbomReader)->read([['name' => 'template.xlsx', 'path' => $template]]);
        $this->assertSame('xlsx', $result['format']);
        $this->assertCount(3, $result['components']);
        $this->assertSame('cpe:2.3:a:openssl:openssl:3.0.13:*:*:*:*:*:*:*', $result['components'][1]->cpe);
        $this->assertSame('hardware', $result['components'][2]->type);

        $book = new Workbook;
        $book->getActiveSheet()->fromArray([
            ['Package Name', 'Package Version', 'Vendor', 'License', 'Package URL', 'Internal id'],
            ['zlib', '1.3.1', 'zlib', 'Zlib', 'pkg:generic/zlib@1.3.1', 'X-1'],
        ]);
        $own = sys_get_temp_dir().'/'.uniqid('own').'.xlsx';
        (new Xlsx($book))->save($own);
        $mapped = (new SbomReader)->read([['name' => 'own.xlsx', 'path' => $own]]);
        $c = $mapped['components'][0];
        $this->assertSame(['zlib', '1.3.1', 'zlib', 'Zlib', 'pkg:generic/zlib@1.3.1'], [$c->name, $c->version, $c->supplier, $c->licence, $c->purl]);
        $this->assertStringContainsString('Internal id', implode(' ', $mapped['warnings']));
    }

    public function test_hostile_and_broken_files_are_refused(): void
    {
        foreach (['xxe.xml' => 'sbom.errors.xml_doctype', 'billion-laughs.xml' => 'sbom.errors.xml_doctype', 'bad.zip' => 'sbom.errors.bad_zip'] as $file => $error) {
            try {
                $this->read($file);
                $this->fail("{$file} was read");
            } catch (SbomException $e) {
                $this->assertSame(__($error), $e->getMessage(), $file);
            }
        }

        // JSON nested deeper than the limit.
        $deep = sys_get_temp_dir().'/'.uniqid('deep').'.json';
        file_put_contents($deep, str_repeat('{"a":', 100).'1'.str_repeat('}', 100));
        $this->assertRefused($deep, 'sbom.errors.bad_json');

        // XML in another encoding (could hide a DOCTYPE from the check): UTF-16 is not even text to us,
        // and a declared non-UTF-8 encoding is refused.
        $utf16 = sys_get_temp_dir().'/'.uniqid('u16').'.xml';
        file_put_contents($utf16, mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><bom xmlns="http://cyclonedx.org/schema/bom/1.5"/>', 'UTF-16LE'));
        $this->assertRefused($utf16, 'sbom.errors.unknown_format', ['name' => 'file']);
        $utf7 = sys_get_temp_dir().'/'.uniqid('u7').'.xml';
        file_put_contents($utf7, '<?xml version="1.0" encoding="UTF-7"?><bom xmlns="http://cyclonedx.org/schema/bom/1.5"/>');
        $this->assertRefused($utf7, 'sbom.errors.bad_xml');

        // Zip bomb: a 5 MB entry squeezed into a few kB.
        $bomb = sys_get_temp_dir().'/'.uniqid('bomb').'.zip';
        $zip = new ZipArchive;
        $zip->open($bomb, ZipArchive::CREATE);
        $zip->addFromString('tag.swidtag', str_repeat("\0", 5 * 1024 * 1024));
        $zip->close();
        $this->assertRefused($bomb, 'sbom.errors.zip_unsafe');

        // Path traversal inside a zip.
        $evil = sys_get_temp_dir().'/'.uniqid('evil').'.zip';
        $zip = new ZipArchive;
        $zip->open($evil, ZipArchive::CREATE);
        $zip->addFromString('../../etc/tag.swidtag', (string) file_get_contents(base_path('tests/Fixtures/sbom/swid/busybox.swidtag')));
        $zip->close();
        $this->assertRefused($evil, 'sbom.errors.zip_unsafe');

        // Something else entirely.
        $png = sys_get_temp_dir().'/'.uniqid('img').'.json';
        file_put_contents($png, "\x89PNG\r\n\x1a\n\0\0\0");
        $this->assertRefused($png, 'sbom.errors.unknown_format', ['name' => 'file']);
    }

    /** @param array<string, string> $replace */
    private function assertRefused(string $path, string $error, array $replace = []): void
    {
        try {
            (new SbomReader)->read([['name' => 'file', 'path' => $path]]);
            $this->fail(basename($path).' was read');
        } catch (SbomException $e) {
            $this->assertSame(__($error, $replace), $e->getMessage());
        }
    }
}
