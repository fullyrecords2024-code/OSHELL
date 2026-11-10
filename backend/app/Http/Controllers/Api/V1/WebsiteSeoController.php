<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class WebsiteSeoController extends Controller
{
    private const PUBLIC_PAGES = ['about', 'services', 'shop', 'rewards', 'testimonials', 'journal', 'contact'];

    public function robots(): Response
    {
        $sitemapUrl = rtrim((string) config('services.public_site_url'), '/').'/sitemap.xml';

        return response(
            "User-agent: *\nAllow: /\nDisallow: /staff\nDisallow: /api/\nSitemap: {$sitemapUrl}\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    public function sitemap(): Response
    {
        $baseUrl = rtrim((string) config('services.public_site_url'), '/');
        $organizations = Organization::query()
            ->where('is_active', true)
            ->orderBy('slug')
            ->get(['id', 'slug', 'updated_at']);
        $urls = [];

        foreach ($organizations as $organization) {
            $prefix = '/'.rawurlencode($organization->slug);
            $urls[] = [$prefix, $organization->updated_at];
            foreach (self::PUBLIC_PAGES as $page) {
                $urls[] = [$prefix.'/'.$page, $organization->updated_at];
            }

            $pages = DB::table('website_pages')
                ->where('organization_id', $organization->id)
                ->where('page_type', 'page')
                ->whereNotIn('slug', ['home', ...self::PUBLIC_PAGES])
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now('UTC'))
                ->get(['slug', 'updated_at']);
            foreach ($pages as $page) {
                $urls[] = [$prefix.'/'.rawurlencode($page->slug), $page->updated_at];
            }

            $articles = DB::table('website_pages')
                ->where('organization_id', $organization->id)
                ->where('page_type', 'journal')
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now('UTC'))
                ->get(['slug', 'updated_at']);
            foreach ($articles as $article) {
                $urls[] = [$prefix.'/journal/'.rawurlencode($article->slug), $article->updated_at];
            }

            $products = DB::table('inventory_products')
                ->where('organization_id', $organization->id)
                ->where('is_active', true)
                ->where('is_public', true)
                ->whereNotNull('slug')
                ->get(['slug', 'updated_at']);
            foreach ($products as $product) {
                $urls[] = [$prefix.'/shop/'.rawurlencode($product->slug), $product->updated_at];
            }

            $services = DB::table('services')
                ->where('organization_id', $organization->id)
                ->where('is_active', true)
                ->whereNotNull('slug')
                ->whereExists(static fn ($query) => $query
                    ->select(DB::raw(1))
                    ->from('service_branches')
                    ->whereColumn('service_branches.service_id', 'services.id')
                    ->where('service_branches.is_available', true)
                    ->where('service_branches.is_public', true))
                ->get(['slug', 'updated_at']);
            foreach ($services as $service) {
                $urls[] = [$prefix.'/services/'.rawurlencode($service->slug), $service->updated_at];
            }
        }

        $entries = collect($urls)->map(function (array $item) use ($baseUrl): string {
            [$path, $updatedAt] = $item;
            $lastmod = $updatedAt ? date(DATE_ATOM, strtotime((string) $updatedAt)) : null;

            return '<url><loc>'.$this->xml($baseUrl.$path).'</loc>'
                .($lastmod ? '<lastmod>'.$this->xml($lastmod).'</lastmod>' : '')
                .'</url>';
        })->implode('');

        return response(
            '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$entries.'</urlset>',
            200,
            ['Content-Type' => 'application/xml; charset=UTF-8'],
        );
    }

    public function resolveRedirect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_slug' => ['required', 'string', 'max:160'],
            'source_path' => ['required', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/'],
        ]);
        $organization = Organization::query()
            ->where('slug', $data['organization_slug'])
            ->where('is_active', true)
            ->firstOrFail();
        $redirect = DB::table('website_redirects')
            ->where('organization_id', $organization->id)
            ->where('source_path', $data['source_path'])
            ->where('is_active', true)
            ->first(['target_path', 'status_code']);

        return response()->json(['redirect' => $redirect]);
    }

    public function redirect(string $organizationSlug, ?string $path = null): Response
    {
        $organization = Organization::query()
            ->where('slug', $organizationSlug)
            ->where('is_active', true)
            ->first();
        abort_if($organization === null, 404);
        $sourcePath = '/'.ltrim((string) $path, '/');
        $redirect = DB::table('website_redirects')
            ->where('organization_id', $organization->id)
            ->where('source_path', $sourcePath)
            ->where('is_active', true)
            ->first(['target_path', 'status_code']);
        abort_if($redirect === null, 404);

        $url = rtrim((string) config('services.public_site_url'), '/')
            .'/'.rawurlencode($organization->slug)
            .$redirect->target_path;

        return redirect()->away($url, (int) $redirect->status_code);
    }

    public function recordPageView(Request $request): Response
    {
        $data = $request->validate([
            'organization_slug' => ['required', 'string', 'max:160'],
            'anonymous_id' => ['required', 'uuid'],
            'event_id' => ['required', 'uuid'],
            'event_name' => ['required', Rule::in(['page_view'])],
            'page_path' => ['required', 'string', 'max:500', 'regex:/^\/[A-Za-z0-9_-]*(?:\/[A-Za-z0-9_-]+)*$/'],
        ]);
        $organization = Organization::query()
            ->where('slug', $data['organization_slug'])
            ->where('is_active', true)
            ->firstOrFail();
        $hasConsent = DB::table('website_privacy_consents')
            ->where('organization_id', $organization->id)
            ->where('anonymous_id', $data['anonymous_id'])
            ->where('analytics_allowed', true)
            ->exists();
        if (! $hasConsent) {
            return response()->noContent();
        }

        DB::table('website_analytics_events')->insertOrIgnore([
            'organization_id' => $organization->id,
            'event_id' => $data['event_id'],
            'anonymous_id' => $data['anonymous_id'],
            'event_name' => $data['event_name'],
            'page_path' => $data['page_path'],
            'occurred_at' => now('UTC'),
        ]);

        return response()->noContent();
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
