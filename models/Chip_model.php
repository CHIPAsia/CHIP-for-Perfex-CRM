<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Chip_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get payment by ID
     * @param  mixed $id payment id
     * @return object
     */
    public function get_by_slug($slug)
    {
        $this->db->select('*');
        $this->db->order_by(db_prefix() . 'chip.id', 'desc');
        $this->db->where(db_prefix() . 'chip.slug', $slug);
        $payment = $this->db->get(db_prefix() . 'chip')->row();

        return $payment;
    }

    public function insert($purchase) {
      $data = [
        'slug' => $purchase['id'],
        'status' => $purchase['status']
      ];

      try {
        $this->db->insert(db_prefix() . 'chip', $data);
      } catch (Exception $e) {
        return false;
      }
      
      $insert_id = $this->db->insert_id();

      return $insert_id;
    }
}
