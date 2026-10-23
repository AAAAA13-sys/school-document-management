@php
    $viewer = $viewer ?? auth()->user();
    $isSidebarCollapsed = request()->cookie('dms_sidebar_collapsed') === 'true';
    $isAdmin = $isAdmin ?? (request()->is('admin') || request()->is('admin/*') || ($section === 'account' && ($viewer->isSuperAdmin() || $viewer->isAdmin())));
    $sources = $viewer->sources();
    $navSource = in_array($source ?? null, $sources, true) ? $source : ($sources[0] ?? $viewer->source ?? 'EMS');
    $root = $isAdmin ? '/admin' : '/workspaces/'.strtolower($navSource);
    $workspaceName = $isAdmin ? 'Administration' : (($workspaceLabels ?? App\Services\WorkspaceRegistry::labels($viewer))[$source] ?? 'Workspace');
    $workspaceCode = $isAdmin ? 'ADMIN' : $source;
    $libraryView = request('view', 'all');
    $roleName = config('dms.role_labels.'.$viewer->role);
    $initials = collect(explode(' ', $viewer->name))->take(2)->map(fn($s)=>mb_strtoupper(mb_substr($s,0,1)))->join('');
    $sections = $isAdmin
        ? ['overview'=>['home','Overview'],'repository'=>['file','School repository'],'accounts'=>['users','Staff accounts'],'connectors'=>['link','ERP connectors'],'scans'=>['shield','File security'],'audit'=>['history','Audit trail']]
        : ['overview'=>['home','Overview'],'documents'=>['file','Documents'],'history'=>['history','Received history'],'snapshots'=>['link','Current records']];
    $libraryLinks = $viewer->isSuperAdmin()
        ? ['all'=>['file','All documents'],'trash'=>['trash','Trash']]
        : ['mine'=>['folder','My documents'],'shared'=>['users','Shared with me'],'starred'=>['star','Starred'],'trash'=>['trash','Trash']];
@endphp
<!doctype html>
<html lang="en" class="{{ $isSidebarCollapsed ? 'sidebar-collapsed' : '' }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="color-scheme" content="light" />
    <title>
        @yield ('title', 'Workspace')
        · PLP Documents
    </title>
    <script>
        (function () {
            try {
                document.documentElement.classList.add('sidebar-no-transition');
                var saved = localStorage.getItem('dms_sidebar_collapsed');
                var isTablet = window.innerWidth >= 768 && window.innerWidth < 1200;
                var collapsed = saved === 'true' || (saved === null && isTablet);
                if (collapsed) {
                    document.documentElement.classList.add('sidebar-collapsed');
                } else if (saved === 'false') {
                    document.documentElement.classList.remove('sidebar-collapsed');
                }
                window.addEventListener('DOMContentLoaded', function () {
                    if (document.body) {
                        if (document.documentElement.classList.contains('sidebar-collapsed')) {
                            document.body.classList.add('sidebar-collapsed');
                        } else {
                            document.body.classList.remove('sidebar-collapsed');
                        }
                    }
                    requestAnimationFrame(function () {
                        setTimeout(function () {
                            document.documentElement.classList.remove('sidebar-no-transition');
                        }, 50);
                    });
                });
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="/vendor/bootstrap.min.css" />
    <link rel="stylesheet" href="/vendor/jquery-ui.min.css" />
    <link rel="stylesheet" href="/css/dms.css" />
    <script defer src="/vendor/jquery.min.js"></script>
    <script defer src="/vendor/jquery-ui.min.js"></script>
    <script defer src="/vendor/bootstrap.bundle.min.js"></script>
    <script defer src="/js/dms.js"></script>
</head>
<body class="{{ $isSidebarCollapsed ? 'sidebar-collapsed' : '' }}" data-section="{{ $section ?? 'workspaces' }}" data-source="{{ $source ?? 'ALL' }}">
    <a class="skip-link" href="#main">Skip to content</a>
    <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
        <div class="sidebar-header">
            <a class="brand" href="/" data-bs-toggle="tooltip" data-bs-placement="right" title="{{ $isSidebarCollapsed ? 'Expand navigation (Ctrl+B)' : 'PLP Documents · Document Management' }}" id="brand-link"
                ><span class="brand-mark">d.</span><span class="brand-text">PLP Documents<small>DOCUMENT MANAGEMENT</small></span></a
            >
            <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="Collapse navigation sidebar" aria-controls="sidebar" aria-expanded="{{ $isSidebarCollapsed ? 'false' : 'true' }}" data-bs-toggle="tooltip" data-bs-placement="right" title="Collapse navigation (Ctrl+B)">
                <span class="sidebar-toggle-icon" aria-hidden="true">«</span>
            </button>
        </div>
        <div class="workspace-label">{{ $isAdmin ? 'ADMINISTRATION':'YOUR WORKSPACE' }}</div>
        <div class="workspace-identity" tabindex="0" data-bs-toggle="tooltip" data-bs-placement="right" title="{{ $workspaceName }} · {{ $viewer->campus }} campus" aria-label="{{ $workspaceName }} · {{ $viewer->campus }} campus">
            <span class="source-icon">{{ $workspaceCode }}</span>
            <div class="workspace-info">
                <strong>{{ $workspaceName }}</strong
                ><small>{{ $viewer->campus }} campus</small>
            </div>
        </div>
        <nav class="main-nav">
            @foreach ($sections as $key=>[$glyph,$label])
                @continue($key === 'connectors' && ! $viewer->can('connectors.manage'))
                @continue($key === 'accounts' && ! $viewer->can('accounts.manage'))
                @continue($key === 'history' && ! $viewer->can('workspace.history'))
                @continue($key === 'snapshots' && ! $viewer->can('workspace.history'))
                @if ($isAdmin && $key==='scans')
                    <span class="nav-caption governance-caption">GOVERNANCE</span>
                @endif
                @php $active=$section===$key && ($key!=='documents' || $libraryView==='all'); @endphp
                <a href="{{ $root }}/{{ $key }}" class="{{ $active?'active':'' }}" @if ($active) aria-current="page" @endif data-bs-toggle="tooltip" data-bs-placement="right" title="{{ $label }}"
                    ><x-figma-icon :name="$glyph.($active?'-active':'')" /><span class="nav-label">{{ $label }}</span></a
                >
            @endforeach
            @unless ($isAdmin)
                <span class="nav-caption">{{ $viewer->isSuperAdmin() ? 'REPOSITORY':'MY LIBRARY' }}</span>
                @foreach ($libraryLinks as $key=>[$glyph,$label])
                    @php $active=$section==='documents' && $libraryView===$key; @endphp
                    <a href="{{ $root }}/documents?view={{ $key }}" class="{{ $active?'active':'' }}" @if ($active) aria-current="page" @endif data-bs-toggle="tooltip" data-bs-placement="right" title="{{ $label }}"
                        ><x-figma-icon :name="$glyph.($active && in_array($glyph,['users','trash'])?'-active':'')" /><span class="nav-label">{{ $label }}</span></a
                    >
                @endforeach
            @endunless
        </nav>
        <div class="sidebar-bottom">
            @if (($viewer->isSuperAdmin() || $viewer->isAdmin()) && !$isAdmin)
                <a class="return-admin" href="/admin" data-bs-toggle="tooltip" data-bs-placement="right" title="Administration"><span class="return-text">← Administration</span></a>
            @endif

            <a href="/approvals" class="return-admin" data-bs-toggle="tooltip" data-bs-placement="right" title="Requests and approvals"><span class="return-text">Requests and approvals</span></a>
            @if ($isAdmin)
                @if ($viewer->isSuperAdmin())<a href="/admin/workspaces" class="return-admin" data-bs-toggle="tooltip" data-bs-placement="right" title="Workspace registry"><span class="return-text">Workspace registry</span></a>@endif
            @endif
            <a href="/account" class="return-admin" data-bs-toggle="tooltip" data-bs-placement="right" title="My account"><span class="return-text">My account</span></a>
            <div class="profile" tabindex="0" data-bs-toggle="tooltip" data-bs-placement="right" title="{{ $viewer->name }} · {{ $roleName }}" aria-label="{{ $viewer->name }} · {{ $roleName }}">
                <span class="avatar">{{ $initials }}</span>
                <div class="profile-info"><strong>{{ $viewer->name }}</strong><small>{{ $roleName }}</small></div>
                <form action="/logout" method="post">
                    @csrf
                    <button type="submit" class="icon-button" aria-label="Sign out" data-bs-toggle="tooltip" data-bs-placement="right" title="Sign out">
                        <x-figma-icon name="logout" :size="18" />
                    </button>
                </form>
            </div>
        </div>
    </aside>
    <div class="sidebar-scrim" id="sidebar-scrim" hidden></div>
    <div class="app-body">
        <header class="topbar">
            <button class="icon-button mobile-menu" id="menu-toggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
                <x-figma-icon name="mobile-menu" :size="24" />
            </button>
            <a class="mobile-brand" href="/">PLP Documents</a>
            <div class="breadcrumb-line">
                @if ($isAdmin)
                    <a href="/admin">Administration</a>
                @else
                    <a href="/">School ERP</a
                    ><span>/</span
                    ><span class="breadcrumb-source">{{ $source }}</span>
                @endif
                <span>/</span><strong>{{ $sections[$section][1] ?? ($section==='account'?'My account':'Documents') }}</strong>
            </div>
            <div class="topbar-right">
                <span class="privacy-label"><x-figma-icon name="shield-small" :size="16" />Private workspace</span
                ><button
                    type="button"
                    class="icon-button notification-button"
                    data-bs-toggle="popover"
                    data-bs-title="Notifications"
                    data-bs-content="File scanning runs automatically after upload. Pending versions remain blocked until clean."
                    aria-label="Notifications">
                    <x-figma-icon name="bell" /></button
                ><span class="top-avatar">{{ $initials }}</span><span class="mobile-source">{{ $workspaceCode }}</span>
            </div>
        </header>
        <main id="main" tabindex="-1">
            @php
            // The Back button is kept only for the document preview, where a
            // deep link needs an explicit way out. Everywhere else navigation is
            // carried by the sidebar plus the "Up to ..." folder tile.
            $showBack=isset($doc);
            $backFallback=$root.'/overview';
            if(isset($doc)) $backFallback=$root.'/documents'.($doc->folder_id?'?folder='.$doc->folder_id:'');
            elseif(isset($folder) && $folder) $backFallback=$root.'/documents'.($folder->parent_id?'?folder='.$folder->parent_id:'');
            if($section==='account') $backFallback='/';
        @endphp
            @if ($showBack)
                <a class="global-back" href="{{ $backFallback }}" data-global-back aria-label="Go back">← Back</a>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    <strong>Please check the following:</strong>
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @yield ('content')
        </main>
        <footer class="app-footer"><span>Pamantasan ng Lungsod ng Pasig</span><span>All files stored privately · {{ $viewer->campus }} campus</span></footer>
    </div>

    <div class="toast-container position-fixed top-0 end-0 p-3" id="toast-container">
        <div class="toast" id="feedback-toast" role="status" aria-live="polite" aria-atomic="true">
            <div class="toast-header">
                <strong class="me-auto">PLP Documents</strong><button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">{{ session('status') }}</div>
            <div class="toast-countdown" aria-hidden="true"></div>
        </div>
    </div>
    @stack ('dialogs')
</body>
</html>
