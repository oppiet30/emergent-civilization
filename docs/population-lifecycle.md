# Population lifecycle (first iteration)

Apply `004_population_lifecycle.sql` after 001–003. Seed new worlds with `bin/seed-agents.php`; founders alternate sexes deterministically and begin at age 20. For existing worlds, set sex and age explicitly before enabling reproduction.

Each tick ages living agents by one day. Adult females and adult males occupying the same tile may conceive; gestation lasts 280 days and mothers cannot have overlapping pregnancies. Infant loss has an illustrative 2% probability. Successful births create agents with parent IDs, age zero and a sex chosen from higher RNG bits.

This first iteration does not yet include maternal mortality, childcare, mortality across later childhood, learned skills, or extinction history. The probability values are placeholders for simulation tuning, not medical estimates.
