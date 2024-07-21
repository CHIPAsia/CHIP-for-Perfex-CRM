<?php

defined('BASEPATH') or exit('No direct script access allowed');

if (!$CI->db->table_exists(db_prefix() . 'chip')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "chip` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chip_slug_idx` (`slug`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');
}