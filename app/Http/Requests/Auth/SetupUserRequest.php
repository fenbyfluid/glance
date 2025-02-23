<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class SetupUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->password === null;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'lowercase', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ];
    }

    public function fulfill(): void
    {
        $user = $this->user();

        $user->fill([
            'username' => $this->string('username'),
            'password' => Hash::make($this->string('password')),
        ])->save();

        event(new PasswordReset($user));
    }
}
