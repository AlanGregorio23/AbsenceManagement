<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudentArchivePathBuilder
{
    public const CATEGORY_CERTIFICATES = 'certificati_medici';

    public const CATEGORY_SIGNATURES = 'firme';

    public const CATEGORY_SLIPS = 'talloncini';

    /**
     * @param  array<string,mixed>  $meta
     */
    public static function storeUploadedFileForStudent(
        UploadedFile $file,
        User $student,
        string $category,
        array $meta = []
    ): string {
        $disk = Storage::disk(config('filesystems.default', 'local'));
        $directory = static::buildDirectory($student, $category);
        $baseName = static::buildBaseName($student, $meta);
        $extension = static::normalizeExtension(
            (string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin')
        );
        $fileName = static::resolveUniqueFileName($disk, $directory, $baseName, $extension);

        return $file->storeAs($directory, $fileName);
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    public static function storeBinaryForStudent(
        string $binaryContent,
        User $student,
        string $category,
        string $extension = 'bin',
        array $meta = []
    ): string {
        $disk = Storage::disk(config('filesystems.default', 'local'));
        $directory = static::buildDirectory($student, $category);
        $baseName = static::buildBaseName($student, $meta);
        $safeExtension = static::normalizeExtension($extension);
        $fileName = static::resolveUniqueFileName($disk, $directory, $baseName, $safeExtension);
        $path = $directory.'/'.$fileName;
        $disk->put($path, $binaryContent);

        return $path;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private static function buildBaseName(User $student, array $meta = []): string
    {
        $student->loadMissing(['classes', 'guardians']);
        $studentCode = 'u'.max((int) $student->id, 0);
        $studentName = static::slug(
            trim((string) $student->name.' '.(string) $student->surname),
            'studente'
        );
        $classLabel = static::slug(
            (string) ($meta['class'] ?? static::resolveClassLabel($student)),
            'classe_nd'
        );
        $guardianLabel = static::slug(
            (string) ($meta['guardian'] ?? static::resolveGuardianLabel($student)),
            'tutore_nd'
        );
        $contextLabel = static::slug((string) ($meta['context'] ?? 'documento'), 'documento');
        $codeLabel = static::slug((string) ($meta['code'] ?? ''), '');
        $timestampLabel = now()->format('YmdHis');

        $parts = [
            $contextLabel,
            $codeLabel,
            $studentCode,
            $studentName,
            $classLabel,
            $guardianLabel,
            $timestampLabel,
        ];

        return collect($parts)
            ->filter(fn ($part) => $part !== '')
            ->implode('_');
    }

    private static function resolveClassLabel(User $student): string
    {
        $class = $student->classes->first();
        if (! $class) {
            return '';
        }

        $year = trim((string) ($class->year ?? ''));
        $section = trim((string) ($class->section ?? ''));
        if ($year !== '' || $section !== '') {
            return $year.$section;
        }

        return trim((string) ($class->name ?? ''));
    }

    private static function resolveGuardianLabel(User $student): string
    {
        $guardian = $student->guardians->first();

        return trim((string) ($guardian?->name ?? ''));
    }

    private static function buildDirectory(User $student, string $category): string
    {
        $normalizedCategory = static::normalizeCategory($category);

        return 'archivio/'.max((int) $student->id, 0).'/'.$normalizedCategory;
    }

    private static function normalizeCategory(string $category): string
    {
        $normalized = static::slug($category, '');

        return match ($normalized) {
            self::CATEGORY_CERTIFICATES => self::CATEGORY_CERTIFICATES,
            self::CATEGORY_SIGNATURES => self::CATEGORY_SIGNATURES,
            self::CATEGORY_SLIPS => self::CATEGORY_SLIPS,
            default => self::CATEGORY_SLIPS,
        };
    }

    private static function normalizeExtension(string $extension): string
    {
        $normalized = strtolower(trim($extension));
        $normalized = preg_replace('/[^a-z0-9]+/i', '', $normalized) ?? '';

        return $normalized !== '' ? $normalized : 'bin';
    }

    private static function slug(string $value, string $fallback): string
    {
        $ascii = Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        if ($ascii === '') {
            return $fallback;
        }

        return Str::limit($ascii, 40, '');
    }

    private static function resolveUniqueFileName($disk, string $directory, string $baseName, string $extension): string
    {
        $candidate = $baseName.'.'.$extension;
        $increment = 1;

        while ($disk->exists($directory.'/'.$candidate)) {
            $candidate = $baseName.'_'.$increment.'.'.$extension;
            $increment++;
        }

        return $candidate;
    }
}
