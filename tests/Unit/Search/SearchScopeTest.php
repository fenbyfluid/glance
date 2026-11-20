<?php

namespace Tests\Unit\Search;

use App\Auth\AccessRules;
use App\Search\SearchScope;
use PHPUnit\Framework\TestCase;

class SearchScopeTest extends TestCase
{
    public function test_grants_nested_under_a_root_grant_do_not_restrict(): void
    {
        $scope = $this->scope(['' => true, 'Foo' => true, 'Foo/Bar' => true, '42' => true]);

        $this->assertFalse($scope->isEmpty());
        $this->assertTrue($scope->isUnrestricted());
    }

    public function test_rules_that_change_access_are_kept(): void
    {
        $rules = [
            '' => true,
            'Foo' => false,
            'Foo/Bar' => true,
            // Redundant with their nearest ancestor rule.
            'Foo/Bar/Baz' => true,
            'Foo/Other' => false,
        ];

        $this->assertFalse($this->scope($rules)->isUnrestricted());

        // Within an allowed subtree with nothing that changes access below it, no filtering is needed beyond the path.
        $scope = $this->scope($rules, 'Foo/Bar');
        $this->assertFalse($scope->isEmpty());
        $this->assertFalse($scope->isUnrestricted());
        $this->assertTrue($this->accessUnrestricted($scope));

        // But the denial above it still matters for its parent.
        $this->assertFalse($this->accessUnrestricted($this->scope($rules, 'Foo')));
    }

    public function test_only_denials_is_empty(): void
    {
        $this->assertTrue($this->scope([])->isEmpty());
        $this->assertTrue($this->scope(['' => false, 'Foo' => false, '3387' => false])->isEmpty());
    }

    public function test_numeric_paths_are_handled(): void
    {
        $scope = $this->scope(['' => false, '3387' => true, '3387/42' => true]);

        $this->assertFalse($scope->isEmpty());
        $this->assertFalse($scope->isUnrestricted());
        $this->assertTrue($this->accessUnrestricted($this->scope(['' => false, '3387' => true, '3387/42' => true], '3387')));
    }

    public function test_a_path_is_in_scope_if_any_set_allows_it(): void
    {
        $restricted = ['' => false, 'Foo' => true];

        $this->assertFalse($this->scope($restricted)->isUnrestricted());
        $this->assertFalse($this->scope($restricted, sets: [['' => false, 'Bar' => true]])->isUnrestricted());
        $this->assertTrue($this->scope($restricted, sets: [['' => true]])->isUnrestricted());

        // A set with nothing granted in the requested subtree contributes nothing.
        $this->assertTrue($this->scope(['' => false], 'Foo', sets: [['' => false, 'Bar' => true]])->isEmpty());
        $this->assertTrue($this->accessUnrestricted($this->scope(['' => false], 'Bar/Baz', sets: [['' => false, 'Bar' => true]])));
    }

    public function test_files_shared_on_their_own_are_kept_within_the_path_filter(): void
    {
        $files = ['Foo/file.mp4', 'Bar/file.mp4'];

        $scope = SearchScope::fromAccessRules([AccessRules::none()], $files, null);
        $this->assertFalse($scope->isEmpty());
        $this->assertFalse($scope->isUnrestricted());
        $this->assertSame($files, $scope->getFilePaths());

        $this->assertSame(['Foo/file.mp4'], SearchScope::fromAccessRules([AccessRules::none()], $files, 'Foo')->getFilePaths());
        $this->assertTrue(SearchScope::fromAccessRules([AccessRules::none()], $files, 'Baz')->isEmpty());
    }

    /**
     * @param  array<string, bool>  $rules
     * @param  list<array<string, bool>>  $sets  Further sets of rules.
     */
    private function scope(array $rules, ?string $pathPrefix = null, array $sets = []): SearchScope
    {
        $ruleSets = [];
        foreach ([$rules, ...$sets] as $set) {
            $ruleSets[] = AccessRules::fromRules(array_map(null, array_map(strval(...), array_keys($set)), array_values($set)));
        }

        return SearchScope::fromAccessRules($ruleSets, [], $pathPrefix);
    }

    private function accessUnrestricted(SearchScope $scope): bool
    {
        return (fn () => $this->isAccessUnrestricted())->call($scope);
    }
}
