<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_points_to_the_versioned_api(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertExactJson([
                'name' => config('app.name').' API',
                'api' => url('/api/v1'),
            ]);
    }
}
