<?php

namespace Tests\Unit\Auth;

use App\Auth\AccessRules;
use PHPUnit\Framework\TestCase;

class AccessRulesTest extends TestCase
{
    public function test_nothing_is_accessible_without_a_granting_rule(): void
    {
        $this->assertSame(['' => false], AccessRules::none()->infoAt(''));
        $this->assertFalse(AccessRules::none()->allows('Some/Dir'));
        $this->assertTrue(AccessRules::none()->isEmpty());
        $this->assertTrue($this->rules(['Some' => false])->isEmpty());
    }

    public function test_the_deepest_rule_wins(): void
    {
        $rules = $this->rules(['' => true, 'Foo' => false, 'Foo/Bar' => true]);

        $this->assertTrue($rules->allows(''));
        $this->assertTrue($rules->allows('Other/file.jpg'));
        $this->assertFalse($rules->allows('Foo'));
        $this->assertFalse($rules->allows('Foo/file.jpg'));
        $this->assertTrue($rules->allows('Foo/Bar'));
        $this->assertTrue($rules->allows('Foo/Bar/file.jpg'));

        // Only whole segments match.
        $this->assertTrue($rules->allows('Foobar'));
        $this->assertFalse($rules->allows('Foo/Barbaz'));
    }

    public function test_rules_are_evaluated_in_any_order_given(): void
    {
        $sorted = $this->rules(['' => true, 'Foo' => false, 'Foo/Bar' => true]);
        $shuffled = $this->rules(['Foo/Bar' => true, '' => true, 'Foo' => false]);

        foreach (['', 'Foo', 'Foo/Bar', 'Foo/Bar/file.jpg', 'Foo/other.jpg'] as $path) {
            $this->assertSame($sorted->infoAt($path), $shuffled->infoAt($path), $path);
        }
    }

    public function test_listings_show_the_way_down_to_deeper_grants(): void
    {
        $rules = $this->rules(['Some/Dir' => true]);

        $this->assertSame(['' => false, 'Some' => true], $rules->infoAt(''));
        $this->assertSame(['' => false, 'Dir' => true], $rules->infoAt('Some'));
        $this->assertSame(['' => true], $rules->infoAt('Some/Dir'));
        $this->assertSame(['' => false], $rules->infoAt('Some/Other'));
    }

    public function test_listings_hide_denied_children(): void
    {
        $rules = $this->rules(['' => true, 'Foo/3387' => false, 'Foo/42' => false, 'Foo/42/deeper' => true]);

        // 42 has a grant inside it, so is still the way down to it.
        $this->assertSame(['' => true, 3387 => false], $rules->infoAt('Foo'));
        $this->assertSame(['' => false, 'deeper' => true], $rules->infoAt('Foo/42'));
    }

    public function test_numeric_children_are_int_keys(): void
    {
        $rules = $this->rules(['Foo/Bar/3387' => true, 'Foo/Bar/42/deeper' => true, 'Foo/Bar/Flub' => true]);

        $this->assertSame(['' => false, 3387 => true, 42 => true, 'Flub' => true], $rules->infoAt('Foo/Bar'));
        $this->assertSame(['' => false, 3387 => true], $this->rules(['3387' => true])->infoAt(''));
        $this->assertTrue($this->rules(['3387' => true])->allows('3387/file.jpg'));
    }

    public function test_a_single_path_grants_just_that_path(): void
    {
        $rules = AccessRules::forPath('Some/Dir/file.mp4');

        $this->assertTrue($rules->allows('Some/Dir/file.mp4'));
        $this->assertFalse($rules->allows('Some/Dir/other.mp4'));
        $this->assertFalse($rules->allows('Some/Dir'));
        $this->assertSame(['' => false, 'file.mp4' => true], $rules->infoAt('Some/Dir'));
        $this->assertSame(['' => false, 'Dir' => true], $rules->infoAt('Some'));
    }

    public function test_limiting_to_a_subtree_keeps_deeper_rules_and_drops_everything_outside(): void
    {
        $creator = $this->rules(['' => true, 'Shared/Private' => false, 'Shared/Private/Open' => true, 'Elsewhere' => false]);
        $link = $creator->limitedTo('Shared');

        $this->assertTrue($link->allows('Shared'));
        $this->assertTrue($link->allows('Shared/file.jpg'));
        $this->assertFalse($link->allows('Shared/Private'));
        $this->assertTrue($link->allows('Shared/Private/Open/file.jpg'));
        $this->assertFalse($link->allows(''));
        $this->assertFalse($link->allows('Other'));
        $this->assertFalse($link->allows('Elsewhere'));

        $this->assertSame(['' => false, 'Shared' => true], $link->infoAt(''));
        // Private is still listed, as the way down to what's open in it.
        $this->assertSame(['' => true], $link->infoAt('Shared'));
        $this->assertSame(['' => false, 'Open' => true], $link->infoAt('Shared/Private'));

        $closed = $this->rules(['' => true, 'Shared/Private' => false])->limitedTo('Shared');
        $this->assertSame(['' => true, 'Private' => false], $closed->infoAt('Shared'));
    }

    public function test_limiting_to_somewhere_inaccessible_allows_nothing(): void
    {
        $creator = $this->rules(['Mine' => true]);

        $this->assertTrue($creator->limitedTo('Other')->isEmpty());

        // Grants deeper inside don't leak out through the subtree.
        $creator = $this->rules(['Shared/Inner' => true]);
        $this->assertTrue($creator->limitedTo('Shared')->isEmpty());
    }

    public function test_limiting_to_the_root_is_everything_the_rules_allow(): void
    {
        $creator = $this->rules(['' => true, 'Private' => false]);
        $link = $creator->limitedTo('');

        foreach (['', 'Some/Dir', 'Private', 'Private/file.jpg'] as $path) {
            $this->assertSame($creator->infoAt($path), $link->infoAt($path), $path);
        }
    }

    public function test_effective_rules_drop_those_that_change_nothing(): void
    {
        $rules = $this->rules([
            '' => true,
            'Foo' => true,
            'Foo/Bar' => false,
            'Foo/Bar/Baz' => false,
            'Foo/Bar/Baz/Qux' => true,
            'Redundant' => true,
            '42' => true,
        ]);

        $this->assertSame([['', true], ['Foo/Bar', false], ['Foo/Bar/Baz/Qux', true]], $rules->getEffectiveRules());
        $this->assertSame([], $this->rules(['Foo' => false])->getEffectiveRules());
    }

    /**
     * @param  array<string, bool>  $rules
     */
    private function rules(array $rules): AccessRules
    {
        return AccessRules::fromRules(array_map(null, array_map(strval(...), array_keys($rules)), array_values($rules)));
    }
}
