# Emergent Civilization

An open-source, persistent civilization simulation and browser game. Planned modes: observer, god, and character. The simulation engine is independent of the web UI.

## Status

Early MVP foundation: deterministic 32x32 world generation and MariaDB persistence. Agent behavior, HTTP API, and interactive game modes are **not implemented yet**.

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
php bin/create-world.php "First World" 1337
composer test
```

Never commit credentials. The first migration contains four core tables only.

## Roadmap

1. World generator and database foundation
2. 30 autonomous agents, basic resource systems, transactional tick engine
3. Read-only API and canvas map
4. God and character commands with authorization and auditing
5. Historical causality and research experiments

MIT license.
