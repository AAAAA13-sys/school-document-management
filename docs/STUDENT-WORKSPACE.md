# Student workspace implementation

Students see their own files, folders, recent and starred documents, Trash, and explicit shares. Administrative repository, integration history and account management remain role restricted.

The student shell uses green/yellow navigation, a top search, compact welcome area, upload/folder shortcuts, grid/list controls, and a page-local hover help icon. Uploads are usable immediately. There is no scanning service, scanner configuration or scan retry command. Existing pending/failed records become available when the migration runs; legacy scan metadata remains in the schema for API compatibility and historical records.

Uploaded original filenames **and** visible file names must be unique across the owner's school/campus workspace, including all folders and Trash. Comparisons trim leading/trailing whitespace and ignore case. Other users can use the same name. New versions may reuse their own document's name. Duplicate uploads and renames return HTTP 409; database unique keys prevent simultaneous requests creating duplicates. Trash reserves names so restoration remains safe.

For legacy unowned integration records, uniqueness is school/campus/source scoped. The migration preserves existing content and disambiguates existing conflicting display/download names with `(2)`, `(3)`, etc. No files or prior versions are deleted.

Private storage, RBAC, CSRF/session protection, 10 MB limit, permitted types and signatures, integrity checks, idempotency, revision conflict checks and version-pinned shares remain in place.
