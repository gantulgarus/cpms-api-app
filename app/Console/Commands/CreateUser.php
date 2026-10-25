<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

/**
 * Хэрэглэгчийг командаас үүсгэнэ.
 *
 * ЯАГААД ХЭРЭГТЭЙ ВЭ: хэрэглэгч бүртгэх дэлгэц рүү орохын тулд ЭХЛЭЭД
 * нэвтэрсэн байх ёстой. Тиймээс систем дээр ганц ч данс үлдээгүй үед
 * (шинэ сервер, эсвэл `demo:purge` demo админыг устгасны дараа) вебээр
 * гарах зам байхгүй — тахиа, өндөгний асуудал.
 *
 * Хэрэглээ:
 *   php artisan cpms:create-user --name="Б.Батбаяр" --email=admin@cpms.mn --role=admin
 *
 * Нууц үг өгөөгүй бол автоматаар үүсгээд НЭГ УДАА хэвлэнэ. Дахин харах
 * боломжгүй — мартвал `--password` тугаар дахин тавина.
 */
class CreateUser extends Command
{
    protected $signature = 'cpms:create-user
                            {--name= : Бүтэн нэр}
                            {--email= : Нэвтрэх и-мэйл}
                            {--role=admin : Үүрэг}
                            {--password= : Нууц үг (өгөөгүй бол автоматаар)}
                            {--reset : Бүртгэлтэй и-мэйл байвал нууц үгийг нь солино}';

    protected $description = 'Хэрэглэгч үүсгэнэ (эсвэл нууц үгийг нь сэргээнэ)';

    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?: $this->ask('Нэр'),
            'email' => $this->option('email') ?: $this->ask('И-мэйл'),
            'role' => $this->option('role'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }
            $this->line('Боломжит үүрэг: '.implode(', ', array_keys(User::ROLES)));

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::upper(Str::random(4)).'-'.random_int(1000, 9999);
        $existing = User::where('email', $data['email'])->first();

        if ($existing && ! $this->option('reset')) {
            $this->error("«{$data['email']}» аль хэдийн бүртгэлтэй.");
            $this->line('Нууц үгийг нь солих бол --reset тугийг нэмнэ үү.');

            return self::FAILURE;
        }

        if ($existing) {
            $existing->update(['password' => Hash::make($password), 'is_active' => true]);
            // Нууц үг сольсон бол хуучин төхөөрөмжүүд гарах ёстой — нууц үг
            // мартсан гэдэг нь ихэвчлэн утас алдсан гэсэн үг.
            $existing->tokens()->delete();
            $user = $existing;
            $this->info('Нууц үг сэргээгдлээ.');
        } else {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($password),
                'role' => $data['role'],
                'is_active' => true,
                // Бодит данс — `demo:purge` үүнийг ХЭЗЭЭ Ч устгахгүй.
                'is_demo' => false,
            ]);
            $this->info('Хэрэглэгч үүслээ.');
        }

        $this->table(['Талбар', 'Утга'], [
            ['Нэр', $user->name],
            ['И-мэйл', $user->email],
            ['Үүрэг', User::ROLES[$user->role] ?? $user->role],
            ['Нууц үг', $password],
        ]);

        // Нууц үг нь hash хэлбэрээр хадгалагддаг тул энэ мөр л цорын ганц
        // боломж. Дараагийн удаа `--reset` ашиглана.
        $this->warn('Нууц үгийг ОДОО хадгалж авна уу — дахин харуулах боломжгүй.');

        return self::SUCCESS;
    }
}
