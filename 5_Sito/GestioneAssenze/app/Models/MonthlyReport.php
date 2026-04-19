<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MonthlyReport extends Model
{
    use HasFactory;

    public const STATUS_GENERATED = 'generated';

    public const STATUS_SENT = 'sent';

    public const STATUS_SIGNED_UPLOADED = 'signed_uploaded';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'student_id',
        'class_id',
        'report_month',
        'status',
        'summary_json',
        'system_pdf_path',
        'signed_pdf_path',
        'generated_at',
        'sent_at',
        'last_sent_at',
        'signed_uploaded_at',
        'approved_at',
        'approved_by',
        'created_by',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'report_month' => 'date',
            'summary_json' => 'array',
            'generated_at' => 'datetime',
            'sent_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'signed_uploaded_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function emailNotifications()
    {
        return $this->hasMany(MonthlyReportEmailNotification::class);
    }

    public static function normalizeStatus(?string $status): string
    {
        return match (trim((string) $status)) {
            self::STATUS_GENERATED => self::STATUS_GENERATED,
            self::STATUS_SENT => self::STATUS_SENT,
            self::STATUS_SIGNED_UPLOADED => self::STATUS_SIGNED_UPLOADED,
            self::STATUS_APPROVED => self::STATUS_APPROVED,
            self::STATUS_FAILED => self::STATUS_FAILED,
            default => self::STATUS_GENERATED,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match (self::normalizeStatus($status)) {
            self::STATUS_GENERATED => 'Generato',
            self::STATUS_SENT => 'Inviato / In attesa upload firmato',
            self::STATUS_SIGNED_UPLOADED => 'Caricato (in attesa approvazione)',
            self::STATUS_APPROVED => 'Approvato / Archiviato',
            self::STATUS_FAILED => 'Errore generazione',
            default => 'Generato',
        };
    }

    public static function statusBadge(string $status): string
    {
        return match (self::normalizeStatus($status)) {
            self::STATUS_GENERATED => 'bg-slate-100 text-slate-700',
            self::STATUS_SENT => 'bg-amber-100 text-amber-700',
            self::STATUS_SIGNED_UPLOADED => 'bg-sky-100 text-sky-700',
            self::STATUS_APPROVED => 'bg-emerald-100 text-emerald-700',
            self::STATUS_FAILED => 'bg-rose-100 text-rose-700',
            default => 'bg-slate-100 text-slate-700',
        };
    }

    public static function bucketForStatus(string $status): string
    {
        return match (self::normalizeStatus($status)) {
            self::STATUS_SIGNED_UPLOADED => 'pending',
            self::STATUS_APPROVED => 'completed',
            default => 'missing',
        };
    }

    public function reportCode(): string
    {
        return 'RM-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    public function monthLabel(): string
    {
        return $this->report_month
            ? $this->report_month->format('m/Y')
            : '-';
    }
}
