# Survival simulation (experimental)

Apply migrations 001 and 002 in order. Create a world, then seed it **once**:

```sh
php bin/create-world.php "First World" 1337
php bin/seed-agents.php 1
```

Start the world using SQL (`UPDATE worlds SET status='running' WHERE id=1;`) and advance:

```sh
php bin/simulate.php 1 24
```

Each tick increases hunger, drains energy, lets agents eat carried food or gather locally, applies starvation damage, regenerates tile food and commits atomically. Agents do **not** move, reproduce, hunt, form families or establish settlements yet. There is no systemd timer installed by this PR. Seeded terrain and ordered agents are used for reproducibility. Resource regeneration is deliberately simplistic and will be replaced with ecological rates and carrying-capacity constraints. Avoid running on an important database before testing.

## Follow-up

Implement movement, energy/rest tradeoffs, tile depletion ecology, tests with MariaDB, animal populations and hunting, demographic simulation, and a secure game API.
