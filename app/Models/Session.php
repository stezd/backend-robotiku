<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Session extends Model
{
    protected $table = 'class_sessions';

    protected $fillable = [
        'class_id',
        'trainer_id',
        'start_latitude',
        'start_longitude',
        'start_photo',
        'started_at',
        'ended_at',
        'status',
        'end_latitude',
        'end_longitude',
        'end_photo',
        'period_id',
        'week',
        'is_manual',
    ];

    protected $casts = [
        'started_at'      => 'datetime',
        'ended_at'        => 'datetime',
        'start_latitude'  => 'float',
        'start_longitude' => 'float',
        'is_manual' => 'boolean',
    ];

    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'class_id');
    }
    public function trainer()
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }
    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'session_id');
    }
    public function period()
    {
        return $this->belongsTo(Period::class);
    }
}
