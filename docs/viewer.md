# World observer

The observer shows terrain, living people, person details and family links, and recorded events. It never advances ticks or changes a world. Refresh explicitly after running a simulation in another terminal. Births/deaths are cumulative recorded events; infant loss is separate from the deaths of living agents. Names such as `Child-1-3` remain names after adulthood.

## Start locally

Install Composer dependencies and apply migrations 001–004 using the normal setup. Then:

```sh
php bin/serve-viewer.php
```

Open `http://127.0.0.1:8080`. An optional numeric argument selects another port. This launcher loads DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD from the project's `.env` only when they are not already exported. Plain and single/double-quoted values are supported; shell expansion is not. Other simulation commands still use exported environment settings as before.

For a remote Dell, run the launcher there and forward the port from your own computer:

```sh
ssh -N -L 8080:127.0.0.1:8080 oppie@10.1.10.138
```

The development server listens only on loopback. Its document root is `public/`, never the repository root. Keep this local observer private; authentication and public hosting are later milestones. A SELECT-only database account may be used for additional separation.

## API

All endpoints accept GET only and return JSON. Every request executes in a repeatable-read, read-only database transaction. Errors never expose database credentials or SQL messages. No simulation command is exposed over HTTP.

| Endpoint | Result |
| --- | --- |
| `/api/worlds` | First 100 worlds, ordered by ID |
| `/api/worlds/1` | World, population/vital summary, terrain and living map occupants |
| `/api/worlds/1/people/33` | Living or deceased person, parents, children and death event |
| `/api/worlds/1/events` | Latest vital events (birth, death and infant loss) |

Events accept `kind=vital|birth|death|conception|infant_death|all`, `limit=1..100` (default 30), and `before=<event-id>`. Use `next_before` from the response for the next page. The person detail includes up to 200 children. The map/list includes up to 5,000 living people and flags truncation; terrain is capped at 10,000 tiles. Summary counts cover the entire world. Current generated worlds contain 1,024 tiles.

Use the searchable list or map to inspect people. History and family links can open people who died. The map supports click, arrow keys and Enter. Each endpoint is internally consistent; separate requests can observe different ticks if a simulation runs concurrently. Refresh shows the latest saved state; it does not start or pause a simulation.

## Validation

CI checks API summaries, world isolation, parent/child relationships, historical death causes, cursor pagination, invalid requests, and unchanged tick/RNG state. HTTP smoke tests also verify response headers, blocked mutation methods, static assets, and that `/.env` cannot be served.
