<?php

namespace Tests\Concerns;

use App\Guest\DemoProduct;

/**
 * A copy of the fixture pack that ships a tiny invented demo product (demo/smart-bulb.json and its
 * picture), for the "See a demo" and "Load the demo" tests: its two walks and every assessment unit
 * of the fixture criteria provided. Needs UsesPackCopy.
 */
trait WithFixtureDemo
{
    protected function useFixtureDemo(): void
    {
        $this->usePackCopy(function (string $path) {
            mkdir($path.'/demo');
            $image = imagecreatetruecolor(16, 16);
            imagepng($image, $path.'/demo/smart-bulb.png');
            file_put_contents($path.'/demo/smart-bulb.json', json_encode([
                'schema' => DemoProduct::SCHEMA,
                'slug' => 'smart-bulb',
                'standards' => ['demo-1'],
                'picture' => 'smart-bulb.png',
                'manufacturer' => ['legal_name' => 'Emotec', 'address' => "1 rue de l'Exemple\n69000 Lyon", 'country' => 'France'],
                'product' => ['name' => ['en' => 'Sample bulb (demo)', 'fr' => 'Ampoule exemple (démo)'], 'reference' => 'EM-1',
                    'description' => 'A sample product.', 'models' => [['value' => 'EM-1 E27']]],
                'version' => ['label' => '1.0', 'intended_use' => 'Indoor lighting.', 'software_version' => '1.0'],
                'scoping' => ['connected' => true, 'updates' => false],
                'route' => 'module_a',
                'risks' => [['threat' => 'Sample threat', 'stride' => ['T'], 'impact' => 3, 'likelihood' => 2, 'articles' => ['A1'],
                    'remediation' => 'Sample remediation.', 'residual_rank' => 4]],
                'inventory' => ['assets' => [['code' => 'AS-01', 'name' => 'Sample asset', 'attributes' => ['type' => 'security', 'stored' => true]]]],
                'none' => ['interfaces'],
                'evaluations' => [
                    ['standard' => 'demo-1', 'requirement' => 'ACC-1', 'item' => 'AS-01', 'answers' => ['No', 'Yes'],
                        'justification' => ['en' => 'Access to the sample asset needs the paired Emotec app.', 'fr' => 'L’accès à l’actif exemple exige l’application Emotec appairée.']],
                    ['standard' => 'demo-1', 'requirement' => 'UPD-1', 'answers' => ['Yes'],
                        'justification' => ['en' => 'Signed updates are delivered over the air.', 'fr' => 'Des mises à jour signées sont livrées à distance.']],
                ],
                // Every assessment unit of the fixture criteria, provided (PASS) with its evidence.
                'units' => array_map(fn (array $u) => ['standard' => 'demo-1', 'requirement' => $u[0], 'case' => $u[1], 'verdict' => 'PASS',
                    'evidence' => ['en' => 'Sample evidence: checked on the sample product, as documented.', 'fr' => 'Élément exemple : vérifié sur le produit exemple, comme documenté.']], [
                        ['ACC-1', 'conceptual'], ['ACC-1', 'functional_completeness'], ['ACC-1', 'functional_sufficiency'],
                        ['UPD-1', 'conceptual'], ['UPD-1', 'functional_completeness'],
                    ]),
                'declaration' => ['signatory_name' => 'Camille Martin', 'signatory_function' => 'Head of Quality', 'place' => 'Lyon', 'date' => 'today'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        });
    }
}
