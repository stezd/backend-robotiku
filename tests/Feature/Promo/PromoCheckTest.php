<?php

namespace Tests\Feature\Promo;

use App\Models\DiscountCode;
use App\Models\Program;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoCheckTest extends TestCase
{
    use RefreshDatabase;

    private function seedProgram(float $reg = 150000, float $cycle = 200000): Program
    {
        // Harga promo dihitung dari program, bukan dari kelas.
        return Program::create([
            'name' => 'Robotika Dasar',
            'level' => 'beginner',
            'registration_fee' => $reg,
            'price_per_cycle' => $cycle,
            'is_active' => true,
            'is_visible' => true,
        ]);
    }

    private function promo(array $attr = []): DiscountCode
    {
        return DiscountCode::create(array_merge([
            'code' => 'HEMAT50',
            'type' => 'percentage',
            'value' => 50,
            'quota' => 0,
            'is_active' => true,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
        ], $attr));
    }

    public function test_promo_persentase_valid(): void
    {
        $program = $this->seedProgram();
        $this->promo();

        $this->postJson('/api/v1/promo/check', ['code' => 'hemat50', 'program_id' => $program->id])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.discount_amount', 75000)   // 50% dari 150rb
            ->assertJsonPath('data.total_preview', 275000);   // (150rb-75rb)+200rb
    }

    public function test_promo_nominal_dibatasi_registration_fee(): void
    {
        $program = $this->seedProgram();
        $this->promo(['code' => 'POTONG999', 'type' => 'nominal', 'value' => 999000]);

        $this->postJson('/api/v1/promo/check', ['code' => 'POTONG999', 'program_id' => $program->id])
            ->assertOk()
            ->assertJsonPath('data.discount_amount', 150000)  // tidak melebihi reg fee
            ->assertJsonPath('data.total_preview', 200000);
    }

    public function test_promo_tidak_ditemukan(): void
    {
        $program = $this->seedProgram();
        $this->postJson('/api/v1/promo/check', ['code' => 'NGAWUR', 'program_id' => $program->id])
            ->assertStatus(422);
    }

    public function test_promo_kedaluwarsa(): void
    {
        $program = $this->seedProgram();
        $this->promo(['valid_until' => now()->subDay()]);

        $this->postJson('/api/v1/promo/check', ['code' => 'HEMAT50', 'program_id' => $program->id])
            ->assertStatus(422);
    }

    public function test_promo_kuota_habis(): void
    {
        $program = $this->seedProgram();
        $this->promo(['quota' => 5, 'used_count' => 5]);

        $this->postJson('/api/v1/promo/check', ['code' => 'HEMAT50', 'program_id' => $program->id])
            ->assertStatus(422);
    }
}
