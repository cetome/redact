<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Terms of use and legal notice are one page (/terms): the publisher (cetome, France) and the host
 * first, then the terms. /legal is a permanent redirect to it.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_terms_page_starts_with_the_legal_notice(): void
    {
        $html = $this->get(route('legal.terms'))->assertOk()->getContent();

        $this->assertStringContainsString('<title>Terms of use', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        foreach (['849 836 101 00014', 'FR85849836101', '37 Rue Antoine Charial, 69003 Lyon, France', 'Cédric LÉVY-BENCHETON EI', 'IONOS SE'] as $fact) {
            $this->assertStringContainsString(e($fact), $html);
        }
        $this->assertLessThan(strpos($html, 'id="terms"'), strpos($html, 'id="notice"'), 'The legal notice comes first.');
        // The PGP key of cetome.com/security, in the security section and in security.txt.
        $this->get(route('legal.terms'))->assertSee('https://cetome.com/security/cetome-security.asc', false)
            ->assertSee('BA3F 45B4 928E DF7A 2C1A EE3A 3CB7 7CFA 4CE0 B1E0');
        $this->get(route('security.txt'))->assertSee('Encryption: https://cetome.com/security/cetome-security.asc', false)
            ->assertSee('Encryption: openpgp4fpr:BA3F45B4928EDF7A2C1AEE3A3CB77CFA4CE0B1E0', false);
        // security.txt points to the security section.
        $this->assertStringContainsString('id="security"', $html);
        $this->assertStringContainsString('mailto:'.config('redact.security_contact'), $html);
        $this->assertStringNotContainsString(':hours', $html);
        $this->assertStringNotContainsString(':email', $html);
    }

    public function test_the_page_is_translated(): void
    {
        $this->withHeader('Accept-Language', 'fr')->get(route('legal.terms'))->assertOk()
            ->assertSee("Conditions d'utilisation")
            ->assertSee('Directeur de la publication')
            ->assertSee('Hébergeur');
    }

    public function test_the_terms_cover_the_saas_in_both_languages(): void
    {
        $sections = ['plans', 'billing', 'cancellation', 'prices', 'consumers', 'ai', 'use', 'licence', 'liability', 'law', 'sandbox'];
        $facts = [
            'en' => ['Version 2026-10-23', 'Enterprise (self-hosted or dedicated, on request)', '100 € per month excluding VAT for 2 products', 'reverse charge', 'end of the paid period', 'read-only', '30 days', '14 days', 'never gives a verdict', 'reverse engineer', 'AGPL-3.0', '12 months before the claim', 'courts of Lyon', 'Owner + 1 team member', '500 AI units'],
            'fr' => ['Version 2026-10-23', 'Entreprise (auto-hébergée ou dédiée, sur demande)', '100 € par mois hors TVA pour 2 produits', 'autoliquidation', 'fin de la période payée', 'lecture seule', '30 jours', '14 jours', 'ne rend jamais de verdict', 'ingénierie inverse', 'AGPL-3.0', '12 mois précédant la réclamation', 'tribunaux de Lyon', 'Propriétaire + 1 membre', '500 unités'],
        ];

        foreach ($facts as $locale => $expected) {
            $html = $this->withHeader('Accept-Language', $locale)->get(route('legal.terms'))->assertOk()->getContent();
            foreach ($sections as $section) {
                $this->assertStringContainsString('id="'.$section.'"', $html, "$locale: section $section");
            }
            foreach ($expected as $fact) {
                $this->assertStringContainsString(e($fact), $html, "$locale: $fact");
            }
            $this->assertStringContainsString('href="'.e(route('contact.topic', 'self-hosted')).'"', $html, "$locale: Enterprise contact");
            $this->assertDoesNotMatchRegularExpression('/:(price|ai_units|free_team|talk|hours|email)\b/', strip_tags($html), "$locale: placeholder left");
        }
    }

    public function test_the_privacy_policy_covers_the_saas_in_both_languages(): void
    {
        $facts = [
            'en' => ['Version 2026-10-24', 'passkey', 'e-mail link or code', 'Kept 13 months', 'Kept 24 months', 'Kept 12 months', 'card numbers never reach REDact', 'Google LLC / Google Ireland (Gemini API, paid tier)', 'does not use prompts and responses to train its models', 'consent to start the service at once', 'EUVD', 'NVD', 'OSV.dev', 'IONOS SE', 'Stripe', 'shown in the product and organisation history', 'cross-site request forgery', 'CNIL', 'Subprocessors'],
            'fr' => ['Version 2026-10-24', 'clé d\'accès', 'lien ou un code', 'Conservés 13 mois', 'Conservé 24 mois', 'Conservés 12 mois', 'les numéros de carte n\'arrivent jamais à REDact', 'Google LLC / Google Ireland (API Gemini, offre payante)', 'pour entraîner ses modèles', 'démarrage immédiat du service', 'EUVD', 'NVD', 'OSV.dev', 'IONOS SE', 'Stripe', 'visible dans l\'historique du produit et de l\'organisation', 'falsification de requêtes intersites', 'CNIL', 'Sous-traitants'],
        ];
        config(['redact.ai.provider' => 'gemini']);

        foreach ($facts as $locale => $expected) {
            $html = $this->withHeader('Accept-Language', $locale)->get(route('legal.privacy'))->assertOk()->getContent();
            foreach ($expected as $fact) {
                $this->assertStringContainsString(e($fact), $html, "$locale: $fact");
            }
            $this->assertStringContainsString('href="'.e(route('contact.topic', 'privacy')).'"', $html);
            $this->assertStringNotContainsString('privacy@', $html);
            $this->assertStringNotContainsString('DRAFT', $html);
            $this->assertStringNotContainsString('BROUILLON', $html);
            $this->withHeader('Accept-Language', $locale)->get(route('contact.topic', 'privacy'))->assertOk()->assertSee(__('contact.topics.privacy', [], $locale));
            $this->assertStringContainsString('href="'.route('legal.terms').'#notice"', $html);
            $this->assertDoesNotMatchRegularExpression('/:(notice|contact|email|hours|ai_provider|\w+_months|unverified_days)\b/', strip_tags($html), "$locale: placeholder left");
        }
    }

    public function test_the_old_legal_notice_address_redirects_permanently(): void
    {
        $this->get('/legal')->assertStatus(301)->assertRedirect('/terms');
        $this->assertSame(url('/legal'), route('legal.notice'));
    }

    public function test_the_footer_links_the_merged_page_once(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'href="'.route('legal.terms').'"'));
        $this->assertStringNotContainsString('href="'.route('legal.notice').'"', $html);
    }
}
