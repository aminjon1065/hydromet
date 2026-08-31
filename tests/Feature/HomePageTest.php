<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    #[Test]
    public function the_home_page_renders_the_inertia_react_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('home')
                ->has('generatedAt')
                ->where('displayTimezone', 'Asia/Dushanbe')
                ->has('locale.available', 3)
                ->has('translations.brand_name'));
    }

    #[Test]
    public function the_root_template_declares_a_standards_based_language_tag(): void
    {
        $this->withSession(['locale' => 'tj'])
            ->get('/')
            ->assertOk()
            ->assertSee('<html lang="tg-TJ">', false);
    }
}
