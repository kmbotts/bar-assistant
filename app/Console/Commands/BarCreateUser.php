<?php

declare(strict_types=1);

namespace Kami\Cocktail\Console\Commands;

use Throwable;
use Illuminate\Support\Str;
use Kami\Cocktail\Models\Bar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Kami\Cocktail\Models\Enums\UserRoleEnum;
use BarAssistant\Application\User\UserService;
use BarAssistant\Application\Bar\MemberService;
use BarAssistant\Application\Bar\DTO\CreateMemberRequest;
use BarAssistant\Application\User\DTO\RegisterUserRequest;
use BarAssistant\Application\Exception\ValidationException;

class BarCreateUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bar:create-user
        {email : Email address of the new user}
        {name : Display name of the new user}
        {--password= : Password to set. A random one is generated and printed when omitted}
        {--bar= : ID of a bar to add the user to}
        {--role=guest : Role in that bar (admin, general or guest)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a user account even when registration is disabled, optionally adding it to a bar';

    private const MIN_PASSWORD_LENGTH = 5;

    /**
     * Execute the console command.
     */
    public function handle(UserService $userService, MemberService $memberService): int
    {
        $email = (string) $this->argument('email');
        $name = (string) $this->argument('name');

        $validator = Validator::make(['email' => $email, 'name' => $name], [
            'email' => ['required', 'email'],
            'name' => ['required'],
        ]);
        if ($validator->fails()) {
            $this->output->error($validator->errors()->first());

            return Command::FAILURE;
        }

        $role = $this->parseRole((string) $this->option('role'));
        if ($role === null) {
            $this->output->error('Role must be one of: admin, general, guest.');

            return Command::FAILURE;
        }

        $bar = null;
        if ($this->option('bar') !== null) {
            $bar = Bar::find((int) $this->option('bar'));
            if ($bar === null) {
                $this->output->error('Bar not found!');

                return Command::FAILURE;
            }
        }

        $generatedPassword = null;
        $password = $this->option('password');
        if ($password === null) {
            $password = $generatedPassword = Str::password(length: 16, symbols: false);
        }

        if (strlen((string) $password) < self::MIN_PASSWORD_LENGTH) {
            $this->output->error('Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.');

            return Command::FAILURE;
        }

        try {
            DB::transaction(function () use ($userService, $memberService, $name, $email, $password, $bar, $role) {
                $user = $userService->register(new RegisterUserRequest(
                    name: $name,
                    email: $email,
                    passwordHash: Hash::make((string) $password),
                    confirmAccount: true,
                ));

                if ($bar !== null) {
                    $memberService->addMemberToBar(new CreateMemberRequest(
                        userId: $user->id,
                        barId: $bar->id,
                        roleId: $role->value,
                    ));
                }
            });
        } catch (ValidationException $e) {
            $this->output->error($e->getMessage());

            return Command::FAILURE;
        } catch (Throwable $e) {
            $this->output->error('Could not create user: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->output->success('User created successfully!');
        $this->comment('Email: ' . $email);
        if ($bar !== null) {
            $this->comment('Added to bar "' . $bar->name . '" as ' . strtolower($role->name));
        }
        if ($generatedPassword !== null) {
            $this->comment('Generated password: ' . $generatedPassword);
        }

        return Command::SUCCESS;
    }

    private function parseRole(string $input): ?UserRoleEnum
    {
        return match (strtolower($input)) {
            'admin' => UserRoleEnum::Admin,
            'general' => UserRoleEnum::General,
            'guest' => UserRoleEnum::Guest,
            default => null,
        };
    }
}
