-- Wildlife populations are aggregated by tile and species to avoid one row per animal.
CREATE TABLE wildlife_populations (
 world_id BIGINT UNSIGNED NOT NULL,
 tile_id BIGINT UNSIGNED NOT NULL,
 species ENUM('deer','rabbit') NOT NULL,
 population INT UNSIGNED NOT NULL DEFAULT 0,
 capacity INT UNSIGNED NOT NULL,
 PRIMARY KEY (tile_id, species),
 KEY idx_wildlife_world_species (world_id, species),
 FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
 FOREIGN KEY (tile_id) REFERENCES world_tiles(id) ON DELETE CASCADE,
 CONSTRAINT chk_wildlife_capacity CHECK (population <= capacity)
) ENGINE=InnoDB;
