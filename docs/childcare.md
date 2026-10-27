# Childcare and childhood survival

This first care model treats living agents younger than 6,570 days (18 years) as dependents. They do not gather, hunt or explore. They can eat their carried food; when hungry and empty-handed, they consume one carried food unit donated by a living adult on the same tile. Local mothers are preferred, then fathers, then other adults in ID order. If a preferred adult has no food, another eligible adult can donate.

Before movement, each child selects an available local adult using the same parent preference. Selected caregivers stay on that tile; they still gather, hunt and eat. Other adults can explore. Adults finish survival actions before children are fed, and donations reduce the donor's current inventory immediately. Food is never transferred across tiles or worlds. A child reaching adulthood stops requiring care on the next tick.

Children without food accumulate hunger and take the existing starvation damage. Their deaths create ordinary death events with a childhood-specific description. This is a deliberately simple game model: hunger and damage rates are unchanged, and dependency until 18 is a placeholder. There is no nursing model, adoption record, family following or age-specific disease model.

Reports append `children` (living dependents), `births_interval`, `child_deaths_interval` and `feedings_interval`. A feeding is one food unit consumed from an adult. Interval counts reset after each report row; deaths at delivery remain the existing infant-loss events and are not counted as deaths of living children.

No migration or reseeding is required. Existing children keep their current coordinates and inventory. If their parents have already moved away, a free parent can now travel back under the reunion rules below. Unsupported children can still starve before help arrives. Inspect existing worlds before advancing a long run with the new rules.

CI covers caregiver retention, parent preference and fallback, single-unit food conservation, tile/world boundaries, unsupported starvation and the transition to adulthood. The movement integration test also replays two identical worlds with births under these rules.

## Caregiver reunion

A child with no living adult on its tile can draw a living adult parent back from elsewhere in the same world. Children are considered by hunger (highest first), then ID; a free, reachable mother is preferred, then a father. Adults already staying with another child are not reassigned. Siblings on the same tile reserve only one returning parent.

The parent takes one cardinal step per tick along a shortest route across grass/forest, with a fixed north/east/south/west tie-break. Returning overrides random exploration but still requires at least 20 energy and enough energy for that step. Tiles costing more than the maximum 100 energy cannot be used. Water, mountains and disconnected land block rescue; a blocked or unavailable parent allows the other parent to be considered. Routes are recalculated each tick and no extra random draws are used.

Food remains local: a parent feeds only after reaching the child's tile. On the next tick the existing caregiver rule keeps an adult there. Dead children are not reunion targets. Existing deaths are not reversed, and recovery is not guaranteed if no parent is available or travel takes too long. No migration or reseeding is needed.
