<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeminiJob extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $table = 'gemini_jobs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'source_system',
        'source_entity_type',
        'source_entity_id',
        'idempotency_key',
        'status',
        'attempt',
        'prompt',
        'model',
        'aspect_ratio',
        'image_size',
        'input_images',
        'result_mime_type',
        'result_base64_data',
        'result_text',
        'result_width',
        'result_height',
        'result_size_bytes',
        'response_id',
        'model_version',
        'error_message',
        'meta',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'source_entity_id' => 'integer',
        'attempt' => 'integer',
        'input_images' => 'array',
        'meta' => 'array',
        'result_width' => 'integer',
        'result_height' => 'integer',
        'result_size_bytes' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
