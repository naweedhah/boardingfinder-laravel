# BoardingFinder

BoardingFinder is a full-stack student boarding platform for discovering listings, viewing locations on a map, saving favorites, sending inquiries, chatting in real time, and managing bookings.

## Features

- Student boarding search with filters for city, area, price, gender, type, and available slots
- Interactive Sri Lanka map view for listings and demand areas
- Listing details with amenities, nearby places, demand insights, and booking flow
- User authentication and protected dashboards
- Saved listings and watchlist-based search tracking
- Real-time chat and inquiry messaging
- Booking request and payment workflow
- Roommate matching
- Notifications and smart alerts
- Reporting and safety moderation tools
- Admin dashboard for platform oversight

## Project Structure

- `client` - React + Vite frontend
- `backend` - Laravel 13 API (MySQL/SQLite, Sanctum cookie auth, Reverb real-time), see [backend/README.md](./backend/README.md)
- `api` - the original Express + Prisma + MongoDB backend (superseded by `backend`)
- `socket` - the original standalone Socket.IO service (superseded by Laravel Reverb)

## Requirements

- PHP 8.3+ (8.4+ recommended) and Composer
- Node.js and npm (for the React client)
- SQLite (default) or MySQL

## How To Run

1. Backend (Laravel):

```bash
cd backend
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed      # demo accounts use the password `password123`
php artisan storage:link
php artisan serve --port=8800   # API: http://localhost:8800
php artisan reverb:start        # realtime: ws://localhost:8080 (second terminal)
```

2. Frontend:

```bash
cd client
npm install
npm run dev                     # http://localhost:5173
```

Environment variables and the full endpoint list are documented in [backend/README.md](./backend/README.md).

## Screenshots

### Home / Landing

![Home screen](./api/screenshots/homepage.png)

### Listings And Map

![Listings with map](./api/screenshots/boarding_listing.jpeg)

### Demand Insights

![Demand heatmap and results](./api/screenshots/search.png)

### Watchlist

![Filtered listings](./api/screenshots/watchlist.png)

### User Dashboard

![User dashboard](./api/screenshots/user_dashboard.png)

### Roommate Finder

![Roommate finder](./api/screenshots/roommate_finder.png)

### Roommate Matches

![Roommate matches](./api/screenshots/roommate_matches.png)
