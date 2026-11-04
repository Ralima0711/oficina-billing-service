<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MensagemProcessada extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'mensagens_processadas';

    protected $primaryKey = 'message_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'message_id',
        'tipo',
    ];
}
