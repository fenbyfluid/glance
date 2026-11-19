<?php

namespace App\Http\Controllers;

use App\Auth\SharedLinkGrants;
use App\Media\FileDetails;
use App\Models\IndexedFile;
use App\Models\SharedLink;
use App\Models\User;
use App\Utilities\Path;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Jcupitt\Vips;

class SharedLinkController extends Controller
{
    /**
     * Opening a link: it's recorded in the session, which lets the visitor (signed in or not) in to the file's info
     * page and everything it loads. Answered with a page rather than a redirect, so link previews work: chat apps
     * generally don't keep the session cookie across a redirect, so would end up at the login page, but they do read
     * the page's Open Graph tags. Browsers are forwarded straight on.
     */
    public function show(Request $request, string $token): Response
    {
        $link = $this->findUsableLink($token);

        SharedLinkGrants::add($request, $link);

        $pathInfo = new \SplFileInfo(config('media.path').'/'.$link->path);
        $fileEntry = $this->findCurrentFileEntry($link->path, $pathInfo);
        $details = new FileDetails($pathInfo, $fileEntry);

        $durationMs = $fileEntry?->media_info?->durationMs;

        return response()->view('shared-links.show', [
            'title' => basename($link->path),
            'description' => implode(' · ', array_filter([
                ucfirst($details->kind->displayName()),
                ($durationMs !== null) ? FileDetails::formatDuration($durationMs) : null,
            ])),
            'linkUrl' => $link->url(),
            'previewUrl' => $fileEntry?->thumbnail ? $link->previewUrl() : null,
            'targetUrl' => media_info_url($link->path),
        ]);
    }

    /**
     * The thumbnail for link previews, as a JPEG: several apps don't show AVIF. It's fetched without a session, so
     * goes by the token alone.
     */
    public function preview(string $token): Response
    {
        $link = $this->findUsableLink($token);

        $pathInfo = new \SplFileInfo(config('media.path').'/'.$link->path);
        $thumbnail = $this->findCurrentFileEntry($link->path, $pathInfo)?->thumbnail;
        if ($thumbnail === null) {
            abort(404);
        }

        $thumbnailPath = Path::makeThumbnailPath($thumbnail);
        if (!file_exists($thumbnailPath)) {
            abort(404);
        }

        config(['clockwork.enable' => false]);

        $jpeg = Vips\Image::newFromFile($thumbnailPath)->writeToBuffer('.jpg', ['Q' => 85]);

        // Short-lived, so a revoked link's preview doesn't hang around in caches for long.
        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * The active links to a file, for the share dialog: the user's own, or everyone's for admins.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $path = $this->validatedSharablePath($request);

        $links = SharedLink::active()
            ->where('path', $path)
            ->unless($user->is_admin, fn (Builder $query) => $query->whereBelongsTo($user))
            ->with('user')
            ->latest()
            ->get();

        return response()->json([
            'links' => $links->map(fn (SharedLink $link) => $this->present($link, $user)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', SharedLink::class);

        $user = $request->user();
        $path = $this->validatedSharablePath($request);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
            'expires_in' => ['required', 'integer', Rule::in(array_keys(SharedLink::EXPIRY_CHOICES))],
        ]);

        $link = SharedLink::create([
            'user_id' => $user->getKey(),
            'path' => $path,
            'note' => $validated['note'] ?? null,
            'expires_at' => now()->addDays((int) $validated['expires_in']),
        ]);
        $link->setRelation('user', $user);

        return response()->json(['link' => $this->present($link, $user)], 201);
    }

    public function update(Request $request, SharedLink $sharedLink): JsonResponse
    {
        Gate::authorize('update', $sharedLink);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $sharedLink->update(['note' => $validated['note'] ?? null]);
        $sharedLink->load('user');

        return response()->json(['link' => $this->present($sharedLink, $request->user())]);
    }

    /**
     * Revoking a link (from the share dialog, or the lists).
     */
    public function destroy(Request $request, SharedLink $sharedLink): JsonResponse|RedirectResponse
    {
        Gate::authorize('delete', $sharedLink);

        $sharedLink->delete();

        if ($request->expectsJson()) {
            return response()->json(null, 204);
        }

        return back()->with('status', 'link-revoked');
    }

    public function mine(Request $request): View
    {
        return view('shared-links.index', [
            'heading' => 'Shared Links',
            'links' => SharedLink::active()->whereBelongsTo($request->user())->with('user')->latest()->get(),
            'showCreator' => false,
        ]);
    }

    public function all(): View
    {
        return view('shared-links.index', [
            'heading' => 'Manage Shared Links',
            'links' => SharedLink::active()->with('user')->latest()->get(),
            'showCreator' => true,
        ]);
    }

    private function findUsableLink(string $token): SharedLink
    {
        $link = SharedLink::where('token', $token)->with('user')->first();
        if ($link === null || !$link->isUsable()) {
            abort(404);
        }

        return $link;
    }

    /**
     * The file's index entry, unless the index has fallen behind the filesystem (in which case what was generated is
     * for the old contents).
     */
    private function findCurrentFileEntry(string $path, \SplFileInfo $pathInfo): ?IndexedFile
    {
        $fileEntry = IndexedFile::query()->atPath($path)->first();

        return ($fileEntry?->isIndexOutdated($pathInfo) === false) ? $fileEntry : null;
    }

    /**
     * The requested file, which the user must be able to see with their own access (not one an admin is browsing as,
     * nor a shared link), since links let others in through their creator's access.
     */
    private function validatedSharablePath(Request $request): string
    {
        $validated = $request->validate([
            'path' => ['required', 'string'],
        ]);

        $path = Path::resolve($validated['path']);

        if (!$request->user()->getPathAccessInfo($path)[''] || !is_file(config('media.path').'/'.$path)) {
            abort(404);
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SharedLink $link, User $viewer): array
    {
        return [
            'id' => $link->getKey(),
            'url' => $link->url(),
            'note' => $link->note,
            'createdAt' => $link->created_at->toIso8601String(),
            'expiresAt' => $link->expires_at->toIso8601String(),
            'creator' => ($link->user_id !== $viewer->getKey()) ? ($link->user->note ?: $link->user->username) : null,
            'apiUrl' => route('shared-links.update', ['sharedLink' => $link]),
        ];
    }
}
