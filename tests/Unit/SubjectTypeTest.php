<?php

namespace Tests\Unit;

use App\Services\MovieBox\SubjectType;
use PHPUnit\Framework\TestCase;

class SubjectTypeTest extends TestCase
{
    public function test_it_resolves_from_names_numbers_and_aliases(): void
    {
        $this->assertSame(SubjectType::MOVIES, SubjectType::resolve('movies'));
        $this->assertSame(SubjectType::MOVIES, SubjectType::resolve('movie'));
        $this->assertSame(SubjectType::TV_SERIES, SubjectType::resolve('tv-series'));
        $this->assertSame(SubjectType::TV_SERIES, SubjectType::resolve('series'));
        $this->assertSame(SubjectType::TV_SERIES, SubjectType::resolve(2));
        $this->assertSame(SubjectType::ALL, SubjectType::resolve('all'));
        $this->assertSame(SubjectType::ALL, SubjectType::resolve(null));
        $this->assertSame(SubjectType::ALL, SubjectType::resolve('nonsense'));
    }

    public function test_it_exposes_human_labels(): void
    {
        $this->assertSame('Movies', SubjectType::MOVIES->label());
        $this->assertSame('TV Series', SubjectType::TV_SERIES->label());
    }
}
