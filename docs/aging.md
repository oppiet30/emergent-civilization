# Aging and lifespan

One tick is one day; the game uses 365-day years. Survivors age once per tick. Old-age deaths are evaluated at the start of the tick using the age that person would reach that day, before movement, food sharing or reproduction. Dead people keep their age at death and ancestry links.

These are illustrative game settings, not medical or historical demographic estimates:

| Age reached | Daily old-age death chance |
| --- | --- |
| Under 60 | 0 |
| 60–69 | 5 / 100,000 |
| 70–79 | 15 / 100,000 |
| 80–89 | 40 / 100,000 |
| 90–99 | 100 / 100,000 |
| 100–109 | 250 / 100,000 |
| 110 or older | Certain |

Eligible agents are checked in ID order using the world's saved random generator. Agents under 60 use no mortality random draw. The maximum age rule uses no random draw. Identical initial simulation states replay identically, but installing these new rules changes future trajectories.

Old-age deaths set health and alive to zero and create one ordinary `death` event with details `Died from old age`. Total deaths include all causes. CSV and console reports append `old_age_deaths_interval`, counting deaths during the complete reporting interval. Existing starvation and child-death counters retain their meaning.

No migration or reseeding is needed. Existing people are evaluated at their current age on the next tick; someone already 110 or older dies on that next tick. Older caregivers may die, after which the existing childcare/reunion rules consider living adults. Pending deliveries from a mother who died cannot create a child. Previously recorded deaths remain unchanged.

This first version does not model disease, frailty, disability, age-related fertility changes beyond the existing reproduction window, or differing mortality by sex.
