<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="color-scheme" content="light" />
    <title>
        @yield ('title', 'Workspace')
        · PLP Documents
    </title>
    <link rel="stylesheet" href="/vendor/bootstrap.min.css" />
    <link rel="stylesheet" href="/vendor/jquery-ui.min.css" />
    <link rel="stylesheet" href="/css/dms.css" />
    <script defer src="/vendor/jquery.min.js"></script>
    <script defer src="/vendor/jquery-ui.min.js"></script>
    <script defer src="/vendor/bootstrap.bundle.min.js"></script>
    <script defer src="/js/dms.js"></script>
</head>
@php
    $viewer = $viewer ?? auth()->user();
    $personalWorkspace = $personalWorkspace ?? ($source === 'DMS' && $section === 'documents');
    $isAdmin = $source === 'DMS' && !$personalWorkspace;
    $navSource=in_array($source,$viewer->sources())?$source:$viewer->source;
    $root = $isAdmin ? '/admin' : ($personalWorkspace && $viewer->role==='super_admin' ? '/my-workspace' : '/workspaces/'.strtolower($navSource));
    $libraryView = request('view', 'all');
    $roleName = config('dms.role_labels.'.$viewer->role);
    $initials = collect(explode(' ', $viewer->name))->take(2)->map(fn($s)=>mb_strtoupper(mb_substr($s,0,1)))->join('');
    $sections = $isAdmin ? ['overview'=>['home','Overview'],'repository'=>['file','School repository'],'accounts'=>['users','Staff accounts'],'connectors'=>['link','ERP connectors'],'scans'=>['shield','File security'],'audit'=>['history','Audit trail']] : ['overview'=>['home','Overview'],'documents'=>['file','Documents'],'history'=>['history','Received history'],'snapshots'=>['link','Current records']];
    if ($personalWorkspace) $sections = ['overview'=>['home','Overview'],'documents'=>['file','Documents']];
@endphp
<body data-section="{{ $section }}" data-source="{{ $source }}" data-preview="{{ !empty($frontendPreview) ? 'true':'false' }}">
    <a class="skip-link" href="#main">Skip to content</a>
    <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
        <a class="brand" href="/"
            ><span class="brand-mark">d.</span><span>PLP Documents<small>DOCUMENT MANAGEMENT</small></span></a
        >
        <div class="workspace-label">{{ $isAdmin ? 'ADMINISTRATION':'YOUR WORKSPACE' }}</div>
        <div class="workspace-identity">
            <span class="source-icon">{{ $source }}</span>
            <div>
                <strong>{{ $isAdmin ? 'Administration':($personalWorkspace?'My workspace':config('dms.sources.'.$source)) }}</strong
                ><small>{{ $viewer->campus }} campus</small>
            </div>
        </div>
        <nav class="main-nav">
            @if ($viewer->role==='super_admin' && !$personalWorkspace)
                <a href="/my-workspace"><x-figma-icon name="folder" /><span>My workspace</span></a>
            @endif
            @foreach ($sections as $key=>[$glyph,$label])
                @if (($viewer->role!=='staff' || !in_array($key,['history','snapshots'])) && !($viewer->role==='admin' && $key==='connectors'))
                    @if ($isAdmin && $key==='scans')
                        <span class="nav-caption governance-caption">GOVERNANCE</span>
                    @endif
                    @php $active=$section===$key && ($key!=='documents' || $libraryView==='all'); @endphp
                    <a href="{{ $root }}/{{ $key }}" class="{{ $active?'active':'' }}" @if ($active) aria-current="page" @endif
                        ><x-figma-icon :name="$glyph.($active?'-active':'')" /><span>{{ $label }}</span></a
                    >
                @endif
            @endforeach
            @unless ($isAdmin)
                <span class="nav-caption">{{ $viewer->role==='super_admin' && !$personalWorkspace?'REPOSITORY':'MY LIBRARY' }}</span>
                @foreach (($viewer->role==='super_admin' && !$personalWorkspace?['trash'=>['trash','Trash']]:['mine'=>['folder','My documents'],'shared'=>['users','Shared with me'],'starred'=>['star','Starred'],'trash'=>['trash','Trash']]) as $key=>[$glyph,$label])
                    @php $active=$section==='documents' && $libraryView===$key; @endphp
                    <a href="{{ $root }}/documents?view={{ $key }}" class="{{ $active?'active':'' }}" @if ($active) aria-current="page" @endif
                        ><x-figma-icon :name="$glyph.($active && in_array($glyph,['users','trash'])?'-active':'')" /><span>{{ $label }}</span></a
                    >
                @endforeach
            @endunless
        </nav>
        <div class="sidebar-bottom">
            @if (in_array($viewer->role,['super_admin','admin']) && !$isAdmin)
                <a class="return-admin" href="/admin">← Administration</a>
            @endif

            <a href="/account" class="return-admin">My account</a>
            <div class="profile">
                <span class="avatar">{{ $initials }}</span>
                <div><strong>{{ $viewer->name }}</strong><small>{{ $roleName }}</small></div>
                <form action="/logout" method="post" data-demo-action="logout">
                    @csrf
                    <button type="submit" class="icon-button" aria-label="Sign out">
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
                @if ($personalWorkspace)
                    <a href="/my-workspace">My workspace</a>
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
                ><span class="top-avatar">{{ $initials }}</span><span class="mobile-source">{{ $source }}</span>
            </div>
        </header>
        <main id="main" tabindex="-1">
            @php
            // The Back button is kept only for the document preview, where a
            // deep link needs an explicit way out. Everywhere else navigation is
            // carried by the sidebar plus the "Up to ..." folder tile.
            $showBack=isset($doc);
            $backFallback=$isAdmin?($viewer->role==='super_admin'?'/my-workspace':'/workspaces/'.strtolower($viewer->source)):$root.'/overview';
            if(isset($doc)) $backFallback=$root.'/documents'.($doc->folder_id?'?folder='.$doc->folder_id:'');
            elseif(isset($folder) && $folder) $backFallback=$root.'/documents'.($folder->parent_id?'?folder='.$folder->parent_id:'');
            elseif(!$isAdmin && $section==='overview' && in_array($viewer->role,['super_admin','admin'])) $backFallback='/admin';
            if(isset($doc) && $doc->owner_id!==$viewer->id && ($viewer->role==='staff' || $source==='DMS' || !in_array($source,$viewer->sources()))) $backFallback=$root.'/documents?view=shared';
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
    @if (!empty($frontendPreview))
        <div class="preview-bar">
            <span>Frontend preview · Sample data</span><label class="visually-hidden" for="preview-workspace">Preview workspace</label
            ><select id="preview-workspace" aria-label="Preview workspace">
                <option value="/admin" @selected ($isAdmin)>DMS Admin</option>
                @foreach (config('dms.sources') as $code=>$name)
                    <option value="/workspaces/{{ strtolower($code) }}" @selected ($source===$code)>{{ $code }}</option>
                @endforeach
                <option value="/login">Sign-in screen</option></select
            ><button type="button" class="btn btn-sm btn-light" id="reset-preview">Reset demo</button>
        </div>
    @endif
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
