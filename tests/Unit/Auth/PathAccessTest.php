<?php

namespace Tests\Unit\Auth;

use App\Auth\AccessRules;
use App\Auth\PathAccess;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PathAccessTest extends TestCase
{
    public function test_without_links_it_is_the_users_own_access(): void
    {
        $own = $this->rules(['' => true, 'Private' => false]);
        $access = new PathAccess($own);

        foreach (['', 'Private', 'Some/Dir'] as $path) {
            $this->assertSame($own->infoAt($path), $access->info($path), $path);
            $this->assertSame($own->allows($path), $access->allows($path), $path);
        }
    }

    public function test_links_add_to_the_users_own_access(): void
    {
        $access = new PathAccess(
            own: $this->rules(['Mine' => true]),
            linked: [
                $this->rules(['' => true, 'Theirs/Private' => false])->limitedTo('Theirs'),
                AccessRules::forPath('Other/file.mp4'),
            ],
            linkedFilePaths: ['Other/file.mp4'],
        );

        $this->assertSame(['' => false, 'Mine' => true, 'Theirs' => true, 'Other' => true], $access->info(''));
        $this->assertSame(['' => true, 'Private' => false], $access->info('Theirs'));
        $this->assertSame(['' => false, 'file.mp4' => true], $access->info('Other'));

        $this->assertTrue($access->allows('Mine/file.jpg'));
        $this->assertTrue($access->allows('Theirs/file.jpg'));
        $this->assertFalse($access->allows('Theirs/Private/file.jpg'));
        $this->assertTrue($access->allows('Other/file.mp4'));
        $this->assertFalse($access->allows('Other/other.mp4'));
        $this->assertFalse($access->allows(''));
    }

    public function test_a_child_is_visible_if_any_set_shows_it(): void
    {
        // The user's own rules deny what a link allows, and the other way around.
        $access = new PathAccess(
            own: $this->rules(['' => true, 'Dir/Hidden' => false]),
            linked: [$this->rules(['' => true, 'Dir/Other' => false])->limitedTo('Dir')],
        );

        $this->assertSame(['' => true], $access->info('Dir'));
        $this->assertTrue($access->allows('Dir/Hidden'));

        // Hidden by everything is still hidden.
        $access = new PathAccess(
            own: $this->rules(['' => true, 'Dir/Hidden' => false]),
            linked: [$this->rules(['' => true, 'Dir/Hidden' => false])->limitedTo('Dir')],
        );

        $this->assertSame(['' => true, 'Hidden' => false], $access->info('Dir'));
        $this->assertFalse($access->allows('Dir/Hidden'));
    }

    public function test_links_from_different_creators_keep_their_own_limits(): void
    {
        $access = new PathAccess(
            own: AccessRules::none(),
            linked: [
                $this->rules(['' => true, 'Shared/A/Private' => false])->limitedTo('Shared/A'),
                $this->rules(['' => true, 'Shared/B/Private' => false])->limitedTo('Shared/B'),
            ],
            isGuest: true,
        );

        $this->assertSame(['' => false, 'A' => true, 'B' => true], $access->info('Shared'));
        $this->assertFalse($access->allows('Shared/A/Private/file.jpg'));
        $this->assertFalse($access->allows('Shared/B/Private/file.jpg'));
        $this->assertTrue($access->allows('Shared/A/file.jpg'));
    }

    public function test_numeric_children_merge_by_name(): void
    {
        $access = new PathAccess(
            own: $this->rules(['Foo/42' => true]),
            linked: [AccessRules::forPath('Foo/3387'), AccessRules::forPath('Foo/42/file.jpg')],
        );

        $this->assertSame(['' => false, 42 => true, 3387 => true], $access->info('Foo'));
    }

    public function test_directories_need_something_visible_and_files_need_access(): void
    {
        $access = new PathAccess(AccessRules::none(), [AccessRules::forPath('Some/Dir/file.mp4')]);

        $this->assertSame(['' => false, 'Dir' => true], $access->ensureVisible('Some', isDirectory: true));
        $this->assertSame(['' => true], $access->ensureVisible('Some/Dir/file.mp4', isDirectory: false));

        $this->expectException(NotFoundHttpException::class);
        $access->ensureVisible('Some', isDirectory: false);
    }

    public function test_guests_are_sent_to_log_in_for_anything_else(): void
    {
        $access = new PathAccess(AccessRules::none(), [AccessRules::forPath('Some/Dir/file.mp4')], isGuest: true);

        $this->expectException(AuthenticationException::class);
        $access->ensureVisible('Other', isDirectory: true);
    }

    public function test_marks_what_is_only_visible_through_links(): void
    {
        $access = new PathAccess(
            own: $this->rules(['Mine' => true]),
            linked: [$this->rules(['' => true])->limitedTo('Theirs'), AccessRules::forPath('Mine/Dir/file.mp4')],
        );

        $this->assertFalse($access->isOnlyThroughLinks('', 'Mine'));
        $this->assertTrue($access->isOnlyThroughLinks('', 'Theirs'));
        $this->assertTrue($access->isOnlyThroughLinks('Theirs', 'file.jpg'));
        $this->assertFalse($access->isOnlyThroughLinks('Mine/Dir', 'file.mp4'));
        $this->assertTrue($access->isOnlyThroughLinks('Theirs/file.jpg'));
        $this->assertFalse($access->isOnlyThroughLinks('Mine/file.jpg'));

        // Everything a guest sees is through links, so nothing is marked.
        $guest = new PathAccess(AccessRules::none(), [$this->rules(['' => true])->limitedTo('Theirs')], isGuest: true);
        $this->assertFalse($guest->isOnlyThroughLinks('', 'Theirs'));
        $this->assertFalse($guest->isOnlyThroughLinks('Theirs/file.jpg'));
    }

    /**
     * @param  array<string, bool>  $rules
     */
    private function rules(array $rules): AccessRules
    {
        return AccessRules::fromRules(array_map(null, array_map(strval(...), array_keys($rules)), array_values($rules)));
    }
}
