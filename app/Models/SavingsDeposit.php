<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavingsDeposit extends Model
{
    protected $fillable = ['savings_goal_id', 'user_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function goal() { return $this->belongsTo(SavingsGoal::class, 'savings_goal_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
