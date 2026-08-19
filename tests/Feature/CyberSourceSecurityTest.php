<?php

namespace Tests\Feature;

use App\Services\CyberSource\CyberSourceSanitizer;
use Tests\TestCase;

class CyberSourceSecurityTest extends TestCase
{
    public function test_cybersource_configuration_is_available(): void
    {
        $this->assertIsArray(config('services.cybersource'));
        $this->assertArrayHasKey('merchant_id', config('services.cybersource'));
        $this->assertArrayHasKey('key_id', config('services.cybersource'));
        $this->assertArrayHasKey('secret_key', config('services.cybersource'));
        $this->assertArrayHasKey('api_url', config('services.cybersource'));
    }

    public function test_sanitizer_masks_pan_and_cvv(): void
    {
        $this->assertSame('************4242', CyberSourceSanitizer::maskPan('4111111111114242'));
        $this->assertSame('***', CyberSourceSanitizer::maskCvv('123'));
        $this->assertSame('***REMOVED***', CyberSourceSanitizer::redactSensitiveValue('abc123'));
    }
}
