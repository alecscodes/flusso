<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_client-ip', fn (Request $request) => $request->ip());
});

test('client IP comes from CF-Connecting-IP behind Traefik', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.5'])
        ->withHeaders(['CF-Connecting-IP' => '203.0.113.9', 'X-Forwarded-For' => '172.64.0.1'])
        ->get('/_client-ip')
        ->assertSeeText('203.0.113.9');
});

test('client IP falls back to X-Forwarded-For without Cloudflare', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.5'])
        ->withHeaders(['X-Forwarded-For' => '192.168.1.10'])
        ->get('/_client-ip')
        ->assertSeeText('192.168.1.10');
});
