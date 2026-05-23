<?php

use App\Models\AdminAccount;
use App\Models\AdminAuditLog;
use App\Models\Page;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Return an admin bearer token. */
function adminToken(): array
{
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    return [$admin, $token];
}

/** Create a published page with the given overrides. */
function publishedPage(array $attrs = []): Page
{
    return Page::create(array_merge([
        'title' => 'Test Page',
        'slug' => 'test-page',
        'content' => '# Hello',
        'status' => 'published',
        'published_at' => now(),
    ], $attrs));
}

/** Create a draft page with the given overrides. */
function draftPage(array $attrs = []): Page
{
    return Page::create(array_merge([
        'title' => 'Draft Page',
        'slug' => 'draft-page',
        'content' => '# Draft',
        'status' => 'draft',
    ], $attrs));
}

// ── Public endpoint: list pages ───────────────────────────────────────────────

it('public index returns only published pages', function () {
    publishedPage(['slug' => 'pub-a', 'title' => 'Pub A']);
    publishedPage(['slug' => 'pub-b', 'title' => 'Pub B']);
    draftPage(['slug' => 'hidden-draft', 'title' => 'Hidden Draft']);

    $res = $this->getJson('/api/pages')->assertOk();

    $slugs = collect($res->json('data'))->pluck('slug')->toArray();

    expect($slugs)->toContain('pub-a')
        ->toContain('pub-b')
        ->not->toContain('hidden-draft');
});

it('public index does not expose page content or meta fields', function () {
    publishedPage(['slug' => 'no-content', 'title' => 'No Content Page']);

    $res = $this->getJson('/api/pages')->assertOk();

    $page = collect($res->json('data'))->firstWhere('slug', 'no-content');

    expect($page)->toHaveKeys(['title', 'slug', 'excerpt'])
        ->not->toHaveKey('content')
        ->not->toHaveKey('meta_title')
        ->not->toHaveKey('meta_description');
});

it('public index returns pages sorted alphabetically by title', function () {
    publishedPage(['slug' => 'zzz', 'title' => 'Zzz Page']);
    publishedPage(['slug' => 'aaa', 'title' => 'Aaa Page']);
    publishedPage(['slug' => 'mmm', 'title' => 'Mmm Page']);

    $res = $this->getJson('/api/pages')->assertOk();

    $titles = collect($res->json('data'))->pluck('title')->toArray();

    expect($titles)->toBe(array_values(array_filter(
        $titles,
        fn ($t) => in_array($t, ['Aaa Page', 'Mmm Page', 'Zzz Page'])
    )));

    // Simpler: verify relative order
    $pos = array_flip($titles);
    expect($pos['Aaa Page'])->toBeLessThan($pos['Mmm Page'])
        ->and($pos['Mmm Page'])->toBeLessThan($pos['Zzz Page']);
});

// ── Public endpoint: show page by slug ────────────────────────────────────────

it('public show returns a published page with content', function () {
    publishedPage([
        'slug' => 'privacy-policy',
        'title' => 'Privacy Policy',
        'excerpt' => 'Our privacy policy.',
        'content' => '# Privacy\nWe respect your data.',
        'meta_title' => 'Privacy | SatView',
        'meta_description' => 'Our privacy policy description.',
    ]);

    $this->getJson('/api/pages/privacy-policy')
        ->assertOk()
        ->assertJsonPath('data.title', 'Privacy Policy')
        ->assertJsonPath('data.slug', 'privacy-policy')
        ->assertJsonPath('data.excerpt', 'Our privacy policy.')
        ->assertJsonPath('data.meta_title', 'Privacy | SatView')
        ->assertJsonPath('data.meta_description', 'Our privacy policy description.');

    $content = $this->getJson('/api/pages/privacy-policy')->json('data.content');
    expect($content)->toContain('Privacy');
});

it('public show returns 404 for a draft page', function () {
    draftPage(['slug' => 'secret-draft']);

    $this->getJson('/api/pages/secret-draft')->assertNotFound();
});

it('public show returns 404 for a non-existent slug', function () {
    $this->getJson('/api/pages/does-not-exist')->assertNotFound();
});

it('public show requires no auth token', function () {
    publishedPage(['slug' => 'open-page', 'title' => 'Open Page']);

    // No withToken() — ensure it's genuinely public
    $this->getJson('/api/pages/open-page')->assertOk();
});

// ── Admin endpoint: create page ───────────────────────────────────────────────

it('admin can create a page with all fields', function () {
    [, $token] = adminToken();

    $this->withToken($token)
        ->postJson('/api/admin/pages', [
            'title' => 'New Page',
            'slug' => 'new-page',
            'excerpt' => 'A short description.',
            'content' => '# New Page\nContent here.',
            'meta_title' => 'New Page | SatView',
            'meta_description' => 'SEO description.',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.title', 'New Page')
        ->assertJsonPath('data.slug', 'new-page')
        ->assertJsonPath('data.status', 'draft'); // default status
});

it('admin create auto-generates slug from title when slug is omitted', function () {
    [, $token] = adminToken();

    $this->withToken($token)
        ->postJson('/api/admin/pages', [
            'title' => 'My Awesome Page',
            'content' => 'Some content.',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.slug', 'my-awesome-page');
});

it('admin create resolves slug collision by appending a counter', function () {
    Page::create(['title' => 'Existing', 'slug' => 'collision', 'content' => 'x', 'status' => 'draft']);

    [, $token] = adminToken();

    // First collision → -2
    $this->withToken($token)
        ->postJson('/api/admin/pages', ['title' => 'Collision', 'content' => 'y'])
        ->assertStatus(201)
        ->assertJsonPath('data.slug', 'collision-2');

    // Second collision → -3
    $this->withToken($token)
        ->postJson('/api/admin/pages', ['title' => 'Collision', 'content' => 'z'])
        ->assertStatus(201)
        ->assertJsonPath('data.slug', 'collision-3');
});

it('admin create rejects a slug that does not match the allowed pattern', function () {
    [, $token] = adminToken();

    $res = $this->withToken($token)
        ->postJson('/api/admin/pages', [
            'title' => 'Bad Slug Test',
            'slug' => 'Bad Slug With Spaces!',
            'content' => 'content',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect($res->json('error.details'))->toHaveKey('slug');
});

it('admin create rejects a duplicate explicit slug', function () {
    Page::create(['title' => 'Taken', 'slug' => 'taken-slug', 'content' => 'x', 'status' => 'draft']);

    [, $token] = adminToken();

    $res = $this->withToken($token)
        ->postJson('/api/admin/pages', [
            'title' => 'Another Page',
            'slug' => 'taken-slug',
            'content' => 'content',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect($res->json('error.details'))->toHaveKey('slug');
});

it('admin create requires title and content', function () {
    [, $token] = adminToken();

    $res = $this->withToken($token)
        ->postJson('/api/admin/pages', [])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect($res->json('error.details'))->toHaveKeys(['title', 'content']);
});

// ── Admin endpoint: update page ───────────────────────────────────────────────

it('admin can update page fields', function () {
    [, $token] = adminToken();
    $page = draftPage();

    $this->withToken($token)
        ->patchJson("/api/admin/pages/{$page->id}", [
            'title' => 'Updated Title',
            'excerpt' => 'Updated excerpt.',
            'content' => 'Updated content.',
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated Title')
        ->assertJsonPath('data.excerpt', 'Updated excerpt.');
});

it('admin update auto-generates new slug when title changes and no slug is provided', function () {
    [, $token] = adminToken();
    $page = Page::create([
        'title' => 'Original Title',
        'slug' => 'original-title',
        'content' => 'x',
        'status' => 'draft',
    ]);

    $this->withToken($token)
        ->patchJson("/api/admin/pages/{$page->id}", ['title' => 'Brand New Title'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'brand-new-title');
});

it('admin update preserves existing slug when only content changes', function () {
    [, $token] = adminToken();
    $page = Page::create([
        'title' => 'Keep Slug',
        'slug' => 'keep-this-slug',
        'content' => 'old',
        'status' => 'draft',
    ]);

    $this->withToken($token)
        ->patchJson("/api/admin/pages/{$page->id}", ['content' => 'new content'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'keep-this-slug');
});

it('admin update allows keeping the same slug on the same page (no false collision)', function () {
    [, $token] = adminToken();
    $page = Page::create([
        'title' => 'Sticky Slug',
        'slug' => 'sticky-slug',
        'content' => 'x',
        'status' => 'draft',
    ]);

    $this->withToken($token)
        ->patchJson("/api/admin/pages/{$page->id}", [
            'title' => 'Sticky Slug',
            'slug' => 'sticky-slug',
        ])
        ->assertOk()
        ->assertJsonPath('data.slug', 'sticky-slug');
});

it('admin update rejects a slug already used by a different page', function () {
    Page::create(['title' => 'Other', 'slug' => 'other-slug', 'content' => 'x', 'status' => 'draft']);
    $page = draftPage(['slug' => 'my-slug']);

    [, $token] = adminToken();

    $res = $this->withToken($token)
        ->patchJson("/api/admin/pages/{$page->id}", ['slug' => 'other-slug'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    expect($res->json('error.details'))->toHaveKey('slug');
});

// ── Admin endpoint: publish / unpublish ──────────────────────────────────────

it('admin can publish a draft page', function () {
    [, $token] = adminToken();
    $page = draftPage();

    $this->withToken($token)
        ->postJson("/api/admin/pages/{$page->id}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    expect($page->fresh()->status)->toBe('published')
        ->and($page->fresh()->published_at)->not->toBeNull();
});

it('publish preserves the original published_at if already set', function () {
    $originalTime = now()->subDays(30);
    [, $token] = adminToken();
    $page = Page::create([
        'title' => 'Old Pub',
        'slug' => 'old-pub',
        'content' => 'x',
        'status' => 'draft',
        'published_at' => $originalTime,
    ]);

    $this->withToken($token)
        ->postJson("/api/admin/pages/{$page->id}/publish")
        ->assertOk();

    $refreshed = $page->fresh();
    expect($refreshed->published_at->toDateString())->toBe($originalTime->toDateString());
});

it('admin can unpublish a published page', function () {
    [, $token] = adminToken();
    $page = publishedPage();

    $this->withToken($token)
        ->postJson("/api/admin/pages/{$page->id}/unpublish")
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');

    expect($page->fresh()->status)->toBe('draft');
});

it('unpublished page is no longer returned by the public endpoint', function () {
    [, $token] = adminToken();
    $page = publishedPage(['slug' => 'soon-hidden']);

    // Confirm it's publicly visible
    $this->getJson('/api/pages/soon-hidden')->assertOk();

    // Unpublish via admin
    $this->withToken($token)->postJson("/api/admin/pages/{$page->id}/unpublish")->assertOk();

    // Now it should be 404 publicly
    $this->getJson('/api/pages/soon-hidden')->assertNotFound();
});

// ── Admin endpoint: delete page ───────────────────────────────────────────────

it('admin can delete a page', function () {
    [, $token] = adminToken();
    $page = draftPage();

    $this->withToken($token)
        ->deleteJson("/api/admin/pages/{$page->id}")
        ->assertOk();

    expect(Page::find($page->id))->toBeNull();
});

it('deleted page is no longer returned publicly', function () {
    [, $token] = adminToken();
    $page = publishedPage(['slug' => 'about-to-vanish']);

    $this->getJson('/api/pages/about-to-vanish')->assertOk();

    $this->withToken($token)->deleteJson("/api/admin/pages/{$page->id}")->assertOk();

    $this->getJson('/api/pages/about-to-vanish')->assertNotFound();
});

// ── Admin endpoint: list pages ────────────────────────────────────────────────

it('admin index returns both draft and published pages', function () {
    [, $token] = adminToken();
    publishedPage(['slug' => 'pub-one', 'title' => 'Pub One']);
    draftPage(['slug' => 'draft-one', 'title' => 'Draft One']);

    $res = $this->withToken($token)->getJson('/api/admin/pages')->assertOk();

    $slugs = collect($res->json('data'))->pluck('slug')->toArray();

    expect($slugs)->toContain('pub-one')
        ->toContain('draft-one');
});

it('admin index includes content and all meta fields', function () {
    [, $token] = adminToken();
    publishedPage(['slug' => 'full-meta', 'title' => 'Full Meta']);

    $res = $this->withToken($token)->getJson('/api/admin/pages')->assertOk();

    $page = collect($res->json('data'))->firstWhere('slug', 'full-meta');

    expect($page)->toHaveKeys(['id', 'title', 'slug', 'excerpt', 'content', 'status', 'meta_title', 'meta_description', 'published_at', 'created_at', 'updated_at']);
});

// ── Authorization: unauthenticated requests rejected ─────────────────────────

it('unauthenticated request to admin pages list returns 401', function () {
    $this->getJson('/api/admin/pages')->assertUnauthorized();
});

it('unauthenticated create is rejected', function () {
    $this->postJson('/api/admin/pages', ['title' => 'x', 'content' => 'y'])
        ->assertUnauthorized();
});

it('unauthenticated update is rejected', function () {
    $page = draftPage();
    $this->patchJson("/api/admin/pages/{$page->id}", ['title' => 'x'])
        ->assertUnauthorized();
});

it('unauthenticated delete is rejected', function () {
    $page = draftPage();
    $this->deleteJson("/api/admin/pages/{$page->id}")
        ->assertUnauthorized();
});

it('unauthenticated publish is rejected', function () {
    $page = draftPage();
    $this->postJson("/api/admin/pages/{$page->id}/publish")
        ->assertUnauthorized();
});

it('customer bearer token cannot access admin page endpoints', function () {
    $user = User::factory()->create();
    $token = $user->createToken('customer')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/admin/pages')
        ->assertUnauthorized();
});

// ── Audit logging ─────────────────────────────────────────────────────────────

it('records page.created when admin creates a page', function () {
    [$admin, $token] = adminToken();

    $this->withToken($token)
        ->postJson('/api/admin/pages', [
            'title' => 'Audit Create Test',
            'content' => 'content',
        ])
        ->assertStatus(201);

    $log = AdminAuditLog::forAction(AdminAuditLog::PAGE_CREATED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->target_type)->toBe('Page')
        ->and($log->metadata['title'])->toBe('Audit Create Test');
});

it('records page.updated when admin updates a page', function () {
    [$admin, $token] = adminToken();
    $page = draftPage();

    $this->withToken($token)
        ->patchJson("/api/admin/pages/{$page->id}", ['title' => 'Audited Update'])
        ->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::PAGE_UPDATED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->target_type)->toBe('Page')
        ->and($log->target_id)->toBe($page->id)
        ->and($log->metadata['fields'])->toContain('title');
});

it('records page.published when admin publishes a page', function () {
    [$admin, $token] = adminToken();
    $page = draftPage();

    $this->withToken($token)
        ->postJson("/api/admin/pages/{$page->id}/publish")
        ->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::PAGE_PUBLISHED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->target_id)->toBe($page->id)
        ->and($log->metadata['slug'])->toBe($page->slug);
});

it('records page.unpublished when admin unpublishes a page', function () {
    [$admin, $token] = adminToken();
    $page = publishedPage();

    $this->withToken($token)
        ->postJson("/api/admin/pages/{$page->id}/unpublish")
        ->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::PAGE_UNPUBLISHED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->target_id)->toBe($page->id);
});

it('records page.deleted when admin deletes a page', function () {
    [$admin, $token] = adminToken();
    $page = draftPage(['title' => 'To Be Deleted', 'slug' => 'to-be-deleted']);

    $this->withToken($token)
        ->deleteJson("/api/admin/pages/{$page->id}")
        ->assertOk();

    $log = AdminAuditLog::forAction(AdminAuditLog::PAGE_DELETED)
        ->forActor($admin->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->target_id)->toBe($page->id)
        ->and($log->metadata['title'])->toBe('To Be Deleted')
        ->and($log->metadata['slug'])->toBe('to-be-deleted');
});

it('audit log captures page title and slug on deletion even after the record is gone', function () {
    [$admin, $token] = adminToken();
    $page = draftPage(['title' => 'Ephemeral', 'slug' => 'ephemeral']);
    $pageId = $page->id;

    $this->withToken($token)
        ->deleteJson("/api/admin/pages/{$pageId}")
        ->assertOk();

    // The page row is gone
    expect(Page::find($pageId))->toBeNull();

    // But the audit log still has the title and slug
    $log = AdminAuditLog::forAction(AdminAuditLog::PAGE_DELETED)->first();
    expect($log->metadata['title'])->toBe('Ephemeral')
        ->and($log->metadata['slug'])->toBe('ephemeral');
});

// ── Page model scope ──────────────────────────────────────────────────────────

it('scopePublished only returns pages with status=published', function () {
    publishedPage(['slug' => 'p1']);
    publishedPage(['slug' => 'p2']);
    draftPage(['slug' => 'd1']);

    $published = Page::published()->get();

    expect($published->count())->toBe(2)
        ->and($published->pluck('slug')->toArray())->each->toBeIn(['p1', 'p2']);
});
