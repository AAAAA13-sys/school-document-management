<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class FrontendViewTest extends TestCase
{
    private function renderScreen(string $path, string $source, string $section, ?string $document = null): string
    {
        // An accidental database read must fail; these are pure presentation fixtures.
        config(['database.default' => 'frontend-must-not-query', 'session.driver' => 'array']);
        $request = Request::create($path, 'GET');
        $this->app->instance('request', $request);
        $session = $this->app['session']->driver();
        $session->start();
        $request->setLaravelSession($session);
        View::share('errors', new ViewErrorBag);
        $fixtures = require resource_path('preview/data.php');
        $view = $document ? 'document' : (str_starts_with($request->path(), 'admin') ? 'admin' : 'workspace');

        return view($view, $fixtures($source, $section, $document))->render();
    }

    public function test_four_workspaces_render_from_one_blade_template_without_database_access(): void
    {
        foreach (['EMS', 'PMS', 'ES', 'OAS'] as $source) {
            foreach (['overview', 'documents', 'history', 'snapshots'] as $section) {
                $html = $this->renderScreen('/workspaces/'.strtolower($source).'/'.$section, $source, $section);
                $this->assertStringContainsString('PLP Documents', $html);
                $this->assertStringContainsString('data-source="'.$source.'"', $html);
                $this->assertStringNotContainsString('www.figma.com/api/mcp/asset', $html);
                $this->assertStringNotContainsString('Scan file', $html);
                if (in_array($section, ['overview', 'documents'])) {
                    $this->assertStringContainsString('data-multi-upload="true"', $html);
                    $this->assertStringNotContainsString('id="upload-name"', $html);
                    $this->assertStringContainsString('id="upload-class" name="classification"', $html);
                    $this->assertStringContainsString('<option selected>Confidential</option>', $html);
                    $this->assertStringContainsString('<option>Restricted</option>', $html);
                    $this->assertStringContainsString('<option>Internal</option>', $html);
                    $this->assertStringNotContainsString('id="upload-reference"', $html);
                }
                $this->assertValidMarkup($html);
            }
        }
    }

    public function test_admin_pages_and_dialogs_render_without_database_access(): void
    {
        foreach (['overview', 'repository', 'accounts', 'connectors', 'scans', 'audit'] as $section) {
            $html = $this->renderScreen('/admin/'.$section, 'ALL', $section);
            $this->assertStringContainsString('PLP Documents', $html);
            $this->assertValidMarkup($html);
            if ($section === 'scans') {
                $this->assertStringContainsString('Scanning runs automatically in the background', $html);
                $this->assertStringContainsString('Quarantined', $html);
            }
        }
    }

    public function test_document_details_keep_unclean_downloads_disabled_and_support_sharing(): void
    {
        $id = '00000001-0000-4000-8000-000000000001';
        $html = $this->renderScreen('/documents/'.$id, 'EMS', 'documents', $id);
        foreach (['Version history', 'share-modal', 'share-search', 'datepicker', 'Upload new version', 'SHA-256 verified'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(4, $xpath->query('//*[@id="document-tabs"]/ul/li/a')->length);
        foreach (['file-details', 'file-versions', 'file-preview', 'file-shares'] as $panel) {
            $this->assertSame(1, $xpath->query('//*[@id="'.$panel.'"]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="document-tabs"]/ul/li/a[@href="#'.$panel.'"]')->length);
        }
        foreach (['Subject', 'Category:', 'Checksum', 'Created', 'Modified:', 'Owner', 'No expiry'] as $metadata) {
            $this->assertStringContainsString($metadata, $html);
        }
        $this->assertStringContainsString('id="detail-download"', $html);
        $this->assertValidMarkup($html);
        $blocked = '00000004-0000-4000-8000-000000000004';
        $html = $this->renderScreen('/documents/'.$blocked, 'OAS', 'documents', $blocked);
        $this->assertStringContainsString('Quarantined', $html);
        $this->assertStringNotContainsString('data-demo-download', $html);
        $this->assertStringNotContainsString('id="detail-download"', $html);
        $this->assertStringContainsString('disabled>Blocked', $html);
    }

    public function test_library_views_folders_and_empty_state_render(): void
    {
        foreach (['all', 'mine', 'shared', 'starred', 'trash'] as $view) {
            $html = $this->renderScreen('/workspaces/ems/documents?view='.$view, 'EMS', 'documents');
            $this->assertValidMarkup($html);
            $this->assertStringContainsString('document-empty', $html);
        }
        $html = $this->renderScreen('/workspaces/ems/documents?folder=00000001-1111-4000-8000-000000000001', 'EMS', 'documents');
        $this->assertStringContainsString('2026 appointments', $html);
        $this->assertStringContainsString('folder-edit-modal', $html);
    }

    public function test_sign_in_view_renders_without_database_access(): void
    {
        config(['database.default' => 'frontend-must-not-query', 'session.driver' => 'array']);
        $request = Request::create('/login', 'GET');
        $this->app->instance('request', $request);
        $session = $this->app['session']->driver();
        $session->start();
        $request->setLaravelSession($session);
        View::share('errors', new ViewErrorBag);
        $html = view('login')->render();
        $this->assertStringContainsString('A home for every', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertValidMarkup($html);
    }

    private function assertValidMarkup(string $html): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $id = $node->getAttribute('id');
            $this->assertArrayNotHasKey($id, $ids, 'Duplicate id '.$id);
            $ids[$id] = true;
        }
        foreach ($xpath->query('//img') as $image) {
            $src = $image->getAttribute('src');
            $this->assertFileExists(public_path(ltrim($src, '/')));
            $this->assertNotEmpty($image->getAttribute('width'));
            $this->assertNotEmpty($image->getAttribute('height'));
            $svg = file_get_contents(public_path(ltrim($src, '/')));
            preg_match('/<svg[^>]*width="([0-9.]+)"[^>]*height="([0-9.]+)"/', $svg, $geometry);
            $this->assertNotEmpty($geometry, 'Missing SVG root dimensions: '.$src);
            $this->assertEquals((float) $geometry[1], (float) $image->getAttribute('width'), 'SVG width: '.$src);
            $this->assertEquals((float) $geometry[2], (float) $image->getAttribute('height'), 'SVG height: '.$src);
        }
        foreach ($xpath->query('//*[@data-bs-target]') as $node) {
            $target = ltrim($node->getAttribute('data-bs-target'), '#');
            $this->assertArrayHasKey($target, $ids, 'Missing dialog '.$target);
        }
        $this->assertStringNotContainsString('Â·', $html);
        $this->assertStringNotContainsString('â€™', $html);
    }
}
