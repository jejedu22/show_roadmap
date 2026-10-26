-- Base SQLite de l'ancienne version (FastAPI), données fictives de démonstration.
BEGIN TRANSACTION;
CREATE TABLE companies (
	id INTEGER NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	notes TEXT NOT NULL, 
	PRIMARY KEY (id)
);
INSERT INTO "companies" VALUES(1,'Compagnie Démo','');
INSERT INTO "companies" VALUES(2,'Cie Vide','');
CREATE TABLE days (
	id INTEGER NOT NULL, 
	participation_id INTEGER NOT NULL, 
	day DATE NOT NULL, 
	night INTEGER NOT NULL, 
	meals INTEGER NOT NULL, 
	breakfast INTEGER NOT NULL, 
	PRIMARY KEY (id), 
	UNIQUE (participation_id, day), 
	FOREIGN KEY(participation_id) REFERENCES participations (id) ON DELETE CASCADE
);
INSERT INTO "days" VALUES(1,1,'2026-05-18',1,1,0);
INSERT INTO "days" VALUES(2,1,'2026-05-19',1,2,1);
INSERT INTO "days" VALUES(3,1,'2026-05-20',1,2,1);
INSERT INTO "days" VALUES(4,1,'2026-05-21',1,2,1);
INSERT INTO "days" VALUES(5,1,'2026-05-22',1,2,1);
INSERT INTO "days" VALUES(6,1,'2026-05-23',0,1,1);
INSERT INTO "days" VALUES(7,2,'2026-05-16',1,1,0);
INSERT INTO "days" VALUES(8,2,'2026-05-17',1,2,1);
INSERT INTO "days" VALUES(9,2,'2026-05-18',1,2,1);
INSERT INTO "days" VALUES(10,2,'2026-05-19',1,2,1);
INSERT INTO "days" VALUES(11,2,'2026-05-20',1,2,1);
INSERT INTO "days" VALUES(12,2,'2026-05-21',1,2,1);
INSERT INTO "days" VALUES(13,2,'2026-05-22',1,2,1);
INSERT INTO "days" VALUES(14,2,'2026-05-23',0,1,1);
INSERT INTO "days" VALUES(15,3,'2026-05-18',1,1,0);
INSERT INTO "days" VALUES(16,3,'2026-05-19',1,2,1);
INSERT INTO "days" VALUES(17,3,'2026-05-20',1,2,1);
INSERT INTO "days" VALUES(18,3,'2026-05-21',1,2,1);
INSERT INTO "days" VALUES(19,3,'2026-05-22',1,2,1);
INSERT INTO "days" VALUES(20,3,'2026-05-23',0,1,1);
INSERT INTO "days" VALUES(21,4,'2026-05-18',1,1,0);
INSERT INTO "days" VALUES(22,4,'2026-05-19',1,2,1);
INSERT INTO "days" VALUES(23,4,'2026-05-20',1,2,1);
INSERT INTO "days" VALUES(24,4,'2026-05-21',1,2,1);
INSERT INTO "days" VALUES(25,4,'2026-05-22',1,2,1);
INSERT INTO "days" VALUES(26,4,'2026-05-23',0,1,1);
INSERT INTO "days" VALUES(27,5,'2026-05-20',1,1,0);
INSERT INTO "days" VALUES(28,5,'2026-05-21',1,2,1);
INSERT INTO "days" VALUES(29,5,'2026-05-22',1,2,1);
INSERT INTO "days" VALUES(30,5,'2026-05-23',0,1,1);
INSERT INTO "days" VALUES(31,6,'2026-05-20',1,1,0);
INSERT INTO "days" VALUES(32,6,'2026-05-21',1,2,1);
INSERT INTO "days" VALUES(33,6,'2026-05-22',1,2,1);
INSERT INTO "days" VALUES(34,6,'2026-05-23',0,1,1);
CREATE TABLE events (
	id INTEGER NOT NULL, 
	show_id INTEGER NOT NULL, 
	venue VARCHAR(255) NOT NULL, 
	city VARCHAR(120) NOT NULL, 
	address VARCHAR(255) NOT NULL, 
	venue_contact TEXT NOT NULL, 
	status VARCHAR(20) NOT NULL, 
	first_date DATE NOT NULL, 
	last_date DATE NOT NULL, 
	tech_arrival DATE, 
	artistic_arrival DATE, 
	departure DATE, 
	performances INTEGER NOT NULL, 
	notes TEXT NOT NULL, 
	meal_rate FLOAT NOT NULL, 
	breakfast_rate FLOAT NOT NULL, 
	night_rate FLOAT NOT NULL, 
	truck_rate FLOAT NOT NULL, 
	vat_rate FLOAT NOT NULL, 
	fee FLOAT NOT NULL, 
	lighting FLOAT NOT NULL, 
	posters FLOAT NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(show_id) REFERENCES shows (id) ON DELETE CASCADE
);
INSERT INTO "events" VALUES(1,1,'Théâtre du Beauvaisis — Scène nationale','Beauvais','3 place Georges Brassens, 60000 Beauvais','Accueil : Jeanne Exemple — 03 00 00 00 00','option','2026-05-19','2026-05-23','2026-05-16','2026-05-18','2026-05-23',3,'Note « spéciale » =1+1',20.7,7.0,0.0,1.1,5.5,9000.0,450.0,0.0);
INSERT INTO "events" VALUES(2,2,'Festival Exemple','Strasbourg','','','confirmed','2026-05-21','2026-05-22',NULL,'2026-05-20','2026-05-23',2,'',20.7,7.0,0.0,1.1,5.5,5000.0,0.0,0.0);
CREATE TABLE extra_costs (
	id INTEGER NOT NULL, 
	event_id INTEGER NOT NULL, 
	label VARCHAR(255) NOT NULL, 
	amount FLOAT NOT NULL, 
	kind VARCHAR(20) NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(event_id) REFERENCES events (id) ON DELETE CASCADE
);
INSERT INTO "extra_costs" VALUES(1,1,'Métro Paris (2 × 4)',8.0,'team_transport');
CREATE TABLE legs (
	id INTEGER NOT NULL, 
	participation_id INTEGER NOT NULL, 
	direction VARCHAR(10) NOT NULL, 
	day DATE NOT NULL, 
	origin VARCHAR(120) NOT NULL, 
	destination VARCHAR(120) NOT NULL, 
	dep_time VARCHAR(20) NOT NULL, 
	arr_time VARCHAR(20) NOT NULL, 
	mode VARCHAR(60) NOT NULL, 
	reference VARCHAR(60) NOT NULL, 
	price FLOAT NOT NULL, 
	booked_on DATE, 
	note VARCHAR(255) NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(participation_id) REFERENCES participations (id) ON DELETE CASCADE
);
INSERT INTO "legs" VALUES(1,1,'outbound','2026-05-16','Lyon','Paris','9h00','11h00','TGV','ABC123',79.0,NULL,'');
INSERT INTO "legs" VALUES(2,1,'return','2026-05-23','Beauvais','Paris','19h39','20h57','TER','XYZ789',12.4,NULL,'');
CREATE TABLE lodgings (
	id INTEGER NOT NULL, 
	event_id INTEGER NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	address VARCHAR(255) NOT NULL, 
	phone VARCHAR(60) NOT NULL, 
	details TEXT NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(event_id) REFERENCES events (id) ON DELETE CASCADE
);
INSERT INTO "lodgings" VALUES(1,1,'Hôtel Cathédrale','11 rue Chambiges, 60000 Beauvais','','Check-in 15h / check-out 10h30. Petit-déjeuner 7h–9h.');
CREATE TABLE participations (
	id INTEGER NOT NULL, 
	event_id INTEGER NOT NULL, 
	person_id INTEGER NOT NULL, 
	role VARCHAR(120) NOT NULL, 
	room_type VARCHAR(40) NOT NULL, 
	departure_city VARCHAR(120) NOT NULL, 
	lodging_id INTEGER, 
	note VARCHAR(255) NOT NULL, 
	PRIMARY KEY (id), 
	UNIQUE (event_id, person_id), 
	FOREIGN KEY(event_id) REFERENCES events (id) ON DELETE CASCADE, 
	FOREIGN KEY(person_id) REFERENCES people (id) ON DELETE CASCADE, 
	FOREIGN KEY(lodging_id) REFERENCES lodgings (id) ON DELETE SET NULL
);
INSERT INTO "participations" VALUES(1,1,1,'Admin tournée','single','Lyon',1,'');
INSERT INTO "participations" VALUES(2,1,2,'Régisseur lumière','single','Marseille',1,'');
INSERT INTO "participations" VALUES(3,1,3,'Danseuse','single','Paris',1,'');
INSERT INTO "participations" VALUES(4,1,4,'Danseur','single','Lille',1,'');
INSERT INTO "participations" VALUES(5,2,4,'Danseur','single','Lille',NULL,'');
INSERT INTO "participations" VALUES(6,2,5,'Danseuse','single','Paris',NULL,'');
CREATE TABLE people (
	id INTEGER NOT NULL, 
	company_id INTEGER NOT NULL, 
	last_name VARCHAR(120) NOT NULL, 
	first_name VARCHAR(120) NOT NULL, 
	function VARCHAR(120) NOT NULL, 
	phone VARCHAR(60) NOT NULL, 
	email VARCHAR(255) NOT NULL, 
	diet VARCHAR(255) NOT NULL, 
	home_city VARCHAR(120) NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(company_id) REFERENCES companies (id) ON DELETE CASCADE
);
INSERT INTO "people" VALUES(1,1,'MARTIN','Alice','Admin tournée','06 00 00 00 01','alice@example.org','','Lyon');
INSERT INTO "people" VALUES(2,1,'DURAND','Bruno','Régisseur lumière','06 00 00 00 02','bruno@example.org','','Marseille');
INSERT INTO "people" VALUES(3,1,'LEROY','Chloé','Danseuse','06 00 00 00 03','chloe@example.org','Végétarienne','Paris');
INSERT INTO "people" VALUES(4,1,'PETIT','David','Danseur','06 00 00 00 04','david@example.org','','Lille');
INSERT INTO "people" VALUES(5,1,'MOREAU','Emma','Danseuse','06 00 00 00 05','emma@example.org','','Paris');
CREATE TABLE roadmap_sections (
	id INTEGER NOT NULL, 
	event_id INTEGER NOT NULL, 
	title VARCHAR(255) NOT NULL, 
	body TEXT NOT NULL, 
	position INTEGER NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(event_id) REFERENCES events (id) ON DELETE CASCADE
);
INSERT INTO "roadmap_sections" VALUES(1,1,'ACCÈS AU THÉÂTRE','Entrée par l''arrière du bâtiment, quai de déchargement.',10);
CREATE TABLE schedule_items (
	id INTEGER NOT NULL, 
	event_id INTEGER NOT NULL, 
	day DATE NOT NULL, 
	time VARCHAR(20) NOT NULL, 
	label VARCHAR(255) NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(event_id) REFERENCES events (id) ON DELETE CASCADE
);
INSERT INTO "schedule_items" VALUES(1,1,'2026-05-18','10h–18h','Montage et réglages');
CREATE TABLE shows (
	id INTEGER NOT NULL, 
	company_id INTEGER NOT NULL, 
	title VARCHAR(255) NOT NULL, 
	description TEXT NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(company_id) REFERENCES companies (id) ON DELETE CASCADE
);
INSERT INTO "shows" VALUES(1,1,'Le Murmure des songes','');
INSERT INTO "shows" VALUES(2,1,'Prélude','');
CREATE TABLE truck_legs (
	id INTEGER NOT NULL, 
	event_id INTEGER NOT NULL, 
	label VARCHAR(255) NOT NULL, 
	km FLOAT NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(event_id) REFERENCES events (id) ON DELETE CASCADE
);
INSERT INTO "truck_legs" VALUES(1,1,'Paris → Beauvais',90.0);
CREATE TABLE user_company (
	user_id INTEGER NOT NULL, 
	company_id INTEGER NOT NULL, 
	PRIMARY KEY (user_id, company_id), 
	FOREIGN KEY(user_id) REFERENCES users (id) ON DELETE CASCADE, 
	FOREIGN KEY(company_id) REFERENCES companies (id) ON DELETE CASCADE
);
INSERT INTO "user_company" VALUES(1,1);
INSERT INTO "user_company" VALUES(2,1);
CREATE TABLE users (
	id INTEGER NOT NULL, 
	email VARCHAR(255) NOT NULL, 
	name VARCHAR(255) NOT NULL, 
	password_hash VARCHAR(255) NOT NULL, 
	is_admin BOOLEAN NOT NULL, 
	is_active BOOLEAN NOT NULL, 
	PRIMARY KEY (id)
);
INSERT INTO "users" VALUES(1,'admin@example.com','Administrateur','scrypt$b5797bbb8d0d73275897c686539a08f4$5be550fc6a31b2d4e4b176bb214b636cc775e64d83d455adce27c12c84741d7c820fb6b3f7304a902f9125cd7d4f17ebb8da6cd8de2ef5b9bf14f0796d7e8af4',1,1);
INSERT INTO "users" VALUES(2,'Bob@Example.com','Bob','scrypt$1fa7f11402b469a4a7a36a975f9e73b6$25aaffc844298c3211bcd2f908f6a4e86f5c7a458fe5320c2d331aa219623b451526674b1744bd8978043a7c1791d52b8656a59e4042193c48737feb9682fe3f',0,1);
INSERT INTO "users" VALUES(3,'old@example.com','Ancien','scrypt$75823136e66a089ca2ce87b4e090eb8c$9a11b48e83c9dd00874651ab9e762c433c5ceb76d97dfcbd1972ea2f4efc9a4584749df8a4bf18d241bcc757484f27b42f2ad849ce89feeba8435ff7364867ba',0,0);
CREATE UNIQUE INDEX ix_users_email ON users (email);
CREATE INDEX ix_shows_company_id ON shows (company_id);
CREATE INDEX ix_people_company_id ON people (company_id);
CREATE INDEX ix_events_show_id ON events (show_id);
CREATE INDEX ix_lodgings_event_id ON lodgings (event_id);
CREATE INDEX ix_truck_legs_event_id ON truck_legs (event_id);
CREATE INDEX ix_extra_costs_event_id ON extra_costs (event_id);
CREATE INDEX ix_roadmap_sections_event_id ON roadmap_sections (event_id);
CREATE INDEX ix_schedule_items_event_id ON schedule_items (event_id);
CREATE INDEX ix_participations_event_id ON participations (event_id);
CREATE INDEX ix_participations_person_id ON participations (person_id);
CREATE INDEX ix_days_participation_id ON days (participation_id);
CREATE INDEX ix_legs_participation_id ON legs (participation_id);
COMMIT;
