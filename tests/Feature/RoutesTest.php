<?php

namespace Ogp\UiApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Ogp\UiApi\Tests\TestCase;

class RoutesTest extends TestCase
{
    public function test_package_routes_registered(): void
    {
        $all = collect(Route::getRoutes())->map(fn ($r) => $r->uri());
        $this->assertTrue($all->contains('api/ccs/{model}'));
        $this->assertTrue($all->contains('api/gapi/{model}'));
        $this->assertTrue($all->contains('api/gapi/{model}/{id}'));
    }
}
