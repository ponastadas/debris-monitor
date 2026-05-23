<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePageRequest;
use App\Http\Requests\Admin\UpdatePageRequest;
use App\Models\AdminAuditLog;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AdminPageController extends Controller
{
    public function index(): JsonResponse
    {
        $pages = Page::orderByDesc('updated_at')
            ->get()
            ->map(fn (Page $p) => $this->pageResource($p));

        return $this->success($pages);
    }

    public function show(Page $page): JsonResponse
    {
        return $this->success($this->pageResource($page));
    }

    public function store(StorePageRequest $request): JsonResponse
    {
        $admin = auth('admin')->user();
        $data = $request->validated();

        // Auto-generate slug from title if not provided or empty
        if (empty($data['slug'])) {
            $data['slug'] = $this->uniqueSlug(Str::slug($data['title']));
        }

        // Ensure status is always set so the in-memory instance reflects the DB default
        $data['status'] ??= 'draft';

        $page = Page::create($data);

        AdminAuditLog::record(
            $admin->id,
            AdminAuditLog::PAGE_CREATED,
            'Page',
            $page->id,
            ['title' => $page->title, 'slug' => $page->slug],
        );

        return $this->success($this->pageResource($page), 201);
    }

    public function update(UpdatePageRequest $request, Page $page): JsonResponse
    {
        $admin = auth('admin')->user();
        $data = $request->validated();

        // Regenerate slug if title changed and no explicit slug was provided
        if (isset($data['title']) && ! isset($data['slug'])) {
            $newSlug = Str::slug($data['title']);
            if ($newSlug !== $page->slug) {
                $data['slug'] = $this->uniqueSlug($newSlug, $page->id);
            }
        }

        $page->update($data);

        AdminAuditLog::record(
            $admin->id,
            AdminAuditLog::PAGE_UPDATED,
            'Page',
            $page->id,
            ['title' => $page->title, 'fields' => array_keys($data)],
        );

        return $this->success($this->pageResource($page->fresh()));
    }

    public function destroy(Page $page): JsonResponse
    {
        $admin = auth('admin')->user();

        AdminAuditLog::record(
            $admin->id,
            AdminAuditLog::PAGE_DELETED,
            'Page',
            $page->id,
            ['title' => $page->title, 'slug' => $page->slug],
        );

        $page->delete();

        return $this->success(null);
    }

    public function publish(Page $page): JsonResponse
    {
        $admin = auth('admin')->user();

        $page->update([
            'status' => 'published',
            'published_at' => $page->published_at ?? now(),
        ]);

        AdminAuditLog::record(
            $admin->id,
            AdminAuditLog::PAGE_PUBLISHED,
            'Page',
            $page->id,
            ['title' => $page->title, 'slug' => $page->slug],
        );

        return $this->success($this->pageResource($page->fresh()));
    }

    public function unpublish(Page $page): JsonResponse
    {
        $admin = auth('admin')->user();

        $page->update(['status' => 'draft']);

        AdminAuditLog::record(
            $admin->id,
            AdminAuditLog::PAGE_UNPUBLISHED,
            'Page',
            $page->id,
            ['title' => $page->title, 'slug' => $page->slug],
        );

        return $this->success($this->pageResource($page->fresh()));
    }

    private function pageResource(Page $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'excerpt' => $page->excerpt,
            'content' => $page->content,
            'status' => $page->status,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'published_at' => $page->published_at?->toIso8601String(),
            'created_at' => $page->created_at->toIso8601String(),
            'updated_at' => $page->updated_at->toIso8601String(),
        ];
    }

    /** Generate a unique slug, appending -N suffix if necessary. */
    private function uniqueSlug(string $base, ?int $excludeId = null): string
    {
        $slug = $base;
        $counter = 2;

        while (
            Page::where('slug', $slug)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
