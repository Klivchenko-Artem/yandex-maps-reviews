<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Регистрации нет: один пользователь из переменных окружения.
     * Повторный запуск сидов не падает и подхватывает новый пароль из окружения.
     */
    public function run(): void
    {
        $password = (string) config('app.seed_user.password');

        $user = User::firstOrNew(['email' => config('app.seed_user.email')]);
        $user->name = config('app.seed_user.name');

        // Перехэшировать тот же пароль нельзя: у bcrypt каждый раз новая соль, а Sanctum
        // держит сессии привязанными к хэшу и выкидывает всех при каждом перезапуске контейнера.
        if (! $user->exists || ! Hash::check($password, $user->password)) {
            $user->password = $password;
        }

        $user->save();
    }
}
