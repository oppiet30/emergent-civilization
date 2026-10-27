# Childcare and childhood survival

This first care model treats living agents younger than 6,570 days (18 years) as dependents. They do not gather, hunt or explore. They can eat their carried food; when hungry and empty-handed, they consume one carried food unit donated by a living adult on the same tile. Local mothers are preferred, then fathers, then other adults in ID order. If a preferred adult has no food, another eligible adult can donate.

Before movement, each child selects an available local adult using the same parent preference. Selected caregivers stay on that tile; they still gather, hunt and eat. Other adults can explore. Adults finish survival actions before children are fed, and donations reduce the donor's current inventory immediately. Food is never transferred across tiles or worlds. A child reaching adulthood stops requiring care on the next tick.

Children without food accumulate hunger and take the existing starvation damage. Their deaths create ordinary death events with a childhood-specific description. This is a deliberately simple game model: hunger and damage rates are unchanged, and dependency until 18 is a placeholder. There is no nursing model, adoption record, family following, rescue pathfinding or age-specific disease model.

Reports append `children` (living dependents), `births_interval`, `child_deaths_interval` and `feedings_interval`. A feeding is one food unit consumed from an adult. Interval counts reset after each report row; deaths at delivery remain the existing infant-loss events and are not counted as deaths of living children.

No migration or reseeding is required. Existing children keep their current coordinates and inventory. If their parents have already moved away, only adults currently sharing the tile can help; unsupported children can now starve. Inspect existing worlds before advancing a long run with the new rules.

CI covers caregiver retention, parent preference and fallback, single-unit food conservation, tile/world boundaries, unsupported starvation and the transition to adulthood. The movement integration test also replays two identical worlds with births under these rules.
