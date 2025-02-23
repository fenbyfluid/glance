<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetupUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class InvitedUserController extends Controller
{
    public function login(User $user): RedirectResponse
    {
        if (isset($user->password)) {
            return redirect()->route('login')
                ->with('status', 'Account already activated, please login.')
                ->with('expected-username', $user->username);
        }

        Auth::login($user);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()->password !== null) {
            return redirect()->route('profile.edit');
        }

        return view('auth.invite', [
            'user' => $request->user(),
        ]);
    }

    public function store(SetupUserRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
