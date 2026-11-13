<?php

namespace Tests\Feature;

use App\Models\AccessControlEntry;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_numeric_access_entries_remain_addressable_in_parent_listings(): void
    {
        $user = User::factory()->create();
        AccessControlEntry::create(['path' => '']);
        foreach (['Foo/Bar/3387', 'Foo/Bar/42/deeper', 'Foo/Bar/Flub'] as $path) {
            $entry = AccessControlEntry::create(['path' => $path]);
            $entry->users()->attach($user);
        }

        $this->assertSame(['' => false, 'Foo' => true], $user->getPathAccessInfo(''));
        $this->assertSame(['' => false, 3387 => true, 42 => true, 'Flub' => true], $user->getPathAccessInfo('Foo/Bar'));
        $this->assertSame(['' => true], $user->getPathAccessInfo('Foo/Bar/3387'));
        $this->assertSame(['' => false], $user->getPathAccessInfo('Foo/Bar/other.jpg'));

        foreach (['3387', '42', 'Flub', '0', 'other.jpg'] as $name) {
            DB::table('indexed_directories')->insert([
                'name' => $name,
                'path' => 'Foo/Bar/'.$name,
                'inode' => 1,
                'mtime' => 1,
            ]);
        }

        $names = IndexedDirectory::havingAccess($user->getPathAccessInfo('Foo/Bar'))->pluck('name')->all();
        sort($names, SORT_STRING);
        $this->assertSame(['3387', '42', 'Flub'], $names);
    }

    public function test_numeric_denials_exclude_only_the_named_children(): void
    {
        $user = User::factory()->create();
        $root = AccessControlEntry::create(['path' => '']);
        $root->users()->attach($user);
        AccessControlEntry::create(['path' => 'Foo/3387']);
        AccessControlEntry::create(['path' => 'Foo/42']);

        $access = $user->getPathAccessInfo('Foo');
        $this->assertSame(['' => true, 3387 => false, 42 => false], $access);

        foreach (['3387', '42', '0', 'other'] as $name) {
            DB::table('indexed_directories')->insert([
                'name' => $name,
                'path' => 'Foo/'.$name,
                'inode' => 1,
                'mtime' => 1,
            ]);
        }

        $names = IndexedDirectory::havingAccess($access)->pluck('name')->all();
        sort($names, SORT_STRING);
        $this->assertSame(['0', 'other'], $names);
        $this->assertSame(['' => false], $user->getPathAccessInfo('Foo/3387/file.jpg'));
    }

    public function test_numeric_overrides_are_bound_as_strings_for_allow_and_deny_filters(): void
    {
        foreach ([IndexedDirectory::class, IndexedFile::class] as $model) {
            $allow = $model::havingAccess(['' => false, 3387 => true, 0 => true, '03387' => true, 'Flub' => true]);
            $deny = $model::havingAccess(['' => true, 3387 => false, 0 => false, '03387' => false, 'Flub' => false]);

            $this->assertSame(['3387', '0', '03387', 'Flub'], $allow->getBindings());
            $this->assertSame(['3387', '0', '03387', 'Flub'], $deny->getBindings());
        }
    }
}
