<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminUserCreationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Admin',
            'last_name' => 'Owner',
            'email' => 'admin-create@example.com',
            'phone_number' => '0500000001',
            'password_hash' => Hash::make('1234567890'),
            'role' => 'admin',
        ]);
    }

    public function test_admin_creates_user_with_system_generated_credentials(): void
    {
        $this->actingAs($this->makeAdmin(), 'sanctum');

        $response = $this->postJson('/api/admin/users', [
            'first_name' => 'Ahmad',
            'last_name' => 'Hassan',
            'phone_number' => '0934128426',
            'role' => 'user',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.generated', true);

        $email = $response->json('data.credentials.email');
        $password = $response->json('data.credentials.password');

        $this->assertSame('ahmad.hassan@como.app', $email);
        $this->assertSame($email, $response->json('data.user.email'));
        $this->assertIsString($password);
        $this->assertGreaterThanOrEqual(10, strlen($password));

        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password])
            ->assertOk()
            ->assertJsonPath('data.role', 'user');
    }

    public function test_admin_can_fetch_single_user_by_id(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        $created = $this->postJson('/api/admin/users', [
            'first_name' => 'Sara',
            'last_name' => 'Khalil',
            'phone_number' => '0934128427',
            'role' => 'user',
        ]);
        $created->assertCreated();
        $userId = $created->json('data.user.id');

        $response = $this->getJson('/api/admin/users/'.$userId);
        $response->assertOk();
        $response->assertJsonPath('data.id', $userId);

        $this->getJson('/api/admin/users/'.(string) Str::uuid())->assertNotFound();
    }

    public function test_admin_can_reset_system_generated_credentials(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        $created = $this->postJson('/api/admin/users', [
            'first_name' => 'Nour',
            'last_name' => 'Saleh',
            'phone_number' => '0934128430',
            'role' => 'user',
        ]);
        $created->assertCreated();
        $created->assertJsonPath('data.user.credentials_generated', true);
        $userId = $created->json('data.user.id');
        $oldPassword = $created->json('data.credentials.password');

        $reset = $this->postJson('/api/admin/users/'.$userId.'/reset-credentials');
        $reset->assertOk();
        $reset->assertJsonPath('data.user.credentials_generated', true);

        $newEmail = $reset->json('data.credentials.email');
        $newPassword = $reset->json('data.credentials.password');

        $this->assertSame('nour.saleh@como.app', $newEmail);
        $this->assertNotSame($oldPassword, $newPassword);

        $this->postJson('/api/auth/login', ['email' => $newEmail, 'password' => $oldPassword])
            ->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => $newEmail, 'password' => $newPassword])
            ->assertOk();
    }

    public function test_reset_credentials_is_blocked_for_accounts_not_created_by_admin(): void
    {
        $this->actingAs($this->makeAdmin(), 'sanctum');

        $plain = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Self',
            'last_name' => 'Registered',
            'email' => 'self.registered@example.com',
            'phone_number' => '0500000094',
            'password_hash' => Hash::make('1234567890'),
            'role' => 'user',
        ]);

        $this->postJson('/api/admin/users/'.$plain->id.'/reset-credentials')
            ->assertStatus(400);
    }

    public function test_generated_email_gets_suffix_when_name_is_taken(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'sanctum');

        $first = $this->postJson('/api/admin/users', [
            'first_name' => 'Ahmad',
            'last_name' => 'Hassan',
            'phone_number' => '0934128428',
            'role' => 'dealer',
        ]);
        $second = $this->postJson('/api/admin/users', [
            'first_name' => 'Ahmad',
            'last_name' => 'Hassan',
            'phone_number' => '0934128429',
            'role' => 'user',
        ]);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame('ahmad.hassan@como.app', $first->json('data.credentials.email'));
        $this->assertSame('ahmad.hassan2@como.app', $second->json('data.credentials.email'));
        $this->assertSame('dealer', $first->json('data.user.role'));
    }
}
