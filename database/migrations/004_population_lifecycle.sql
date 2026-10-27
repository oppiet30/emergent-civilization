ALTER TABLE agents
 ADD COLUMN sex ENUM('female','male') NULL,
 ADD COLUMN age_ticks INT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN mother_id BIGINT UNSIGNED NULL,
 ADD COLUMN father_id BIGINT UNSIGNED NULL,
 ADD KEY idx_agents_mother (mother_id),
 ADD KEY idx_agents_father (father_id),
 ADD CONSTRAINT fk_agents_mother FOREIGN KEY (mother_id) REFERENCES agents(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_agents_father FOREIGN KEY (father_id) REFERENCES agents(id) ON DELETE SET NULL;
CREATE TABLE pregnancies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 world_id BIGINT UNSIGNED NOT NULL,
 mother_id BIGINT UNSIGNED NOT NULL,
 father_id BIGINT UNSIGNED NOT NULL,
 conceived_tick BIGINT UNSIGNED NOT NULL,
 due_tick BIGINT UNSIGNED NOT NULL,
 resolved_tick BIGINT UNSIGNED NULL,
 child_id BIGINT UNSIGNED NULL,
 KEY idx_pregnancies_due (world_id,due_tick,resolved_tick),
 FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE,
 FOREIGN KEY (mother_id) REFERENCES agents(id),
 FOREIGN KEY (father_id) REFERENCES agents(id),
 FOREIGN KEY (child_id) REFERENCES agents(id) ON DELETE SET NULL
) ENGINE=InnoDB;
