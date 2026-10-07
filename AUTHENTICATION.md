# Authentication

Last updated: 2026-10-08

This backend provides separate administrator and user authentication APIs using Laravel Sanctum bearer tokens. Administrators are stored in `admins`; users are stored in `users`.

## Maintenance

Update this document in the same task whenever authentication is added, edited, or removed. Keep endpoint contracts, examples, validation, configuration, provisioning, file references, and test instructions aligned with the code. Update the date above and record meaningful changes in the change history below. This requirement is also recorded in [AGENTS.md](AGENTS.md).

## Architecture

The implementation currently uses Laravel 13 and Sanctum 4. Confirm installed versions with `composer show laravel/framework` and `composer show laravel/sanctum` before changing package-dependent behavior.

| Account | Model / table | Sanctum guard | Provider | Route file | Controller |
| --- | --- | --- | --- | --- | --- |
| User | `App\Models\User` / `users` | `users-api` | `users` | [users.php](routes/api/users.php) | [User/AuthController.php](app/Http/Controllers/Api/V1/User/AuthController.php) |
| Administrator | `App\Models\Admin` / `admins` | `admins-api` | `admins` | [admins.php](routes/api/admins.php) | [Admin/AuthController.php](app/Http/Controllers/Api/V1/Admin/AuthController.php) |

- [bootstrap/app.php](bootstrap/app.php) loads [routes/api.php](routes/api.php), which groups the two route files under `/api/v1`.
- [config/auth.php](config/auth.php) binds each Sanctum guard to its own provider. A token for one account type cannot authenticate requests for the other, even if the database IDs or email addresses match.
- Both models use `HasApiTokens` and hash passwords through their `hashed` cast. Email uniqueness is enforced within each account table; the same email may exist in both tables with independent credentials.
- Sanctum stores hashed tokens in the shared `personal_access_tokens` table. Its polymorphic `tokenable_type` and `tokenable_id` identify the owning account.
- [LoginRequest.php](app/Http/Requests/Auth/LoginRequest.php) handles request authorization and input validation only.
- [AuthService.php](app/Services/AuthService.php) handles credential lookup, password verification, failed-login errors, timing protection, and password rehashing. Both authentication controllers receive this service through constructor injection and pass validated credentials with their server-selected provider. Clients cannot choose the provider through the request body. The service accepts credential data without depending on an HTTP request.
- [UserResource.php](app/Http/Resources/UserResource.php) and [AdminResource.php](app/Http/Resources/AdminResource.php) expose only `id`, `name`, and `email`.

## Setup and administrator provisioning

Install locked dependencies and apply migrations on a new environment:

```bash
composer install --no-interaction
php artisan migrate --no-interaction
```

Configure the application's database connection before migrating. Authentication adds migrations for `admins` and `personal_access_tokens`; the existing migration creates `users`.

Create an administrator interactively:

```bash
php artisan admin:create
```

You may supply the non-secret fields as options:

```bash
php artisan admin:create --name="Clinic Admin" --email="admin@example.com"
```

The command prompts for the password and confirmation using hidden input. It rejects non-interactive execution, invalid input, and duplicate administrator emails. It does not change existing accounts or issue a token. Administrator passwords require 12–72 characters, uppercase and lowercase letters, a number, and a symbol.

For local development or testing only, an optional seeder is available:

```bash
php artisan db:seed --class=AdminSeeder --no-interaction
```

[AdminSeeder.php](database/seeders/AdminSeeder.php) creates `admin@example.com` with password `ChangeMe123!` if that administrator does not already exist. It leaves existing credentials unchanged and refuses to run outside the `local` and `testing` environments. It is not automatically called by `DatabaseSeeder`. Use `admin:create` outside local development; there is no public administrator registration endpoint.

## API endpoints

Send JSON request bodies with `Content-Type: application/json` and request JSON responses with `Accept: application/json`.

| Method | Path | Authentication | Successful response |
| --- | --- | --- | --- |
| POST | `/api/v1/users/register` | Public | `201`, user profile and new token |
| POST | `/api/v1/users/login` | Public | `200`, user profile and new token |
| GET | `/api/v1/users/me` | User bearer token | `200`, user profile |
| POST | `/api/v1/users/logout` | User bearer token | `204`, empty body |
| POST | `/api/v1/admins/login` | Public | `200`, administrator profile and new token |
| GET | `/api/v1/admins/me` | Administrator bearer token | `200`, administrator profile |
| POST | `/api/v1/admins/logout` | Administrator bearer token | `204`, empty body |

Route names follow `api.v1.users.{action}` and `api.v1.admins.{action}`. Protected requests require:

```http
Authorization: Bearer <token>
```

### User registration

Example body for `POST /api/v1/users/register`:

```json
{
  "name": "Example User",
  "email": "user@example.com",
  "password": "example-password",
  "password_confirmation": "example-password",
  "device_name": "web-client"
}
```

| Field | Rules |
| --- | --- |
| `name` | Required string, maximum 255 characters |
| `email` | Required valid email string, maximum 255 characters, unique in `users` |
| `password` | Required string, 8–72 characters |
| `password_confirmation` | Must match `password` |
| `device_name` | Optional; if supplied, a nonempty string of at most 255 characters |

Registration persists only the validated `name`, `email`, and `password` fields. Extra fields such as `role` or `is_admin` cannot create an administrator. Account and token creation run inside a database transaction.

### Login

Both login endpoints accept the same body:

```json
{
  "email": "admin@example.com",
  "password": "your-account-password",
  "device_name": "web-client"
}
```

`email` must be a valid email string of at most 255 characters. `password` is a required string of at most 255 characters. `device_name` has the same rules as registration and defaults to `api` when omitted.

Successful login and registration return this structure; registration uses status `201`, while login uses `200`:

```json
{
  "data": {
    "id": 1,
    "name": "Example Account",
    "email": "account@example.com"
  },
  "token": "<new-plain-text-token>",
  "token_type": "Bearer"
}
```

Each successful login creates a new token. The plain-text value is returned when issued; profile endpoints do not return it. The `/me` response contains only the `data` object shown above.

### Errors

| Status | Meaning |
| --- | --- |
| `401` | Missing, invalid, revoked, expired, or wrong-account-type token on a protected endpoint |
| `404` | Unregistered endpoint, including `/api/v1/admins/register` |
| `422` | Invalid request fields or incorrect login credentials |
| `429` | Login or registration rate limit exceeded; includes `Retry-After` |

Validation responses include a `message` and field-keyed `errors`. Invalid login credentials use the `email` error key. Unknown accounts and wrong passwords return the same credential error. API authentication failures return JSON even without an `Accept` header.

## Token lifecycle and rate limits

[config/sanctum.php](config/sanctum.php) sets `guard` to an empty array, so these APIs use bearer tokens rather than web sessions. A web-session login does not authenticate these endpoints.

The default token lifetime is 1,440 minutes (24 hours), measured from creation. Override it with `SANCTUM_EXPIRATION` in the environment. A positive value enables the global lifetime limit; `0` disables that global limit. A token-specific `expires_at`, if set, is also enforced. Expiration rejects access without automatically deleting the token row.

Logout deletes only the token used for that request. Other device tokens remain usable. After logout or expiration, log in again to obtain a new token; there is no refresh-token endpoint.

[AppServiceProvider.php](app/Providers/AppServiceProvider.php) defines:

| Operation | Limit |
| --- | --- |
| Login | 5 requests per minute per lowercase email and IP combination, plus 30 requests per minute per IP |
| Registration | 5 requests per minute per IP |

The login limits count requests, including successful requests, and are shared across the user and administrator login endpoints.

Password reset, email verification, password changes, multi-factor authentication, and logout from all devices are not currently implemented as API endpoints.

## Verification

[AuthenticationTest.php](tests/Feature/AuthenticationTest.php) covers login, profile access, account separation, independent credentials for matching emails, validation, registration, bearer-token requirements, expiration, logout, and login throttling.

[CreateAdminTest.php](tests/Feature/CreateAdminTest.php) covers administrator provisioning, password hashing, duplicate emails, password validation, non-interactive rejection, and development-seeder restrictions.

Run the focused tests:

```bash
php vendor/bin/phpunit tests/Feature/AuthenticationTest.php tests/Feature/CreateAdminTest.php
```

The test configuration uses in-memory SQLite. If PDO SQLite is installed but disabled in the CLI configuration, enable it for the test process:

```bash
php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/AuthenticationTest.php tests/Feature/CreateAdminTest.php
```

After PHP changes, format the affected code and inspect API routes:

```bash
php vendor/bin/pint --dirty --format agent
php artisan route:list --path=api --no-interaction
```

Last verified implementation result: 29 authentication feature tests passed with 154 assertions; Pint passed. Documentation-only changes do not require rerunning the application tests.

## Change history

- 2026-10-08: Implemented separate administrator and user tables, models, controllers, route files, and Sanctum guards; added registration, login, profile, logout, token expiry, rate limits, administrator provisioning, and feature tests.
- 2026-10-08: Added this feature reference and the repository requirement to maintain it alongside future authentication changes.
- 2026-10-08: Moved credential authentication from `LoginRequest` into the injected `AuthService` shared by user and administrator controllers. Request validation and API contracts remain unchanged.
