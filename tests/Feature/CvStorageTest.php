<?php

namespace Tests\Feature;

use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CvStorageTest extends TestCase
{
    use RefreshDatabase;

    private function profile(): CandidateProfile
    {
        $user = User::factory()->create(['type' => User::TYPE_TALENT]);

        return CandidateProfile::create([
            'user_id' => $user->id,
            'location' => 'Manila',
            'desired_position' => 'Developer',
            'experience_level' => 'mid',
            'work_setup' => 'remote',
        ]);
    }

    public function test_cvs_are_stored_on_the_default_disk(): void
    {
        config(['filesystems.default' => 'do']);
        Storage::fake('do');
        Storage::fake('local');

        $profile = $this->profile();
        $profile->storeCv(UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'));
        $first = $profile->cv_path;

        Storage::disk('do')->assertExists($first);
        Storage::disk('local')->assertMissing($first);
        $this->assertTrue($profile->hasCv());

        // Replacing the CV removes the old file from the same disk.
        $profile->storeCv(UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'));
        Storage::disk('do')->assertMissing($first);
        Storage::disk('do')->assertExists($profile->cv_path);
    }

    public function test_cvs_never_go_on_the_public_disk(): void
    {
        config(['filesystems.default' => 'public']);

        $this->assertSame('local', CandidateProfile::cvDisk());
    }
}
