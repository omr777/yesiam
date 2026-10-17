<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateDemoUser extends Command
{
    protected $signature = 'yesiam:create-demo-user';

    protected $description = 'Create a local YESIAM test user';

    public function handle(): int
    {
        $name = $this->ask('Nombre del usuario');
        $email = $this->ask('Correo electrónico');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('El correo no es válido.');
            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('Este usuario ya existe.');
            return self::FAILURE;
        }

        $password = $this->secret('Contraseña (mínimo 12 caracteres)');

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('La contraseña debe tener al menos 12 caracteres.');
            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Usuario creado correctamente. ID: {$user->id}");

        return self::SUCCESS;
    }
}
