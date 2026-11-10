<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\WebsitePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class WebsiteContentController extends Controller
{
    public function publicPage(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'organization_slug' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'alpha_dash:ascii', 'max:120'],
        ]);
        $organization = Organization::query()
            ->where('slug', $filters['organization_slug'])
            ->where('is_active', true)
            ->firstOrFail();
        $page = WebsitePage::query()
            ->where('organization_id', $organization->id)
            ->where('slug', $filters['slug'])
            ->whereIn('page_type', ['page', 'journal'])
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now('UTC'))
            ->select(['slug', 'page_type', 'title', 'eyebrow', 'summary', 'body', 'seo_title', 'seo_description', 'cover_image_path', 'published_at'])
            ->first();
        $page?->append('cover_image_url');
        $page?->makeHidden('cover_image_path');

        return response()->json(['page' => $page]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            WebsitePage::query()
                ->where('organization_id', $request->user()->organization_id)
                ->orderByDesc('updated_at')
                ->paginate(50)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedContent($request);
        $page = WebsitePage::query()->create([
            ...$data,
            'page_type' => $data['page_type'] ?? 'page',
            'organization_id' => $request->user()->organization_id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
            'status' => 'draft',
        ]);
        $this->audit($request, 'website.page.create', $page);

        return response()->json($page, 201);
    }

    public function update(Request $request, WebsitePage $page): JsonResponse
    {
        $this->assertOrganization($request, $page);
        $page->fill([
            ...$this->validatedContent($request, $page),
            'updated_by' => $request->user()->id,
        ])->save();
        $this->audit($request, 'website.page.update', $page);

        return response()->json($page->refresh());
    }

    public function setPublication(Request $request, WebsitePage $page): JsonResponse
    {
        $this->assertOrganization($request, $page);
        $data = $request->validate([
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'published_at' => ['nullable', 'date'],
        ]);
        $publishedAt = $data['status'] === 'published'
            ? ($data['published_at'] ?? $page->published_at ?? now('UTC'))
            : null;

        $page->forceFill([
            'status' => $data['status'],
            'published_at' => $publishedAt,
            'updated_by' => $request->user()->id,
        ])->save();
        $this->audit($request, 'website.page.'.$data['status'], $page);

        return response()->json($page->refresh());
    }

    public function uploadMedia(Request $request, WebsitePage $page): JsonResponse
    {
        $this->assertOrganization($request, $page);
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $path = $data['image']->store("website/{$page->organization_id}/pages", 'public');
        $oldPath = $page->cover_image_path;
        $page->forceFill([
            'cover_image_path' => $path,
            'updated_by' => $request->user()->id,
        ])->save();
        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }
        $this->audit($request, 'website.page.media.update', $page);

        return response()->json($page->refresh());
    }

    public function deleteMedia(Request $request, WebsitePage $page): JsonResponse
    {
        $this->assertOrganization($request, $page);
        $path = $page->cover_image_path;
        $page->forceFill([
            'cover_image_path' => null,
            'updated_by' => $request->user()->id,
        ])->save();
        if ($path) {
            Storage::disk('public')->delete($path);
        }
        $this->audit($request, 'website.page.media.delete', $page);

        return response()->json($page->refresh());
    }

    public function redirects(Request $request): JsonResponse
    {
        return response()->json([
            'redirects' => DB::table('website_redirects')
                ->where('organization_id', $request->user()->organization_id)
                ->orderBy('source_path')
                ->get(['id', 'source_path', 'target_path', 'status_code', 'is_active', 'created_at']),
        ]);
    }

    public function createRedirect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_path' => [
                'required',
                'string',
                'max:255',
                'regex:/^\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/',
                Rule::unique('website_redirects', 'source_path')
                    ->where('organization_id', $request->user()->organization_id),
            ],
            'target_path' => ['required', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/', 'different:source_path'],
            'status_code' => ['required', Rule::in([301, 302])],
        ]);
        $organizationId = (int) $request->user()->organization_id;
        $nextPath = $data['target_path'];
        $visitedPaths = [$data['source_path'] => true];
        while ($nextPath !== null) {
            if (isset($visitedPaths[$nextPath])) {
                throw ValidationException::withMessages([
                    'target_path' => 'This redirect would create a redirect loop.',
                ]);
            }
            $visitedPaths[$nextPath] = true;
            $nextPath = DB::table('website_redirects')
                ->where('organization_id', $organizationId)
                ->where('source_path', $nextPath)
                ->where('is_active', true)
                ->value('target_path');
        }
        $id = DB::table('website_redirects')->insertGetId([
            ...$data,
            'organization_id' => $organizationId,
            'created_by' => $request->user()->id,
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $redirect = DB::table('website_redirects')->where('id', $id)->first();
        AuditEvent::query()->create([
            'user_id' => $request->user()->id,
            'organization_id' => $organizationId,
            'branch_id' => null,
            'action' => 'website.redirect.create',
            'subject_type' => 'website_redirect',
            'subject_id' => (string) $id,
            'status_code' => 201,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => ['source_path' => $data['source_path'], 'target_path' => $data['target_path']],
            'occurred_at' => now('UTC'),
        ]);

        return response()->json(['redirect' => $redirect], 201);
    }

    public function deleteRedirect(Request $request, int $redirect): Response
    {
        $record = DB::table('website_redirects')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $redirect)
            ->first();
        abort_if($record === null, 404);
        DB::table('website_redirects')->where('id', $redirect)->delete();
        AuditEvent::query()->create([
            'user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'branch_id' => null,
            'action' => 'website.redirect.delete',
            'subject_type' => 'website_redirect',
            'subject_id' => (string) $redirect,
            'status_code' => 204,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => ['source_path' => $record->source_path, 'target_path' => $record->target_path],
            'occurred_at' => now('UTC'),
        ]);

        return response()->noContent();
    }

    private function validatedContent(Request $request, ?WebsitePage $page = null): array
    {
        return $request->validate([
            'slug' => [
                'required',
                'string',
                'alpha_dash:ascii',
                'max:120',
                Rule::unique('website_pages', 'slug')
                    ->where('organization_id', $request->user()->organization_id)
                    ->ignore($page?->id),
            ],
            'page_type' => ['sometimes', Rule::in(['page', 'journal'])],
            'title' => ['required', 'string', 'max:180'],
            'eyebrow' => ['nullable', 'string', 'max:120'],
            'summary' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:10000'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:160'],
        ]);
    }

    private function assertOrganization(Request $request, WebsitePage $page): void
    {
        abort_unless((int) $page->organization_id === (int) $request->user()->organization_id, 404);
    }

    private function audit(Request $request, string $action, WebsitePage $page): void
    {
        AuditEvent::query()->create([
            'user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'branch_id' => null,
            'action' => $action,
            'subject_type' => 'website_page',
            'subject_id' => (string) $page->id,
            'status_code' => 200,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => ['slug' => $page->slug, 'status' => $page->status],
            'occurred_at' => now('UTC'),
        ]);
    }
}
