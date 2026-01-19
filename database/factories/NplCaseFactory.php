<?php

namespace Database\Factories;

use App\Models\NplCase;
use App\Models\LoanAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class NplCaseFactory extends Factory
{
    protected $model = NplCase::class;

    public function definition(): array
    {
        return [
            'loan_account_id' => LoanAccount::factory(), // penting
            'pic_user_id' => null,
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => null,
            'closed_at' => null,
            'summary' => null,
        ];
    }
}
