<?php

namespace Tests\Unit;

use App\Services\Bow\VoterImport\LocationNormalizer;
use PHPUnit\Framework\TestCase;

class LocationNormalizerTest extends TestCase
{
    public function test_it_exactly_matches_an_existing_punctuation_only_source_name(): void
    {
        $normalizer = new LocationNormalizer();

        $this->assertTrue($normalizer->exactSource('..', ' .. '));
        $this->assertTrue($normalizer->same('..', '..'));
    }

    public function test_it_does_not_merge_different_punctuation_only_source_names(): void
    {
        $normalizer = new LocationNormalizer();

        $this->assertFalse($normalizer->exactSource('.', '..'));
        $this->assertFalse($normalizer->same('.', '..'));
    }

    public function test_it_keeps_normalized_matching_for_regular_purok_variations(): void
    {
        $normalizer = new LocationNormalizer();

        $this->assertTrue($normalizer->same('PRK. 7', 'PRK 7'));
    }
}
