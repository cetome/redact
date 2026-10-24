<?php

namespace Tests\Feature;

use Tests\TestCase;

/** The previous redact.cetome.com addresses keep working after the switch to the app. */
class OldSiteRedirectsTest extends TestCase
{
    public function test_old_download_and_file_addresses_redirect_to_the_templates(): void
    {
        $this->get('/files/cetome%20REDact%20-%20E.Info%20v1.0.xlsx')->assertStatus(301)->assertRedirect('/templates');
        $this->get('/dl/')->assertStatus(301); // TemplateController::legacy (FreeTemplatesTest)
        $this->get('/legal')->assertStatus(301)->assertRedirect('/terms');
        $this->get('/privacy')->assertOk();
    }
}
