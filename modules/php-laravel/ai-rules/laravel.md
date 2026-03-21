# CodeGuard AI Analysis Rules — Laravel

## Analysis Context

You are analyzing a Laravel project against patterns configured in codeguard.yaml.
Read CODEGUARD.md for project-specific context.

## How to Analyze

### Step 1: Load active patterns
Read codeguard.yaml → patterns section. For each active pattern,
load its YAML definition for verification rules.

### Step 2: For each pattern, check verification rules
Each pattern YAML has a `verification.rules` list.
Check each rule against the code in scope.

### Step 3: Classify findings
- **Critical**: Design pattern completely broken — core architecture violated
- **Warning**: Pattern partially followed with significant deviation
- **Suggestion**: Improvement opportunity — pattern could be applied but isn't required

### Step 4: Report format
For each finding:
- Severity (critical/warning/suggestion)
- File:line
- Project standard (from pattern name + description)
- What was found (specific violation)
- Suggested remediation (with code example)

Group findings by pattern. Order by severity within group.

## Laravel-Specific Analysis Guidelines

### Layer boundaries
```
Request → Controller → Service/Action → Model
               ↓
          Form Request (validation)
          Policy (authorization)
          DTO (data transfer)
```

Controllers MUST NOT:
- Access Eloquent models directly (use Services)
- Contain business logic
- Return anything other than HTTP responses
- Call `$request->validate()` inline

Services MUST NOT:
- Access Request object
- Return HTTP responses
- Know about Controllers or HTTP layer

### Detection heuristics

When checking service-layer pattern:

When checking "controllers must not access Eloquent models directly":
- Look for Model::method() calls in Controller files (e.g., `Order::create()`, `User::where()`, `Product::find()`)
- Look for `DB::` facade calls in Controller files (e.g., `DB::table()`, `DB::select()`, `DB::raw()`)
- Look for Query Builder chains in Controller files (e.g., `->where()->get()`, `->orderBy()->paginate()`)
- EXCEPTION: Route model binding in method signature IS OK (`public function show(Order $order)`)

When checking "controllers must not contain business logic":
- Look for complex conditionals in controllers (nested if/else, switch with domain rules)
- Look for loops with domain logic in controllers (foreach that transforms, filters, or calculates)
- Look for calculations or formulas in controllers (price computation, discount logic, status transitions)
- A controller should only: validate, authorize, call service, return response

When checking "services must not return HTTP responses":
- Look for `response()` helper calls in Service files
- Look for `redirect()` calls in Service files
- Look for `abort()` calls in Service files
- Look for return types like `JsonResponse`, `RedirectResponse`, `Response` on service methods
- Services should return domain objects, DTOs, arrays, or primitives

When checking "services must not access Request object":
- Look for `Request $request` parameters in Service method signatures
- Look for `request()` helper calls inside Service files
- Look for any `Illuminate\Http\Request` import in Service files
- Services should receive typed DTOs, value objects, or primitive parameters

When checking "DTOs required between layers":
- Look for raw arrays being passed from Controller to Service
- Look for raw arrays being returned from Service to Controller
- LaravelData objects (extends Data) are valid DTOs
- Eloquent Models returned from Service to Controller are acceptable

When checking "no logic in Blade":
- @if/@foreach/@unless with simple conditions are display logic, NOT violations
- @php blocks with calculations, loops over data, or business rules ARE violations
- Blade components with logic in their class are OK (logic in component class, not template)

When checking "form requests required":
- Look for `$request->validate()` calls in controllers
- Look for controller methods accepting generic `Request` instead of specific FormRequest subclass
- FormRequest classes in `app/Http/Requests/` are the expected pattern

When checking "no env() outside config/":
- Scan all PHP files for `env()` calls
- Only `config/*.php` files are allowed to call `env()`
- This is critical because `env()` returns null after `config:cache` — it is a runtime bug

When checking "resource controllers":
- Inspect public methods on controller classes
- Standard methods: index, show, create, store, edit, update, destroy
- Single-action controllers with only `__invoke` are valid
- Any other public method is a violation

When checking "policies for authorization":
- Look for manual user ID comparisons in controllers (`$user->id !== $model->user_id`)
- Look for inline `Gate::allows()` / `Gate::denies()` in controllers
- Look for `abort(403)` with manual condition checks
- `$this->authorize()`, `Gate::authorize()`, and `->can()` middleware are correct usage

When checking "action classes":
- Look for service methods exceeding ~50 lines
- Look for methods that orchestrate multiple distinct operations (create + notify + sync)
- Single-purpose Action classes with `__invoke` or `execute` are the expected refactoring

When checking "value objects":
- Look for the same primitive type (string, int, float) representing a domain concept across multiple classes
- Look for validation of the same format (email, CPF, phone) duplicated in multiple places
- readonly classes wrapping a primitive with validation are the expected pattern

### False positive prevention

- Route model binding (`public function show(Order $order)`) is NOT a violation of "controllers must not access models directly" — it is type-hinting for dependency injection
- Eloquent relationships defined in Models are NOT violations of any pattern
- Blade `@if`/`@foreach`/`@unless` are NOT "logic in Blade" — only `@php` blocks with calculations
- Test files are EXCLUDED from all analysis
- Migration files are EXCLUDED from type declaration checks
- Seeder and factory files have relaxed rules
- `env()` in `config/` files IS correct usage — only flag env() OUTSIDE config/
- Policy checks via middleware `->can('action')` in routes are valid authorization
- Single-action controllers (`__invoke`) are a valid alternative to resource controllers
- Private/protected helper methods in controllers are not violations of resource-controllers pattern
- Eloquent models returned from services are acceptable (not every return needs a DTO)
- `$request->validated()` in a controller that type-hints a FormRequest is acceptable — the validation already ran

### Thresholds (from codeguard.yaml)
- max_method_lines: methods exceeding this are findings
- max_indentation_levels: nesting exceeding this is a finding
- These are Warning severity, not Critical
