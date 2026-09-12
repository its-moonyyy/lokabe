SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS poker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE poker;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(32) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  api_token VARCHAR(64) NULL,
  avatar CHAR(2) NOT NULL DEFAULT '♠',
  chips BIGINT UNSIGNED NOT NULL DEFAULT 10000,
  wins INT UNSIGNED NOT NULL DEFAULT 0,
  hands_played INT UNSIGNED NOT NULL DEFAULT 0,
  is_bot TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) NOT NULL,
  small_blind INT UNSIGNED NOT NULL,
  big_blind INT UNSIGNED NOT NULL,
  min_buyin BIGINT UNSIGNED NOT NULL,
  max_buyin BIGINT UNSIGNED NOT NULL,
  max_players TINYINT UNSIGNED NOT NULL DEFAULT 6,
  sort INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS game_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id INT UNSIGNED NOT NULL,
  hand_id INT UNSIGNED NOT NULL DEFAULT 0,
  phase ENUM('idle','preflop','flop','turn','river','showdown') NOT NULL DEFAULT 'idle',
  status ENUM('open','paused','ended') NOT NULL DEFAULT 'open',
  dealer_seat SMALLINT NOT NULL DEFAULT 0,
  turn_seat SMALLINT NULL,
  current_bet BIGINT UNSIGNED NOT NULL DEFAULT 0,
  min_raise BIGINT UNSIGNED NOT NULL DEFAULT 0,
  to_act INT NOT NULL DEFAULT 0,
  pot BIGINT UNSIGNED NOT NULL DEFAULT 0,
  community_cards VARCHAR(255) NOT NULL DEFAULT '[]',
  deck VARCHAR(300) NULL,
  turn_started_at DATETIME NULL,
  showdown_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_room (room_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS players (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  is_bot TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bot_level ENUM('easy','medium','hard') NULL,
  seat SMALLINT NOT NULL,
  stack BIGINT UNSIGNED NOT NULL DEFAULT 0,
  street_bet BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_bet BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cards VARCHAR(16) NULL,
  folded TINYINT UNSIGNED NOT NULL DEFAULT 0,
  all_in TINYINT UNSIGNED NOT NULL DEFAULT 0,
  has_acted TINYINT UNSIGNED NOT NULL DEFAULT 0,
  result VARCHAR(255) NULL,
  connected TINYINT UNSIGNED NOT NULL DEFAULT 1,
  last_seen DATETIME NULL,
  UNIQUE KEY uq_seat (session_id, seat),
  KEY idx_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  username VARCHAR(32) NOT NULL DEFAULT '',
  message VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_session (session_id, id)
) ENGINE=InnoDB;

INSERT INTO rooms (name, small_blind, big_blind, min_buyin, max_buyin, max_players, sort) VALUES
  ('Aurum Table',   5,   10,    500,   2000,   6, 1),
  ('Emerald Lounge', 25,  50,   2000,  10000,  6, 2),
  ('Onyx Room',     100,  200,   5000,  50000,  6, 3),
  ('High Rollers',  500,  1000, 20000, 200000,  6, 4)
ON DUPLICATE KEY UPDATE
  name = VALUES(name);

INSERT IGNORE INTO users (username, password_hash, chips, is_bot) VALUES
  ('Brax',   '', 20000, 1),
  ('Lyra',   '', 20000, 1),
  ('Nico',   '', 20000, 1),
  ('Odette', '', 20000, 1),
  ('Vance',  '', 20000, 1),
  ('Rook',   '', 20000, 1),
  ('Blair',  '', 20000, 1),
  ('Juno',   '', 20000, 1);