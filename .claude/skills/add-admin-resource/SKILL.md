---
name: add-admin-resource
description: Scaffold a new admin-managed entity (CRUD) in the IPTV service following project conventions (migration, model with PHP attributes, service class, thin controller, resource routes, Blade views on admin.css, docs). Use when asked to add a new manageable thing to the admin panel, e.g. "add servers", "manage EPG sources", "add resellers".
---

# Add an admin resource

Follow the existing pattern. `Channel` / `ChannelController` / `ChannelService` / `resources/views/admin/channels/` is the reference implementation, so read it first.

## 1. Migration
- `php artisan make:migration create_<things>_table`.
- If the table has FKs to project tables, make sure its timestamp sorts after them (custom migrations use `2026_04_20_063000_<n>_...`).
- Use `foreignId(...)->constrained()->onDelete('cascade' | 'set null')` like the existing tables.

## 2. Model (`app/Models/<Thing>.php`)
```php
#[Fillable(['name', ...])]
class Thing extends Model
{
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function channels() { return $this->belongsToMany(Channel::class, 'thing_channel'); }
}
```
- Always pass the pivot table name to `belongsToMany`.
- Add the inverse relation on the related model.

## 3. Service (`app/Services/<Thing>Service.php`)
Methods: `getAll<Things>()` (paginate(20) or `withCount`), `create<Thing>(array $data)`, `update<Thing>(Thing $t, array $data)`, `delete<Thing>(Thing $t)`. Sync many-to-many arrays here with `if (isset($data['x'])) $model->x()->sync($data['x']);`.

## 4. Controller (`app/Http/Controllers/Admin/<Thing>Controller.php`)
- Constructor injection: `protected $thingService;` set in `__construct(ThingService $thingService)`.
- Inline `$request->validate([...])`; for unique fields on update use `'unique:things,name,' . $thing->id`.
- After a write: `return redirect()->route('admin.things.index')->with('success', 'Thing created successfully.');`
- Implement every method the resource route registers, or limit the route with `->except(['show'])`. Don't leave dangling routes (see known-issues).

## 5. Routes (`routes/web.php`)
Inside the `auth` / `admin.` group: `Route::resource('things', ThingController::class)->except(['show']);`

## 6. Views (`resources/views/admin/things/`)
- `index`, `create`, `edit` (+ `_form.blade.php` shared by create/edit, taking a `$submit` label). Each `@extends('layouts.admin')`, sets `@section('title', ...)`; forms also set `@section('container', 'container-narrow')`.
- Copy the structure from `admin/sources/*` or `admin/bouquets/*`: `.page-header` (h1, one-line description, `.page-actions`), `.card` > `.toolbar` (filters) / `.table-wrap` > `.table`, `.empty` state with a call to action, `{{ $things->links('admin.partials.pagination') }}`.
- Forms: `.card` > `.card-body.form-grid` > `.field` (`.label`, `.input`/`.select`, `.help`), `.card-footer` with Cancel + primary submit. Checkboxes: hidden `value="0"` input before a `.check` checkbox.
- Deletes: `<form data-confirm="Delete X? What happens to related data.">`. Icons: `<x-icon name="..."/>` (add missing ones to `components/icon.blade.php`).
- Flash messages and validation errors are rendered by the layout. Don't add them per page.
- Only `admin.css` classes and CSS variables. **No Tailwind classes**, no inline colours. Check it at 375 px.
- Add a sidebar entry to the `$nav` array in `resources/views/layouts/admin.blade.php`.
- If it can hold thousands of rows: paginate, filter server-side, and use set-based bulk writes.

## 7. Verify
- `php artisan route:list --path=admin/things`
- Migrate and load each page in the browser (see the `run-app` skill). Test the migration's `down()` on MySQL too.
- Add feature tests (see the `write-tests` skill).

## 8. Document
Run the `update-docs` skill: data-model, routes, admin-panel feature doc, CHANGELOG.
