# BoardingFinder API (Laravel)

REST + real-time backend for **BoardingFinder**, a student boarding platform: listings and map demand, bookings, inquiries, chat with scam screening, saved-search alerts, roommate matching, reviews and admin moderation.

This is a Laravel 13 rewrite of the original Node/Express/Prisma/MongoDB API ([Smart-Boarding-Finder](https://github.com/naweedhah/Smart-Boarding-Finder)). It serves the **existing React client unchanged** (same routes, same JSON, same cookie auth) apart from the real-time layer, see [Real-time](#real-time).

## Stack

| Concern | Choice |
| --- | --- |
| Framework | Laravel 13, PHP 8.3+ (8.4+ recommended, see [Notes](#notes)) |
| Database | SQLite for local dev; PostgreSQL and MySQL tested in CI (Eloquent, migrations, factories, seeders) |
| Auth | Sanctum personal access tokens carried in an **httpOnly `token` cookie** (7 days, revoked on logout/suspension) |
| Real-time | Laravel Reverb (WebSockets) + Echo on the client |
| Email | Laravel Mail (queued Mailable, only when a real mailer is configured) |
| AI | Google Gemini via Laravel `Http` (listing description, room-photo check) |
| Tests | PHPUnit feature + unit tests (155), in-memory SQLite locally; SQLite, MySQL and PostgreSQL in CI |

## Quick start

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed          # demo data, every account uses password `password123`
php artisan storage:link            # serves uploaded listing photos

php artisan serve --port=8800       # API      -> http://localhost:8800
php artisan reverb:start            # realtime -> ws://localhost:8080   (second terminal)
```

`composer dev` runs both processes together. The React client (`../client`, `npm run dev`) already points at `http://localhost:8800/api`.

Demo logins (password `password123`): `demo_student`, `demo_owner_kandy`, `demo_owner_colombo`, `demo_admin`, plus roommate-finder students such as `demo_student_anu`.

### Environment

See [.env.example](.env.example). The ones you will touch:

| Variable | Purpose |
| --- | --- |
| `APP_URL` | Where this API is served (used for absolute photo URLs) |
| `CLIENT_URL` | React origin, for CORS with credentials |
| `DB_*` | Switch `DB_CONNECTION` to `mysql` or `pgsql` (see `.env.example`) |
| `REVERB_*` | WebSocket server credentials (client reads matching `VITE_REVERB_*`) |
| `MAIL_*` | Optional. With `MAIL_MAILER=log` no email is attempted |
| `GEMINI_API_KEY` | Optional. Enables the AI description and photo check |

### Tests

```bash
php artisan test
vendor/bin/pint --test     # code style

# against PostgreSQL or MySQL instead of in-memory SQLite
DB_CONNECTION=pgsql DB_DATABASE=boardingfinder DB_USERNAME=postgres DB_PASSWORD=secret php artisan test
```

### Docker

`docker compose up --build` from the repository root starts PostgreSQL, the API, Reverb and the client (see the root README).

## API overview

All routes are under `/api`. 🔒 = needs the login cookie.

| Area | Endpoints |
| --- | --- |
| Auth | `POST auth/register` · `auth/login` · `auth/logout` |
| Listings | `GET posts` (filters: `city area boardingType preferredTenantGender status capacity minPrice maxPrice`, plus `lat lng radiusKm` for a distance-sorted near search that adds `distanceKm`) · `GET posts/demand/overview` · `GET posts/{id}` · 🔒 `POST posts` (owner/admin) · 🔒 `PUT/DELETE posts/{id}` (owner) · 🔒 `POST posts/{id}/book` (student) |
| Reviews | `GET posts/{id}/reviews` · 🔒 `POST/DELETE posts/{id}/reviews` |
| Users | 🔒 `PUT/DELETE users/{id}` (self) · `GET users` (admin) · `POST users/save` (toggle saved listing) · `GET users/profilePosts` |
| Notifications | 🔒 `GET users/notification` (unread count) · `users/notifications` · `PUT users/notifications/{id}/read` · `read-all` · `GET/PUT users/notifications/preferences` · `POST users/notifications/test-email` |
| Search alerts | 🔒 `GET/POST users/watchlists/searches` · `DELETE users/watchlists/searches/{id}` |
| Roommates | 🔒 `GET/PUT users/roommate-profile` · `GET users/roommate-matches` |
| Bookings | 🔒 `GET bookings` · `PUT bookings/{id}/approve\|reject\|cancel` · `POST bookings/{id}/chat` |
| Chat | 🔒 `GET/POST chats` · `GET chats/{id}` · `PUT chats/read/{id}` · `POST messages/{chatId}` · `support-chat/*` |
| Inquiries | 🔒 `POST/GET inquiry` · `PATCH inquiry/{id}` (reject/cancel) · `PATCH inquiry/{id}/accept` |
| Moderation | 🔒 `POST/GET report` · admin: `GET report/admin/overview` · `PATCH report/{id}` · `POST report/warn` · `POST report/user-state` |
| Owner dashboard | 🔒 `GET boardings` · `POST boardings/add` (multipart) · `PUT boardings/{id}` · `PATCH boardings/{id}/status` · `DELETE boardings/{id}` · `POST/GET boardings/{id}/waitlist` · `POST boardings/generate-description` · `POST boardings/verify-image` |
| Real-time | 🔒 `POST broadcasting/auth` (private channel `users.{id}`) |

Errors are JSON. Validation failures return **422** with `{ "message": "<first error>", "errors": ["<all errors>"] }`, the shape the client already reads.

## How it is organised

```
app/
  Enums/            backed enums (roles, statuses, notification types…)
  Models/           Eloquent models; Concerns/SerializesCamelCase keeps JSON camelCase
  Http/
    Controllers/    thin controllers, one per feature
    Requests/       Form Requests: validation + the exact messages the UI shows
    Resources/      PostResource (demand figures), ChatResource (userIDs/seenBy/receiver)
    Middleware/     AuthenticateWithToken, EnsureUserHasRole, ParseMultipartBody
  Policies/         who may edit a post / user / act on a booking
  Services/         business logic: NotificationService, AlertNotifier, BookingService,
                    ChatService, DemandService, RoommateMatcher, ReputationService,
                    ScamDetector, GeminiService
  Support/          Geo (Haversine + bounding box), HtmlSanitizer (HTMLPurifier allow-list)
  Events/           broadcast events (NotificationCreated/Read, MessageSent)
database/           migrations, factories, DemoSeeder (+ data/demo.json)
routes/             api.php, channels.php
tests/              Feature/ and Unit/
```

Domain highlights:

- **Demand score**: saves ×2 + inquiries ×3 + bookings ×4, bucketed `low / medium (≥6) / high (≥12)`; `remainingSlots` = capacity − live booking requests.
- **Booking requests** lock the listing row inside a transaction so two students cannot take the last slot, and re-sending is idempotent.
- **Alerts**: new/edited listings notify matching saved searches, price drops notify savers, low availability notifies savers, and three watchers in one area trigger a demand-spike alert. Users can mute each category; critical notices bypass that.
- **Roommate matching**: weighted 0–100 score (budget 25, location 20, sleep/cleanliness/study/smoking 10 each, food/sociability/pets 5 each); same-gender, active students only.
- **Near search**: an indexed bounding-box `WHERE` on the `DECIMAL` coordinates, then exact Haversine distances in PHP. It works the same on SQLite (no trig functions), MySQL and PostgreSQL.
- **Input safety**: listing pins must fall inside Sri Lanka, photos must be HTTPS URLs, and rich-text descriptions are sanitised server-side. 422 responses carry `message`, a flat `errors` list and a per-field `fields` map.
- **Safety**: messages are screened for scam phrasing, flagged `warning`/`danger`, the recipient is warned, and admins can review, warn or suspend. Suspension revokes tokens immediately.

## Real-time

The Node API embedded Socket.IO. Laravel's equivalent is Reverb + Echo, so the client's `SocketContext`, `Navbar` and `Chat` were switched to Echo (`client/src/lib/echo.js`). Each signed-in user subscribes to the private channel `users.{id}` (authorised through `/api/broadcasting/auth` with the login cookie) and receives `.notification.new`, `.notification.read` and `.message.new`. Broadcast failures are logged, never surfaced: if Reverb is down the REST API keeps working.

## Notes

- **IDs are ULIDs** (26-char strings) rather than Mongo ObjectIds; the client treats ids as opaque strings. There is no data migration from MongoDB, use the seeder.
- **Photo uploads** are stored on the `public` disk (`storage/app/public/uploads`) and returned as absolute URLs. Multiple photos must be sent as `images[]` (PHP does not keep repeated plain `images` fields).
- **Editing a listing with `PUT` + multipart** needs PHP ≥ 8.4 (`request_parse_body()`, see `ParseMultipartBody`); on older PHP send `POST` with `_method=PUT`.
- `OtpCode`, `Complaint` and `BookingPayment` tables/models exist (they were in the Prisma schema) but, as in the Node app, have no endpoints yet.
- Production: set `APP_ENV=production`, `APP_DEBUG=false`, `QUEUE_CONNECTION=database` with a `queue:work` process, a real `MAIL_*` transport, and serve over HTTPS (the auth cookie becomes `Secure`).
