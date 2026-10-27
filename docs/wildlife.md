# Wildlife and hunting (first iteration)

Apply migration 003 after migrations 001 and 002. Seed wildlife **before** running any ticks:

```sh
php bin/create-world.php "Wildlife World" 1337
php bin/seed-agents.php 1
php bin/seed-wildlife.php 1
# Set the world's status to running, then:
php bin/simulate-report.php 1 1000 100
```

Wildlife populations are aggregated by tile and species. Deer and rabbits occupy grass and forest tiles. Inhabitants may hunt instead of gathering; successful hunts reduce local wildlife and yield food. Births and natural deaths occur every 10 ticks. Populations can go extinct locally, with no spontaneous replacement. Hunting counts and meat gained are reported for each interval, alongside deer and rabbit totals.

This is a deliberately simple ecological model. It does not yet simulate migration, predators, individual animals, seasonal changes, animal age, or food-chain vegetation consumption. Hunting is possible only where agents already stand; movement is not implemented. The existing 1,000-tick GitHub Actions integration test exercises the wildlife model and exports a CSV.
