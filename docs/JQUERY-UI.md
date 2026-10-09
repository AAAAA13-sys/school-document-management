# How this application uses jQuery UI

The stack is Laravel 12 + Blade + Bootstrap 5.3.8 CSS + jQuery 3.7.1 + jQuery UI 1.14.2.

Load in this order: Bootstrap CSS, jQuery UI CSS, application CSS; then jQuery, jQuery UI, application JavaScript. Assets are vendored locally, so the workspace needs no CDN or Node build step.

Bootstrap JavaScript is intentionally not loaded. Bootstrap says it does not officially support jQuery UI. Keeping its responsibility to layout avoids duplicate `button`, `tooltip` and dialog plugins; compatibility is still verified through browser tests.

| Widget | Real use | Location |
|---|---|---|
| Dialog | Upload and document detail windows | `public/assets/app.js` |
| Tabs | Record details/file intake and version history | `resources/views/workspace.blade.php` |
| Datepicker | Optional expiry date, stored as YYYY-MM-DD | `#expires_at` |
| Autocomplete | Suggestions from currently visible, authorized record owners | `#subject` |
| Accordion | Expandable workspace help | `#guide-accordion` |

Example initialization:

```javascript
$('#upload-dialog').dialog({ autoOpen: false, modal: true, width: 680 });
$('#upload-tabs').tabs();
$('#expires_at').datepicker({ dateFormat: 'yy-mm-dd', changeMonth: true, changeYear: true });
$('#subject').autocomplete({ source: authorizedNames, minLength: 1 });
$('#guide-accordion').accordion({ heightStyle: 'content', collapsible: true });
```

Never use an autocomplete label to establish identity. `reference` is an external business-record reference in this slice; upstream existence checks and confirmed applicant/student mappings are explicitly pending.

Laravel checks permissions and validation on every request. jQuery UI improves interaction; it is never the security boundary. CSRF tokens accompany session-authenticated mutations, and backend validation remains authoritative.

Official references: [jQuery UI widgets](https://jqueryui.com/), [Bootstrap JavaScript compatibility](https://getbootstrap.com/docs/5.3/getting-started/javascript/), [Laravel release requirements](https://laravel.com/docs/13.x/releases).
