<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\JsonResponse;

/**
 * Public read-only page endpoint.
 * Only published pages are accessible. Drafts return 404.
 */
class PageController extends Controller
{
    /** List all published pages (title, slug, excerpt — no content). */
    public function index(): JsonResponse
    {
        $pages = Page::published()
            ->orderBy('title')
            ->get()
            ->map(fn (Page $p) => [
                'title' => $p->title,
                'slug' => $p->slug,
                'excerpt' => $p->excerpt,
            ]);

        return $this->success($pages);
    }

    /** Return a single published page by slug. */
    public function show(string $slug): JsonResponse
    {
        $page = Page::published()->where('slug', $slug)->firstOrFail();

        return $this->success([
            'title' => $page->title,
            'slug' => $page->slug,
            'excerpt' => $page->excerpt,
            'content' => $page->content,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'published_at' => $page->published_at?->toIso8601String(),
            'updated_at' => $page->updated_at->toIso8601String(),
        ]);
    }
}
