<?php

namespace Tests\Unit;

use Tests\TestCase;

class SlideOverLayoutConfigTest extends TestCase
{
    public function test_slide_over_layout_defaults_are_percentages(): void
    {
        $slideOver = config('layout.slide_over');

        $this->assertIsArray($slideOver);
        $this->assertSame(45, $slideOver['width_percent']);
        $this->assertSame(5, $slideOver['nested_step_percent']);
        $this->assertSame(30, $slideOver['min_width_percent']);
    }
}
