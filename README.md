# Poker Royale — No-Limit Texas Hold'em

A full-stack multiplayer poker game.

- **Frontend:** React 18 + Vite (Playfair Display / Cinzel / Inter, dark casino gold & emerald theme)
- **Backend:** PHP 8 (server-authoritative game engine)
- **Database:** MySQL / MariaDB

Multiple humans can sit at the same table; empty seats are filled with bots that
play at three skill levels (easy / medium / hard). No WebSockets needed — the
client polls a tick endpoint that advances the authoritative state machine
(bot decisions, betting streets, showdown, side pots, timeouts).

## Project layout

```
poker/
├── api/                    # PHP backend
│   ├── index.php           # API router (auth, rooms, join/leave, action, chat)
│   ├── config.php          # DB credentials + tuning constants
│   ├── install.php         # one-shot installer / seeder
│   ├── schema.sql          # tables + seed data (rooms, bot accounts)
│   ├── router.php          # router for `php -S`
│   ├── .htaccess           # Apache rewriting
│   └── src/
│       ├── Db.php          # PDO wrapper (auto-installs if database missing)
│       ├── Auth.php        # bearer-token auth
│       ├── Api.php         # JSON helpers
│       ├── Cards.php       # deck, hand evaluation, hand names
│       └── Game.php        # game engine: tick loop, side pots, bots, state
└── frontend/               # Vite + React app
    └── src/
        ├── App.jsx         # view routing (auth / lobby / game)
        ├── AuthPage.jsx    # sign in / create account
        ├── Lobby.jsx       # choose a table
        ├── Game.jsx        # live table: seats, cards, actions, chat, rebuy
        ├── api.js          # fetch client (token kept in localStorage)
        ├── index.css       # design system (palette + fonts)
        └── components/     # Card, Chip, Seat
```

## Requirements

- PHP 8.1+ with `pdo_mysql`
- MySQL / MariaDB (tested on MariaDB 10.4)
- Node.js 18+ and npm

## Setup (development)

### 1. Database

Create the database once (it also auto-creates on first API call):

```bash
php api/install.php
```

Creates the `poker` database, tables, 4 rooms and 8 bot accounts.

### 2. Run the API

```bash
php -S 127.0.0.1:8000 -t api api/router.php
```

### 3. Run the frontend

```bash
cd frontend
npm install
npm run dev
```

Vite proxies `/api/*` to `http://127.0.0.1:8000`. Open
[http://127.0.0.1:5173](http://127.0.0.1:5173), create an account, pick a
table and play. Hand evaluation is done server-side so bots can't be cheated.

## Configuration (`api/config.php`)

| Constant        | Meaning                                    | Default |
|-----------------|--------------------------------------------|---------|
| `DB_HOST`       | MySQL host                                 | `127.0.0.1` |
| `DB_PORT`       | MySQL port                                 | `3306` |
| `DB_NAME`       | database name                              | `poker` |
| `DB_USER`/`DB_PASS` | MySQL credentials                      | `root` / `` |
| `API_BASE`      | URL prefix if deployed in a sub-directory  | `` (dev) |
| `CHIPS_START`   | starting bankroll                          | `10000` |
| `SITEOUT`       | seconds before a silent human is folded    | `40` |
| `SHOWDOWN_PAUSE`| seconds the result stays on screen         | `4` |

If the API is served from a sub-folder (e.g. XAMPP
`http://localhost/fullstack/poker/api`), set `API_BASE` accordingly and point
the Vite proxy at that URL instead of `127.0.0.1:8000`.

## Production build

```bash
cd frontend
npm run build       # outputs dist/
```

Deploy `dist/` anywhere and serve the PHP API with Apache (`.htaccess` is
included) or PHP's built-in server.

## API reference

| Method | Route                     | Body                               | Purpose                        |
|--------|---------------------------|------------------------------------|--------------------------------|
| POST   | `/auth/register`          | `{username, password}`             | create account                 |
| POST   | `/auth/login`             | `{username, password}`             | sign in                        |
| GET    | `/me`                     | —                                  | profile + bankroll             |
| POST   | `/me/buyin`               | `{amount}`                         | add chips to bankroll          |
| GET    | `/rooms`                  | —                                  | list tables with occupancy     |
| GET    | `/rooms/:id/state`        | —                                  | full game state (also ticks)   |
| POST   | `/rooms/:id/join`         | `{seat?}`                          | sit at a table                 |
| POST   | `/rooms/:id/leave`        | —                                  | leave table                    |
| POST   | `/rooms/:id/action`       | `{action, amount?}`                | fold/check/call/bet/raise/allin |
| POST   | `/rooms/:id/buyin`        | `{amount}`                         | rebuy at the table             |
| GET    | `/rooms/:id/chat`         | —                                  | recent messages                |
| POST   | `/rooms/:id/chat`         | `{message}`                        | send a message                 |

Auth uses the `X-Auth-Token` header (or `Authorization: Bearer <token>`).

## Rules implemented

- 6-max ring games, blinds scale per table
- Blinds, dealer rotation, heads-up blind handling
- Pre-flop / flop / turn / river betting with min-raise enforcement
- All-in short calls and shoves, **correct side-pot construction**
- Showdown with full 7-card hand evaluation (royal/stright flush → high card),
  split pots share the odd chip fairly
- Human timeout → auto-fold after `SITEOUT` seconds
- Bots added to fill seats; practice-grade AI (pre-flop strength + street equity)