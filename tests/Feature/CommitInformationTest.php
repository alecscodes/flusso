<?php

declare(strict_types=1);

it('shares commit with inertia pages as a short hash', function () {
    config(['app.commit' => 'abcdef1234567890']);

    $response = $this->get(route('home'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->where('commit', 'abcdef1'));
});

it('shares null commit on inertia pages when app commit is not configured', function () {
    config(['app.commit' => null]);

    $response = $this->get(route('home'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page->where('commit', null));
});
