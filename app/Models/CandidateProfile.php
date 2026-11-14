<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CandidateProfile extends Model
{
    public const STATUS_FOR_ASSESSMENT = 'for_assessment';

    public const STATUS_FOR_EVALUATION = 'for_evaluation';

    public const STATUS_ACTIVE = 'active';

    /**
     * Disk CVs are stored on: the app's default disk (FILESYSTEM_DISK, e.g. "do" for DigitalOcean Spaces).
     * CVs are private, so they never go on the public disk; that setting falls back to "local".
     */
    public static function cvDisk(): string
    {
        $disk = (string) config('filesystems.default', 'local');

        return $disk === 'public' ? 'local' : $disk;
    }

    protected $fillable = [
        'user_id',
        'location',
        'desired_position',
        'experience_level',
        'work_setup',
        'skills',
        'linkedin_url',
        'cv_path',
        'cv_original_name',
        'cv_size',
        'cv_uploaded_at',
        'status',
        'status_updated_at',
    ];

    protected $hidden = [
        'cv_path',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'cv_size' => 'integer',
            'cv_uploaded_at' => 'datetime',
            'status_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function storeCv(UploadedFile $file): void
    {
        $previous = $this->cv_path;

        $this->forceFill([
            'cv_path' => $file->store('cvs', self::cvDisk()),
            'cv_original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'cv_size' => $file->getSize(),
            'cv_uploaded_at' => now(),
        ])->save();

        if ($previous && $previous !== $this->cv_path) {
            Storage::disk(self::cvDisk())->delete($previous);
        }
    }

    public function hasCv(): bool
    {
        return $this->cv_path !== null && Storage::disk(self::cvDisk())->exists($this->cv_path);
    }

    /** Public CV metadata (no storage path). */
    public function cvSummary(): ?array
    {
        if (! $this->cv_path) {
            return null;
        }

        return [
            'name' => $this->cv_original_name,
            'size' => $this->cv_size,
            'uploaded_at' => $this->cv_uploaded_at?->toIso8601String(),
        ];
    }
}
