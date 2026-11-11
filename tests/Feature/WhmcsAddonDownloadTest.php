<?php

use App\Models\User;

it('requires an authenticated verified user to download the WHMCS addon', function () {
    $this->get('/whmcs-addon/download')
        ->assertRedirect('/login');
});

it('downloads a WHMCS addon archive with the expected module structure', function () {
    if (! class_exists(\ZipArchive::class)) {
        $this->markTestSkipped('The PHP ZIP extension is required.');
    }

    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/whmcs-addon/download');

    $response->assertDownload('resellerbytes-ai-ops-whmcs-v1.0.0.zip');

    $file = $response->baseResponse->getFile();
    expect($file)->not->toBeNull();

    $archive = new \ZipArchive;
    expect($archive->open($file->getPathname()))->toBeTrue();

    expect($archive->locateName('resellerbytes_ai_ops/resellerbytes_ai_ops.php'))->not->toBeFalse();
    expect($archive->locateName('resellerbytes_ai_ops/lib/CredentialsFile.php'))->not->toBeFalse();
    expect($archive->locateName('INSTALLATION.txt'))->not->toBeFalse();
    expect($archive->locateName('VERSION.txt'))->not->toBeFalse();
    expect($archive->locateName('SHA256SUMS.txt'))->not->toBeFalse();
    expect($archive->locateName('configuration.php'))->toBeFalse();

    $archive->close();
});

it('does not expose the WHMCS addon download to unverified users', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
    ]);

    $this->actingAs($user)
        ->get('/whmcs-addon/download')
        ->assertRedirect(route('verification.notice'));
});
