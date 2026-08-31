<?php

namespace kernpfad\sitevolumes\tests\unit;

use kernpfad\sitevolumes\services\SiteAccessRule;
use PHPUnit\Framework\TestCase;

class SiteAccessRuleTest extends TestCase
{
    private SiteAccessRule $rule;

    protected function setUp(): void
    {
        $this->rule = new SiteAccessRule();
    }

    public function testEmptyAllowlistMeansUnrestricted(): void
    {
        self::assertTrue($this->rule->isAllowed([], 1));
        self::assertTrue($this->rule->isAllowed([], 999));
    }

    public function testAllowsExplicitlyListedSite(): void
    {
        self::assertTrue($this->rule->isAllowed([1, 2, 3], 2));
    }

    public function testDeniesSiteNotInTheList(): void
    {
        self::assertFalse($this->rule->isAllowed([1, 2, 3], 4));
    }

    public function testSingleSiteAllowlist(): void
    {
        self::assertTrue($this->rule->isAllowed([5], 5));
        self::assertFalse($this->rule->isAllowed([5], 6));
    }

    public function testUsesStrictComparison(): void
    {
        // Regression guard: a loose in_array() comparison would let a
        // string site ID or "0" match unrelated entries.
        self::assertFalse($this->rule->isAllowed([1, 2], 0));
    }
}
