<?php

namespace Database\Factories;

use App\Models\LoanAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class LoanAccountFactory extends Factory
{
    protected $model = LoanAccount::class;

    public function definition(): array
    {
        return [
            'account_no' => (string) $this->faker->numerify('##########'),
            'cif' => (string) $this->faker->numerify('######'),
            'customer_name' => $this->faker->name(),
            'position_date' => now()->toDateString(), // WAJIB

            // optional fields boleh default null/0
            'product_type' => null,
            'segment' => null,
            'kolek' => null,
            'dpd' => 0,
            'plafond' => 0,
            'outstanding' => 0,
            'arrears_principal' => 0,
            'arrears_interest' => 0,
            'branch_code' => null,
            'branch_name' => null,
            'ao_code' => null,
            'ao_name' => null,
            'is_active' => true,
        ];
    }
}
