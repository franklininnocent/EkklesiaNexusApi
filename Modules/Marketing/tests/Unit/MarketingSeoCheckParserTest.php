<?php

namespace Modules\Marketing\Tests\Unit;

use Modules\Marketing\Support\SeoMonitoring\MarketingSeoCheckParser;
use Modules\Marketing\Support\SeoMonitoring\MarketingSeoMonitoringConfig;
use Modules\Marketing\Support\SeoMonitoring\MarketingSeoUrlPolicy;
use Tests\TestCase;

class MarketingSeoCheckParserTest extends TestCase
{
    public function test_parse_robots_sitemap_url(): void
    {
        $robots = "User-agent: *\nAllow: /\nSitemap: https://ekklesianexus.com/sitemap.xml\n";
        $this->assertSame(
            'https://ekklesianexus.com/sitemap.xml',
            MarketingSeoCheckParser::parseRobotsSitemapUrl($robots),
        );
    }

    public function test_validate_sitemap_loc_count_fixture(): void
    {
        $config = new MarketingSeoMonitoringConfig;
        $policy = new MarketingSeoUrlPolicy($config);
        $fixture = base_path('../EkklesiaNexusWeb/scripts/fixtures/seo-monitoring/sitemap-good.xml');
        if (! is_readable($fixture)) {
            $this->markTestSkipped('Shared sitemap fixture unavailable.');
        }
        $xml = file_get_contents($fixture);
        $this->assertIsString($xml);
        $results = MarketingSeoCheckParser::validateSitemapXml($xml, $config, $policy);
        $locCount = collect($results)->firstWhere('check_id', 'sitemap-loc-count');
        $this->assertNotNull($locCount);
        $this->assertFalse($locCount['passed']);
    }

    public function test_is_marketing_leak_body(): void
    {
        $this->assertTrue(MarketingSeoCheckParser::isMarketingLeakBody('<html>EkklesiaNexus marketing</html>'));
        $this->assertFalse(MarketingSeoCheckParser::isMarketingLeakBody('<html>other</html>'));
    }
}
