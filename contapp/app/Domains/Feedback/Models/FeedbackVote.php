<?php

namespace App\Domains\Feedback\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El voto de una cuenta a una publicación: +1 a favor, -1 en contra. Uno por
 * cuenta y publicación; quitarlo borra la fila (FeedbackService::vote).
 */
class FeedbackVote extends Model
{
    protected $fillable = ['comment_id', 'user_id', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(FeedbackComment::class, 'comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
