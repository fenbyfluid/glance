<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessControlEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccessController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::orderBy('note')->get();
        $accessControlEntries = AccessControlEntry::orderBy('path')->get();

        // We load the pivot data manually as Eloquent doesn't have an
        // efficient helper for loading the full set of both models.
        $allows = DB::table('access_control_entry_user')->get();

        return view('admin.access.index', [
            'users' => $users,
            'entries' => $accessControlEntries,
            'allows' => $allows->map(function ($allow) {
                return $allow->access_control_entry_id.'_'.$allow->user_id;
            })->flip(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Trim slashes from the start and end of the path before validation so that the unique rule matches correctly.
        $request->merge([
            'path_new' => trim($request->input('path_new') ?? '', '/') ?: null,
        ]);

        $validated = $request->validate([
            'path_new' => ['nullable', 'string', 'unique:'.AccessControlEntry::class.',path'],
            'allow' => ['array'],
            'allow.*' => ['boolean'],
        ], [
            'path_new.unique' => 'That path has already been added.',
        ]);

        $newEntry = null;
        if ($validated['path_new'] !== null) {
            $newEntry = AccessControlEntry::create([
                'path' => $validated['path_new'],
            ]);
        }

        $existingAllows = DB::table('access_control_entry_user')->get()->map(function ($allow) {
            return $allow->access_control_entry_id.'_'.$allow->user_id;
        })->flip()->toArray();

        $toCreate = [];
        foreach (($validated['allow'] ?? []) as $key => $allowed) {
            if (!$allowed || isset($existingAllows[$key])) {
                continue;
            }

            [$entryId, $userId] = explode('_', $key);
            if ($entryId === 'new') {
                if ($newEntry) {
                    $entryId = $newEntry->getKey();
                } else {
                    continue;
                }
            }

            $toCreate[] = [
                'access_control_entry_id' => (int) $entryId,
                'user_id' => (int) $userId,
            ];
        }

        if (!empty($toCreate)) {
            DB::table('access_control_entry_user')->insert($toCreate);
        }

        $toDelete = [];
        foreach ($existingAllows as $key => $_) {
            if ($validated['allow'][$key] ?? false) {
                continue;
            }

            [$entryId, $userId] = explode('_', $key);
            $toDelete[] = [
                'access_control_entry_id' => (int) $entryId,
                'user_id' => (int) $userId,
            ];
        }

        if (!empty($toDelete)) {
            $deleteQuery = DB::table('access_control_entry_user');
            foreach ($toDelete as $deleteEntry) {
                $deleteQuery->orWhereRowValues(array_keys($deleteEntry), '=', array_values($deleteEntry));
            }

            $deleteQuery->delete();
        }

        return redirect()
            ->route('admin.access.index')
            ->with('status', 'access-updated');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AccessControlEntry $access)
    {
        $access->users()->detach();
        $access->delete();

        return redirect()
            ->route('admin.access.index')
            ->with('status', 'access-deleted');
    }
}
