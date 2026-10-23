<?php

namespace App\Providers;

use App\Models\Document;
use App\Models\Folder;
use App\Models\User;
use App\Policies\ConnectorPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\FolderPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('connector', fn (Request $r) => Limit::perMinute(120)->by('connector:'.$r->attributes->get('connector')->id));
        RateLimiter::for('signin', fn (Request $r) => [Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()), Limit::perMinute(30)->by('signin-ip:'.$r->ip())]);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Folder::class, FolderPolicy::class);

        // Administrative sections and account/connector management.
        Gate::define('admin.access', [UserPolicy::class, 'viewAdmin']);
        Gate::define('admin.section', [UserPolicy::class, 'viewAdminSection']);
        Gate::define('accounts.manage', [UserPolicy::class, 'manageAccounts']);
        Gate::define('accounts.role', [UserPolicy::class, 'createRole']);
        Gate::define('accounts.source', [UserPolicy::class, 'createSource']);
        Gate::define('audit.view', [UserPolicy::class, 'viewAudit']);
        Gate::define('connectors.manage', [ConnectorPolicy::class, 'viewAny']);
        Gate::define('workspaces.access', [UserPolicy::class, 'accessWorkspace']);
        Gate::define('workspace.history', [UserPolicy::class, 'sourceHistory']);

        // Document abilities. These take the query-builder row because documents
        // are not modelled as an Eloquent resource in this codebase.
        Gate::define('documents.write', [DocumentPolicy::class, 'writeAny']);
        Gate::define('documents.batch', [DocumentPolicy::class, 'batchAny']);
        Gate::define('documents.share', [DocumentPolicy::class, 'shareAny']);
        Gate::define('documents.comment', [DocumentPolicy::class, 'commentAny']);
        Gate::define('document.view', [DocumentPolicy::class, 'view']);
        Gate::define('document.create', [DocumentPolicy::class, 'create']);
        Gate::define('document.edit', [DocumentPolicy::class, 'edit']);
        Gate::define('document.classify', [DocumentPolicy::class, 'classify']);
        Gate::define('document.manage', [DocumentPolicy::class, 'manage']);
        Gate::define('document.delete', [DocumentPolicy::class, 'manage']);
        Gate::define('document.restore', [DocumentPolicy::class, 'manage']);
        Gate::define('document.share', [DocumentPolicy::class, 'share']);
        Gate::define('document.comment', [DocumentPolicy::class, 'comment']);
        Gate::define('document.star', [DocumentPolicy::class, 'star']);
        Gate::define('document.all-versions', [DocumentPolicy::class, 'allVersions']);

        // Folder management remains delegated to scoped Admins.
        Gate::define('folders.manage', [FolderPolicy::class, 'manageAny']);
        Gate::define('folder.create', [FolderPolicy::class, 'create']);
        Gate::define('folder.update', [FolderPolicy::class, 'update']);
        Gate::define('folder.share', [FolderPolicy::class, 'share']);
    }
}
