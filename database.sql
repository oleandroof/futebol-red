-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 26/03/2026 às 18:37
-- Versão do servidor: 10.4.32-MariaDB
-- Versão do PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `padasorte`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `bet_tickets`
--

CREATE TABLE `bet_tickets` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticket_code` varchar(40) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `stake` decimal(12,2) NOT NULL,
  `total_odd` decimal(10,2) NOT NULL,
  `potential_return` decimal(12,2) NOT NULL,
  `status` enum('open','won','lost','cancelled') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `bet_tickets`
--

INSERT INTO `bet_tickets` (`id`, `ticket_code`, `user_id`, `stake`, `total_odd`, `potential_return`, `status`, `created_at`) VALUES
(1, 'BT202603060001', 2, 25.00, 3.37, 84.25, 'open', '2026-03-06 02:08:12'),
(2, 'BT2026030606162360', 1, 10.00, 2.00, 20.00, 'won', '2026-03-06 02:16:23'),
(3, 'BT2026030606193034', 1, 10.00, 4.00, 40.00, 'lost', '2026-03-06 02:19:30'),
(4, 'BT2026030616290698', 1, 10.00, 1.84, 18.40, 'open', '2026-03-06 12:29:06'),
(5, 'BT2026030619204361', 1, 10.00, 1.84, 18.40, 'open', '2026-03-06 15:20:43'),
(6, 'BT2026030705103192', 1, 10.00, 3.50, 34.96, 'open', '2026-03-07 01:10:31'),
(7, 'BT2026032613400427', 1, 10.00, 3.00, 30.00, 'open', '2026-03-26 13:40:04'),
(8, 'BT2026032614251268', 1, 10.00, 3.00, 30.00, 'open', '2026-03-26 14:25:12');

-- --------------------------------------------------------

--
-- Estrutura para tabela `bet_ticket_items`
--

CREATE TABLE `bet_ticket_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `ticket_id` int(10) UNSIGNED NOT NULL,
  `game_id` int(10) UNSIGNED NOT NULL,
  `odd_id` int(10) UNSIGNED NOT NULL,
  `market_name` varchar(120) NOT NULL,
  `option_name` varchar(120) NOT NULL,
  `odd_value` decimal(8,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `bet_ticket_items`
--

INSERT INTO `bet_ticket_items` (`id`, `ticket_id`, `game_id`, `odd_id`, `market_name`, `option_name`, `odd_value`) VALUES
(1, 1, 1, 1, 'Vencedor do Encontro', 'Casa', 2.02),
(3, 2, 14, 48, 'Resultado final', 'Casa', 2.00),
(4, 3, 15, 51, 'Resultado final', 'Casa', 2.00),
(5, 3, 15, 56, 'Ambos marcam', 'Sim', 2.00),
(6, 4, 2, 11, 'Resultado final', 'Casa', 1.84),
(7, 5, 2, 11, 'Resultado final', 'Casa', 1.84),
(8, 6, 2, 11, 'Resultado final', 'Casa', 1.84),
(9, 6, 3, 18, 'Resultado final', 'Casa', 1.90),
(10, 7, 92, 286, 'Resultado final', 'Casa', 3.00),
(11, 8, 92, 286, 'Resultado final', 'Casa', 3.00);

-- --------------------------------------------------------

--
-- Estrutura para tabela `categories`
--

CREATE TABLE `categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `categories`
--

INSERT INTO `categories` (`id`, `name`, `slug`, `is_active`, `sort_order`) VALUES
(1, 'Futebol', 'futebol', 1, 1),
(2, 'Basquete', 'basquete', 1, 2),
(3, 'Tenis', 'tenis', 1, 3),
(4, 'MMA', 'mma', 1, 4);

-- --------------------------------------------------------

--
-- Estrutura para tabela `games`
--

CREATE TABLE `games` (
  `id` int(10) UNSIGNED NOT NULL,
  `league_id` int(10) UNSIGNED NOT NULL,
  `sport` varchar(50) NOT NULL,
  `home_team_id` int(10) UNSIGNED NOT NULL,
  `away_team_id` int(10) UNSIGNED NOT NULL,
  `match_date` datetime NOT NULL,
  `status` enum('scheduled','live','finished') NOT NULL DEFAULT 'scheduled',
  `risk_level` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `betting_locked` tinyint(1) NOT NULL DEFAULT 0,
  `betting_only_before_start` tinyint(1) NOT NULL DEFAULT 0,
  `betting_lock_after_minutes` smallint(5) UNSIGNED DEFAULT NULL,
  `game_origin` enum('admin','api') NOT NULL DEFAULT 'admin',
  `created_by_user_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `flashscore_match_id` varchar(64) DEFAULT NULL,
  `sofascore_match_id` varchar(64) DEFAULT NULL,
  `xscores_match_id` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `games`
--

INSERT INTO `games` (`id`, `league_id`, `sport`, `home_team_id`, `away_team_id`, `match_date`, `status`, `risk_level`, `betting_locked`, `betting_only_before_start`, `betting_lock_after_minutes`, `game_origin`, `created_by_user_id`, `created_at`, `flashscore_match_id`, `sofascore_match_id`, `xscores_match_id`) VALUES
(1, 1, 'Futebol', 1, 2, '2026-03-06 16:30:00', 'finished', 'high', 0, 0, NULL, 'admin', 1, '2026-03-06 02:08:12', NULL, NULL, NULL),
(2, 1, 'Futebol', 3, 5, '2026-03-07 14:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-06 02:08:12', NULL, NULL, NULL),
(3, 2, 'Futebol', 11, 12, '2026-03-07 17:00:00', 'scheduled', 'high', 0, 0, NULL, 'admin', 1, '2026-03-06 02:08:12', NULL, NULL, NULL),
(14, 5, 'Futebol', 81, 97, '2026-03-06 04:17:00', 'finished', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-06 02:16:12', NULL, NULL, NULL),
(15, 5, 'Futebol', 81, 97, '2026-03-06 04:19:00', 'finished', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-06 02:19:12', NULL, NULL, NULL),
(92, 41, 'Futebol', 278, 279, '2026-03-26 17:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 13:39:22', NULL, '15341366', NULL),
(117, 58, 'Futebol', 328, 329, '2026-03-26 16:45:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'IigYMdQH', NULL, NULL),
(118, 58, 'Futebol', 330, 331, '2026-03-26 16:45:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', '8MHjuvtn', NULL, NULL),
(119, 58, 'Futebol', 332, 333, '2026-03-26 16:45:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'v7ssLzeU', NULL, NULL),
(120, 58, 'Futebol', 334, 335, '2026-03-26 16:45:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', '4lBawIAb', NULL, NULL),
(121, 58, 'Futebol', 336, 337, '2026-03-26 16:45:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'ruWYiHPi', NULL, NULL),
(122, 58, 'Futebol', 338, 339, '2026-03-26 16:45:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'x297yduB', NULL, NULL),
(123, 58, 'Futebol', 340, 341, '2026-03-26 16:45:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'YT2GZZ9N', NULL, NULL),
(124, 58, 'Futebol', 342, 343, '2026-03-26 19:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'UPBJl1go', NULL, NULL),
(125, 58, 'Futebol', 344, 345, '2026-03-26 00:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'bsLmfNVN', NULL, NULL),
(126, 59, 'Futebol', 346, 347, '2026-03-26 17:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'OC7rxIJi', NULL, NULL),
(127, 59, 'Futebol', 348, 349, '2026-03-26 18:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'AVUVAuLh', NULL, NULL),
(128, 59, 'Futebol', 350, 351, '2026-03-26 18:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'Ig9bYZIG', NULL, NULL),
(129, 59, 'Futebol', 352, 353, '2026-03-26 21:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'ltWVFR2k', NULL, NULL),
(130, 59, 'Futebol', 354, 355, '2026-03-26 21:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'rcrw9Jk5', NULL, NULL),
(131, 59, 'Futebol', 356, 357, '2026-03-26 21:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'WIKdEERp', NULL, NULL),
(132, 60, 'Futebol', 358, 359, '2026-03-26 14:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'dpXdC6Ha', NULL, NULL),
(133, 61, 'Futebol', 360, 361, '2026-03-26 21:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'tx59wY8O', NULL, NULL),
(134, 62, 'Futebol', 362, 363, '2026-03-26 15:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'vXvfKZ43', NULL, NULL),
(135, 62, 'Futebol', 364, 365, '2026-03-26 15:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'C4a10uEU', NULL, NULL),
(136, 62, 'Futebol', 366, 367, '2026-03-26 15:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'jVUBKran', NULL, NULL),
(137, 62, 'Futebol', 368, 369, '2026-03-26 15:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'KAMSG0bB', NULL, NULL),
(138, 62, 'Futebol', 370, 371, '2026-03-26 15:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', '6oLIkOaC', NULL, NULL),
(139, 62, 'Futebol', 372, 373, '2026-03-26 15:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'beYJIMTb', NULL, NULL),
(140, 62, 'Futebol', 374, 375, '2026-03-26 15:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'nwh8dWCd', NULL, NULL),
(141, 62, 'Futebol', 376, 377, '2026-03-26 18:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'jBbabhrp', NULL, NULL),
(142, 62, 'Futebol', 378, 379, '2026-03-26 19:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'xS4x6qpg', NULL, NULL),
(143, 62, 'Futebol', 380, 381, '2026-03-26 20:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'YXMAipVa', NULL, NULL),
(144, 62, 'Futebol', 382, 383, '2026-03-26 20:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'x6T1g60m', NULL, NULL),
(145, 63, 'Futebol', 384, 385, '2026-03-26 20:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'GQpSTC1n', NULL, NULL),
(146, 64, 'Futebol', 386, 387, '2026-03-26 18:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'tImwGjxB', NULL, NULL),
(147, 64, 'Futebol', 388, 389, '2026-03-26 20:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'AF6DaViU', NULL, NULL),
(148, 64, 'Futebol', 390, 391, '2026-03-26 20:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', '84kVHC7b', NULL, NULL),
(149, 65, 'Futebol', 392, 393, '2026-03-26 19:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'UgU6vL6t', NULL, NULL),
(150, 66, 'Futebol', 394, 395, '2026-03-26 15:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', '6iuUlaXh', NULL, NULL),
(151, 67, 'Futebol', 396, 397, '2026-03-26 19:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'zR63owU6', NULL, NULL),
(152, 67, 'Futebol', 398, 399, '2026-03-26 21:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'WWOyXwFD', NULL, NULL),
(153, 68, 'Futebol', 400, 401, '2026-03-26 15:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'E1pUBk0f', NULL, NULL),
(154, 68, 'Futebol', 402, 403, '2026-03-26 15:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'CYejJX7K', NULL, NULL),
(155, 68, 'Futebol', 404, 405, '2026-03-26 15:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'SOU68yBa', NULL, NULL),
(156, 68, 'Futebol', 406, 407, '2026-03-26 15:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'OEQTFJIP', NULL, NULL),
(157, 68, 'Futebol', 408, 409, '2026-03-26 15:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'KbRbActm', NULL, NULL),
(158, 69, 'Futebol', 410, 411, '2026-03-26 18:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'IBd7SUGM', NULL, NULL),
(159, 69, 'Futebol', 412, 413, '2026-03-26 20:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'M7ooLn9d', NULL, NULL),
(160, 69, 'Futebol', 414, 415, '2026-03-26 20:30:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'EsrwN8vp', NULL, NULL),
(161, 70, 'Futebol', 416, 417, '2026-03-26 18:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'j5RJ6J77', NULL, NULL),
(162, 70, 'Futebol', 418, 419, '2026-03-26 19:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'bqfxNJzi', NULL, NULL),
(163, 70, 'Futebol', 420, 421, '2026-03-26 20:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 't8JMUVgc', NULL, NULL),
(164, 70, 'Futebol', 422, 423, '2026-03-26 21:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'fwYA8uwe', NULL, NULL),
(165, 71, 'Futebol', 424, 425, '2026-03-26 17:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'xjqQcB13', NULL, NULL),
(166, 71, 'Futebol', 426, 427, '2026-03-26 17:00:00', 'scheduled', 'medium', 0, 0, NULL, 'admin', 1, '2026-03-26 14:35:39', 'df6wz89d', NULL, NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `game_results`
--

CREATE TABLE `game_results` (
  `id` int(10) UNSIGNED NOT NULL,
  `game_id` int(10) UNSIGNED NOT NULL,
  `market_name` varchar(120) NOT NULL,
  `result_option` varchar(120) NOT NULL,
  `settled_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `game_results`
--

INSERT INTO `game_results` (`id`, `game_id`, `market_name`, `result_option`, `settled_at`) VALUES
(1, 1, 'Ambos marcam', 'Nao', '2026-03-06 02:13:14'),
(2, 1, 'Dupla chance', 'Casa ou Empate', '2026-03-06 02:14:46'),
(3, 1, 'Resultado final', 'Empate', '2026-03-06 02:15:36'),
(4, 1, 'Total de gols', 'Menos de 2.5', '2026-03-06 02:14:01'),
(15, 14, 'Resultado final', 'Casa', '2026-03-06 02:16:31'),
(16, 15, 'Ambos marcam', 'Nao', '2026-03-06 02:31:08'),
(17, 15, 'Total de gols', 'Menos de 2.5', '2026-03-06 02:20:14'),
(18, 15, 'Resultado final', 'Casa', '2026-03-06 02:20:17');

-- --------------------------------------------------------

--
-- Estrutura para tabela `leagues`
--

CREATE TABLE `leagues` (
  `id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `country_code` varchar(5) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `leagues`
--

INSERT INTO `leagues` (`id`, `category_id`, `name`, `country_code`) VALUES
(1, 1, 'Premier League', 'gb'),
(2, 1, 'La Liga', 'es'),
(5, 1, 'Brasileirao Serie A', 'br'),
(41, 1, 'Int. Friendly Games', 'int'),
(58, 1, 'Copa do Mundo - Qualificação - Acesso', 'int'),
(59, 1, 'CONCACAF Series - Segunda Fase', 'int'),
(60, 1, 'Premier League Feminina', 'int'),
(61, 1, 'Liga Profesional - Abertura', 'ar'),
(62, 1, 'Liga de Reservas - Abertura', 'ar'),
(63, 1, 'Copa Centro-Oeste', 'br'),
(64, 1, 'Copa Norte', 'br'),
(65, 1, 'Copa do Nordeste Superbet', 'br'),
(66, 1, 'Paranaense Sub-20', 'br'),
(67, 1, 'Brasileirão Feminino', 'br'),
(68, 1, 'Brasileiro U20 Women', 'br'),
(69, 1, 'Copa de la Liga', 'cl'),
(70, 1, 'Primeira B - Abertura', 'int'),
(71, 1, 'Liga de Acesso - Encerramento', 'int');

-- --------------------------------------------------------

--
-- Estrutura para tabela `odds`
--

CREATE TABLE `odds` (
  `id` int(10) UNSIGNED NOT NULL,
  `game_id` int(10) UNSIGNED NOT NULL,
  `market_name` varchar(120) NOT NULL,
  `option_name` varchar(120) NOT NULL,
  `odd_value` decimal(8,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `odds`
--

INSERT INTO `odds` (`id`, `game_id`, `market_name`, `option_name`, `odd_value`) VALUES
(1, 1, 'Resultado final', 'Casa', 2.02),
(2, 1, 'Resultado final', 'Empate', 3.30),
(3, 1, 'Resultado final', 'Fora', 3.44),
(4, 1, 'Total de gols', 'Mais de 2.5', 1.88),
(5, 1, 'Total de gols', 'Menos de 2.5', 1.92),
(6, 1, 'Ambos marcam', 'Sim', 1.71),
(7, 1, 'Ambos marcam', 'Nao', 2.04),
(8, 1, 'Dupla chance', 'Casa ou Empate', 1.31),
(9, 1, 'Dupla chance', 'Casa ou Fora', 1.29),
(10, 1, 'Dupla chance', 'Empate ou Fora', 1.70),
(11, 2, 'Resultado final', 'Casa', 1.84),
(12, 2, 'Resultado final', 'Empate', 3.45),
(13, 2, 'Resultado final', 'Fora', 4.05),
(14, 2, 'Total de gols', 'Mais de 2.5', 1.95),
(15, 2, 'Total de gols', 'Menos de 2.5', 1.80),
(16, 2, 'Ambos marcam', 'Sim', 1.76),
(17, 2, 'Ambos marcam', 'Nao', 1.98),
(18, 3, 'Resultado final', 'Casa', 1.90),
(19, 3, 'Resultado final', 'Empate', 3.55),
(20, 3, 'Resultado final', 'Fora', 3.90),
(21, 3, 'Total de gols', 'Mais de 2.5', 1.84),
(22, 3, 'Total de gols', 'Menos de 2.5', 1.96),
(48, 14, 'Resultado final', 'Casa', 2.00),
(49, 14, 'Resultado final', 'Empate', 1.00),
(50, 14, 'Resultado final', 'Fora', 2.00),
(51, 15, 'Resultado final', 'Casa', 2.00),
(52, 15, 'Resultado final', 'Empate', 1.00),
(53, 15, 'Resultado final', 'Fora', 2.00),
(54, 15, 'Total de gols', 'Mais de 2.5', 2.00),
(55, 15, 'Total de gols', 'Menos de 2.5', 2.00),
(56, 15, 'Ambos marcam', 'Sim', 2.00),
(57, 15, 'Ambos marcam', 'Nao', 2.00),
(286, 92, 'Resultado final', 'Casa', 3.00),
(287, 92, 'Resultado final', 'Empate', 3.70),
(288, 92, 'Resultado final', 'Fora', 2.20),
(361, 117, 'Resultado final', 'Casa', 1.20),
(362, 117, 'Resultado final', 'Empate', 6.25),
(363, 117, 'Resultado final', 'Fora', 15.00),
(364, 118, 'Resultado final', 'Casa', 2.35),
(365, 118, 'Resultado final', 'Empate', 3.10),
(366, 118, 'Resultado final', 'Fora', 3.30),
(367, 119, 'Resultado final', 'Casa', 1.27),
(368, 119, 'Resultado final', 'Empate', 5.75),
(369, 119, 'Resultado final', 'Fora', 12.00),
(370, 120, 'Resultado final', 'Casa', 1.67),
(371, 120, 'Resultado final', 'Empate', 3.75),
(372, 120, 'Resultado final', 'Fora', 5.25),
(373, 121, 'Resultado final', 'Casa', 1.65),
(374, 121, 'Resultado final', 'Empate', 3.50),
(375, 121, 'Resultado final', 'Fora', 6.00),
(376, 122, 'Resultado final', 'Casa', 1.90),
(377, 122, 'Resultado final', 'Empate', 3.50),
(378, 122, 'Resultado final', 'Fora', 4.10),
(379, 123, 'Resultado final', 'Casa', 3.40),
(380, 123, 'Resultado final', 'Empate', 3.20),
(381, 123, 'Resultado final', 'Fora', 2.20),
(382, 124, 'Resultado final', 'Casa', 2.15),
(383, 124, 'Resultado final', 'Empate', 3.20),
(384, 124, 'Resultado final', 'Fora', 3.60),
(385, 125, 'Resultado final', 'Casa', 34.00),
(386, 125, 'Resultado final', 'Empate', 13.00),
(387, 125, 'Resultado final', 'Fora', 1.05),
(388, 126, 'Resultado final', 'Casa', 1.50),
(389, 126, 'Resultado final', 'Empate', 3.00),
(390, 126, 'Resultado final', 'Fora', 2.50),
(391, 127, 'Resultado final', 'Casa', 1.50),
(392, 127, 'Resultado final', 'Empate', 3.00),
(393, 127, 'Resultado final', 'Fora', 2.50),
(394, 128, 'Resultado final', 'Casa', 1.91),
(395, 128, 'Resultado final', 'Empate', 3.60),
(396, 128, 'Resultado final', 'Fora', 3.20),
(397, 129, 'Resultado final', 'Casa', 2.00),
(398, 129, 'Resultado final', 'Empate', 3.10),
(399, 129, 'Resultado final', 'Fora', 3.50),
(400, 130, 'Resultado final', 'Casa', 1.90),
(401, 130, 'Resultado final', 'Empate', 3.30),
(402, 130, 'Resultado final', 'Fora', 3.40),
(403, 131, 'Resultado final', 'Casa', 1.50),
(404, 131, 'Resultado final', 'Empate', 3.00),
(405, 131, 'Resultado final', 'Fora', 2.50),
(406, 132, 'Resultado final', 'Casa', 1.50),
(407, 132, 'Resultado final', 'Empate', 3.00),
(408, 132, 'Resultado final', 'Fora', 2.50),
(409, 133, 'Resultado final', 'Casa', 1.91),
(410, 133, 'Resultado final', 'Empate', 3.20),
(411, 133, 'Resultado final', 'Fora', 4.33),
(412, 134, 'Resultado final', 'Casa', 1.50),
(413, 134, 'Resultado final', 'Empate', 3.00),
(414, 134, 'Resultado final', 'Fora', 2.50),
(415, 135, 'Resultado final', 'Casa', 1.50),
(416, 135, 'Resultado final', 'Empate', 3.00),
(417, 135, 'Resultado final', 'Fora', 2.50),
(418, 136, 'Resultado final', 'Casa', 1.50),
(419, 136, 'Resultado final', 'Empate', 3.00),
(420, 136, 'Resultado final', 'Fora', 2.50),
(421, 137, 'Resultado final', 'Casa', 1.50),
(422, 137, 'Resultado final', 'Empate', 3.00),
(423, 137, 'Resultado final', 'Fora', 2.50),
(424, 138, 'Resultado final', 'Casa', 1.50),
(425, 138, 'Resultado final', 'Empate', 3.00),
(426, 138, 'Resultado final', 'Fora', 2.50),
(427, 139, 'Resultado final', 'Casa', 1.50),
(428, 139, 'Resultado final', 'Empate', 3.00),
(429, 139, 'Resultado final', 'Fora', 2.50),
(430, 140, 'Resultado final', 'Casa', 1.50),
(431, 140, 'Resultado final', 'Empate', 3.00),
(432, 140, 'Resultado final', 'Fora', 2.50),
(433, 141, 'Resultado final', 'Casa', 1.50),
(434, 141, 'Resultado final', 'Empate', 3.00),
(435, 141, 'Resultado final', 'Fora', 2.50),
(436, 142, 'Resultado final', 'Casa', 1.50),
(437, 142, 'Resultado final', 'Empate', 3.00),
(438, 142, 'Resultado final', 'Fora', 2.50),
(439, 143, 'Resultado final', 'Casa', 1.50),
(440, 143, 'Resultado final', 'Empate', 3.00),
(441, 143, 'Resultado final', 'Fora', 2.50),
(442, 144, 'Resultado final', 'Casa', 1.50),
(443, 144, 'Resultado final', 'Empate', 3.00),
(444, 144, 'Resultado final', 'Fora', 2.50),
(445, 145, 'Resultado final', 'Casa', 1.50),
(446, 145, 'Resultado final', 'Empate', 3.00),
(447, 145, 'Resultado final', 'Fora', 2.50),
(448, 146, 'Resultado final', 'Casa', 1.50),
(449, 146, 'Resultado final', 'Empate', 3.00),
(450, 146, 'Resultado final', 'Fora', 2.50),
(451, 147, 'Resultado final', 'Casa', 1.50),
(452, 147, 'Resultado final', 'Empate', 3.00),
(453, 147, 'Resultado final', 'Fora', 2.50),
(454, 148, 'Resultado final', 'Casa', 1.50),
(455, 148, 'Resultado final', 'Empate', 3.00),
(456, 148, 'Resultado final', 'Fora', 2.50),
(457, 149, 'Resultado final', 'Casa', 2.38),
(458, 149, 'Resultado final', 'Empate', 3.25),
(459, 149, 'Resultado final', 'Fora', 2.60),
(460, 150, 'Resultado final', 'Casa', 1.50),
(461, 150, 'Resultado final', 'Empate', 3.00),
(462, 150, 'Resultado final', 'Fora', 2.50),
(463, 151, 'Resultado final', 'Casa', 1.50),
(464, 151, 'Resultado final', 'Empate', 3.00),
(465, 151, 'Resultado final', 'Fora', 2.50),
(466, 152, 'Resultado final', 'Casa', 1.50),
(467, 152, 'Resultado final', 'Empate', 3.00),
(468, 152, 'Resultado final', 'Fora', 2.50),
(469, 153, 'Resultado final', 'Casa', 1.50),
(470, 153, 'Resultado final', 'Empate', 3.00),
(471, 153, 'Resultado final', 'Fora', 2.50),
(472, 154, 'Resultado final', 'Casa', 1.50),
(473, 154, 'Resultado final', 'Empate', 3.00),
(474, 154, 'Resultado final', 'Fora', 2.50),
(475, 155, 'Resultado final', 'Casa', 1.50),
(476, 155, 'Resultado final', 'Empate', 3.00),
(477, 155, 'Resultado final', 'Fora', 2.50),
(478, 156, 'Resultado final', 'Casa', 1.50),
(479, 156, 'Resultado final', 'Empate', 3.00),
(480, 156, 'Resultado final', 'Fora', 2.50),
(481, 157, 'Resultado final', 'Casa', 1.50),
(482, 157, 'Resultado final', 'Empate', 3.00),
(483, 157, 'Resultado final', 'Fora', 2.50),
(484, 158, 'Resultado final', 'Casa', 1.50),
(485, 158, 'Resultado final', 'Empate', 3.00),
(486, 158, 'Resultado final', 'Fora', 2.50),
(487, 159, 'Resultado final', 'Casa', 1.50),
(488, 159, 'Resultado final', 'Empate', 3.00),
(489, 159, 'Resultado final', 'Fora', 2.50),
(490, 160, 'Resultado final', 'Casa', 1.50),
(491, 160, 'Resultado final', 'Empate', 3.00),
(492, 160, 'Resultado final', 'Fora', 2.50),
(493, 161, 'Resultado final', 'Casa', 1.50),
(494, 161, 'Resultado final', 'Empate', 3.00),
(495, 161, 'Resultado final', 'Fora', 2.50),
(496, 162, 'Resultado final', 'Casa', 1.50),
(497, 162, 'Resultado final', 'Empate', 3.00),
(498, 162, 'Resultado final', 'Fora', 2.50),
(499, 163, 'Resultado final', 'Casa', 1.50),
(500, 163, 'Resultado final', 'Empate', 3.00),
(501, 163, 'Resultado final', 'Fora', 2.50),
(502, 164, 'Resultado final', 'Casa', 1.50),
(503, 164, 'Resultado final', 'Empate', 3.00),
(504, 164, 'Resultado final', 'Fora', 2.50),
(505, 165, 'Resultado final', 'Casa', 1.50),
(506, 165, 'Resultado final', 'Empate', 3.00),
(507, 165, 'Resultado final', 'Fora', 2.50),
(508, 166, 'Resultado final', 'Casa', 1.50),
(509, 166, 'Resultado final', 'Empate', 3.00),
(510, 166, 'Resultado final', 'Fora', 2.50);

-- --------------------------------------------------------

--
-- Estrutura para tabela `pix_transactions`
--

CREATE TABLE `pix_transactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `reference_code` varchar(80) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `tx_type` enum('deposit','withdraw') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` enum('pending','paid','failed') NOT NULL DEFAULT 'pending',
  `provider` varchar(40) NOT NULL DEFAULT 'ecompag',
  `provider_transaction_id` varchar(100) DEFAULT NULL,
  `qr_code` text DEFAULT NULL,
  `request_payload` longtext DEFAULT NULL,
  `response_payload` longtext DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `pix_transactions`
--

INSERT INTO `pix_transactions` (`id`, `reference_code`, `user_id`, `tx_type`, `amount`, `status`, `provider`, `provider_transaction_id`, `qr_code`, `request_payload`, `response_payload`, `applied_at`, `created_at`) VALUES
(1, 'PIXDEP-20260306-0001', 2, 'deposit', 200.00, 'pending', 'ecompag', NULL, NULL, NULL, NULL, NULL, '2026-03-06 02:08:13');

-- --------------------------------------------------------

--
-- Estrutura para tabela `settings`
--

CREATE TABLE `settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`) VALUES
(1, 'site_theme', 'classic'),
(2, 'ecompag_client_id', ''),
(3, 'ecompag_client_secret', ''),
(4, 'ecompag_webhook_url', 'https://seusite.com/webhook/ecompag'),
(5, 'risk_limit', '10000'),
(8, 'betting_lock_all_games', '0');

-- --------------------------------------------------------

--
-- Estrutura para tabela `teams`
--

CREATE TABLE `teams` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `logo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `teams`
--

INSERT INTO `teams` (`id`, `name`, `logo`) VALUES
(1, 'Manchester City', NULL),
(2, 'Liverpool', NULL),
(3, 'Arsenal', NULL),
(5, 'Manchester United', NULL),
(11, 'Real Madrid', NULL),
(12, 'Barcelona', NULL),
(81, 'ABC', NULL),
(97, 'America de Cali', NULL),
(278, 'Brazil', NULL),
(279, 'France', NULL),
(328, 'Dinamarca', NULL),
(329, 'Macedônia do Norte', NULL),
(330, 'Eslováquia', NULL),
(331, 'Kosovo', NULL),
(332, 'Itália', NULL),
(333, 'Irlanda do Norte', NULL),
(334, 'País de Gales', NULL),
(335, 'Bósnia e Herzegovina', NULL),
(336, 'Polônia', NULL),
(337, 'Albânia', NULL),
(338, 'Republica Tcheca', NULL),
(339, 'Irlanda', NULL),
(340, 'Ucrânia', NULL),
(341, 'Suécia', NULL),
(342, 'Bolívia', NULL),
(343, 'Suriname', NULL),
(344, 'Nova Caledônia', NULL),
(345, 'Jamaica', NULL),
(346, 'Saint Martin', NULL),
(347, 'Barbados', NULL),
(348, 'Martinica', NULL),
(349, 'Cuba', NULL),
(350, 'Bahamas', NULL),
(351, 'Anguilha', NULL),
(352, 'Ilhas Caimão', NULL),
(353, 'Ilhas Virgens Britânicas', NULL),
(354, 'República Dominicana', NULL),
(355, 'El Salvador', NULL),
(356, 'Bonaire', NULL),
(357, 'São Vicente e Grenadines', NULL),
(358, 'Al-Hilal F', NULL),
(359, 'Al Nassr Riyadh F', NULL),
(360, 'Argentinos Juniors', NULL),
(361, 'Lanús', NULL),
(362, 'Atl. Rafaela 2', NULL),
(363, 'Platense 2', NULL),
(364, 'Defensa y Justicia 2', NULL),
(365, 'Deportivo Riestra 2', NULL),
(366, 'Estudiantes Rio Cuarto 2', NULL),
(367, 'Ind. Rivadavia 2', NULL),
(368, 'Ferro 2', NULL),
(369, 'Central Córdoba 2', NULL),
(370, 'Godoy Cruz 2', NULL),
(371, 'San Lorenzo 2', NULL),
(372, 'Huracan 2', NULL),
(373, 'San Martin S.J. 2', NULL),
(374, 'Independiente 2', NULL),
(375, 'Quilmes 2', NULL),
(376, 'Boca Juniors 2', NULL),
(377, 'Estudiantes 2', NULL),
(378, 'Talleres Cordoba 2', NULL),
(379, 'Banfield 2', NULL),
(380, 'Atletico Tucuman 2', NULL),
(381, 'Velez Sarsfield 2', NULL),
(382, 'Union Santa Fe 2', NULL),
(383, 'River Plate 2', NULL),
(384, 'Atlético-GO', NULL),
(385, 'Anápolis', NULL),
(386, 'Galvez', NULL),
(387, 'Amazonas', NULL),
(388, 'Nacional-AM', NULL),
(389, 'Trem', NULL),
(390, 'Porto Velho', NULL),
(391, 'Remo', NULL),
(392, 'Sousa', NULL),
(393, 'Confiança', NULL),
(394, 'Athletico-PR U20', NULL),
(395, 'Paraná U20', NULL),
(396, 'Cruzeiro F', NULL),
(397, 'Fluminense F', NULL),
(398, 'Juventude F', NULL),
(399, 'Flamengo F', NULL),
(400, 'Criciúma U20 F', NULL),
(401, 'Vasco U20 F', NULL),
(402, 'Litoral Norte U20 F', NULL),
(403, 'UD Alagoana U20 F', NULL),
(404, 'Palmeiras U20 M', NULL),
(405, 'Santos U20 F', NULL),
(406, 'São Paulo U20 M', NULL),
(407, 'Ferroviária U20 F', NULL),
(408, 'Aliança - GO U20 F', NULL),
(409, 'Sport U20 F', NULL),
(410, 'Limache', NULL),
(411, 'Palestino', NULL),
(412, 'Everton', NULL),
(413, 'O\'Higgins', NULL),
(414, 'Universidad de Concepción', NULL),
(415, 'Cobresal', NULL),
(416, 'U. Magdalena', NULL),
(417, 'Real Santander', NULL),
(418, 'Inter Palmira', NULL),
(419, 'Tigres', NULL),
(420, 'Ind. Yumbo', NULL),
(421, 'Patriotas', NULL),
(422, 'Quindio', NULL),
(423, 'Orsomarso', NULL),
(424, 'Cariari Pococi', NULL),
(425, 'Pitbulls', NULL),
(426, 'Escorpiones', NULL),
(427, 'CS Uruguay', NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `transactions`
--

CREATE TABLE `transactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `reference` varchar(80) NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `type` enum('deposit','withdrawal','bet_win','bet_loss') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` enum('pending','paid','failed') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `transactions`
--

INSERT INTO `transactions` (`id`, `reference`, `user_id`, `type`, `amount`, `status`, `created_at`) VALUES
(1, 'DEP-20260306-0001', 2, 'deposit', 500.00, 'paid', '2026-03-06 02:08:13'),
(2, 'BT202603060001', 2, 'bet_loss', 25.00, 'paid', '2026-03-06 02:08:13'),
(3, 'BT2026030606162360', 1, 'bet_loss', 10.00, 'paid', '2026-03-06 02:16:23'),
(4, 'BT2026030606162360-WIN', 1, 'bet_win', 20.00, 'paid', '2026-03-06 02:16:31'),
(5, 'BT2026030606193034', 1, 'bet_loss', 10.00, 'paid', '2026-03-06 02:19:30'),
(7, 'BT2026030616290698', 1, 'bet_loss', 10.00, 'paid', '2026-03-06 12:29:06'),
(8, 'BT2026030619204361', 1, 'bet_loss', 10.00, 'paid', '2026-03-06 15:20:43'),
(9, 'BT2026030705103192', 1, 'bet_loss', 10.00, 'paid', '2026-03-07 01:10:31'),
(10, 'BT2026032613400427', 1, 'bet_loss', 10.00, 'paid', '2026-03-26 13:40:04'),
(11, 'BT2026032614251268', 1, 'bet_loss', 10.00, 'paid', '2026-03-26 14:25:12');

-- --------------------------------------------------------

--
-- Estrutura para tabela `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(150) NOT NULL,
  `cpf` char(11) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `theme` enum('classic','neo') NOT NULL DEFAULT 'classic',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `cpf`, `password`, `role`, `balance`, `theme`, `created_at`, `updated_at`) VALUES
(1, 'Administrador', 'pedro@pedro.com', '12345678901', '$2a$12$XZTZAN7bIOLGbkChsXmj2eDFpsDbqaeOq1ObvG7YLxQhNdT02Pz0m', 'admin', 950.00, 'classic', '2026-03-06 02:08:12', '2026-03-26 14:25:12'),
(2, 'Jogador Demo', 'teste@test.com', '98765432100', '$2a$12$XZTZAN7bIOLGbkChsXmj2eDFpsDbqaeOq1ObvG7YLxQhNdT02Pz0m', 'user', 450.00, 'classic', '2026-03-06 02:08:12', '2026-03-06 02:08:12'),
(3, 'Teste', 'teste@teste.com', '56394268291', '$2y$10$ifLGVFynGtAS5KiAVb2Okelqv4jA5m9475Gh9O0vEnwt.Cfgt07le', 'user', 0.00, 'classic', '2026-03-06 11:56:32', '2026-03-06 11:56:32'),
(4, 'Pedrow', 'pedro2@pedro.com', '33216258220', '$2y$10$L2boW97W2kzJGQ3hGS/S6.IY0cbvBOS3YpNemL3OaE21cX1m1Rubu', 'user', 0.00, 'classic', '2026-03-06 11:57:55', '2026-03-06 11:57:55');

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `bet_tickets`
--
ALTER TABLE `bet_tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ticket_code` (`ticket_code`),
  ADD KEY `idx_tickets_user` (`user_id`),
  ADD KEY `idx_tickets_status` (`status`);

--
-- Índices de tabela `bet_ticket_items`
--
ALTER TABLE `bet_ticket_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket_items_ticket` (`ticket_id`),
  ADD KEY `idx_ticket_items_game` (`game_id`),
  ADD KEY `fk_ticket_items_odd` (`odd_id`);

--
-- Índices de tabela `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Índices de tabela `games`
--
ALTER TABLE `games`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_games_match_date` (`match_date`),
  ADD KEY `idx_games_league` (`league_id`),
  ADD KEY `idx_games_home_team` (`home_team_id`),
  ADD KEY `idx_games_away_team` (`away_team_id`),
  ADD KEY `fk_games_created_by` (`created_by_user_id`);

--
-- Índices de tabela `game_results`
--
ALTER TABLE `game_results`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_game_market_result` (`game_id`,`market_name`);

--
-- Índices de tabela `leagues`
--
ALTER TABLE `leagues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_leagues_category` (`category_id`);

--
-- Índices de tabela `odds`
--
ALTER TABLE `odds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_odds_game_market_option` (`game_id`,`market_name`,`option_name`),
  ADD KEY `idx_odds_game` (`game_id`);

--
-- Índices de tabela `pix_transactions`
--
ALTER TABLE `pix_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_code` (`reference_code`),
  ADD KEY `idx_pix_user` (`user_id`),
  ADD KEY `idx_pix_status` (`status`);

--
-- Índices de tabela `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Índices de tabela `teams`
--
ALTER TABLE `teams`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Índices de tabela `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transactions_user` (`user_id`),
  ADD KEY `idx_transactions_reference` (`reference`);

--
-- Índices de tabela `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `cpf` (`cpf`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `bet_tickets`
--
ALTER TABLE `bet_tickets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de tabela `bet_ticket_items`
--
ALTER TABLE `bet_ticket_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT de tabela `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `games`
--
ALTER TABLE `games`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=167;

--
-- AUTO_INCREMENT de tabela `game_results`
--
ALTER TABLE `game_results`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT de tabela `leagues`
--
ALTER TABLE `leagues`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- AUTO_INCREMENT de tabela `odds`
--
ALTER TABLE `odds`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=511;

--
-- AUTO_INCREMENT de tabela `pix_transactions`
--
ALTER TABLE `pix_transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- AUTO_INCREMENT de tabela `teams`
--
ALTER TABLE `teams`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=428;

--
-- AUTO_INCREMENT de tabela `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT de tabela `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `bet_tickets`
--
ALTER TABLE `bet_tickets`
  ADD CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `bet_ticket_items`
--
ALTER TABLE `bet_ticket_items`
  ADD CONSTRAINT `fk_ticket_items_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ticket_items_odd` FOREIGN KEY (`odd_id`) REFERENCES `odds` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ticket_items_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `bet_tickets` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `games`
--
ALTER TABLE `games`
  ADD CONSTRAINT `fk_games_away_team` FOREIGN KEY (`away_team_id`) REFERENCES `teams` (`id`),
  ADD CONSTRAINT `fk_games_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_games_home_team` FOREIGN KEY (`home_team_id`) REFERENCES `teams` (`id`),
  ADD CONSTRAINT `fk_games_league` FOREIGN KEY (`league_id`) REFERENCES `leagues` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `game_results`
--
ALTER TABLE `game_results`
  ADD CONSTRAINT `fk_game_results_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `leagues`
--
ALTER TABLE `leagues`
  ADD CONSTRAINT `fk_leagues_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `odds`
--
ALTER TABLE `odds`
  ADD CONSTRAINT `fk_odds_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `pix_transactions`
--
ALTER TABLE `pix_transactions`
  ADD CONSTRAINT `fk_pix_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
