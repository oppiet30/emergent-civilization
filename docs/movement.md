# Agent movement

Adults (age 6,570 ticks or older) with at least 20 energy have a 25% chance each tick to explore one adjacent tile. Destinations are grass or forest tiles to the north, east, south or west; water, mountains, missing tiles and unaffordable movement costs are excluded. Exploration subtracts the destination's movement cost (at least one energy). Infants and other children remain in place in this first iteration.

Movement runs before gathering, hunting and reproduction, inside the same transaction. Agents are processed by ID and neighbors use a fixed order. Random choices use the world's persisted generator, so identical initial worlds replay identically. Updating the simulation rules changes future trajectories of existing saves.

Founders can now meet without manually relocating them. Encounters and births are possible, not guaranteed: isolated patches can still separate founders. There is no pathfinding, mate seeking, family following or migration between disconnected land areas yet. Food regeneration and reproductive probabilities are unchanged.

No new migration or reseeding is required for worlds already on migrations 001–004. After pulling the merged change, continue ticking the existing world. Keep First Generation experiments separate.

CI runs `php tests/MovementIntegrationTest.php` after applying migrations. It creates and cleans up its own small fixture worlds, verifies terrain and energy limits, replays identical worlds, and checks that initially separated founders meet, conceive and produce children with recorded parents.
