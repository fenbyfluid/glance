<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('admin.users.index', [
            'users' => User::all(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.users.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'note' => ['required', 'string', 'max:255'],
            'is_admin' => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'note' => $request->note,
            'is_admin' => $request->has('is_admin'),
        ]);

        event(new Registered($user));

        return redirect()
            ->route('admin.users.edit', ['user' => $user])
            ->with('status', 'user-updated');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user)
    {
        return view('admin.users.edit', [
            'user' => $user,
            'invite_url' => $user->getInviteUrl(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $request->validate([
            'note' => ['required', 'string', 'max:255'],
            'is_admin' => ['nullable', 'boolean'],
        ]);

        $user->fill([
            'note' => $request->note,
            'is_admin' => $request->has('is_admin'),
        ])->save();

        return redirect()
            ->route('admin.users.edit', ['user' => $user])
            ->with('status', 'user-updated');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'user-deleted');
    }
}
