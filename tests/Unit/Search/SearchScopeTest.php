<?php

namespace Tests\Unit\Search;

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

    /**
     * @param  array<string, bool>  $rules
     */
    private function scope(array $rules, ?string $pathPrefix = null): SearchScope
    {
        $entries = [];
        foreach ($rules as $path => $granted) {
            $entries[] = (object) ['path' => strval($path), 'users_count' => $granted ? 1 : 0];
        }

        return SearchScope::fromAccessControlEntries($entries, $pathPrefix);
    }

    private function accessUnrestricted(SearchScope $scope): bool
    {
        return (fn () => $this->isAccessUnrestricted())->call($scope);
    }
}
