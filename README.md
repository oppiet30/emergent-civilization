# Emergent Civilization

An open-source, persistent civilization simulation and browser game. Planned modes: observer, god, and character. The simulation engine is independent of the web UI.

## Status

The terminal simulation includes deterministic 32x32 worlds, gathering and hunting, movement, reproduction, childcare, and aging with MariaDB persistence. A read-only browser observer displays the map, people, families and history. Interactive god and character commands are not implemented yet.

## Requirements

- PHP 8.3+ with PDO MySQL
- MariaDB
- Composer

## Local setup

```bash
git clone https://github.com/oppiet30/emergent-civilization.git
cd emergent-civilization
composer install
cp .env.example .env
```

Configure the database connection through environment variables (the `.env` file is a reference template and is **not automatically loaded**):

```bash
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=emergent_civilization DB_USER=ec_app
read -rsp 'DB password: ' DB_PASSWORD; echo
export DB_PASSWORD
```

Create a database and grant the configured account access, then run:

```bash
mariadb -u root -p emergent_civilization < database/migrations/001_initial.sql
mariadb -u root -p emergent_civilization < database/migrations/002_survival.sql
mariadb -u root -p emergent_civilization < database/migrations/003_wildlife.sql
mariadb -u root -p emergent_civilization < database/migrations/004_population_lifecycle.sql
php bin/create-world.php "First World" 1337
composer test
```

Apply each migration only once. Use the ID printed by `create-world.php` to seed agents and wildlife once, then set the world status to running before simulating. See [survival](docs/survival.md), [wildlife](docs/wildlife.md) and [population lifecycle](docs/population-lifecycle.md) for details. Never commit credentials.

## Browser observer

```bash
php bin/serve-viewer.php
```

Open `http://127.0.0.1:8080` to explore the map, inspect living or deceased people and family relationships, and read the event timeline. Refresh after advancing ticks from the terminal. The observer does not change world state. Unlike the simulation commands, this launcher can read database settings from your local `.env`. See [viewer setup and API](docs/viewer.md), including SSH port forwarding for a remote machine.

## Roadmap

1. World generator and database foundation
2. 30 autonomous agents, basic resource systems, transactional tick engine
3. Read-only API and canvas map
4. God and character commands with authorization and auditing
5. Historical causality and research experiments

MIT license.
