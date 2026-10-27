<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
});

test('registration and banned IPs are managed by the owner only', function () {
    actingAs($this->owner)->get(route('settings.registration.edit'))->assertOk();
    actingAs($this->owner)->get(route('settings.banned-ips.index'))->assertOk();

    actingAs($this->member)->get(route('settings.registration.edit'))->assertForbidden();
    actingAs($this->member)->patch(route('settings.registration.update'), ['registration_enabled' => true])->assertForbidden();
    actingAs($this->member)->get(route('settings.banned-ips.index'))->assertForbidden();
    actingAs($this->member)->delete(route('settings.banned-ips.destroy'), ['ip' => '203.0.113.9'])->assertForbidden();

    expect(Setting::isRegistrationAllowed())->toBeFalse();
});

test('the owner can open registration', function () {
    actingAs($this->owner)->patch(route('settings.registration.update'), ['registration_enabled' => true])->assertRedirect(route('settings.registration.edit'));

    expect(Setting::isRegistrationAllowed())->toBeTrue();
});

test('every user can manage their own connected apps and finance settings', function () {
    actingAs($this->member)->get(route('settings.connected-apps.index'))->assertOk();
    actingAs($this->member)->get(route('settings.finance.edit'))->assertOk();
});

test('an IP can be unbanned from the settings, and the address is validated', function () {
    DB::table('banned_ips')->insert(['ip' => '203.0.113.9', 'reason' => 'probe', 'created_at' => now(), 'updated_at' => now()]);

    actingAs($this->owner)->from(route('settings.banned-ips.index'))->delete(route('settings.banned-ips.destroy'), ['ip' => 'not-an-ip'])
        ->assertSessionHasErrors('ip');
    expect(DB::table('banned_ips')->count())->toBe(1);

    actingAs($this->owner)->delete(route('settings.banned-ips.destroy'), ['ip' => '203.0.113.9'])->assertRedirect(route('settings.banned-ips.index'));
    expect(DB::table('banned_ips')->count())->toBe(0);
});
